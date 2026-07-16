<?php

declare(strict_types=1);

namespace FulltimeTrading\Backtest;

use FulltimeTrading\Domain\Bar;
use FulltimeTrading\Indicators\IndicatorCalculator;

/**
 * Causal close-to-next-open tactical rotation replay.
 *
 * A completed close creates a target for the next session. Portfolio weights,
 * turnover, calendar-day margin interest and an OHLC drawdown/gross bound are
 * then marked chronologically. The class never submits broker orders.
 */
final class CausalTacticalRotationBacktester
{
    /** @var array<string, mixed> */
    private array $config;

    /** @param array<string, mixed> $config */
    public function __construct(array $config, private readonly IndicatorCalculator $indicators = new IndicatorCalculator())
    {
        $this->config = $this->validateConfig($config);
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return $this->config;
    }

    /**
     * @param array<string, list<Bar>> $barsBySymbol
     * @return array{curve:list<array<string,mixed>>,next_target:array<string,mixed>,features_as_of:string}
     */
    public function run(array $barsBySymbol, string $tradeStart, string $tradeEndInclusive, float $initialEquity = 30000.0): array
    {
        if ($initialEquity <= 0.0) {
            throw new \InvalidArgumentException('Initial equity must be positive.');
        }
        if ($tradeEndInclusive < $tradeStart) {
            throw new \InvalidArgumentException('Trade end must not precede trade start.');
        }

        [$features, $barsByDate] = $this->buildFeatures($barsBySymbol);
        $benchmark = (string) $this->config['benchmark'];
        $dates = array_keys($barsByDate[$benchmark] ?? []);
        $dates = array_values(array_filter(
            $dates,
            static fn (string $date): bool => $date >= $tradeStart && $date <= $tradeEndInclusive,
        ));
        sort($dates, SORT_STRING);
        if (count($dates) < 2) {
            throw new \RuntimeException('Tactical rotation replay requires at least two benchmark sessions.');
        }

        $equity = $initialEquity;
        $weights = [];
        $desired = [];
        $desiredSignalDate = null;
        $curve = [];
        $previousDate = null;
        $drawdownPeak = $equity;
        $drawdownLatched = false;
        $drawdownRearmPending = false;
        $cooldownLeft = 0;
        $riskExitPending = false;
        $positionPeakClose = null;
        $circuitActivations = 0;
        $positionExitActivations = 0;

        foreach ($dates as $dayIndex => $date) {
            $startEquity = $equity;
            if ($previousDate === null) {
                $nextDesired = $this->desiredWeights($date, $features);
                $curve[] = [
                    'date' => $date,
                    'period_start_date' => $date,
                    'start_equity' => $equity,
                    'equity' => $equity,
                    'equity_low' => $equity,
                    'equity_high' => $equity,
                    'gross_close' => 0.0,
                    'gross_bound' => 0.0,
                    'gross_high_notional' => 0.0,
                    'pre_execution_equity' => 0.0,
                    'turnover' => 0.0,
                    'turnover_notional' => 0.0,
                    'holding' => null,
                    'return_symbol' => null,
                    'episode_pnl_segments' => [],
                    'rebalance' => false,
                    'signal_date' => null,
                    'circuit_cooldown_left' => 0,
                    'risk_signal' => null,
                ];
                $previousDate = $date;
                $desired = $nextDesired;
                $desiredSignalDate = $date;
                continue;
            }

            if ($cooldownLeft > 0) {
                $cooldownLeft--;
                if ($cooldownLeft === 0
                    && $drawdownRearmPending
                    && (bool) $this->config['drawdown_rearm_after_cooldown']) {
                    // A completed cash pause starts a new risk epoch. Otherwise a
                    // second decline is ignored until the pre-circuit ATH returns.
                    $drawdownPeak = $equity;
                    $drawdownLatched = false;
                    $drawdownRearmPending = false;
                }
            }

            $previousCloseSymbol = array_key_first($weights);
            $previousCloseWeights = $weights;
            $oldStagePnl = [];
            $newStagePnl = [];

            $grossAtPreviousClose = array_sum(array_map('abs', $weights));
            $calendarDays = max(
                1,
                (int) (new \DateTimeImmutable($previousDate))->diff(new \DateTimeImmutable($date))->days,
            );
            $interestFraction = max(0.0, $grossAtPreviousClose - 1.0)
                * (float) $this->config['margin_rate_annual']
                * $calendarDays / 360.0;

            $overnightReturn = 0.0;
            $openRatios = [];
            foreach ($weights as $symbol => $weight) {
                $previousBar = $barsByDate[$symbol][$previousDate] ?? null;
                $bar = $barsByDate[$symbol][$date] ?? null;
                if (!$previousBar instanceof Bar || !$bar instanceof Bar || $previousBar->close <= 0.0 || $bar->open <= 0.0) {
                    throw new \RuntimeException(sprintf('Missing held-symbol bar for %s across %s/%s.', $symbol, $previousDate, $date));
                }
                $ratio = $bar->open / $previousBar->close;
                $openRatios[$symbol] = $ratio;
                $overnightReturn += $weight * ($ratio - 1.0);
                $oldStagePnl[$symbol] = $startEquity * $weight * ($ratio - 1.0);
            }
            if ($interestFraction > 0.0 && $grossAtPreviousClose > 0.0) {
                $interestDollars = $startEquity * $interestFraction;
                foreach ($previousCloseWeights as $symbol => $weight) {
                    $oldStagePnl[$symbol] = (float) ($oldStagePnl[$symbol] ?? 0.0)
                        - $interestDollars * abs($weight) / $grossAtPreviousClose;
                }
            }
            $openDenominator = 1.0 + $overnightReturn - $interestFraction;
            if ($openDenominator <= 0.0) {
                throw new \RuntimeException(sprintf('Portfolio became insolvent at the open on %s.', $date));
            }
            $equity *= $openDenominator;
            $preExecutionEquity = $equity;
            $openWeights = [];
            foreach ($weights as $symbol => $weight) {
                $openWeights[$symbol] = $weight * $openRatios[$symbol] / $openDenominator;
            }
            $weights = $openWeights;

            $regularRebalance = $dayIndex >= 1
                && ($dayIndex - 1) % (int) $this->config['rebalance_sessions'] === 0;
            $rebalance = $riskExitPending || $regularRebalance;
            $executionDesired = ($riskExitPending || $cooldownLeft > 0) ? [] : $desired;
            $turnover = 0.0;
            if ($rebalance) {
                $previousSymbol = array_key_first($weights);
                [$costFraction, $turnover, $costFractionBySymbol] = $this->executionCost(
                    $weights,
                    $executionDesired,
                );
                $costFactor = 1.0 - $costFraction;
                if ($costFactor <= 0.0) {
                    throw new \RuntimeException(sprintf('Transaction costs exhausted portfolio equity on %s.', $date));
                }
                $equity *= $costFactor;
                foreach ($costFractionBySymbol as $symbol => $symbolCostFraction) {
                    $costPnl = -$preExecutionEquity * $symbolCostFraction;
                    if (array_key_exists($symbol, $previousCloseWeights)) {
                        $oldStagePnl[$symbol] = (float) ($oldStagePnl[$symbol] ?? 0.0) + $costPnl;
                    } else {
                        $newStagePnl[$symbol] = (float) ($newStagePnl[$symbol] ?? 0.0) + $costPnl;
                    }
                }
                $weights = $executionDesired;
                $newSymbol = array_key_first($weights);
                if ($newSymbol !== $previousSymbol) {
                    $positionPeakClose = $newSymbol === null
                        ? null
                        : (float) $barsByDate[$newSymbol][$previousDate]->close;
                }
                $riskExitPending = false;
            }

            $weightsAtOpen = $weights;
            $holdingAtOpen = array_key_first($weightsAtOpen);
            $closeReturn = 0.0;
            $lowReturn = 0.0;
            $highReturn = 0.0;
            foreach ($weightsAtOpen as $symbol => $weight) {
                $bar = $barsByDate[$symbol][$date] ?? null;
                if (!$bar instanceof Bar || $bar->open <= 0.0) {
                    throw new \RuntimeException(sprintf('Missing held-symbol execution bar for %s on %s.', $symbol, $date));
                }
                $closeReturn += $weight * ($bar->close / $bar->open - 1.0);
                $lowReturn += $weight * ($bar->low / $bar->open - 1.0);
                $highReturn += $weight * ($bar->high / $bar->open - 1.0);
                $newStagePnl[$symbol] = (float) ($newStagePnl[$symbol] ?? 0.0)
                    + $equity * $weight * ($bar->close / $bar->open - 1.0);
            }

            $equityAtOpen = $equity;
            $equityLow = $equityAtOpen * max(0.0, 1.0 + $lowReturn);
            $equityHigh = $equityAtOpen * max(0.0, 1.0 + $highReturn);
            $grossHighNotional = $this->grossHighNotional(
                $equityAtOpen,
                $weightsAtOpen,
                $barsByDate,
                $date,
            );
            $closeFactor = 1.0 + $closeReturn;
            if ($closeFactor <= 0.0) {
                throw new \RuntimeException(sprintf('Portfolio became insolvent by the close on %s.', $date));
            }
            $equity *= $closeFactor;
            $episodePnlSegments = $this->episodePnlSegments($oldStagePnl, $newStagePnl);
            $attributedPnl = array_sum(array_map(
                static fn (array $segment): float => (float) $segment['pnl'],
                $episodePnlSegments,
            ));
            $actualPnl = $equity - $startEquity;
            if (abs($attributedPnl - $actualPnl) > max(1.0e-8, abs($actualPnl) * 1.0e-10)) {
                throw new \RuntimeException(sprintf('Episode P/L attribution mismatch on %s.', $date));
            }

            $closeWeights = [];
            $grossClose = 0.0;
            $closeDenominator = max(0.000001, 1.0 + $closeReturn);
            foreach ($weightsAtOpen as $symbol => $weight) {
                $bar = $barsByDate[$symbol][$date];
                $closeWeight = $weight * ($bar->close / $bar->open) / $closeDenominator;
                $closeWeights[$symbol] = $closeWeight;
                $grossClose += abs($closeWeight);
            }
            $weights = $closeWeights;
            $grossBound = max(
                $grossClose,
                $this->scenarioGross($weightsAtOpen, $barsByDate, $date, 'low', $lowReturn),
                $this->scenarioGross($weightsAtOpen, $barsByDate, $date, 'high', $highReturn),
                $this->conservativeGrossBound($weightsAtOpen, $barsByDate, $date, $lowReturn),
            );

            $currentSymbolAtClose = array_key_first($weights);
            if ($currentSymbolAtClose !== null) {
                $currentClose = (float) $barsByDate[$currentSymbolAtClose][$date]->close;
                $positionPeakClose = max((float) ($positionPeakClose ?? $currentClose), $currentClose);
            } else {
                $positionPeakClose = null;
            }
            $drawdownPeak = max($drawdownPeak, $equity);
            if ($equity >= $drawdownPeak * (1.0 - 1.0e-12)) {
                $drawdownLatched = false;
            }
            $riskSignal = null;
            $staticTrailingBreached = false;
            $dynamicTrailingBreached = false;
            if ($currentSymbolAtClose !== null) {
                $currentClose = (float) $barsByDate[$currentSymbolAtClose][$date]->close;
                $staticTrailingPct = (float) $this->config['position_trailing_close_pct'];
                $staticTrailingBreached = $staticTrailingPct > 0.0
                    && $currentClose < (float) $positionPeakClose * (1.0 - $staticTrailingPct);

                $dynamicMultiple = (float) $this->config['position_dynamic_trailing_daily_vol_multiple'];
                $annualizedVolatility = $features[$currentSymbolAtClose][$date]['volatility'] ?? null;
                if ($dynamicMultiple > 0.0
                    && is_float($annualizedVolatility)
                    && is_finite($annualizedVolatility)
                    && $annualizedVolatility > 0.0) {
                    // rollingVolatility() is annualized. The close observed on
                    // this completed session may only queue an exit for the next
                    // open, and must first be converted back to daily volatility.
                    $dynamicTrailingPct = (float) $annualizedVolatility / sqrt(252.0) * $dynamicMultiple;
                    $dynamicTrailingPct = max(
                        (float) $this->config['position_dynamic_trailing_min_pct'],
                        min(
                            (float) $this->config['position_dynamic_trailing_max_pct'],
                            $dynamicTrailingPct,
                        ),
                    );
                    $dynamicTrailingBreached = $currentClose
                        < (float) $positionPeakClose * (1.0 - $dynamicTrailingPct);
                }
            }
            if ($staticTrailingBreached || $dynamicTrailingBreached) {
                $riskSignal = 'position_trailing_close';
            }
            if (!$drawdownLatched
                && $equity / max(0.000001, $drawdownPeak) - 1.0 <= -(float) $this->config['drawdown_kill_pct']) {
                $riskSignal = 'portfolio_drawdown';
                $drawdownLatched = true;
            }
            if ($riskSignal !== null) {
                $riskExitPending = true;
                if ($riskSignal === 'portfolio_drawdown') {
                    $cooldownLeft = max(
                        $cooldownLeft,
                        (int) $this->config['drawdown_cooldown_sessions'] + 1,
                    );
                    $drawdownRearmPending = true;
                    $circuitActivations++;
                } else {
                    $cooldownLeft = max(
                        $cooldownLeft,
                        (int) $this->config['position_exit_cooldown_sessions'] + 1,
                    );
                    $positionExitActivations++;
                }
            }

            $curve[] = [
                'date' => $date,
                'period_start_date' => $previousDate,
                'start_equity' => $startEquity,
                'equity' => $equity,
                'equity_low' => $equityLow,
                'equity_high' => $equityHigh,
                'gross_close' => $grossClose,
                'gross_bound' => $grossBound,
                'gross_high_notional' => $grossHighNotional,
                'pre_execution_equity' => $preExecutionEquity,
                'turnover' => $turnover,
                'turnover_notional' => $turnover * $preExecutionEquity,
                'holding' => array_key_first($weights),
                // Kept as a legacy single-label view. Concentration uses the
                // exact open-boundary dollar segments below.
                'return_symbol' => $holdingAtOpen ?? $previousCloseSymbol,
                'episode_pnl_segments' => $episodePnlSegments,
                'rebalance' => $rebalance,
                'signal_date' => $rebalance ? $desiredSignalDate : null,
                'circuit_cooldown_left' => $cooldownLeft,
                'risk_signal' => $riskSignal,
            ];

            $previousDate = $date;
            $desired = $this->desiredWeights($date, $features);
            $desiredSignalDate = $date;
        }

        $asOf = (string) end($dates);

        $nextDayIndex = count($dates);
        $regularRebalanceDue = ($nextDayIndex - 1) % (int) $this->config['rebalance_sessions'] === 0;
        $rebalanceDue = $riskExitPending || $regularRebalanceDue;
        $nextCooldownLeft = max(0, $cooldownLeft - 1);
        $currentSymbol = array_key_first($weights);
        $currentGross = array_sum(array_map('abs', $weights));
        $rankedSymbol = array_key_first($desired);
        $rankedGross = $desired === [] ? 0.0 : (float) reset($desired);
        $nextExecutionDesired = ($riskExitPending || $nextCooldownLeft > 0) ? [] : $desired;
        // The latest hypothetical ranking lives in ranked_*. Executable fields
        // remain empty when the fixed schedule does not call for a rebalance.
        $signalSymbol = $rebalanceDue ? array_key_first($nextExecutionDesired) : null;
        $signalGross = $rebalanceDue && $nextExecutionDesired !== []
            ? (float) reset($nextExecutionDesired)
            : 0.0;
        $action = 'hold';
        if ($rebalanceDue) {
            if ($signalSymbol === null && $currentSymbol !== null) {
                $action = 'exit_to_cash';
            } elseif ($signalSymbol === null) {
                $action = 'hold_cash';
            } elseif ($signalSymbol !== null) {
                $action = $currentSymbol === $signalSymbol ? 'resize_or_hold' : 'rebalance';
            }
        }

        return [
            'curve' => $curve,
            'next_target' => [
                'signal_date' => $asOf,
                'execution' => 'next_session_open',
                'action' => $action,
                'rebalance_due_next_session' => $rebalanceDue,
                'current_symbol' => $currentSymbol,
                'current_gross' => $currentGross,
                'symbol' => $signalSymbol,
                'gross' => $signalGross,
                'ranked_symbol' => $rankedSymbol,
                'ranked_gross' => $rankedGross,
                'circuit_cooldown_left' => $cooldownLeft,
                'cooldown_after_next_open_tick' => $nextCooldownLeft,
                'risk_exit_pending' => $riskExitPending,
                'drawdown_rearm_pending' => $drawdownRearmPending,
                'shadow_only' => true,
            ],
            'features_as_of' => $asOf,
            'circuit_activations' => $circuitActivations,
            'position_exit_activations' => $positionExitActivations,
        ];
    }

