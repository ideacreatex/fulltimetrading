<?php

declare(strict_types=1);

namespace FulltimeTrading\Backtest;

final class BlockBootstrapAnalyzer
{
    /**
     * @param list<array{date:string,equity:float}> $equityCurve
     * @return array<string, float|int>
     */
    public function analyze(
        array $equityCurve,
        int $iterations = 1000,
        int $blockSize = 20,
        int $seed = 20260716,
    ): array {
        $returns = $this->dailyReturns($equityCurve);
        $count = count($returns);
        $iterations = max(1, $iterations);
        $blockSize = max(1, min(max(1, $count), $blockSize));
        if ($count < 2) {
            return [
                'sessions' => $count,
                'iterations' => $iterations,
                'block_size' => $blockSize,
                'cagr_q05' => 0.0,
                'cagr_q50' => 0.0,
                'cagr_q95' => 0.0,
                'max_drawdown_q50' => 0.0,
                'max_drawdown_q95' => 0.0,
                'positive_cagr_probability' => 0.0,
            ];
        }

        $annualized = [];
        $drawdowns = [];
        $positive = 0;
        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $sample = [];
            for ($block = 0; count($sample) < $count; $block++) {
                $start = $this->deterministicIndex($seed, $iteration, $block, $count);
                for ($offset = 0; $offset < $blockSize && count($sample) < $count; $offset++) {
                    $sample[] = $returns[($start + $offset) % $count];
                }
            }

            [$cagr, $drawdown] = $this->pathMetrics($sample);
            $annualized[] = $cagr;
            $drawdowns[] = abs($drawdown);
            if ($cagr > 0.0) {
                $positive++;
            }
        }

        sort($annualized, SORT_NUMERIC);
        sort($drawdowns, SORT_NUMERIC);

        return [
            'sessions' => $count,
            'iterations' => $iterations,
            'block_size' => $blockSize,
            'cagr_q05' => $this->quantile($annualized, 0.05),
            'cagr_q50' => $this->quantile($annualized, 0.50),
            'cagr_q95' => $this->quantile($annualized, 0.95),
            'max_drawdown_q50' => $this->quantile($drawdowns, 0.50),
            'max_drawdown_q95' => $this->quantile($drawdowns, 0.95),
            'positive_cagr_probability' => $positive / $iterations,
        ];
    }

    /**
     * @param list<array{date:string,equity:float}> $equityCurve
     * @return list<float>
     */
    private function dailyReturns(array $equityCurve): array
    {
        $byDate = [];
        foreach ($equityCurve as $point) {
            $date = substr((string) ($point['date'] ?? ''), 0, 10);
            $equity = (float) ($point['equity'] ?? 0.0);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || $equity <= 0.0) {
                continue;
            }
            $byDate[$date] = $equity;
        }
        ksort($byDate);

        $returns = [];
        $previous = null;
        foreach ($byDate as $equity) {
            if ($previous !== null && $previous > 0.0) {
                $returns[] = $equity / $previous - 1.0;
            }
            $previous = $equity;
        }

        return $returns;
    }

    private function deterministicIndex(int $seed, int $iteration, int $block, int $count): int
    {
        $bytes = hash('sha256', $seed . ':' . $iteration . ':' . $block, true);
        $unpacked = unpack('Nvalue', substr($bytes, 0, 4));
        $value = (int) ($unpacked['value'] ?? 0);

        return $count > 0 ? $value % $count : 0;
    }

    /** @param list<float> $returns @return array{float,float} */
    private function pathMetrics(array $returns): array
    {
        $equity = 1.0;
        $peak = 1.0;
        $maxDrawdown = 0.0;
        foreach ($returns as $return) {
            $equity *= max(0.0, 1.0 + $return);
            $peak = max($peak, $equity);
            if ($peak > 0.0) {
                $maxDrawdown = min($maxDrawdown, ($equity - $peak) / $peak);
            }
        }

        $years = max(1.0 / 252.0, count($returns) / 252.0);
        $cagr = $equity > 0.0 ? $equity ** (1.0 / $years) - 1.0 : -1.0;

        return [$cagr, $maxDrawdown];
    }

    /** @param list<float> $sorted */
    private function quantile(array $sorted, float $probability): float
    {
        if ($sorted === []) {
            return 0.0;
        }
        $position = max(0.0, min(1.0, $probability)) * (count($sorted) - 1);
        $lower = (int) floor($position);
        $upper = (int) ceil($position);
        if ($lower === $upper) {
            return (float) $sorted[$lower];
        }
        $weight = $position - $lower;

        return (float) $sorted[$lower] * (1.0 - $weight) + (float) $sorted[$upper] * $weight;
    }
}
