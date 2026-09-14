<?php

declare(strict_types=1);

namespace FulltimeTrading\Research;

use FulltimeTrading\Domain\Bar;
use FulltimeTrading\Indicators\IndicatorCalculator;

/**
 * Causal close-to-next-open tactical rotation replay.
 *
 * A completed close creates a target for the next session. Portfolio weights,
 * turnover, calendar-day margin interest and an OHLC drawdown/gross bound are
 * then marked chronologically. The class never submits broker orders.
 */
// Research fork of upstream ebbb2eb9 (full SHA is recorded in experiment protocols).
// Defaults must remain numerically identical to the operational backtester.
/** Versioned execution-parity fork; the frozen Opportunity engine is not modified. */
final class PaperExecutionRotationBacktester
{
    /** @var array<string, mixed> */
    private array $config;

    /** @param array<string, mixed> $config */
    public function __construct(array $config, private readonly IndicatorCalculator $indicators = new IndicatorCalculator())
    {
        $this->config = $this->validateConfig($config);
        if (!is_bool($this->config['whole_share_execution'] ?? false)
            || !is_array($this->config['nominal_prices'] ?? [])) {
            throw new \InvalidArgumentException('Invalid nominal execution contract.');
        }
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        return $this->config;
    }

    /** Prepare causal close features once; selection uses the actual sleeve incumbent, not replay holdings. */
    public function paperSignalContexts(array $bars, string $asOf): \Closure
    {
        \FulltimeTrading\Paper\CandidateOrder::date($asOf);
        foreach ($bars as $symbol => $series) {
            $bars[$symbol] = array_values(array_filter($series, static fn (Bar $bar): bool =>
                $bar->time->setTimezone(new \DateTimeZone('America/New_York'))->format('Y-m-d') <= $asOf));
        }
        [$features, $indexed] = $this->buildFeatures($bars);
        $dates = array_keys($indexed[$this->config['benchmark']]); sort($dates, SORT_STRING);
        $previous = []; $last = null;
        foreach ($dates as $date) { $previous[$date] = $last; $last = $date; }
        if ($last !== $asOf) { throw new \RuntimeException('Paper context is missing the completed benchmark session.'); }
        return function (string $date, ?string $incumbent) use ($asOf, $features, $indexed, $previous): array {
            if ($date > $asOf || !array_key_exists($date, $previous)) { throw new \RuntimeException('Paper signal date is outside its causal snapshot.'); }
            if ($this->config['external_daily_scale'] !== null && !array_key_exists($date, $this->config['external_daily_scale'])) {
                throw new \RuntimeException('Missing paper external scale.');
            }
            $desired = $this->desiredWeights($date, $features, $incumbent);
            $closes = $previousCloses = $volatility = [];
            foreach ($indexed as $symbol => $series) {
                if (isset($series[$date])) { $closes[$symbol] = $series[$date]->close; }
                if ($previous[$date] !== null && isset($series[$previous[$date]])) { $previousCloses[$symbol] = $series[$previous[$date]]->close; }
                $volatility[$symbol] = $features[$symbol][$date]['volatility'] ?? null;
            }
            return ['date' => $date, 'previous_session' => $previous[$date], 'desired' => $desired,
                'reentry_conditions_met' => $this->canReleaseCooldown($date, $features, $desired, PHP_INT_MAX),
                'closes' => $closes, 'previous_closes' => $previousCloses, 'volatility' => $volatility];
        };
    }

    /**
     * @param array<string, list<Bar>> $barsBySymbol
     * @return array{curve:list<array<string,mixed>>,next_target:array<string,mixed>,features_as_of:string}
     */
    public function run(array $barsBySymbol, string $tradeStart, string $tradeEndInclusive, float $initialEquity = 30000.0): array
    {
        $steps = $this->steps($barsBySymbol, $tradeStart, $tradeEndInclusive, $initialEquity);
        foreach ($steps as $_) { }
        return $steps->getReturn();
    }