    /**
     * @param list<array<string,mixed>> $curve
     * @return array<string,mixed>
     */
    public function metrics(array $curve, ?string $start = null, ?string $endExclusive = null): array
    {
        $rows = array_values(array_filter(
            $curve,
            static fn (array $row): bool => ($start === null || $row['date'] >= $start)
                && ($endExclusive === null || $row['date'] < $endExclusive),
        ));
        if ($rows === []) {
            return [
                'points' => 0,
                'return' => 0.0,
                'cagr' => 0.0,
                'max_drawdown' => 0.0,
                'max_gross_bound' => 0.0,
                'turnover' => 0.0,
                'invested_sessions' => 0,
                'top1_positive_day_share' => 0.0,
                'top5_positive_day_share' => 0.0,
                'ex_top5_days_cagr' => 0.0,
                'return_symbols' => 0,
                'positive_holding_episodes' => 0,
                'top1_positive_episode_share' => 0.0,
                'top5_positive_episode_share' => 0.0,
            ];
        }

        $firstEquity = (float) $rows[0]['start_equity'];
        $lastEquity = (float) $rows[array_key_last($rows)]['equity'];
        $calendarDays = max(
            1,
            (int) (new \DateTimeImmutable((string) ($rows[0]['period_start_date'] ?? $rows[0]['date'])))
                ->diff(new \DateTimeImmutable($rows[array_key_last($rows)]['date']))->days,
        );
        $factor = $firstEquity > 0.0 ? $lastEquity / $firstEquity : 0.0;
        $peak = $firstEquity;
        $maxDrawdown = 0.0;
        $maxGross = 0.0;
        $turnover = 0.0;
        $invested = 0;
        $dailyReturns = [];
        foreach ($rows as $row) {
            $equity = (float) $row['equity'];
            $equityLow = (float) $row['equity_low'];
            $equityHigh = (float) $row['equity_high'];
            $peak = max($peak, $equityHigh, $equity);
            $maxDrawdown = min($maxDrawdown, min($equityLow, $equity) / max(0.000001, $peak) - 1.0);
            $maxGross = max($maxGross, (float) $row['gross_bound']);
            $turnover += (float) $row['turnover'];
            $invested += $row['holding'] === null ? 0 : 1;
            $startEquity = (float) $row['start_equity'];
            $dailyReturn = $startEquity > 0.0 ? $equity / $startEquity - 1.0 : -1.0;
            $dailyReturns[] = $dailyReturn;
        }
        [$episodeReturns, $returnSymbols] = $this->holdingEpisodeGains($rows);

        $positive = array_values(array_filter($dailyReturns, static fn (float $return): bool => $return > 0.0));
        rsort($positive, SORT_NUMERIC);
        $positiveSum = array_sum($positive);
        $top1Share = $positiveSum > 0.0 ? (float) ($positive[0] ?? 0.0) / $positiveSum : 0.0;
        $top5Share = $positiveSum > 0.0 ? array_sum(array_slice($positive, 0, 5)) / $positiveSum : 0.0;
        $drop = array_keys($dailyReturns);
        usort($drop, static fn (int $a, int $b): int => $dailyReturns[$b] <=> $dailyReturns[$a] ?: $a <=> $b);
        $drop = array_fill_keys(array_slice($drop, 0, 5), true);
        $exTop5Factor = 1.0;
        foreach ($dailyReturns as $index => $return) {
            if (!isset($drop[$index])) {
                $exTop5Factor *= max(0.0, 1.0 + $return);
            }
        }
        $positiveEpisodes = array_values(array_filter(
            $episodeReturns,
            static fn (float $return): bool => $return > 0.0,
        ));
        rsort($positiveEpisodes, SORT_NUMERIC);
        $positiveEpisodeSum = array_sum($positiveEpisodes);
        $top1EpisodeShare = $positiveEpisodeSum > 0.0
            ? (float) ($positiveEpisodes[0] ?? 0.0) / $positiveEpisodeSum
            : 0.0;
        $top5EpisodeShare = $positiveEpisodeSum > 0.0
            ? array_sum(array_slice($positiveEpisodes, 0, 5)) / $positiveEpisodeSum
            : 0.0;

        return [
            'points' => count($rows),
            'return' => $factor - 1.0,
            'cagr' => $factor > 0.0 ? $factor ** (365.25 / $calendarDays) - 1.0 : -1.0,
            'max_drawdown' => $maxDrawdown,
            'max_gross_bound' => $maxGross,
            'turnover' => $turnover,
            'annualized_turnover' => $turnover * 365.25 / $calendarDays,
            'invested_sessions' => $invested,
            'top1_positive_day_share' => $top1Share,
            'top5_positive_day_share' => $top5Share,
            'ex_top5_days_cagr' => $exTop5Factor > 0.0
                ? $exTop5Factor ** (365.25 / $calendarDays) - 1.0
                : -1.0,
            'return_symbols' => count($returnSymbols),
            'positive_holding_episodes' => count($positiveEpisodes),
            'top1_positive_episode_share' => $top1EpisodeShare,
            'top5_positive_episode_share' => $top5EpisodeShare,
        ];
    }

