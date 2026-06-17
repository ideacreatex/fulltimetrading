<?php

declare(strict_types=1);

namespace FulltimeTrading\Backtest;

use FulltimeTrading\Domain\Bar;
use FulltimeTrading\Domain\Signal;
use FulltimeTrading\Domain\Trade;
use FulltimeTrading\Indicators\IndicatorCalculator;
use FulltimeTrading\Strategy\MarketRegime;
use FulltimeTrading\Strategy\MarketRegimeAnalyzer;
use FulltimeTrading\Strategy\PoosScanner;

final class PoosBacktester
{
    public function __construct(
        private readonly IndicatorCalculator $indicators,
        private readonly MarketRegimeAnalyzer $marketRegimeAnalyzer,
        private readonly PoosScanner $scanner,
        /** @var array<string, mixed> */
        private readonly array $strategyConfig,
        /** @var array<string, mixed> */
        private readonly array $riskConfig,
    ) {
    }

    /**
     * @param array<string, list<Bar>> $barsBySymbol
     * @param array<string, list<Bar>> $marketBars
     */
    public function run(array $barsBySymbol, array $marketBars): BacktestResult
    {
        $startingCash = (float) ($this->riskConfig['initial_cash'] ?? 100000.0);
        $cash = $startingCash;
        $riskPct = (float) ($this->riskConfig['risk_per_trade_pct'] ?? 0.005);
        $maxPositionPct = (float) ($this->riskConfig['max_position_pct'] ?? 0.10);
        $validBars = (int) ($this->strategyConfig['order_valid_bars'] ?? 10);
        $partialPct = (float) ($this->strategyConfig['partial_take_profit_pct'] ?? 0.5);
        $maxOpenPositions = (int) ($this->riskConfig['max_open_positions'] ?? 0);

        $marketRegimes = $this->marketRegimeAnalyzer->analyze($marketBars);
        $allSignals = [];
        $allTrades = [];
        $signalsByDate = [];
        $contextBySymbol = [];
        $calendar = [];

        foreach ($barsBySymbol as $symbol => $bars) {
            if (count($bars) < 220) {
                continue;
            }

            $signals = $this->scanner->scan($symbol, $bars, $marketRegimes);
            $allSignals = array_merge($allSignals, $signals);
            foreach ($signals as $signal) {
                $signalsByDate[$signal->createdAt->format('Y-m-d')][$symbol][] = $signal;
            }

            $indicatorMap = $this->indicators->forBars(
                $bars,
                $this->strategyConfig['ema_periods'] ?? [10, 20, 21, 50, 100, 200],
                (int) ($this->strategyConfig['atr_period'] ?? 14),
                (int) ($this->strategyConfig['volume_avg_period'] ?? 50),
            );

            $contextBySymbol[$symbol] = [
                'bars' => $bars,
                'indicators' => $indicatorMap,
            ];
            foreach ($bars as $i => $bar) {
                $calendar[$bar->time->format('Y-m-d')][$symbol] = [
                    'bar' => $bar,
                    'index' => $i,
                ];
            }
        }

        $dates = array_keys($calendar);
        sort($dates);

        $pendingBySymbol = [];
        $positionsBySymbol = [];
        $lastBarsBySymbol = [];
        $equityCurve = [];
        $positionStates = [];
        $stoppedBySymbol = [];

        foreach ($dates as $date) {
            foreach ($calendar[$date] as $symbol => $day) {
                $lastBarsBySymbol[$symbol] = $day['bar'];
            }

            foreach (array_keys($positionsBySymbol) as $positionKey) {
                $symbol = (string) ($positionsBySymbol[$positionKey]['symbol'] ?? $positionKey);
                if (!isset($calendar[$date][$symbol], $contextBySymbol[$symbol])) {
                    continue;
                }

                $bar = $calendar[$date][$symbol]['bar'];
                $index = $calendar[$date][$symbol]['index'];
                $indicatorMap = $contextBySymbol[$symbol]['indicators'];
                $trade = $this->updatePosition($positionsBySymbol[$positionKey], $bar, $indicatorMap, $index, $partialPct);
                if ($trade !== null) {
                    $this->recordStoppedPosition($stoppedBySymbol, $positionsBySymbol[$positionKey], $trade);
                    $cash += $trade->pnl;
                    $allTrades[] = $trade;
                    unset($positionsBySymbol[$positionKey]);
                    continue;
                }

                if (($positionsBySymbol[$positionKey]['break_even_add_on_requested'] ?? false) === true) {
                    $this->openBreakEvenAddOn(
                        $positionsBySymbol,
                        $positionKey,
                        $positionsBySymbol[$positionKey],
                        $bar,
                        $cash,
                        $riskPct,
                        $maxPositionPct,
                        $maxOpenPositions,
                        $marketRegimes[$date] ?? null,
                    );
                }
            }

            $fillCandidates = [];
            foreach (array_keys($pendingBySymbol) as $pendingKey) {
                /** @var Signal $pendingSignal */
                $pendingSignal = $pendingBySymbol[$pendingKey]['signal'];
                $symbol = $pendingSignal->symbol;
                if (!isset($calendar[$date][$symbol], $contextBySymbol[$symbol])) {
                    continue;
                }

                $pendingBySymbol[$pendingKey]['age']++;
                if ($pendingBySymbol[$pendingKey]['age'] > $validBars) {
                    unset($pendingBySymbol[$pendingKey]);
                    continue;
                }

                $bar = $calendar[$date][$symbol]['bar'];
                $index = $calendar[$date][$symbol]['index'];
                $indicatorMap = $contextBySymbol[$symbol]['indicators'];
                if (!$this->canOpenAfterStop($pendingSignal, $stoppedBySymbol, $date)) {
                    continue;
                }
                if ($this->canFill($pendingSignal, $bar, $indicatorMap, $index)) {
                    $fillCandidates[] = [
                        'key' => $pendingKey,
                        'symbol' => $symbol,
                        'bar' => $bar,
                    ];
                }
            }

            usort($fillCandidates, static function (array $a, array $b) use ($pendingBySymbol): int {
                /** @var Signal $signalA */
                $signalA = $pendingBySymbol[$a['key']]['signal'];
                /** @var Signal $signalB */
                $signalB = $pendingBySymbol[$b['key']]['signal'];

                return $signalB->score <=> $signalA->score;
            });

            foreach ($fillCandidates as $candidate) {
                $pendingKey = $candidate['key'];
                $symbol = $candidate['symbol'];
                if (!isset($pendingBySymbol[$pendingKey]) || isset($positionsBySymbol[$pendingKey])) {
                    continue;
                }

                /** @var Bar $bar */
                $bar = $candidate['bar'];
                if ($this->openPendingPosition(
                    $positionsBySymbol,
                    $pendingBySymbol[$pendingKey],
                    $bar,
                    $cash,
                    $riskPct,
                    $maxPositionPct,
                    $maxOpenPositions,
                    $marketRegimes[$date] ?? null,
                )) {
                    unset($pendingBySymbol[$pendingKey]);
                }
            }

            foreach ($signalsByDate[$date] ?? [] as $symbol => $signals) {
                usort($signals, static fn (Signal $a, Signal $b): int => $b->score <=> $a->score);
                foreach ($signals as $signal) {
                    $pendingKey = $this->setupKey($signal);
                    if (isset($pendingBySymbol[$pendingKey]) || isset($positionsBySymbol[$pendingKey])) {
                        continue;
                    }
                    if (!$this->canOpenLayer($signal, $positionsBySymbol)) {
                        continue;
                    }
                    if (!$this->canOpenAfterStop($signal, $stoppedBySymbol, $date)) {
                        continue;
                    }

                    $pendingBySymbol[$pendingKey] = [
                        'key' => $pendingKey,
                        'signal' => $signal,
                        'age' => 0,
                        'events' => [$date . ': planned ' . $signal->direction . ' ' . $signal->strategy . ' limit at ' . round($signal->entry, 4)],
                    ];

                    if (($this->strategyConfig['order_fill_mode'] ?? 'next_touch') !== 'same_day_touch') {
                        continue;
                    }
                    if (!isset($calendar[$date][$symbol], $contextBySymbol[$symbol])) {
                        continue;
                    }

                    $bar = $calendar[$date][$symbol]['bar'];
                    $index = $calendar[$date][$symbol]['index'];
                    $indicatorMap = $contextBySymbol[$symbol]['indicators'];
                    if (!$this->canFill($signal, $bar, $indicatorMap, $index)) {
                        continue;
                    }
                    if ($this->openPendingPosition(
                        $positionsBySymbol,
                        $pendingBySymbol[$pendingKey],
                        $bar,
                        $cash,
                        $riskPct,
                        $maxPositionPct,
                        $maxOpenPositions,
                        $marketRegimes[$date] ?? null,
                    )) {
                        unset($pendingBySymbol[$pendingKey]);
                    }
                }
            }

            foreach ($this->positionStateRows($date, $positionsBySymbol, $lastBarsBySymbol) as $positionState) {
                $positionStates[] = $positionState;
            }

            $equityCurve[] = [
                'date' => $date,
                'equity' => $this->markedEquity($cash, $positionsBySymbol, $lastBarsBySymbol),
            ];
        }

        usort($allSignals, static fn (Signal $a, Signal $b): int => $a->createdAt <=> $b->createdAt);
        usort($allTrades, static fn (Trade $a, Trade $b): int => $a->entryTime <=> $b->entryTime);
        $endingEquity = $equityCurve !== [] ? (float) $equityCurve[array_key_last($equityCurve)]['equity'] : $cash;

        return new BacktestResult(
            $allSignals,
            $allTrades,
            $startingCash,
            $endingEquity,
            $equityCurve,
            array_keys($positionsBySymbol),
            $positionStates,
        );
    }