    /** Each completed close accepts optional portfolio risk feedback for the next session only. */
    public function steps(array $barsBySymbol, string $tradeStart, string $tradeEndInclusive, float $initialEquity = 30000.0): \Generator
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
        if ($this->config['external_daily_scale'] !== null) {
            foreach ($dates as $date) {
                if (!array_key_exists($date, $this->config['external_daily_scale'])) {
                    throw new \RuntimeException('Missing research external scale for ' . $date);
                }
            }
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
        $lastCircuitIndex = null;
        $reentryRampUntil = -1;
        $reentryEvents = [];
        $portfolioFeedback = ['force_cash' => false, 'scale' => 1.0];
        $standingStop = null;
        $standingStopEvents = [];

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
                $portfolioFeedback = self::portfolioFeedback(yield $curve[array_key_last($curve)]);
                continue;
            }

            if ($cooldownLeft > 0) {
                if ($cooldownLeft > 1 && $drawdownRearmPending && $lastCircuitIndex !== null && $weights === []
                    && $this->canReleaseCooldown($previousDate, $features, $desired, $dayIndex - $lastCircuitIndex)) {
                    $reentryEvents[] = [
                        'signal_date' => $previousDate, 'release_session' => $date,
                        'circuit_session' => $dates[$lastCircuitIndex],
                        'elapsed_sessions' => $dayIndex - $lastCircuitIndex,
                        'saved_cooldown_ticks' => $cooldownLeft - 1,
                        'initial_scale' => $this->config['circuit_reentry_initial_scale'],
                    ];
                    $cooldownLeft = 1;
                    $reentryRampUntil = $dayIndex + (int) $this->config['circuit_reentry_ramp_sessions'] - 1;
                }
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

            $gapStop = null;
            $stoppedToday = null;
            if ($standingStop !== null && $previousCloseSymbol !== null
                && $barsByDate[$previousCloseSymbol][$date]->open <= $standingStop) {
                $gapStop = ['date' => $date, 'symbol' => $previousCloseSymbol, 'stop' => $standingStop,
                    'fill' => $barsByDate[$previousCloseSymbol][$date]->open, 'kind' => 'gap_open'];
                $stoppedToday = $gapStop;
                $standingStopEvents[] = $gapStop;
            }

            $regularRebalance = $dayIndex >= 1
                && ($dayIndex - 1) % (int) $this->config['rebalance_sessions'] === (int) $this->config['rebalance_phase'];
            $rebalance = $riskExitPending || $regularRebalance || $gapStop !== null
                || ($portfolioFeedback['force_cash'] && $weights !== []);
            $executionDesired = ($riskExitPending || $cooldownLeft > 0 || $portfolioFeedback['force_cash'] || $gapStop !== null) ? [] : $desired;
            if ($portfolioFeedback['scale'] !== 1.0) {
                $executionDesired = array_map(static fn (float $w): float => $w * $portfolioFeedback['scale'], $executionDesired);
            }
            if ($dayIndex <= $reentryRampUntil) {
                $executionDesired = array_map(fn (float $weight): float => $weight * (float) $this->config['circuit_reentry_initial_scale'], $executionDesired);
            }
            $turnover = 0.0;
            $wholeShareQuantities = null;
            if ($rebalance) {
                $executionDesired = AlgorithmSignalPolicy::executionTarget($weights, $executionDesired, (float) $this->config['max_gross'], $this->config['algorithm_policy']);
                $previousSymbol = array_key_first($weights);
                if (($this->config['whole_share_execution'] ?? false) === true) {
                    $decisionPrices = $openPrices = [];
                    foreach (array_unique(array_merge(array_keys($previousCloseWeights), array_keys($executionDesired))) as $symbol) {
                        $decisionPrices[$symbol] = $this->config['nominal_prices'][$symbol][$previousDate]['close'] ?? null;
                        $openPrices[$symbol] = $this->config['nominal_prices'][$symbol][$date]['open'] ?? null;
                        if (!is_numeric($decisionPrices[$symbol]) || !is_numeric($openPrices[$symbol])
                            || $decisionPrices[$symbol] <= 0 || $openPrices[$symbol] <= 0) {
                            throw new \RuntimeException('Missing nominal execution prices: ' . $symbol . '/' . $date);
                        }
                    }
                    $wholeShareQuantities = \FulltimeTrading\Trading\WholeShareSizing::target(
                        $startEquity,
                        array_map(static fn ($w): float => $w * $startEquity, $previousCloseWeights),
                        array_key_first($executionDesired), (float) array_sum($executionDesired),
                        $decisionPrices, (float) $this->config['cost_bps'],
                    );
                    $sizing = \FulltimeTrading\Trading\WholeShareSizing::execute(
                        $preExecutionEquity, $weights, $wholeShareQuantities, $openPrices, (float) $this->config['cost_bps'],
                    );
                    $executionDesired = $sizing['weights'];
                    $costFraction = $sizing['cost_fraction'];
                    $turnover = $sizing['turnover'];
                    $costFractionBySymbol = $sizing['costs'];
                } else {
                    [$costFraction, $turnover, $costFractionBySymbol] = $this->executionCost($weights, $executionDesired);
                }
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
                    $standingStop = $newSymbol === null || $this->config['standing_stop_pct'] <= 0.0
                        ? null : $barsByDate[$newSymbol][$date]->open * (1.0 - $this->config['standing_stop_pct']);
                }
                $riskExitPending = false;
            }