    /**
     * @param array<string, list<Bar>> $barsBySymbol
     * @return array{0:array<string,array<string,array<string,float|null>>>,1:array<string,array<string,Bar>>}
     */
    private function buildFeatures(array $barsBySymbol): array
    {
        $marketContext = (array) $this->config['market_context'];
        $signalMarketFilter = (array) $this->config['signal_market_filter'];
        $required = array_values(array_unique(array_merge(
            [
                (string) $this->config['benchmark'],
                (string) $marketContext['symbol'],
                (string) $signalMarketFilter['symbol'],
            ],
            (array) $this->config['universe'],
        )));
        $features = [];
        $barsByDate = [];
        $timezone = new \DateTimeZone('America/New_York');
        $periods = array_values(array_unique(array_map('intval', array_keys((array) $this->config['factor_weights']))));
        $periods[] = (int) $this->config['benchmark_sma_period'];
        $periods = array_values(array_unique($periods));

        foreach ($required as $symbol) {
            $bars = $barsBySymbol[$symbol] ?? [];
            if ($bars === []) {
                throw new \RuntimeException('Missing tactical rotation bars for ' . $symbol . '.');
            }
            usort($bars, static fn (Bar $a, Bar $b): int => $a->time <=> $b->time);
            $closes = array_map(static fn (Bar $bar): float => $bar->close, $bars);
            $returns = array_fill(0, count($bars), null);
            foreach ($bars as $index => $bar) {
                if ($index > 0 && $bars[$index - 1]->close > 0.0) {
                    $returns[$index] = $bar->close / $bars[$index - 1]->close - 1.0;
                }
            }
            $volatility = $this->rollingVolatility($returns, (int) $this->config['volatility_period']);
            $dollarVolume = $this->rollingDollarVolume($bars, (int) $this->config['dollar_volume_period']);
            $sma = $this->indicators->sma($closes, (int) $this->config['benchmark_sma_period']);
            $signalMarketSma = $this->indicators->sma($closes, (int) $signalMarketFilter['sma_period']);
            $assetSma = (int) $this->config['asset_sma_period'] >= 2
                ? $this->indicators->sma($closes, (int) $this->config['asset_sma_period'])
                : array_fill(0, count($closes), null);
            $contextSma = $this->indicators->sma($closes, (int) $marketContext['sma_period']);
            foreach ($bars as $index => $bar) {
                $date = $bar->time->setTimezone($timezone)->format('Y-m-d');
                $barsByDate[$symbol][$date] = $bar;
                $row = [
                    'close' => $bar->close,
                    'history_sessions' => $index + 1,
                    'volatility' => $volatility[$index],
                    'dollar_volume' => $dollarVolume[$index],
                    'benchmark_sma' => $sma[$index],
                    'signal_market_sma' => $signalMarketSma[$index],
                    'asset_sma' => $assetSma[$index],
                    'context_sma' => $contextSma[$index],
                ];
                foreach ($periods as $period) {
                    $row['return_' . $period] = $index >= $period && $bars[$index - $period]->close > 0.0
                        ? $bar->close / $bars[$index - $period]->close - 1.0
                        : null;
                }
                $features[$symbol][$date] = $row;
            }
        }

        return [$features, $barsByDate];
    }