    /** @param array<string, mixed> $indicatorMap */
    private function canFill(Signal $signal, Bar $bar, array $indicatorMap, int $index): bool
    {
        if (in_array($signal->strategy, ['SUPPORT_REGULARITY', 'RESISTANCE_REGULARITY_SHORT'], true)) {
            return $bar->low <= $signal->entry && $bar->high >= $signal->entry;
        }

        $ema21 = $indicatorMap['ema21'][$index] ?? null;
        if ($ema21 !== null && $bar->open < $ema21 * 0.99) {
            return false;
        }

        return $bar->low <= $signal->entry && $bar->high >= $signal->entry;
    }

    /**
     * @param array<string, array<string, mixed>> $positionsByKey
     * @param array{key?:string, signal:Signal, age:int, events:list<string>} $pending
     */
    private function openPendingPosition(
        array &$positionsByKey,
        array $pending,
        Bar $bar,
        float $cash,
        float $riskPct,
        float $maxPositionPct,
        int $maxOpenPositions,
        ?MarketRegime $regime,
    ): bool {
        $signal = $pending['signal'];
        $positionKey = (string) ($pending['key'] ?? $this->setupKey($signal));
        if (isset($positionsByKey[$positionKey])) {
            return false;
        }

        $shares = $this->positionSize(
            $cash,
            $this->reservedCapital($positionsByKey),
            count($positionsByKey),
            $signal,
            $riskPct,
            $maxPositionPct,
            $maxOpenPositions,
            $regime,
            $positionsByKey,
        );
        if ($shares <= 0.0) {
            return false;
        }

        $events = $pending['events'];
        $events[] = $bar->time->format('Y-m-d') . ': filled limit at ' . round($signal->entry, 4);
        $positionsByKey[$positionKey] = [
            'key' => $positionKey,
            'symbol' => $signal->symbol,
            'signal' => $signal,
            'entry_time' => $bar->time,
            'shares' => $shares,
            'remaining_shares' => $shares,
            'stop' => $signal->stop,
            'initial_stop' => $signal->stop,
            'hard_stop_active' => $this->initialHardStopActive($signal),
            'break_even_armed' => false,
            'took_partial' => false,
            'realized_pnl' => 0.0,
            'events' => $events,
        ];

        return true;
    }

