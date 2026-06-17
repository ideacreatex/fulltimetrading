<?php

declare(strict_types=1);

namespace FulltimeTrading\Backtest;

use FulltimeTrading\Domain\Bar;
use FulltimeTrading\Domain\Trade;

final class PerformanceReport
{
    /**
     * @param list<Bar> $benchmarkBars
     * @return array<string, mixed>
     */
    public function build(BacktestResult $result, array $benchmarkBars, string $benchmarkSymbol = 'SPY'): array
    {
        $benchmarkCurve = $this->benchmarkCurve($benchmarkBars, $result->startingCash);
        $summary = $result->summary();
        $benchmark = $this->curveSummary($benchmarkCurve);

        return [
            'summary' => $summary,
            'benchmark' => array_merge(['symbol' => $benchmarkSymbol], $benchmark),
            'excess_return_pct' => ($summary['return_pct'] ?? 0.0) - ($benchmark['return_pct'] ?? 0.0),
            'strategies' => $this->groupedTradeStats($result->trades, static fn (Trade $trade): string => $trade->strategy),
            'symbols' => $this->groupedTradeStats($result->trades, static fn (Trade $trade): string => $trade->symbol),
            'years' => $this->years($result, $benchmarkCurve),
            'quarters' => $this->quarters($result, $benchmarkCurve),
        ];
    }

    /**
     * @param list<Bar> $bars
     * @return list<array{date:string, equity:float}>
     */
    private function benchmarkCurve(array $bars, float $capital): array
    {
        if ($bars === []) {
            return [];
        }

        $firstClose = null;
        $curve = [];
        foreach ($bars as $bar) {
            if ($bar->close <= 0.0) {
                continue;
            }
            $firstClose ??= $bar->close;
            $curve[] = [
                'date' => $bar->time->format('Y-m-d'),
                'equity' => $capital * ($bar->close / $firstClose),
            ];
        }

        return $curve;
    }

    /**
     * @param list<array{date:string, equity:float}> $curve
     * @return array<string, float|int>
     */
    private function curveSummary(array $curve): array
    {
        if ($curve === []) {
            return [
                'return_pct' => 0.0,
                'annualized_return_pct' => 0.0,
                'max_drawdown_pct' => 0.0,
                'sharpe' => 0.0,
                'sortino' => 0.0,
                'positive_periods' => 0,
                'recovery_days' => 0,
                'starting_cash' => 0.0,
                'ending_cash' => 0.0,
            ];
        }

        $first = (float) $curve[0]['equity'];
        $last = (float) $curve[array_key_last($curve)]['equity'];
        $returns = $this->dailyReturns($curve);

        return [
            'return_pct' => $first > 0.0 ? ($last - $first) / $first : 0.0,
            'annualized_return_pct' => $this->annualizedReturnPct($curve),
            'max_drawdown_pct' => $this->maxDrawdownPct($curve),
            'sharpe' => $this->sharpe($returns),
            'sortino' => $this->sortino($returns),
            'positive_periods' => count(array_filter($returns, static fn (float $return): bool => $return > 0.0)),
            'recovery_days' => $this->recoveryDays($curve),
            'starting_cash' => $first,
            'ending_cash' => $last,
        ];
    }