    /**
     * @param array<string,array<string,array<string,float|null>>> $features
     * @return array<string,float>
     */
    private function desiredWeights(string $date, array $features): array
    {
        $benchmark = (string) $this->config['benchmark'];
        $benchmarkRow = $features[$benchmark][$date] ?? null;
        $signalMarketFilter = (array) $this->config['signal_market_filter'];
        $signalMarketRow = $features[(string) $signalMarketFilter['symbol']][$date] ?? null;
        if (!is_array($benchmarkRow)
            || !is_array($signalMarketRow)
            || !is_float($signalMarketRow['signal_market_sma'] ?? null)
            || (float) $signalMarketRow['close'] <= (float) $signalMarketRow['signal_market_sma']) {
            return [];
        }

        $scores = [];
        foreach ((array) $this->config['universe'] as $symbol) {
            $row = $features[$symbol][$date] ?? null;
            if (!is_array($row)
                || (int) ($row['history_sessions'] ?? 0) < (int) $this->config['minimum_history_sessions']
                || (float) ($row['dollar_volume'] ?? 0.0) < (float) $this->config['min_dollar_volume']) {
                continue;
            }
            if ((int) $this->config['asset_sma_period'] >= 2
                && (!is_float($row['asset_sma'] ?? null)
                    || (float) $row['close'] <= (float) $row['asset_sma'])) {
                continue;
            }
            $score = 0.0;
            foreach ((array) $this->config['factor_weights'] as $period => $weight) {
                $value = $row['return_' . (int) $period] ?? null;
                if (!is_float($value)) {
                    continue 2;
                }
                $score += (float) $weight * $value;
            }
            if ((bool) $this->config['require_positive_score'] && $score <= 0.0) {
                continue;
            }
            $rawVolatility = $row['volatility'] ?? null;
            if (!is_float($rawVolatility) || !is_finite($rawVolatility) || $rawVolatility <= 0.0) {
                continue;
            }
            $volatility = max(0.05, $rawVolatility);
            $scores[$symbol] = [
                'symbol' => $symbol,
                'score' => $score / ($volatility ** (float) $this->config['volatility_score_power']),
                'volatility' => $volatility,
            ];
        }
        if ($scores === []) {
            return [];
        }
        uasort(
            $scores,
            static fn (array $a, array $b): int => $b['score'] <=> $a['score']
                ?: $a['symbol'] <=> $b['symbol'],
        );
        $symbol = (string) array_key_first($scores);
        $gross = min(
            (float) $this->config['max_gross'],
            (float) $this->config['volatility_target'] / (float) $scores[$symbol]['volatility'],
        );
        $benchmarkVolatility = $benchmarkRow['volatility'] ?? null;
        if (!is_float($benchmarkVolatility) || !is_finite($benchmarkVolatility) || $benchmarkVolatility <= 0.0) {
            return [];
        }
        if ((float) $this->config['benchmark_volatility_target'] > 0.0) {
            $gross *= min(
                1.0,
                (float) $this->config['benchmark_volatility_target'] / max(0.05, $benchmarkVolatility),
            );
        }
        $marketContext = (array) $this->config['market_context'];
        $contextRow = $features[(string) $marketContext['symbol']][$date] ?? null;
        if (!is_array($contextRow)
            || !is_float($contextRow['context_sma'] ?? null)
            || (float) $contextRow['close'] < (float) $contextRow['context_sma']) {
            $gross *= (float) $marketContext['risk_off_multiplier'];
        }
        $gross = min((float) $this->config['max_gross'], max(0.0, $gross));

        return $gross > 0.0 ? [$symbol => $gross] : [];
    }