    /**
     * @param array<string, array<string, mixed>> $positionsByKey
     * @param array<string, mixed> $basePosition
     */
    private function openBreakEvenAddOn(
        array &$positionsByKey,
        string $baseKey,
        array &$basePosition,
        Bar $bar,
        float $cash,
        float $riskPct,
        float $maxPositionPct,
        int $maxOpenPositions,
        ?MarketRegime $regime,
    ): bool {
        $basePosition['break_even_add_on_requested'] = false;
        $layerConfig = $this->strategyConfig['layered_positions'] ?? [];
        $addOn = is_array($layerConfig['break_even_add_on'] ?? null) ? $layerConfig['break_even_add_on'] : [];
        if (!($addOn['enabled'] ?? false) || ($basePosition['break_even_add_on_done'] ?? false)) {
            return false;
        }

        /** @var Signal $baseSignal */
        $baseSignal = $basePosition['signal'];
        $entry = $bar->close;
        $stop = (float) ($basePosition['stop'] ?? $baseSignal->entry);
        if ($baseSignal->direction === 'long' && $entry <= $stop) {
            return false;
        }
        if ($baseSignal->direction === 'short' && $entry >= $stop) {
            return false;
        }

        $risk = abs($entry - $stop);
        if ($risk <= 0.0) {
            return false;
        }

        $targetMultiple = (float) ($this->strategyConfig['support_regularity']['target_atr_multiple'] ?? 3.0);
        $target = $baseSignal->direction === 'short'
            ? $entry - $risk * $targetMultiple
            : $entry + $risk * $targetMultiple;
        $addOnKey = $baseKey . ':BEADD:' . $bar->time->format('Y-m-d');
        if (isset($positionsByKey[$addOnKey])) {
            return false;
        }

        $metadata = $baseSignal->metadata;
        $metadata['setup_key'] = $addOnKey;
        $metadata['add_on_type'] = 'break_even_green_garden';
        $addOnSignal = new Signal(
            $baseSignal->symbol,
            $bar->time,
            $baseSignal->strategy,
            $entry,
            $stop,
            $target,
            $risk,
            max(0.0, $baseSignal->score - 0.01),
            array_merge($baseSignal->reasons, ['break_even_green_garden_add_on']),
            $baseSignal->direction,
            $metadata,
        );

        if (!$this->canOpenLayer($addOnSignal, $positionsByKey)) {
            return false;
        }

        $shares = $this->positionSize(
            $cash,
            $this->reservedCapital($positionsByKey),
            count($positionsByKey),
            $addOnSignal,
            $riskPct,
            $maxPositionPct,
            $maxOpenPositions,
            $regime,
            $positionsByKey,
        );
        $fraction = max(0.0, (float) ($addOn['position_fraction'] ?? 1.0));
        $shares *= $fraction;
        if ($shares <= 0.0) {
            return false;
        }

        $positionsByKey[$addOnKey] = [
            'key' => $addOnKey,
            'symbol' => $addOnSignal->symbol,
            'signal' => $addOnSignal,
            'entry_time' => $bar->time,
            'shares' => $shares,
            'remaining_shares' => $shares,
            'stop' => $stop,
            'initial_stop' => $stop,
            'hard_stop_active' => true,
            'break_even_armed' => false,
            'took_partial' => false,
            'realized_pnl' => 0.0,
            'events' => [
                $bar->time->format('Y-m-d') . ': break-even green garden add-on filled at close ' . round($entry, 4),
            ],
        ];
        $basePosition['break_even_add_on_done'] = true;
        $basePosition['events'][] = $bar->time->format('Y-m-d') . ': break-even green garden add-on opened at ' . round($entry, 4);

        return true;
    }