    /**
     * @param list<array{date:string, equity:float}> $benchmarkCurve
     * @return array<string, array<string, mixed>>
     */
    private function quarters(BacktestResult $result, array $benchmarkCurve): array
    {
        $strategyByQuarter = $this->pointsByQuarter($result->equityCurve);
        $benchmarkByQuarter = $this->pointsByQuarter($benchmarkCurve);
        $tradesByQuarter = $this->tradesByQuarter($result->trades);
        $quarters = array_values(array_unique(array_merge(
            array_keys($strategyByQuarter),
            array_keys($benchmarkByQuarter),
            array_keys($tradesByQuarter),
        )));
        sort($quarters);

        $rows = [];
        foreach ($quarters as $quarter) {
            $strategyPoints = $strategyByQuarter[$quarter] ?? [];
            $benchmarkPoints = $benchmarkByQuarter[$quarter] ?? [];
            $trades = $tradesByQuarter[$quarter] ?? [];
            $wins = array_filter($trades, static fn (Trade $trade): bool => $trade->pnl > 0.0);
            $losses = array_filter($trades, static fn (Trade $trade): bool => $trade->pnl <= 0.0);
            $strategySummary = $this->curveSummary($strategyPoints);
            $benchmarkSummary = $this->curveSummary($benchmarkPoints);
            $pnl = array_sum(array_map(static fn (Trade $trade): float => $trade->pnl, $trades));
            $grossProfit = array_sum(array_map(static fn (Trade $trade): float => max(0.0, $trade->pnl), $trades));
            $grossLoss = abs(array_sum(array_map(static fn (Trade $trade): float => min(0.0, $trade->pnl), $trades)));

            $rows[$quarter] = [
                'strategy_starting_cash' => $strategySummary['starting_cash'],
                'strategy_ending_cash' => $strategySummary['ending_cash'],
                'spy_starting_cash' => $benchmarkSummary['starting_cash'],
                'spy_ending_cash' => $benchmarkSummary['ending_cash'],
                'strategy_return_pct' => $strategySummary['return_pct'],
                'spy_return_pct' => $benchmarkSummary['return_pct'],
                'excess_return_pct' => $strategySummary['return_pct'] - $benchmarkSummary['return_pct'],
                'strategy_max_drawdown_pct' => $strategySummary['max_drawdown_pct'],
                'spy_max_drawdown_pct' => $benchmarkSummary['max_drawdown_pct'],
                'trades' => count($trades),
                'wins' => count($wins),
                'losses' => count($losses),
                'win_rate' => count($trades) > 0 ? count($wins) / count($trades) : 0.0,
                'pnl' => $pnl,
                'gross_profit' => $grossProfit,
                'gross_loss' => $grossLoss,
                'profit_factor' => $grossLoss > 0.0 ? $grossProfit / $grossLoss : null,
                'max_loss' => count($losses) > 0 ? min(array_map(static fn (Trade $trade): float => $trade->pnl, $losses)) : 0.0,
            ];
        }

        return $rows;
    }

    /**
     * @param list<array{date:string, equity:float}> $points
     * @return array<string, list<array{date:string, equity:float}>>
     */
    private function pointsByQuarter(array $points): array
    {
        $result = [];
        foreach ($points as $point) {
            $result[$this->quarterKey((string) $point['date'])][] = $point;
        }

        return $result;
    }

    /**
     * @param list<Trade> $trades
     * @return array<string, list<Trade>>
     */
    private function tradesByQuarter(array $trades): array
    {
        $result = [];
        foreach ($trades as $trade) {
            $result[$this->quarterKey($trade->exitTime->format('Y-m-d'))][] = $trade;
        }

        return $result;
    }

    /**
     * @param list<array{date:string, equity:float}> $benchmarkCurve
     * @return array<string, array<string, mixed>>
     */
    private function years(BacktestResult $result, array $benchmarkCurve): array
    {
        return $this->periodRows(
            $this->pointsByYear($result->equityCurve),
            $this->pointsByYear($benchmarkCurve),
            $this->tradesByYear($result->trades),
        );
    }

