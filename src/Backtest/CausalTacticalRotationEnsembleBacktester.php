<?php

declare(strict_types=1);

namespace FulltimeTrading\Backtest;

use FulltimeTrading\Indicators\IndicatorCalculator;

/**
 * Static-capital ensemble of independent causal tactical-rotation sleeves.
 *
 * Every sleeve owns its initial allocation for the full replay. Sleeve equity
 * is never rebalanced, transferred or netted against another sleeve, and every
 * child remains a shadow-only CausalTacticalRotationBacktester.
 */
final class CausalTacticalRotationEnsembleBacktester
{
    /**
     * @var array<string,array{
     *     allocation:float,
     *     backtester:CausalTacticalRotationBacktester
     * }>
     */
    private array $sleeves;

    /**
     * @param array<string|int,mixed> $sleeves Keyed sleeve definitions, or a
     *        list whose rows contain name, allocation and config.
     */
    public function __construct(
        array $sleeves,
        IndicatorCalculator $indicators = new IndicatorCalculator(),
    ) {
        if (isset($sleeves['sleeves']) && is_array($sleeves['sleeves'])) {
            $sleeves = $sleeves['sleeves'];
        }
        if (count($sleeves) < 2) {
            throw new \InvalidArgumentException('A tactical ensemble requires at least two sleeves.');
        }

        $normalized = [];
        $allocationSum = 0.0;
        foreach ($sleeves as $key => $definition) {
            if (!is_array($definition)) {
                throw new \InvalidArgumentException('Every tactical ensemble sleeve must be an array.');
            }
            $name = is_string($key) ? trim($key) : trim((string) ($definition['name'] ?? ''));
            if ($name === '' || isset($normalized[$name])) {
                throw new \InvalidArgumentException('Tactical ensemble sleeve names must be non-empty and unique.');
            }
            $allocation = $definition['allocation'] ?? null;
            if (!is_numeric($allocation)) {
                throw new \InvalidArgumentException('Every tactical ensemble sleeve requires a numeric allocation.');
            }
            $allocation = (float) $allocation;
            if (!is_finite($allocation) || $allocation <= 0.0 || $allocation >= 1.0) {
                throw new \InvalidArgumentException('Tactical ensemble allocations must be finite and between zero and one.');
            }
            $config = $definition['config'] ?? null;
            if (!is_array($config)) {
                throw new \InvalidArgumentException('Every tactical ensemble sleeve requires a child config array.');
            }
            $normalized[$name] = [
                'allocation' => $allocation,
                'backtester' => new CausalTacticalRotationBacktester($config, $indicators),
            ];
            $allocationSum += $allocation;
        }
        if (abs($allocationSum - 1.0) > 1.0e-12) {
            throw new \InvalidArgumentException('Tactical ensemble sleeve allocations must sum to exactly one.');
        }

        $this->sleeves = $normalized;
    }

    /** @return array<string,array{allocation:float,config:array<string,mixed>}> */
    public function config(): array
    {
        $config = [];
        foreach ($this->sleeves as $name => $sleeve) {
            $config[$name] = [
                'allocation' => $sleeve['allocation'],
                'config' => $sleeve['backtester']->config(),
            ];
        }

        return $config;
    }