    /** @param array<string, array<string, mixed>> $positionsByKey */
    private function canOpenLayer(Signal $signal, array $positionsByKey): bool
    {
        $sameSymbol = [];
        foreach ($positionsByKey as $position) {
            /** @var Signal $openSignal */
            $openSignal = $position['signal'];
            if ($openSignal->symbol !== $signal->symbol) {
                continue;
            }
            if ($openSignal->direction !== $signal->direction) {
                return false;
            }
            $sameSymbol[] = $position;
        }

        if ($sameSymbol === []) {
            return true;
        }

        $layerConfig = $this->strategyConfig['layered_positions'] ?? [];
        if (!($layerConfig['enabled'] ?? false)) {
            return false;
        }
        if (count($sameSymbol) >= (int) ($layerConfig['same_symbol_max_layers'] ?? 1)) {
            return false;
        }
        if (!($layerConfig['require_green_garden'] ?? true)) {
            return true;
        }

        foreach ($sameSymbol as $position) {
            if (($position['break_even_armed'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    private function setupKey(Signal $signal): string
    {
        return (string) ($signal->metadata['setup_key'] ?? implode(':', [
            $signal->symbol,
            $signal->direction,
            $signal->strategy,
            $signal->createdAt->format('Y-m-d'),
        ]));
    }

    private function positionSize(
        float $cash,
        float $reservedCapital,
        int $openPositions,
        Signal $signal,
        float $riskPct,
        float $maxPositionPct,
        int $maxOpenPositions,
        ?MarketRegime $regime,
        array $positionsByKey,
    ): float {
        if ($maxOpenPositions > 0 && $openPositions >= $maxOpenPositions) {
            return 0.0;
        }

        $availableCapital = max(0.0, $this->maxGrossCapital($cash) - $reservedCapital);
        if ($availableCapital <= 0.0) {
            return 0.0;
        }

        $positionPct = $this->positionPctWithSupportHierarchy(
            $this->positionPctForRegime($regime, $maxPositionPct),
            $signal,
            $maxPositionPct,
        );
        $familyAvailableCapital = $this->familyAvailableCapital($signal, $positionsByKey, $cash);
        $maxRuleCapital = max(0.0, $cash * $positionPct);
        $fixedPositionUsd = (float) ($this->riskConfig['fixed_position_usd'] ?? 0.0);
        if ($fixedPositionUsd > 0.0) {
            return $this->sharesForCapital(min($fixedPositionUsd, $availableCapital, $maxRuleCapital, $familyAvailableCapital), $signal->entry);
        }

        $maxCapital = min(
            $cash * $positionPct,
            $availableCapital,
            $familyAvailableCapital,
        );
        if (($this->riskConfig['position_sizing_mode'] ?? 'capital_pct') === 'capital_pct') {
            return $this->sharesForCapital($maxCapital, $signal->entry);
        }

        $riskBudget = $cash * $riskPct;
        $byRisk = $signal->riskPerShare > 0.0 ? $riskBudget / $signal->riskPerShare : 0.0;
        $byCapital = $this->sharesForCapital($maxCapital, $signal->entry);

        return max(0.0, min($byRisk, $byCapital));
    }

    private function maxGrossCapital(float $cash): float
    {
        $clubRules = $this->strategyConfig['club_rules'] ?? [];
        $maxGrossExposurePct = (float) ($clubRules['max_gross_exposure_pct'] ?? 1.0);

        return $cash * max(0.0, $maxGrossExposurePct);
    }

    private function positionPctForRegime(?MarketRegime $regime, float $maxPositionPct): float
    {
        $clubRules = $this->strategyConfig['club_rules'] ?? [];
        if (!($clubRules['enabled'] ?? false)) {
            return $maxPositionPct;
        }

        $stablePct = (float) ($clubRules['stable_market_position_pct'] ?? $maxPositionPct);
        $unstablePct = (float) ($clubRules['unstable_market_position_pct'] ?? 0.05);
        if ($regime === null) {
            return min($maxPositionPct, $stablePct);
        }

        $stableScoreThreshold = (float) ($clubRules['stable_market_score_threshold'] ?? 2.5);
        $unstableWarningCount = (int) ($clubRules['unstable_warning_count'] ?? 3);
        $isUnstable = $regime->score < $stableScoreThreshold || count($regime->warnings) >= $unstableWarningCount;

        return min($maxPositionPct, $isUnstable ? $unstablePct : $stablePct);
    }

    private function positionPctWithSupportHierarchy(float $basePositionPct, Signal $signal, float $maxPositionPct): float
    {
        $basePositionPct = max(0.0, $basePositionPct);
        $layerConfig = $this->strategyConfig['layered_positions'] ?? [];
        $sizing = is_array($layerConfig['support_hierarchy_sizing'] ?? null)
            ? $layerConfig['support_hierarchy_sizing']
            : [];
        if (!($sizing['enabled'] ?? false)) {
            return min($maxPositionPct, $basePositionPct);
        }

        $maxHierarchyPositionPct = max(
            $maxPositionPct,
            (float) ($sizing['max_position_pct'] ?? $maxPositionPct),
        );
        $multiplier = $this->supportHierarchyMultiplier($signal, $sizing);

        return min($maxHierarchyPositionPct, $basePositionPct * $multiplier);
    }

    /** @param array<string, mixed> $sizing */
    private function supportHierarchyMultiplier(Signal $signal, array $sizing): float
    {
        $timeframe = strtoupper((string) ($signal->metadata['timeframe'] ?? 'D'));
        $period = (int) ($signal->metadata['ma_period'] ?? 0);
        if ($period <= 0) {
            return 1.0;
        }

        $multipliers = $sizing['multipliers'] ?? [];
        if (isset($multipliers[$timeframe]) && is_array($multipliers[$timeframe])) {
            $byPeriod = $multipliers[$timeframe];
            if (isset($byPeriod[$period])) {
                return max(0.0, (float) $byPeriod[$period]);
            }
            if (isset($byPeriod[(string) $period])) {
                return max(0.0, (float) $byPeriod[(string) $period]);
            }
        }

        $flatKey = $timeframe . ':' . $period;
        if (isset($multipliers[$flatKey])) {
            return max(0.0, (float) $multipliers[$flatKey]);
        }

        return 1.0;
    }

    /**
     * @param array<string, array<string, mixed>> $positionsByKey
     */
    private function familyAvailableCapital(Signal $signal, array $positionsByKey, float $cash): float
    {
        $config = $this->strategyConfig['family_exposure_caps'] ?? [];
        if (!($config['enabled'] ?? false)) {
            return INF;
        }

        $family = $this->symbolFamily($signal->symbol);
        if ($family === null) {
            return INF;
        }

        $caps = is_array($config['caps'] ?? null) ? $config['caps'] : [];
        $capPct = (float) ($caps[$family] ?? $config['default_max_gross_exposure_pct'] ?? 1.0);
        if ($capPct <= 0.0) {
            return 0.0;
        }

        $reserved = 0.0;
        foreach ($positionsByKey as $position) {
            $symbol = (string) ($position['symbol'] ?? '');
            if ($symbol === '' || $this->symbolFamily($symbol) !== $family) {
                continue;
            }
            /** @var Signal $openSignal */
            $openSignal = $position['signal'];
            $reserved += $openSignal->entry * (float) ($position['shares'] ?? 0.0);
        }

        return max(0.0, $cash * $capPct - $reserved);
    }

    /**
     * @param array<string, array{date:string, strength:int, setup_key:string}> $stoppedBySymbol
     */
    private function canOpenAfterStop(Signal $signal, array $stoppedBySymbol, string $date): bool
    {
        $config = $this->strategyConfig['reentry_after_stop'] ?? [];
        if (!($config['enabled'] ?? false) || !isset($stoppedBySymbol[$signal->symbol])) {
            return true;
        }

        $lastStop = $stoppedBySymbol[$signal->symbol];
        $daysSinceStop = (new \DateTimeImmutable($lastStop['date']))->diff(new \DateTimeImmutable($date))->days;
        if ($daysSinceStop < (int) ($config['cooldown_days'] ?? 0)) {
            return false;
        }

        if (!($config['require_stronger_support'] ?? true)) {
            return true;
        }

        if ($this->supportStrength($signal) > (int) $lastStop['strength']) {
            return true;
        }

        return $daysSinceStop >= (int) ($config['allow_same_strength_after_days'] ?? PHP_INT_MAX);
    }

    /**
     * @param array<string, array{date:string, strength:int, setup_key:string}> $stoppedBySymbol
     * @param array<string, mixed> $position
     */
    private function recordStoppedPosition(array &$stoppedBySymbol, array $position, Trade $trade): void
    {
        if (!in_array($trade->exitReason, ['stop', 'mental_stop_close'], true)) {
            return;
        }

        /** @var Signal $signal */
        $signal = $position['signal'];
        $stoppedBySymbol[$signal->symbol] = [
            'date' => $trade->exitTime->format('Y-m-d'),
            'strength' => $this->supportStrength($signal),
            'setup_key' => $this->setupKey($signal),
        ];
    }

    private function supportStrength(Signal $signal): int
    {
        $period = (int) ($signal->metadata['ma_period'] ?? 0);
        $timeframe = strtoupper((string) ($signal->metadata['timeframe'] ?? 'D'));
        $timeframeWeight = match ($timeframe) {
            'W', '1W' => 1000,
            '4H', 'H', '1H', '15M' => 100,
            default => 0,
        };

        return $timeframeWeight + $period;
    }

    private function symbolFamily(string $symbol): ?string
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            return null;
        }

        $families = [
            'SP500' => ['SPY', 'SPX', 'ES', 'MES', 'RSP', 'UPRO', 'SPXL', 'SPUU', 'SSO', 'SDS', 'SPXU', 'SH'],
            'NASDAQ_100' => ['QQQ', 'NDX', 'NQ', 'MNQ', 'TQQQ', 'QLD', 'SQQQ', 'QID', 'PSQ'],
            'SEMICONDUCTORS' => ['SMH', 'SOXX', 'SOX', 'SOXL', 'SOXS', 'USD', 'NVDA', 'AMD', 'MU', 'AVGO'],
            'TECH' => ['XLK', 'TECL', 'ROM', 'AAPL', 'MSFT', 'ORCL', 'CRM', 'ADBE', 'NOW'],
            'DOW' => ['DIA', 'DJI', 'YM', 'MYM', 'UDOW', 'DDM', 'SDOW', 'DOG'],
            'RUSSELL_2000' => ['IWM', 'RUT', 'RTY', 'M2K', 'TNA', 'URTY', 'TZA', 'TWM'],
            'FINANCIALS' => ['XLF', 'FAS', 'FAZ', 'JPM', 'V', 'MA'],
            'CONSUMER_DISCRETIONARY' => ['XLY', 'AMZN', 'TSLA'],
            'COMMUNICATIONS' => ['XLC', 'META', 'GOOGL', 'GOOG', 'NFLX'],
            'INDUSTRIALS' => ['XLI', 'CAT', 'GE', 'UBER'],
            'HEALTHCARE' => ['XLV', 'LLY', 'UNH'],
            'ENERGY' => ['XLE', 'XOM', 'SCO', 'UCO'],
            'VOLATILITY' => ['VIX', 'VVIX', 'VIXY', 'UVXY', 'SVIX', 'SVXY', 'SVYX'],
            'MEGA_GROWTH' => ['MAGS', 'FNGU', 'BULZ', 'AAPL', 'MSFT', 'NVDA', 'AMZN', 'META', 'GOOGL', 'TSLA'],
        ];

        foreach ($families as $family => $members) {
            if (in_array($symbol, $members, true)) {
                return $family;
            }
        }

        return null;
    }

    private function sharesForCapital(float $capital, float $entry): float
    {
        if ($capital <= 0.0 || $entry <= 0.0) {
            return 0.0;
        }

        $shares = $capital / $entry;
        if (!($this->riskConfig['allow_fractional_shares'] ?? false)) {
            return (float) floor($shares);
        }

        return floor($shares * 1000000.0) / 1000000.0;
    }

    /** @param array<string, array<string, mixed>> $positions */
    private function reservedCapital(array $positions): float
    {
        $reserved = 0.0;
        foreach ($positions as $position) {
            /** @var Signal $signal */
            $signal = $position['signal'];
            $reserved += $signal->entry * (float) $position['shares'];
        }

        return $reserved;
    }

    /**
     * @param array<string, array<string, mixed>> $positions
     * @param array<string, Bar> $lastBarsBySymbol
     */
    private function markedEquity(float $cash, array $positions, array $lastBarsBySymbol): float
    {
        $equity = $cash;
        foreach ($positions as $position) {
            $symbol = (string) ($position['symbol'] ?? '');
            if (!isset($lastBarsBySymbol[$symbol])) {
                continue;
            }

            /** @var Signal $signal */
            $signal = $position['signal'];
            $equity += (float) $position['realized_pnl'];
            $equity += $this->pnlPerShare($signal, $lastBarsBySymbol[$symbol]->close) * (float) $position['remaining_shares'];
        }

        return $equity;
    }

    /**
     * @param array<string, array<string, mixed>> $positions
     * @param array<string, Bar> $lastBarsBySymbol
     * @return list<array<string, mixed>>
     */
    private function positionStateRows(string $date, array $positions, array $lastBarsBySymbol): array
    {
        $rows = [];
        foreach ($positions as $positionKey => $position) {
            $symbol = (string) ($position['symbol'] ?? '');
            if ($symbol === '') {
                continue;
            }

            /** @var Signal $signal */
            $signal = $position['signal'];
            $markPrice = isset($lastBarsBySymbol[$symbol]) ? $lastBarsBySymbol[$symbol]->close : $signal->entry;
            $shares = (float) ($position['shares'] ?? 0.0);
            $remainingShares = (float) ($position['remaining_shares'] ?? 0.0);
            $realizedPnl = (float) ($position['realized_pnl'] ?? 0.0);
            $unrealizedPnl = $this->pnlPerShare($signal, $markPrice) * $remainingShares;
            $capitalAtEntry = max(0.0, $signal->entry * $shares);
            $events = $position['events'] ?? [];

            $rows[] = [
                'date' => $date,
                'key' => (string) ($position['key'] ?? $positionKey),
                'symbol' => $symbol,
                'strategy' => $signal->strategy,
                'direction' => $signal->direction,
                'entry_date' => $position['entry_time'] instanceof \DateTimeImmutable
                    ? $position['entry_time']->format('Y-m-d')
                    : null,
                'entry' => $signal->entry,
                'mark_price' => $markPrice,
                'shares' => $shares,
                'remaining_shares' => $remainingShares,
                'market_value' => $markPrice * $remainingShares,
                'realized_pnl' => $realizedPnl,
                'unrealized_pnl' => $unrealizedPnl,
                'total_pnl' => $realizedPnl + $unrealizedPnl,
                'pnl_pct' => $capitalAtEntry > 0.0 ? ($realizedPnl + $unrealizedPnl) / $capitalAtEntry : 0.0,
                'stop' => (float) ($position['stop'] ?? $signal->stop),
                'initial_stop' => (float) ($position['initial_stop'] ?? $signal->stop),
                'hard_stop_active' => (bool) ($position['hard_stop_active'] ?? true),
                'break_even_armed' => (bool) ($position['break_even_armed'] ?? false),
                'took_partial' => (bool) ($position['took_partial'] ?? false),
                'last_event' => is_array($events) && $events !== [] ? (string) $events[array_key_last($events)] : null,
                'metadata' => $signal->metadata,
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $position
     * @param array<string, list<float|null>> $indicatorMap
     */
    private function updatePosition(array &$position, Bar $bar, array $indicatorMap, int $index, float $partialPct): ?Trade
    {
        /** @var Signal $signal */
        $signal = $position['signal'];
        $events = $position['events'];
        $remainingShares = (float) $position['remaining_shares'];
        $realized = (float) $position['realized_pnl'];
        $stop = (float) $position['stop'];
        $hardStopActive = (bool) ($position['hard_stop_active'] ?? true);
        $clubRules = $this->strategyConfig['club_rules'] ?? [];
        $breakEvenProfitPct = (float) ($clubRules['break_even_profit_pct'] ?? 0.01);
        $breakEvenTriggerMode = (string) ($clubRules['break_even_trigger_mode'] ?? 'high');
        $breakEvenStopMode = (string) ($clubRules['break_even_stop_mode'] ?? 'hard');
        $breakEvenStopOffsetPct = (float) ($clubRules['break_even_stop_offset_pct'] ?? 0.0);
        $mentalStopExitOnClose = (bool) ($clubRules['mental_stop_exit_on_close'] ?? true);

        if ($hardStopActive) {
            if (($position['break_even_armed'] ?? false) === true && $breakEvenStopMode === 'close') {
                if ($this->mentalStopViolated($signal, $bar, $stop)) {
                    $exit = $bar->close;
                    $pnl = $realized + $this->pnlPerShare($signal, $exit) * $remainingShares;
                    $events[] = $bar->time->format('Y-m-d') . ': break-even close stop at ' . round($exit, 4);
                    return $this->tradeFromPosition($position, $bar, $exit, $pnl, 'break_even_close_stop', $events);
                }
            } elseif ($this->stopTouched($signal, $bar, $stop)) {
                $exit = $this->hardStopExitPrice($signal, $bar, $stop);
                $pnl = $realized + $this->pnlPerShare($signal, $exit) * $remainingShares;
                $events[] = $bar->time->format('Y-m-d') . ': hard stop filled at ' . round($exit, 4);
                return $this->tradeFromPosition($position, $bar, $exit, $pnl, 'stop', $events);
            }
        }

        if (!$position['break_even_armed'] && $this->breakEvenReached($signal, $bar, $breakEvenProfitPct, $breakEvenTriggerMode)) {
            $stop = $this->breakEvenStopPrice($signal, $breakEvenStopOffsetPct);
            $events[] = $bar->time->format('Y-m-d') . ': club rule #2, +'
                . round($breakEvenProfitPct * 100, 2) . '% reached, stop moved to ' . round($stop, 4);
            $position['stop'] = $stop;
            $position['hard_stop_active'] = true;
            $position['break_even_armed'] = true;
            $position['break_even_add_on_requested'] = true;
            $position['events'] = $events;
            $hardStopActive = true;
        }

        if (!$hardStopActive && $mentalStopExitOnClose && $this->mentalStopViolated($signal, $bar, (float) ($position['initial_stop'] ?? $stop))) {
            $exit = $bar->close;
            $pnl = $realized + $this->pnlPerShare($signal, $exit) * $remainingShares;
            $events[] = $bar->time->format('Y-m-d') . ': club rule #3 mental swing stop, strategy violated on close at ' . round($exit, 4);
            return $this->tradeFromPosition($position, $bar, $exit, $pnl, 'mental_stop_close', $events);
        }

        if (!$position['took_partial'] && $this->targetTouched($signal, $bar)) {
            $partialShares = max(0.0, ((float) $position['shares']) * $partialPct);
            $partialShares = min($partialShares, $remainingShares);
            $realized += $this->pnlPerShare($signal, $signal->target) * $partialShares;
            $remainingShares -= $partialShares;
            $stop = $this->breakEvenStopPrice($signal, $breakEvenStopOffsetPct);
            $events[] = $bar->time->format('Y-m-d') . ': took partial ' . round($partialShares, 6) . ' at ' . round($signal->target, 4) . ', stop to breakeven';
            $position['took_partial'] = true;
            $position['remaining_shares'] = $remainingShares;
            $position['realized_pnl'] = $realized;
            $position['stop'] = $stop;
            $position['hard_stop_active'] = true;
            $position['break_even_armed'] = true;
            $position['events'] = $events;

            if ($remainingShares <= 0) {
                return $this->tradeFromPosition($position, $bar, $signal->target, $realized, 'target', $events);
            }
        }

        $ema10 = $indicatorMap['ema10'][$index] ?? null;
        if ($position['took_partial'] && $ema10 !== null && $this->shouldTrailToEma10($signal, $bar, $ema10, $stop)) {
            $position['stop'] = $ema10;
            $position['events'][] = $bar->time->format('Y-m-d') . ': trailed stop to EMA10 ' . round($ema10, 4);
        }

        return null;
    }

    private function initialHardStopActive(Signal $signal): bool
    {
        $clubRules = $this->strategyConfig['club_rules'] ?? [];
        if (!($clubRules['enabled'] ?? false)) {
            return true;
        }

        $mode = (string) ($clubRules['default_swing_stop_mode'] ?? 'hard');
        if ($mode === 'hard') {
            return true;
        }
        if ($mode === 'mental') {
            return false;
        }
        if ($mode === 'hybrid') {
            $hardStopSymbols = array_map('strtoupper', $clubRules['hybrid_hard_stop_symbols'] ?? []);

            return in_array(strtoupper($signal->symbol), $hardStopSymbols, true);
        }

        return true;
    }

    private function pnlPerShare(Signal $signal, float $price): float
    {
        if ($signal->direction === 'short') {
            return $signal->entry - $price;
        }

        return $price - $signal->entry;
    }

    private function stopTouched(Signal $signal, Bar $bar, float $stop): bool
    {
        return $signal->direction === 'short' ? $bar->high >= $stop : $bar->low <= $stop;
    }

    private function hardStopExitPrice(Signal $signal, Bar $bar, float $stop): float
    {
        $clubRules = $this->strategyConfig['club_rules'] ?? [];
        if (($clubRules['hard_stop_fill_mode'] ?? 'stop_price') !== 'gap_open') {
            return $stop;
        }

        if ($signal->direction === 'short') {
            return $bar->open > $stop ? $bar->open : $stop;
        }

        return $bar->open < $stop ? $bar->open : $stop;
    }

    private function targetTouched(Signal $signal, Bar $bar): bool
    {
        return $signal->direction === 'short' ? $bar->low <= $signal->target : $bar->high >= $signal->target;
    }

    private function breakEvenReached(Signal $signal, Bar $bar, float $breakEvenProfitPct, string $triggerMode): bool
    {
        if ($triggerMode === 'close') {
            if ($signal->direction === 'short') {
                return $bar->close <= $signal->entry * (1.0 - $breakEvenProfitPct);
            }

            return $bar->close >= $signal->entry * (1.0 + $breakEvenProfitPct);
        }

        if ($signal->direction === 'short') {
            return $bar->low <= $signal->entry * (1.0 - $breakEvenProfitPct);
        }

        return $bar->high >= $signal->entry * (1.0 + $breakEvenProfitPct);
    }

    private function breakEvenStopPrice(Signal $signal, float $offsetPct): float
    {
        if ($signal->direction === 'short') {
            return $signal->entry * (1.0 - max(0.0, $offsetPct));
        }

        return $signal->entry * (1.0 + max(0.0, $offsetPct));
    }

    private function mentalStopViolated(Signal $signal, Bar $bar, float $initialStop): bool
    {
        return $signal->direction === 'short' ? $bar->close >= $initialStop : $bar->close <= $initialStop;
    }

    private function shouldTrailToEma10(Signal $signal, Bar $bar, float $ema10, float $stop): bool
    {
        if ($signal->direction === 'short') {
            return $ema10 < $stop && $ema10 > $bar->close;
        }

        return $ema10 > $stop && $ema10 < $bar->close;
    }

    /** @param array<string, mixed> $position @param list<string> $events */
    private function tradeFromPosition(array $position, Bar $bar, float $exit, float $pnl, string $reason, array $events): Trade
    {
        /** @var Signal $signal */
        $signal = $position['signal'];
        $initialRisk = $signal->riskPerShare * (float) $position['shares'];
        $rMultiple = $initialRisk > 0 ? $pnl / $initialRisk : 0.0;

        return new Trade(
            $signal->symbol,
            $signal->strategy,
            $position['entry_time'],
            $bar->time,
            $signal->entry,
            $exit,
            (float) $position['shares'],
            $pnl,
            $rMultiple,
            $reason,
            $events,
        );
    }
}