    /**
     * @param array<string, list<array{date:string, equity:float}>> $strategyByPeriod
     * @param array<string, list<array{date:string, equity:float}>> $benchmarkByPeriod
     * @param array<string, list<Trade>> $tradesByPeriod
     * @return array<string, array<string, mixed>>
     */
    private function periodRows(array $strategyByPeriod, array $benchmarkByPeriod, array $tradesByPeriod): array
    {
        $periods = array_values(array_unique(array_merge(
            array_keys($strategyByPeriod),
            array_keys($benchmarkByPeriod),
            array_keys($tradesByPeriod),
        )));
        sort($periods);

        $rows = [];
        foreach ($periods as $period) {
            $strategyPoints = $strategyByPeriod[$period] ?? [];
            $benchmarkPoints = $benchmarkByPeriod[$period] ?? [];
            $trades = $tradesByPeriod[$period] ?? [];
            $wins = array_filter($trades, static fn (Trade $trade): bool => $trade->pnl > 0.0);
            $losses = array_filter($trades, static fn (Trade $trade): bool => $trade->pnl <= 0.0);
            $strategySummary = $this->curveSummary($strategyPoints);
            $benchmarkSummary = $this->curveSummary($benchmarkPoints);
            $pnl = array_sum(array_map(static fn (Trade $trade): float => $trade->pnl, $trades));
            $grossProfit = array_sum(array_map(static fn (Trade $trade): float => max(0.0, $trade->pnl), $trades));
            $grossLoss = abs(array_sum(array_map(static fn (Trade $trade): float => min(0.0, $trade->pnl), $trades)));

            $rows[$period] = [
                'strategy_starting_cash' => $strategySummary['starting_cash'],
                'strategy_ending_cash' => $strategySummary['ending_cash'],
                'spy_starting_cash' => $benchmarkSummary['starting_cash'],
                'spy_ending_cash' => $benchmarkSummary['ending_cash'],
                'strategy_return_pct' => $strategySummary['return_pct'],
                'spy_return_pct' => $benchmarkSummary['return_pct'],
                'excess_return_pct' => $strategySummary['return_pct'] - $benchmarkSummary['return_pct'],
                'strategy_max_drawdown_pct' => $strategySummary['max_drawdown_pct'],
                'spy_max_drawdown_pct' => $benchmarkSummary['max_drawdown_pct'],
                'trades' => count($trades),
                'wins' => count($wins),
                'losses' => count($losses),
                'win_rate' => count($trades) > 0 ? count($wins) / count($trades) : 0.0,
                'pnl' => $pnl,
                'gross_profit' => $grossProfit,
                'gross_loss' => $grossLoss,
                'profit_factor' => $grossLoss > 0.0 ? $grossProfit / $grossLoss : null,
                'max_loss' => count($losses) > 0 ? min(array_map(static fn (Trade $trade): float => $trade->pnl, $losses)) : 0.0,
            ];
        }

        return $rows;
    }

    /**
     * @param list<array{date:string, equity:float}> $points
     * @return array<string, list<array{date:string, equity:float}>>
     */
    private function pointsByYear(array $points): array
    {
        $result = [];
        foreach ($points as $point) {
            $year = (new \DateTimeImmutable((string) $point['date']))->format('Y');
            $result[$year][] = $point;
        }

        return $result;
    }

    /**
     * @param list<Trade> $trades
     * @return array<string, list<Trade>>
     */
    private function tradesByYear(array $trades): array
    {
        $result = [];
        foreach ($trades as $trade) {
            $result[$trade->exitTime->format('Y')][] = $trade;
        }

        return $result;
    }

    /**
     * @param list<Trade> $trades
     * @param callable(Trade):string $keyResolver
     * @return array<string, array<string, float|int|string|null>>
     */
    private function groupedTradeStats(array $trades, callable $keyResolver): array
    {
        $groups = [];
        foreach ($trades as $trade) {
            $groups[$keyResolver($trade)][] = $trade;
        }

        $rows = [];
        foreach ($groups as $key => $groupTrades) {
            $wins = array_filter($groupTrades, static fn (Trade $trade): bool => $trade->pnl > 0.0);
            $losses = array_filter($groupTrades, static fn (Trade $trade): bool => $trade->pnl <= 0.0);
            $grossProfit = array_sum(array_map(static fn (Trade $trade): float => max(0.0, $trade->pnl), $groupTrades));
            $grossLoss = abs(array_sum(array_map(static fn (Trade $trade): float => min(0.0, $trade->pnl), $groupTrades)));
            $pnl = array_sum(array_map(static fn (Trade $trade): float => $trade->pnl, $groupTrades));
            $avgR = array_sum(array_map(static fn (Trade $trade): float => $trade->rMultiple, $groupTrades)) / count($groupTrades);
            usort($groupTrades, static fn (Trade $a, Trade $b): int => $a->entryTime <=> $b->entryTime);

            $rows[(string) $key] = [
                'trades' => count($groupTrades),
                'wins' => count($wins),
                'losses' => count($losses),
                'win_rate' => count($groupTrades) > 0 ? count($wins) / count($groupTrades) : 0.0,
                'pnl' => $pnl,
                'gross_profit' => $grossProfit,
                'gross_loss' => $grossLoss,
                'profit_factor' => $grossLoss > 0.0 ? $grossProfit / $grossLoss : null,
                'avg_r' => $avgR,
                'max_loss' => count($losses) > 0 ? min(array_map(static fn (Trade $trade): float => $trade->pnl, $losses)) : 0.0,
                'first_entry' => $groupTrades[0]->entryTime->format('Y-m-d'),
                'last_exit' => $groupTrades[array_key_last($groupTrades)]->exitTime->format('Y-m-d'),
            ];
        }

        uasort($rows, static fn (array $a, array $b): int => $b['pnl'] <=> $a['pnl']);

        return $rows;
    }