            $weightsAtOpen = $weights;
            $holdingAtOpen = array_key_first($weightsAtOpen);
            $closeReturn = 0.0;
            $lowReturn = 0.0;
            $highReturn = 0.0;
            $intradayStop = null;
            foreach ($weightsAtOpen as $symbol => $weight) {
                $bar = $barsByDate[$symbol][$date] ?? null;
                if (!$bar instanceof Bar || $bar->open <= 0.0) {
                    throw new \RuntimeException(sprintf('Missing held-symbol execution bar for %s on %s.', $symbol, $date));
                }
                $executionClose = $bar->close;
                $executionLow = $bar->low;
                $stopCost = 0.0;
                if ($standingStop !== null && $bar->low <= $standingStop) {
                    $executionClose = $this->config['standing_stop_fill'] === 'daily_low' ? $bar->low : min($bar->open, $standingStop);
                    $executionClose *= 1.0 - $this->config['standing_stop_slippage_bps'] / 10000.0;
                    $executionLow = min($bar->open, $executionClose);
                    $stopNotional = $equity * $weight * $executionClose / $bar->open;
                    $stopCost = $stopNotional * $this->config['cost_bps'] / 10000.0 / $equity;
                    $turnover += $stopNotional / $preExecutionEquity;
                    $intradayStop = ['date' => $date, 'symbol' => $symbol, 'stop' => $standingStop,
                        'fill' => $executionClose, 'kind' => $this->config['standing_stop_fill'], 'notional' => $stopNotional];
                    $stoppedToday = $intradayStop;
                    $standingStopEvents[] = $intradayStop;
                }
                $closeReturn += $weight * ($executionClose / $bar->open - 1.0) - $stopCost;
                $lowReturn += $weight * ($executionLow / $bar->open - 1.0) - $stopCost;
                $highReturn += $weight * ($bar->high / $bar->open - 1.0);
                $newStagePnl[$symbol] = (float) ($newStagePnl[$symbol] ?? 0.0)
                    + ($intradayStop !== null
                        ? $equity * ($weight * ($executionClose / $bar->open - 1.0) - $stopCost)
                        : $equity * $weight * ($bar->close / $bar->open - 1.0));
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
                if ($intradayStop !== null) { continue; }
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
                if ($this->config['standing_stop_pct'] > 0.0 && $this->config['standing_stop_trailing']) {
                    $standingStop = max((float) $standingStop, $currentClose * (1.0 - $this->config['standing_stop_pct']));
                }
            } else {
                $positionPeakClose = null;
                $standingStop = null;
            }
            $drawdownPeak = max($drawdownPeak, $equity);
            if ($equity >= $drawdownPeak * (1.0 - 1.0e-12)) {
                $drawdownLatched = false;
            }
            $riskSignal = null;
            if ($stoppedToday !== null) { $riskSignal = 'standing_stop'; }
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
                    $lastCircuitIndex = $dayIndex;
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
                ...(($this->config['whole_share_execution'] ?? false) === true ? [
                    'fixed_quantity_target' => $wholeShareQuantities,
                    'quantity_reference_session' => $rebalance ? $previousDate : null,
                ] : []),
                ...($this->config['standing_stop_pct'] > 0.0 ? ['standing_stop_event' => $stoppedToday, 'standing_stop_next' => $standingStop] : []),
            ];

            $previousDate = $date;
            $desired = $this->desiredWeights($date, $features, array_key_first($weights));
            $desiredSignalDate = $date;
            $portfolioFeedback = self::portfolioFeedback(yield $curve[array_key_last($curve)]);
        }

        $asOf = (string) end($dates);

        $nextDayIndex = count($dates);
        $regularRebalanceDue = ($nextDayIndex - 1) % (int) $this->config['rebalance_sessions'] === (int) $this->config['rebalance_phase'];
        $rebalanceDue = $riskExitPending || $regularRebalanceDue || ($portfolioFeedback['force_cash'] && $weights !== []);
        $nextCooldownLeft = max(0, $cooldownLeft - 1);
        $earlyReleaseDue = $cooldownLeft > 1 && $drawdownRearmPending && $lastCircuitIndex !== null && $weights === []
            && $this->canReleaseCooldown($asOf, $features, $desired, $nextDayIndex - $lastCircuitIndex);
        if ($earlyReleaseDue) {
            $nextCooldownLeft = 0;
        }
        $currentSymbol = array_key_first($weights);
        $currentGross = array_sum(array_map('abs', $weights));
        $rankedSymbol = array_key_first($desired);
        $rankedGross = $desired === [] ? 0.0 : (float) reset($desired);
        $nextExecutionDesired = ($riskExitPending || $nextCooldownLeft > 0 || $portfolioFeedback['force_cash']) ? [] : $desired;
        if ($portfolioFeedback['scale'] !== 1.0) {
            $nextExecutionDesired = array_map(static fn (float $w): float => $w * $portfolioFeedback['scale'], $nextExecutionDesired);
        }
        if ($nextDayIndex <= $reentryRampUntil || ($earlyReleaseDue && (int) $this->config['circuit_reentry_ramp_sessions'] > 0)) {
            $nextExecutionDesired = array_map(fn (float $weight): float => $weight * (float) $this->config['circuit_reentry_initial_scale'], $nextExecutionDesired);
        }
        $nextExecutionDesired = AlgorithmSignalPolicy::executionTarget($weights, $nextExecutionDesired, (float) $this->config['max_gross'], $this->config['algorithm_policy']);
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
            'reentry_events' => $reentryEvents,
            ...($this->config['standing_stop_pct'] > 0.0 ? ['standing_stop_events' => $standingStopEvents] : []),
        ];
    }

    private static function portfolioFeedback(mixed $value): array
    {
        if ($value === null) { return ['force_cash' => false, 'scale' => 1.0]; }
        if (!is_array($value) || !is_bool($value['force_cash'] ?? null)
            || !is_numeric($value['scale'] ?? null) || !is_finite((float) $value['scale']) || $value['scale'] < 0 || $value['scale'] > 1) {
            throw new \InvalidArgumentException('Portfolio feedback must be fail-closed, finite and unlevered.');
        }
        return $value;
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
        $periods = array_merge($periods, [1, 3, 5, 10]);
        $periods = array_values(array_unique($periods));

        foreach ($required as $symbol) {
            $bars = $barsBySymbol[$symbol] ?? [];
            if ($bars === []) {
                throw new \RuntimeException('Missing tactical rotation bars for ' . $symbol . '.');
            }
            usort($bars, static fn (Bar $a, Bar $b): int => $a->time <=> $b->time);
            $algorithmFeatures = AlgorithmSignalPolicy::features($bars, $this->config['algorithm_policy'], (array) $this->config['factor_weights']);
            $closes = array_map(static fn (Bar $bar): float => $bar->close, $bars);
            $returns = array_fill(0, count($bars), null);
            foreach ($bars as $index => $bar) {
                if ($index > 0 && $bars[$index - 1]->close > 0.0) {
                    $returns[$index] = $bar->close / $bars[$index - 1]->close - 1.0;
                }
            }
            $volatility = $this->rollingVolatility($returns, (int) $this->config['volatility_period']);
            $fastVolatility = $this->rollingVolatility($returns, 5);
            $dollarVolume = $this->rollingDollarVolume($bars, (int) $this->config['dollar_volume_period']);
            $sma = $this->indicators->sma($closes, (int) $this->config['benchmark_sma_period']);
            $sma20 = $this->indicators->sma($closes, 20);
            $signalMarketSma = $this->indicators->sma($closes, (int) $signalMarketFilter['sma_period']);
            $assetSma = (int) $this->config['asset_sma_period'] >= 2
                ? $this->indicators->sma($closes, (int) $this->config['asset_sma_period'])
                : array_fill(0, count($closes), null);
            $contextSma = $this->indicators->sma($closes, (int) $marketContext['sma_period']);
            $aboveSmaStreak = 0;
            foreach ($bars as $index => $bar) {
                $aboveSmaStreak = is_float($sma20[$index]) && $bar->close > $sma20[$index] ? $aboveSmaStreak + 1 : 0;
                $date = $bar->time->setTimezone($timezone)->format('Y-m-d');
                $barsByDate[$symbol][$date] = $bar;
                $row = [
                    'close' => $bar->close,
                    'history_sessions' => $index + 1,
                    'volatility' => $volatility[$index],
                    'fast_volatility' => $fastVolatility[$index],
                    'dollar_volume' => $dollarVolume[$index],
                    'benchmark_sma' => $sma[$index],
                    'sma20' => $sma20[$index],
                    'above_sma20_streak' => $aboveSmaStreak,
                    'signal_market_sma' => $signalMarketSma[$index],
                    'asset_sma' => $assetSma[$index],
                    'context_sma' => $contextSma[$index],
                ];
                foreach ([5, 10, 20] as $window) {
                    $row['rebound_' . $window] = $index >= $window - 1
                        ? $bar->close / min(array_slice($closes, $index - $window + 1, $window)) - 1.0
                        : null;
                }
                foreach ($periods as $period) {
                    $row['return_' . $period] = $index >= $period && $bars[$index - $period]->close > 0.0
                        ? $bar->close / $bars[$index - $period]->close - 1.0
                        : null;
                }
                $features[$symbol][$date] = array_merge($row, $algorithmFeatures[$index] ?? []);
            }
        }

        return [$features, $barsByDate];
    }

    /**
     * @param array<string,array<string,array<string,float|null>>> $features
     * @return array<string,float>
     */
    private function desiredWeights(string $date, array $features, ?string $incumbent = null): array
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
            if ((float) $this->config['maximum_asset_sma_extension'] > 0.0
                && is_float($row['asset_sma'] ?? null)
                && $row['close'] > $row['asset_sma'] * (1.0 + (float) $this->config['maximum_asset_sma_extension'])) {
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
            $score = AlgorithmSignalPolicy::score($score, $row, $benchmarkRow, $this->config['algorithm_policy']);
            if ($score === null || ((bool) $this->config['require_positive_score'] && $score <= 0.0)) {
                continue;
            }
            $opportunity = $this->config['opportunity_features'][$symbol][$date] ?? [];
            $score = OpportunityPolicy::score($score, $opportunity, $this->config['opportunity_policy']);
            if ($score === null || ((bool) $this->config['require_positive_score'] && $score <= 0.0)) {
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
        $ranked = array_keys($scores);
        $rankIndex = (int) $this->config['rank_index'];
        if (!isset($ranked[$rankIndex])) {
            return [];
        }
        $symbol = $ranked[$rankIndex];
        $buffer = (float) $this->config['rank_hysteresis'];
        if ($rankIndex === 0 && $buffer > 0.0 && $incumbent !== null && isset($scores[$incumbent])
            && $scores[$incumbent]['score'] >= $scores[$symbol]['score'] * (1.0 - $buffer)) {
            $symbol = $incumbent;
        }
        $symbol = OpportunityPolicy::choose($symbol, $incumbent, $scores,
            $this->config['opportunity_features'] ?? [], $date, $this->config['opportunity_policy'],
            (float) $this->config['cost_bps']);
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
        $shock = (float) $this->config['volatility_shock_ratio'];
        $fastVolatility = $benchmarkRow['fast_volatility'] ?? null;
        if ($shock > 0.0 && is_float($fastVolatility) && $fastVolatility > $benchmarkVolatility * $shock) {
            $gross *= (float) $this->config['volatility_shock_multiplier'];
        }
        $marketContext = (array) $this->config['market_context'];
        $contextRow = $features[(string) $marketContext['symbol']][$date] ?? null;
        if (!is_array($contextRow)
            || !is_float($contextRow['context_sma'] ?? null)
            || (float) $contextRow['close'] < (float) $contextRow['context_sma']) {
            $gross *= (float) $marketContext['risk_off_multiplier'];
        }
        $gross *= AlgorithmSignalPolicy::riskScale($features[$symbol][$date], $features, (array) $this->config['universe'], $date, (int) $this->config['minimum_history_sessions'], $this->config['algorithm_policy']);
        $gross *= (float) ($this->config['external_daily_scale'][$date] ?? 1.0);
        $gross *= OpportunityPolicy::scale($this->config['opportunity_features'][$symbol][$date] ?? [],
            $this->config['opportunity_policy']);
        $gross = min((float) $this->config['max_gross'], max(0.0, $gross));

        return $gross > 0.0 ? [$symbol => $gross] : [];
    }

    private function canReleaseCooldown(string $date, array $features, array $desired, int $elapsed): bool
    {
        $minimum = (int) $this->config['circuit_reentry_min_sessions'];
        if ($minimum === 0 || $elapsed <= $minimum || $desired === []) {
            return false;
        }
        $context = $features[(string) $this->config['market_context']['symbol']][$date] ?? null;
        $benchmark = $features[(string) $this->config['benchmark']][$date] ?? null;
        if (!is_array($context) || !is_array($benchmark)) {
            return false;
        }
        $mode = (string) $this->config['circuit_reentry_mode'];
        if ($mode === 'trend' && (!is_float($context['context_sma'] ?? null)
            || $context['close'] <= $context['context_sma'] || ($context['return_5'] ?? 0.0) <= 0.0)) {
            return false;
        }
        foreach ([1, 3, 5] as $period) {
            $threshold = (float) $this->config['circuit_reentry_return_' . $period];
            if ($threshold > 0.0 && (($context['return_' . $period] ?? -1.0) < $threshold
                || ($benchmark['return_' . $period] ?? -1.0) < $threshold)) {
                return false;
            }
        }
        if ((bool) $this->config['circuit_reentry_require_sma20']
            && (!is_float($context['sma20'] ?? null) || $context['close'] <= $context['sma20']
                || !is_float($benchmark['sma20'] ?? null) || $benchmark['close'] <= $benchmark['sma20'])) {
            return false;
        }
        $streak = (int) $this->config['circuit_reentry_confirmation_sessions'];
        if ($streak > 0 && (($context['above_sma20_streak'] ?? 0) < $streak
            || ($benchmark['above_sma20_streak'] ?? 0) < $streak)) {
            return false;
        }
        $rebound = (float) $this->config['circuit_reentry_trough_rebound'];
        $window = (int) $this->config['circuit_reentry_trough_window'];
        if ($rebound > 0.0 && (($context['rebound_' . $window] ?? -1.0) < $rebound
            || ($benchmark['rebound_' . $window] ?? -1.0) < $rebound)) {
            return false;
        }
        if ((bool) $this->config['circuit_reentry_leader_confirmation']) {
            $leader = $features[array_key_first($desired)][$date] ?? [];
            if (($leader['return_5'] ?? -1.0) <= 0.0 || ($leader['above_sma20_streak'] ?? 0) < max(1, $streak)) {
                return false;
            }
        }
        $minimumBreadth = (float) $this->config['circuit_reentry_minimum_breadth'];
        if ($minimumBreadth > 0.0) {
            $available = $above = 0;
            foreach ($this->config['universe'] as $symbol) {
                $row = $features[$symbol][$date] ?? [];
                if (is_float($row['sma20'] ?? null) && ($row['history_sessions'] ?? 0) >= (int) $this->config['minimum_history_sessions']) {
                    $available++;
                    $above += (int) ($row['close'] > $row['sma20']);
                }
            }
            if ($available < 5 || $above / $available < $minimumBreadth) {
                return false;
            }
        }
        if ((bool) $this->config['circuit_reentry_calm_required']) {
            return is_float($context['fast_volatility'] ?? null)
                && $context['fast_volatility'] <= $context['volatility'];
        }
        return true;
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
            'rebalance_phase' => 0,
            'rank_index' => 0,
            'rank_hysteresis' => 0.0,
            'algorithm_policy' => [],
            'external_daily_scale' => null,
            'standing_stop_pct' => 0.0,
            'standing_stop_trailing' => true,
            'standing_stop_fill' => 'stop_level',
            'standing_stop_slippage_bps' => 0.0,
            'maximum_asset_sma_extension' => 0.0,
            'volatility_shock_ratio' => 0.0,
            'volatility_shock_multiplier' => 0.5,
            'circuit_reentry_min_sessions' => 0,
            'circuit_reentry_calm_required' => false,
            'circuit_reentry_mode' => 'trend',
            'circuit_reentry_return_1' => 0.0,
            'circuit_reentry_return_3' => 0.0,
            'circuit_reentry_return_5' => 0.0,
            'circuit_reentry_require_sma20' => false,
            'circuit_reentry_minimum_breadth' => 0.0,
            'circuit_reentry_initial_scale' => 1.0,
            'circuit_reentry_ramp_sessions' => 0,
            'circuit_reentry_confirmation_sessions' => 0,
            'circuit_reentry_trough_rebound' => 0.0,
            'circuit_reentry_trough_window' => 10,
            'circuit_reentry_leader_confirmation' => false,
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
        if (!is_numeric($config['standing_stop_pct']) || !is_finite((float) $config['standing_stop_pct'])
            || $config['standing_stop_pct'] < 0 || $config['standing_stop_pct'] >= 1
            || !is_bool($config['standing_stop_trailing']) || !in_array($config['standing_stop_fill'], ['stop_level', 'daily_low'], true)
            || !is_numeric($config['standing_stop_slippage_bps']) || !is_finite((float) $config['standing_stop_slippage_bps'])
            || $config['standing_stop_slippage_bps'] < 0 || $config['standing_stop_slippage_bps'] > 1000) {
            throw new \InvalidArgumentException('Invalid research standing-stop model.');
        }
        if ($config['external_daily_scale'] !== null) {
            if (!is_array($config['external_daily_scale'])) { throw new \InvalidArgumentException('External scale must be a dated map.'); }
            foreach ($config['external_daily_scale'] as $date => $scale) {
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)
                    || !is_numeric($scale) || !is_finite((float) $scale) || $scale < 0 || $scale > 2) {
                    throw new \InvalidArgumentException('Research external scale must be finite and between zero and two; max_gross still applies.');
                }
            }
        }
        $config['opportunity_policy'] = OpportunityPolicy::validate($config['opportunity_policy'] ?? []);
        $config['algorithm_policy'] = AlgorithmSignalPolicy::validate($config['algorithm_policy']);
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
        foreach (['rank_hysteresis', 'maximum_asset_sma_extension', 'volatility_shock_ratio', 'volatility_shock_multiplier',
            'circuit_reentry_return_1', 'circuit_reentry_return_3', 'circuit_reentry_return_5',
            'circuit_reentry_minimum_breadth', 'circuit_reentry_initial_scale', 'circuit_reentry_trough_rebound'] as $key) {
            if (!is_numeric($config[$key]) || !is_finite((float) $config[$key]) || (float) $config[$key] < 0.0) {
                throw new \InvalidArgumentException('Invalid adaptive numeric value: ' . $key);
            }
        }
        if ((int) $config['rebalance_phase'] < 0 || (int) $config['rebalance_phase'] >= (int) $config['rebalance_sessions']
            || !is_bool($config['circuit_reentry_require_sma20']) || !is_bool($config['circuit_reentry_leader_confirmation'])
            || !in_array($config['circuit_reentry_trough_window'], [5, 10, 20], true)
            || (int) $config['circuit_reentry_confirmation_sessions'] < 0
            || (int) $config['rank_index'] < 0 || (int) $config['rank_index'] > 5
            || (float) $config['rank_hysteresis'] < 0.0 || (float) $config['rank_hysteresis'] >= 1.0
            || (float) $config['maximum_asset_sma_extension'] < 0.0
            || ((float) $config['maximum_asset_sma_extension'] > 0.0 && (int) $config['asset_sma_period'] < 2)
            || (float) $config['volatility_shock_ratio'] < 0.0
            || (float) $config['volatility_shock_multiplier'] < 0.0 || (float) $config['volatility_shock_multiplier'] > 1.0
            || (int) $config['circuit_reentry_min_sessions'] < 0
            || !is_bool($config['circuit_reentry_calm_required'])
            || !in_array($config['circuit_reentry_mode'], ['trend', 'rebound'], true)
            || (float) $config['circuit_reentry_minimum_breadth'] < 0.0 || (float) $config['circuit_reentry_minimum_breadth'] > 1.0
            || (float) $config['circuit_reentry_initial_scale'] <= 0.0 || (float) $config['circuit_reentry_initial_scale'] > 1.0
            || (int) $config['circuit_reentry_ramp_sessions'] < 0) {
            throw new \InvalidArgumentException('Invalid adaptive research configuration.');
        }
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
