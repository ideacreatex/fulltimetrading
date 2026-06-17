<?php

declare(strict_types=1);

namespace FulltimeTrading\Backtest;

use FulltimeTrading\Domain\Signal;
use FulltimeTrading\Domain\Trade;

final readonly class BacktestResult
{
    /**
     * @param list<Signal> $signals
     * @param list<Trade> $trades
     * @param list<array{date:string, equity:float}> $equityCurve
     * @param list<string> $openPositions
     * @param list<array<string, mixed>> $positionStates
     */
    public function __construct(
        public array $signals,
        public array $trades,
        public float $startingCash,
        public float $endingCash,
        public array $equityCurve = [],
        public array $openPositions = [],
        public array $positionStates = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $wins = array_filter($this->trades, static fn (Trade $trade): bool => $trade->pnl > 0);
        $losses = array_filter($this->trades, static fn (Trade $trade): bool => $trade->pnl <= 0);
        $pnl = array_sum(array_map(static fn (Trade $trade): float => $trade->pnl, $this->trades));
        $avgR = count($this->trades) > 0
            ? array_sum(array_map(static fn (Trade $trade): float => $trade->rMultiple, $this->trades)) / count($this->trades)
            : 0.0;
        $grossProfit = array_sum(array_map(static fn (Trade $trade): float => max(0.0, $trade->pnl), $this->trades));
        $grossLoss = abs(array_sum(array_map(static fn (Trade $trade): float => min(0.0, $trade->pnl), $this->trades)));
        $returns = $this->dailyReturns($this->equityCurve);
        $totalPnl = $this->endingCash - $this->startingCash;
        $unrealizedPnl = $totalPnl - $pnl;

        return [
            'signals' => count($this->signals),
            'trades' => count($this->trades),
            'open_positions' => count($this->openPositions),
            'wins' => count($wins),
            'losses' => count($losses),
            'win_rate' => count($this->trades) > 0 ? count($wins) / count($this->trades) : 0.0,
            'pnl' => $pnl,
            'closed_pnl' => $pnl,
            'unrealized_pnl' => $unrealizedPnl,
            'total_pnl' => $totalPnl,
            'return_pct' => $this->startingCash > 0.0 ? $totalPnl / $this->startingCash : 0.0,
            'annualized_return_pct' => $this->annualizedReturnPct(),
            'gross_profit' => $grossProfit,
            'gross_loss' => $grossLoss,
            'profit_factor' => $grossLoss > 0.0 ? $grossProfit / $grossLoss : null,
            'avg_win' => count($wins) > 0
                ? array_sum(array_map(static fn (Trade $trade): float => $trade->pnl, $wins)) / count($wins)
                : 0.0,
            'avg_loss' => count($losses) > 0
                ? array_sum(array_map(static fn (Trade $trade): float => $trade->pnl, $losses)) / count($losses)
                : 0.0,
            'max_loss' => count($losses) > 0
                ? min(array_map(static fn (Trade $trade): float => $trade->pnl, $losses))
                : 0.0,
            'avg_r' => $avgR,
            'max_drawdown_pct' => $this->maxDrawdownPct($this->equityCurve),
            'sharpe' => $this->sharpe($returns),
            'sortino' => $this->sortino($returns),
            'positive_periods' => count(array_filter($returns, static fn (float $return): bool => $return > 0.0)),
            'recovery_days' => $this->recoveryDays($this->equityCurve),
            'starting_cash' => $this->startingCash,
            'ending_cash' => $this->endingCash,
        ];
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
    private function annualizedReturnPct(): float
    {
        if (count($this->equityCurve) < 2 || $this->startingCash <= 0.0 || $this->endingCash <= 0.0) {
            return 0.0;
        }

        $first = new \DateTimeImmutable((string) $this->equityCurve[0]['date']);
        $last = new \DateTimeImmutable((string) $this->equityCurve[array_key_last($this->equityCurve)]['date']);
        $years = max(1.0 / 365.25, $first->diff($last)->days / 365.25);

        return ($this->endingCash / $this->startingCash) ** (1.0 / $years) - 1.0;
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
                if ($drawdownStart !== null && $peakDate !== null) {
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