    /**
     * @param array<string,float> $weightsAtOpen
     * @param array<string,array<string,Bar>> $barsByDate
     */
    private function scenarioGross(array $weightsAtOpen, array $barsByDate, string $date, string $field, float $scenarioReturn): float
    {
        $denominator = max(0.000001, 1.0 + $scenarioReturn);
        $gross = 0.0;
        foreach ($weightsAtOpen as $symbol => $weight) {
            $bar = $barsByDate[$symbol][$date];
            $price = $field === 'low' ? $bar->low : $bar->high;
            $gross += abs($weight * ($price / $bar->open) / $denominator);
        }

        return $gross;
    }

    /**
     * Solve cost against post-cost target weights. The returned turnover is
     * traded notional divided by pre-cost equity and is therefore directly
     * compatible with a one-way bps assumption.
     *
     * @param array<string,float> $currentWeights
     * @param array<string,float> $targetWeights
     * @return array{0:float,1:float,2:array<string,float>}
     */
    private function executionCost(array $currentWeights, array $targetWeights): array
    {
        $rate = (float) $this->config['cost_bps'] / 10000.0;
        $costFraction = 0.0;
        $turnover = 0.0;
        $symbols = array_values(array_unique(array_merge(array_keys($currentWeights), array_keys($targetWeights))));
        for ($iteration = 0; $iteration < 16; $iteration++) {
            $postCostFactor = max(0.0, 1.0 - $costFraction);
            $turnover = 0.0;
            foreach ($symbols as $symbol) {
                $targetPreCostEquityWeight = (float) ($targetWeights[$symbol] ?? 0.0) * $postCostFactor;
                $turnover += abs($targetPreCostEquityWeight - (float) ($currentWeights[$symbol] ?? 0.0));
            }
            $nextCostFraction = $turnover * $rate;
            if (abs($nextCostFraction - $costFraction) < 1.0e-14) {
                $costFraction = $nextCostFraction;
                break;
            }
            $costFraction = $nextCostFraction;
        }

        $postCostFactor = max(0.0, 1.0 - $costFraction);
        $turnoverBySymbol = [];
        foreach ($symbols as $symbol) {
            $targetPreCostEquityWeight = (float) ($targetWeights[$symbol] ?? 0.0) * $postCostFactor;
            $turnoverBySymbol[$symbol] = abs(
                $targetPreCostEquityWeight - (float) ($currentWeights[$symbol] ?? 0.0),
            );
        }
        $attributionTurnover = array_sum($turnoverBySymbol);
        $costFractionBySymbol = [];
        foreach ($turnoverBySymbol as $symbol => $symbolTurnover) {
            $costFractionBySymbol[$symbol] = $attributionTurnover > 0.0
                ? $costFraction * $symbolTurnover / $attributionTurnover
                : 0.0;
        }

        return [$costFraction, $turnover, $costFractionBySymbol];
    }