    /**
     * @param array<string,list<\FulltimeTrading\Domain\Bar>> $barsBySymbol
     * @return array<string,mixed>
     */
    public function run(
        array $barsBySymbol,
        string $tradeStart,
        string $tradeEndInclusive,
        float $initialEquity = 30000.0,
    ): array {
        if (!is_finite($initialEquity) || $initialEquity <= 0.0) {
            throw new \InvalidArgumentException('Initial ensemble equity must be finite and positive.');
        }

        $sleeveResults = [];
        $sleeveCurves = [];
        $nextTargets = [];
        $initialAllocated = 0.0;
        $lastName = (string) array_key_last($this->sleeves);
        foreach ($this->sleeves as $name => $sleeve) {
            // Assign the tiny floating-point residual to the final sleeve so
            // static sleeve capital sums to the exact requested initial equity.
            $sleeveInitialEquity = $name === $lastName
                ? $initialEquity - $initialAllocated
                : $initialEquity * $sleeve['allocation'];
            if ($sleeveInitialEquity <= 0.0) {
                throw new \RuntimeException('Static sleeve allocation produced non-positive capital.');
            }
            $initialAllocated += $sleeveInitialEquity;

            $child = $sleeve['backtester']->run(
                $barsBySymbol,
                $tradeStart,
                $tradeEndInclusive,
                $sleeveInitialEquity,
            );
            if (($child['next_target']['shadow_only'] ?? false) !== true) {
                throw new \RuntimeException('Every tactical ensemble child target must remain shadow-only.');
            }
            $sleeveCurves[$name] = $child['curve'];
            $nextTargets[$name] = array_merge(
                $child['next_target'],
                [
                    'allocation' => $sleeve['allocation'],
                    'initial_equity' => $sleeveInitialEquity,
                    'capital_scope' => 'independent_static_sleeve',
                    'shadow_only' => true,
                ],
            );
            $sleeveResults[$name] = array_merge(
                $child,
                [
                    'allocation' => $sleeve['allocation'],
                    'initial_equity' => $sleeveInitialEquity,
                ],
            );
        }

        $curve = $this->aggregateCurves($sleeveCurves, $sleeveResults);
        $featuresAsOfBySleeve = array_map(
            static fn (array $result): string => (string) $result['features_as_of'],
            $sleeveResults,
        );
        $featuresAsOfValues = array_values(array_unique($featuresAsOfBySleeve));

        return [
            'curve' => $curve,
            'sleeve_curves' => $sleeveCurves,
            'sleeves' => $sleeveResults,
            'circuit_activations' => array_sum(array_map(
                static fn (array $result): int => (int) ($result['circuit_activations'] ?? 0),
                $sleeveResults,
            )),
            'position_exit_activations' => array_sum(array_map(
                static fn (array $result): int => (int) ($result['position_exit_activations'] ?? 0),
                $sleeveResults,
            )),
            'next_targets' => $nextTargets,
            'features_as_of' => count($featuresAsOfValues) === 1 ? $featuresAsOfValues[0] : null,
            'features_as_of_by_sleeve' => $featuresAsOfBySleeve,
            'initial_equity' => $initialEquity,
            'capital_model' => 'independent_static_sleeves',
            'shadow_only' => true,
            'order_submission_enabled' => false,
        ];
    }