    private function quarterKey(string $date): string
    {
        $dt = new \DateTimeImmutable($date);
        $quarter = intdiv(((int) $dt->format('n')) - 1, 3) + 1;

        return $dt->format('Y') . '-Q' . $quarter;
    }

    /**
     * @param list<array{date:string, equity:float}> $curve
     * @return list<float>
     */
    private function dailyReturns(array $curve): array
    {
        $returns = [];
        $previous = null;
        foreach ($curve as $point) {
            $equity = (float) $point['equity'];
            if ($previous !== null && $previous > 0.0) {
                $returns[] = ($equity - $previous) / $previous;
            }
            $previous = $equity;
        }

        return $returns;
    }

    /** @param list<array{date:string, equity:float}> $curve */
    private function annualizedReturnPct(array $curve): float
    {
        if (count($curve) < 2) {
            return 0.0;
        }

        $first = (float) $curve[0]['equity'];
        $last = (float) $curve[array_key_last($curve)]['equity'];
        if ($first <= 0.0 || $last <= 0.0) {
            return 0.0;
        }

        $firstDate = new \DateTimeImmutable((string) $curve[0]['date']);
        $lastDate = new \DateTimeImmutable((string) $curve[array_key_last($curve)]['date']);
        $years = max(1.0 / 365.25, $firstDate->diff($lastDate)->days / 365.25);

        return ($last / $first) ** (1.0 / $years) - 1.0;
    }

    /** @param list<array{date:string, equity:float}> $curve */
    private function maxDrawdownPct(array $curve): float
    {
        $peak = null;
        $maxDrawdown = 0.0;
        foreach ($curve as $point) {
            $equity = (float) $point['equity'];
            $peak = $peak === null ? $equity : max($peak, $equity);
            if ($peak > 0.0) {
                $maxDrawdown = min($maxDrawdown, ($equity - $peak) / $peak);
            }
        }

        return $maxDrawdown;
    }

    /** @param list<float> $returns */
    private function sharpe(array $returns): float
    {
        if (count($returns) < 2) {
            return 0.0;
        }

        $mean = array_sum($returns) / count($returns);
        $variance = array_sum(array_map(static fn (float $r): float => ($r - $mean) ** 2, $returns)) / (count($returns) - 1);
        $std = sqrt($variance);

        return $std > 0.0 ? sqrt(252.0) * $mean / $std : 0.0;
    }

    /** @param list<float> $returns */
    private function sortino(array $returns): float
    {
        if (count($returns) < 2) {
            return 0.0;
        }

        $mean = array_sum($returns) / count($returns);
        $downside = array_values(array_filter($returns, static fn (float $r): bool => $r < 0.0));
        if ($downside === []) {
            return 0.0;
        }

        $variance = array_sum(array_map(static fn (float $r): float => $r ** 2, $downside)) / count($downside);
        $downsideStd = sqrt($variance);

        return $downsideStd > 0.0 ? sqrt(252.0) * $mean / $downsideStd : 0.0;
    }

    /** @param list<array{date:string, equity:float}> $curve */
    private function recoveryDays(array $curve): int
    {
        $peak = null;
        $peakDate = null;
        $drawdownStart = null;
        $maxRecovery = 0;

        foreach ($curve as $point) {
            $equity = (float) $point['equity'];
            $date = new \DateTimeImmutable((string) $point['date']);

            if ($peak === null || $equity >= $peak) {
                if ($drawdownStart !== null) {
                    $maxRecovery = max($maxRecovery, $drawdownStart->diff($date)->days);
                }
                $peak = $equity;
                $peakDate = $date;
                $drawdownStart = null;
                continue;
            }

            if ($drawdownStart === null) {
                $drawdownStart = $peakDate;
            }
        }

        if ($drawdownStart !== null && $curve !== []) {
            $last = new \DateTimeImmutable((string) $curve[array_key_last($curve)]['date']);
            $maxRecovery = max($maxRecovery, $drawdownStart->diff($last)->days);
        }

        return $maxRecovery;
    }
}