    /**
     * Preserve the chronological ownership of P/L around the execution open.
     * Overnight movement, margin and exit costs belong to the prior position;
     * entry costs and open-to-close movement belong to the new position.
     * Consecutive segments for the same symbol are merged into one episode leg.
     *
     * @param array<string,float> $oldStagePnl
     * @param array<string,float> $newStagePnl
     * @return list<array{symbol:string,pnl:float}>
     */
    private function episodePnlSegments(array $oldStagePnl, array $newStagePnl): array
    {
        $segments = [];
        foreach ([$oldStagePnl, $newStagePnl] as $stage) {
            foreach ($stage as $symbol => $pnl) {
                $lastIndex = array_key_last($segments);
                if ($lastIndex !== null && $segments[$lastIndex]['symbol'] === $symbol) {
                    $segments[$lastIndex]['pnl'] += $pnl;
                    continue;
                }
                $segments[] = ['symbol' => $symbol, 'pnl' => $pnl];
            }
        }

        return $segments;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{0:list<float>,1:array<string,true>}
     */
    private function holdingEpisodeGains(array $rows): array
    {
        $episodeGains = [];
        $returnSymbols = [];
        $episodeSymbol = null;
        $episodeGain = 0.0;
        foreach ($rows as $row) {
            $segments = $row['episode_pnl_segments'] ?? null;
            if (!is_array($segments)) {
                $fallbackSymbol = $row['return_symbol'] ?? $row['holding'] ?? null;
                $segments = is_string($fallbackSymbol) && $fallbackSymbol !== ''
                    ? [[
                        'symbol' => $fallbackSymbol,
                        'pnl' => (float) $row['equity'] - (float) $row['start_equity'],
                    ]]
                    : [];
            }
            foreach ($segments as $segment) {
                if (!is_array($segment)) {
                    continue;
                }
                $symbol = $segment['symbol'] ?? null;
                if (!is_string($symbol) || $symbol === '') {
                    continue;
                }
                if ($symbol !== $episodeSymbol) {
                    if ($episodeSymbol !== null) {
                        $episodeGains[] = $episodeGain;
                    }
                    $episodeSymbol = $symbol;
                    $episodeGain = 0.0;
                }
                $returnSymbols[$symbol] = true;
                $episodeGain += (float) ($segment['pnl'] ?? 0.0);
            }
            $closingHolding = $row['holding'] ?? null;
            if ((!is_string($closingHolding) || $closingHolding === '') && $episodeSymbol !== null) {
                $episodeGains[] = $episodeGain;
                $episodeSymbol = null;
                $episodeGain = 0.0;
            }
        }
        if ($episodeSymbol !== null) {
            $episodeGains[] = $episodeGain;
        }

        return [$episodeGains, $returnSymbols];
    }

    /**
     * Conservative leverage envelope: value the long assets at their daily
     * highs while dividing by portfolio equity at simultaneous daily lows.
     *
     * @param array<string,float> $weightsAtOpen
     * @param array<string,array<string,Bar>> $barsByDate
     */
    private function conservativeGrossBound(
        array $weightsAtOpen,
        array $barsByDate,
        string $date,
        float $lowReturn,
    ): float {
        $equityAtLow = max(0.000001, 1.0 + $lowReturn);
        $highNotional = 0.0;
        foreach ($weightsAtOpen as $symbol => $weight) {
            $bar = $barsByDate[$symbol][$date];
            $highNotional += abs($weight * ($bar->high / $bar->open));
        }

        return $highNotional / $equityAtLow;
    }

    /**
     * Exact currency notional of the held assets marked at the session high.
     * Keeping this in currency units lets an ensemble sum independent sleeve
     * notionals before dividing by the sum of sleeve low equities, without
     * accidentally netting or equity-weighting already-normalized ratios.
     *
     * @param array<string,float> $weightsAtOpen
     * @param array<string,array<string,Bar>> $barsByDate
     */
    private function grossHighNotional(
        float $equityAtOpen,
        array $weightsAtOpen,
        array $barsByDate,
        string $date,
    ): float {
        $notional = 0.0;
        foreach ($weightsAtOpen as $symbol => $weight) {
            $bar = $barsByDate[$symbol][$date];
            $notional += abs($equityAtOpen * $weight * ($bar->high / $bar->open));
        }

        return $notional;
    }

    /** @param list<float|null> $returns @return list<float|null> */
    private function rollingVolatility(array $returns, int $period): array
    {
        $result = array_fill(0, count($returns), null);
        for ($index = $period; $index < count($returns); $index++) {
            $slice = array_values(array_filter(
                array_slice($returns, $index - $period + 1, $period),
                static fn (?float $value): bool => is_float($value),
            ));
            if (count($slice) < $period - 1) {
                continue;
            }
            $mean = array_sum($slice) / count($slice);
            $variance = array_sum(array_map(
                static fn (float $value): float => ($value - $mean) ** 2,
                $slice,
            )) / max(1, count($slice) - 1);
            $result[$index] = sqrt($variance * 252.0);
        }

        return $result;
    }

    /** @param list<Bar> $bars @return list<float|null> */
    private function rollingDollarVolume(array $bars, int $period): array
    {
        $result = array_fill(0, count($bars), null);
        $sum = 0.0;
        foreach ($bars as $index => $bar) {
            $sum += $bar->close * $bar->volume;
            if ($index >= $period) {
                $sum -= $bars[$index - $period]->close * $bars[$index - $period]->volume;
            }
            if ($index >= $period - 1) {
                $result[$index] = $sum / $period;
            }
        }

        return $result;
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    private function validateConfig(array $config): array
    {
        $providedSignalMarketFilter = $config['signal_market_filter'] ?? null;
        $defaults = [
            'benchmark' => 'QQQ',
            'signal_market_filter' => null,
            'market_context' => ['symbol' => 'QQQ', 'sma_period' => 50, 'risk_off_multiplier' => 1.0],
            'factor_weights' => [5 => -2.0, 20 => 1.0, 60 => 1.0, 90 => 2.0, 120 => -1.0],
            'volatility_period' => 20,
            'volatility_score_power' => 1.0,
            'volatility_target' => 0.45,
            'benchmark_volatility_target' => 0.0,
            'max_gross' => 1.15,
            'rebalance_sessions' => 3,
            'rebalance_schedule' => 'fixed',
            'benchmark_sma_period' => 200,
            'asset_sma_period' => 0,
            'dollar_volume_period' => 20,
            'min_dollar_volume' => 5_000_000.0,
            'minimum_history_sessions' => 253,
            'require_positive_score' => true,
            'position_trailing_close_pct' => 0.0,
            'position_dynamic_trailing_daily_vol_multiple' => 0.0,
            'position_dynamic_trailing_min_pct' => 0.0,
            'position_dynamic_trailing_max_pct' => 0.0,
            'position_exit_cooldown_sessions' => 1,
            'drawdown_kill_pct' => 0.15,
            'drawdown_cooldown_sessions' => 40,
            'drawdown_rearm_after_cooldown' => true,
            'cost_bps' => 20.0,
            'margin_rate_annual' => 0.0625,
        ];
        $config = array_replace($defaults, $config);
        $config['benchmark'] = strtoupper(trim((string) $config['benchmark']));
        if ($providedSignalMarketFilter !== null && !is_array($providedSignalMarketFilter)) {
            throw new \InvalidArgumentException('Signal market filter must be an array.');
        }
        $config['signal_market_filter'] = array_replace(
            [
                'symbol' => $config['benchmark'],
                'sma_period' => (int) $config['benchmark_sma_period'],
            ],
            $providedSignalMarketFilter ?? [],
        );
        $config['signal_market_filter']['symbol'] = strtoupper(trim(
            (string) $config['signal_market_filter']['symbol'],
        ));
        $config['market_context'] = array_replace(
            $defaults['market_context'],
            (array) $config['market_context'],
        );
        $config['market_context']['symbol'] = strtoupper(trim((string) $config['market_context']['symbol']));
        $config['universe'] = array_values(array_unique(array_map(
            static fn (string $symbol): string => strtoupper(trim($symbol)),
            array_filter((array) ($config['universe'] ?? []), 'is_string'),
        )));
        if ($config['benchmark'] === '' || $config['universe'] === []) {
            throw new \InvalidArgumentException('Tactical rotation benchmark and universe are required.');
        }
        $dynamicTrailingMultiple = (float) $config['position_dynamic_trailing_daily_vol_multiple'];
        $dynamicTrailingMin = (float) $config['position_dynamic_trailing_min_pct'];
        $dynamicTrailingMax = (float) $config['position_dynamic_trailing_max_pct'];
        if ((int) $config['rebalance_sessions'] < 1
            || (string) $config['rebalance_schedule'] !== 'fixed'
            || (int) $config['benchmark_sma_period'] < 2
            || (string) $config['signal_market_filter']['symbol'] === ''
            || (int) $config['signal_market_filter']['sma_period'] < 2
            || ((int) $config['asset_sma_period'] !== 0 && (int) $config['asset_sma_period'] < 2)
            || (string) $config['market_context']['symbol'] === ''
            || (int) $config['market_context']['sma_period'] < 2
            || (float) $config['market_context']['risk_off_multiplier'] < 0.0
            || (float) $config['market_context']['risk_off_multiplier'] > 1.0
            || (int) $config['volatility_period'] < 2
            || (int) $config['minimum_history_sessions'] < 2
            || (float) $config['volatility_target'] <= 0.0
            || (float) $config['benchmark_volatility_target'] < 0.0
            || (float) $config['max_gross'] <= 0.0
            || (float) $config['max_gross'] > 1.25
            || (float) $config['drawdown_kill_pct'] <= 0.0
            || (float) $config['drawdown_kill_pct'] >= 1.0
            || (float) $config['position_trailing_close_pct'] < 0.0
            || (float) $config['position_trailing_close_pct'] >= 1.0
            || !is_finite($dynamicTrailingMultiple)
            || $dynamicTrailingMultiple < 0.0
            || !is_finite($dynamicTrailingMin)
            || $dynamicTrailingMin < 0.0
            || $dynamicTrailingMin >= 1.0
            || !is_finite($dynamicTrailingMax)
            || $dynamicTrailingMax < 0.0
            || $dynamicTrailingMax >= 1.0
            || $dynamicTrailingMin > $dynamicTrailingMax
            || ($dynamicTrailingMultiple > 0.0 && $dynamicTrailingMax <= 0.0)
            || (int) $config['position_exit_cooldown_sessions'] < 1
            || (int) $config['drawdown_cooldown_sessions'] < 1
            || !is_bool($config['drawdown_rearm_after_cooldown'])
            || (float) $config['cost_bps'] < 0.0
            || (float) $config['margin_rate_annual'] < 0.0) {
            throw new \InvalidArgumentException('Invalid tactical rotation risk/execution configuration.');
        }
        foreach ((array) $config['factor_weights'] as $period => $weight) {
            if ((int) $period < 1 || !is_numeric($weight)) {
                throw new \InvalidArgumentException('Factor weights must map positive periods to numeric weights.');
            }
        }

        return $config;
    }
}