    /**
     * Aggregate metrics use combined-dollar daily returns and drawdown. Holding
     * episodes are formed independently inside each sleeve, measured by their
     * actual equity P/L, and only then pooled for concentration. That preserves
     * static allocations and prevents a tiny high-percentage sleeve from being
     * treated as the ensemble's dominant profit source.
     *
     * Accepts either the full run result or its aggregate curve. Aggregate rows
     * retain their child rows under sleeves, so both forms are deterministic.
     *
     * @param array<string,mixed>|list<array<string,mixed>> $curveOrResult
     * @return array<string,mixed>
     */
    public function metrics(
        array $curveOrResult,
        ?string $start = null,
        ?string $endExclusive = null,
    ): array {
        if (isset($curveOrResult['curve']) && is_array($curveOrResult['curve'])) {
            $curve = array_values($curveOrResult['curve']);
            $sleeveCurves = isset($curveOrResult['sleeve_curves']) && is_array($curveOrResult['sleeve_curves'])
                ? $curveOrResult['sleeve_curves']
                : $this->extractSleeveCurves($curve);
        } else {
            $curve = array_values($curveOrResult);
            $sleeveCurves = $this->extractSleeveCurves($curve);
        }

        $rows = array_values(array_filter(
            $curve,
            static fn (array $row): bool => ($start === null || $row['date'] >= $start)
                && ($endExclusive === null || $row['date'] < $endExclusive),
        ));
        if ($rows === []) {
            return $this->emptyMetrics();
        }

        $firstEquity = (float) $rows[0]['start_equity'];
        $lastEquity = (float) $rows[array_key_last($rows)]['equity'];
        $calendarDays = max(
            1,
            (int) (new \DateTimeImmutable((string) ($rows[0]['period_start_date'] ?? $rows[0]['date'])))
                ->diff(new \DateTimeImmutable((string) $rows[array_key_last($rows)]['date']))->days,
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
            $maxDrawdown = min(
                $maxDrawdown,
                min($equityLow, $equity) / max(0.000001, $peak) - 1.0,
            );
            $maxGross = max($maxGross, (float) $row['gross_bound']);
            $turnover += (float) $row['turnover'];
            $invested += (int) ($row['invested_sleeves'] ?? 0) > 0 ? 1 : 0;
            $startEquity = (float) $row['start_equity'];
            $dailyReturns[] = $startEquity > 0.0 ? $equity / $startEquity - 1.0 : -1.0;
        }

        $positiveDays = array_values(array_filter(
            $dailyReturns,
            static fn (float $return): bool => $return > 0.0,
        ));
        rsort($positiveDays, SORT_NUMERIC);
        [$top1DayShare, $top5DayShare] = $this->positiveConcentration($positiveDays);

        $drop = array_keys($dailyReturns);
        usort(
            $drop,
            static fn (int $a, int $b): int => $dailyReturns[$b] <=> $dailyReturns[$a] ?: $a <=> $b,
        );
        $drop = array_fill_keys(array_slice($drop, 0, 5), true);
        $exTop5Factor = 1.0;
        foreach ($dailyReturns as $index => $return) {
            if (!isset($drop[$index])) {
                $exTop5Factor *= max(0.0, 1.0 + $return);
            }
        }

        [$episodeGains, $returnSymbols] = $this->holdingEpisodeGains(
            $sleeveCurves,
            $start,
            $endExclusive,
        );
        $positiveEpisodes = array_values(array_filter(
            $episodeGains,
            static fn (float $gain): bool => $gain > 0.0,
        ));
        rsort($positiveEpisodes, SORT_NUMERIC);
        [$top1EpisodeShare, $top5EpisodeShare] = $this->positiveConcentration($positiveEpisodes);

        return [
            'points' => count($rows),
            'return' => $factor - 1.0,
            'cagr' => $factor > 0.0 ? $factor ** (365.25 / $calendarDays) - 1.0 : -1.0,
            'max_drawdown' => $maxDrawdown,
            'max_gross_bound' => $maxGross,
            'turnover' => $turnover,
            'annualized_turnover' => $turnover * 365.25 / $calendarDays,
            'invested_sessions' => $invested,
            'top1_positive_day_share' => $top1DayShare,
            'top5_positive_day_share' => $top5DayShare,
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
     * @param array<string,list<array<string,mixed>>> $sleeveCurves
     * @param array<string,array<string,mixed>> $sleeveResults
     * @return list<array<string,mixed>>
     */
    private function aggregateCurves(array $sleeveCurves, array $sleeveResults): array
    {
        $referenceName = (string) array_key_first($sleeveCurves);
        $reference = $sleeveCurves[$referenceName] ?? [];
        foreach ($sleeveCurves as $name => $curve) {
            if (count($curve) !== count($reference)) {
                throw new \RuntimeException('Tactical ensemble sleeve curves must have identical sessions.');
            }
            foreach ($curve as $index => $row) {
                $referenceRow = $reference[$index];
                if (($row['date'] ?? null) !== ($referenceRow['date'] ?? null)
                    || ($row['period_start_date'] ?? null) !== ($referenceRow['period_start_date'] ?? null)) {
                    throw new \RuntimeException(sprintf(
                        'Tactical ensemble sleeve %s is not session-aligned at row %d.',
                        $name,
                        $index,
                    ));
                }
            }
        }

        $aggregate = [];
        foreach ($reference as $index => $referenceRow) {
            $startEquity = 0.0;
            $equity = 0.0;
            $equityLow = 0.0;
            $equityHigh = 0.0;
            $grossCloseNotional = 0.0;
            $grossHighNotional = 0.0;
            $preExecutionEquity = 0.0;
            $turnoverNotional = 0.0;
            $holdings = [];
            $returnSymbols = [];
            $signalDates = [];
            $riskSignals = [];
            $rebalance = false;
            $rowSleeves = [];
            foreach ($sleeveCurves as $name => $curve) {
                $row = $curve[$index];
                $startEquity += (float) $row['start_equity'];
                $equity += (float) $row['equity'];
                $equityLow += (float) $row['equity_low'];
                $equityHigh += (float) $row['equity_high'];
                $grossCloseNotional += (float) $row['gross_close'] * (float) $row['equity'];
                $grossHighNotional += (float) ($row['gross_high_notional'] ?? 0.0);
                $preExecutionEquity += (float) ($row['pre_execution_equity'] ?? 0.0);
                $turnoverNotional += (float) ($row['turnover_notional'] ?? 0.0);
                if (is_string($row['holding'] ?? null) && $row['holding'] !== '') {
                    $holdings[$name] = $row['holding'];
                }
                if (is_string($row['return_symbol'] ?? null) && $row['return_symbol'] !== '') {
                    $returnSymbols[$name] = $row['return_symbol'];
                }
                if (is_string($row['signal_date'] ?? null) && $row['signal_date'] !== '') {
                    $signalDates[$name] = $row['signal_date'];
                }
                if (is_string($row['risk_signal'] ?? null) && $row['risk_signal'] !== '') {
                    $riskSignals[$name] = $row['risk_signal'];
                }
                $rebalance = $rebalance || (bool) ($row['rebalance'] ?? false);
                $rowSleeves[$name] = array_merge(
                    $row,
                    [
                        'allocation' => (float) $sleeveResults[$name]['allocation'],
                        'initial_equity' => (float) $sleeveResults[$name]['initial_equity'],
                    ],
                );
            }

            $aggregate[] = [
                'date' => $referenceRow['date'],
                'period_start_date' => $referenceRow['period_start_date'],
                'start_equity' => $startEquity,
                'equity' => $equity,
                'equity_low' => $equityLow,
                'equity_high' => $equityHigh,
                'gross_close' => $equity > 0.0 ? $grossCloseNotional / $equity : 0.0,
                'gross_bound' => $grossHighNotional > 0.0
                    ? $grossHighNotional / max(0.000001, $equityLow)
                    : 0.0,
                'gross_high_notional' => $grossHighNotional,
                'pre_execution_equity' => $preExecutionEquity,
                'turnover' => $preExecutionEquity > 0.0
                    ? $turnoverNotional / $preExecutionEquity
                    : 0.0,
                'turnover_notional' => $turnoverNotional,
                'holding' => $holdings === [] ? null : $holdings,
                'holdings' => $holdings,
                'return_symbol' => null,
                'return_symbols' => $returnSymbols,
                'invested_sleeves' => count($holdings),
                'rebalance' => $rebalance,
                'signal_date' => $signalDates === [] ? null : $signalDates,
                'signal_dates' => $signalDates,
                'risk_signal' => $riskSignals === [] ? null : $riskSignals,
                'risk_signals' => $riskSignals,
                'sleeves' => $rowSleeves,
            ];
        }

        return $aggregate;
    }

    /**
     * @param list<array<string,mixed>> $curve
     * @return array<string,list<array<string,mixed>>>
     */
    private function extractSleeveCurves(array $curve): array
    {
        $sleeveCurves = [];
        foreach ($curve as $row) {
            $rowSleeves = $row['sleeves'] ?? null;
            if (!is_array($rowSleeves)) {
                throw new \InvalidArgumentException('Ensemble metrics require aggregate rows with sleeve curves.');
            }
            foreach ($rowSleeves as $name => $sleeveRow) {
                if (!is_string($name) || !is_array($sleeveRow)) {
                    throw new \InvalidArgumentException('Invalid embedded ensemble sleeve curve row.');
                }
                $sleeveCurves[$name][] = $sleeveRow;
            }
        }

        return $sleeveCurves;
    }

    /**
     * @param array<string,list<array<string,mixed>>> $sleeveCurves
     * @return array{0:list<float>,1:array<string,true>}
     */
    private function holdingEpisodeGains(
        array $sleeveCurves,
        ?string $start,
        ?string $endExclusive,
    ): array {
        $episodeGains = [];
        $returnSymbols = [];
        foreach ($sleeveCurves as $curve) {
            $episodeSymbol = null;
            $episodeGain = 0.0;
            foreach ($curve as $row) {
                if (($start !== null && $row['date'] < $start)
                    || ($endExclusive !== null && $row['date'] >= $endExclusive)) {
                    continue;
                }
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
                    $returnSymbol = $segment['symbol'] ?? null;
                    if (!is_string($returnSymbol) || $returnSymbol === '') {
                        continue;
                    }
                    if ($returnSymbol !== $episodeSymbol) {
                        if ($episodeSymbol !== null) {
                            $episodeGains[] = $episodeGain;
                        }
                        $episodeSymbol = $returnSymbol;
                        $episodeGain = 0.0;
                    }
                    $returnSymbols[$returnSymbol] = true;
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
        }

        return [$episodeGains, $returnSymbols];
    }

    /** @param list<float> $positiveReturns @return array{0:float,1:float} */
    private function positiveConcentration(array $positiveReturns): array
    {
        $sum = array_sum($positiveReturns);
        if ($sum <= 0.0) {
            return [0.0, 0.0];
        }

        return [
            (float) ($positiveReturns[0] ?? 0.0) / $sum,
            array_sum(array_slice($positiveReturns, 0, 5)) / $sum,
        ];
    }

    /** @return array<string,mixed> */
    private function emptyMetrics(): array
    {
        return [
            'points' => 0,
            'return' => 0.0,
            'cagr' => 0.0,
            'max_drawdown' => 0.0,
            'max_gross_bound' => 0.0,
            'turnover' => 0.0,
            'annualized_turnover' => 0.0,
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
}
