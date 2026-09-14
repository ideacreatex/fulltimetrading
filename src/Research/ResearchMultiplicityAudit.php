<?php

declare(strict_types=1);

namespace FulltimeTrading\Research;

/** A least-favorable centered block-bootstrap check for the declared search. */
final class ResearchMultiplicityAudit
{
    public static function run(array $logExcessReturns, int $block = 20, int $iterations = 1000, int $seed = 20260908): array
    {
        if ($logExcessReturns === [] || $block < 1 || $iterations < 1) {
            throw new \InvalidArgumentException('Nonempty matrix and positive bootstrap settings required.');
        }
        $n = count(reset($logExcessReturns));
        if ($n < 2) {
            throw new \InvalidArgumentException('At least two aligned sessions required.');
        }
        $block = min($block, $n);
        $means = $sums = $tails = [];
        $remainder = $n % $block;
        foreach ($logExcessReturns as $id => $series) {
            if (count($series) !== $n) {
                throw new \InvalidArgumentException('Unaligned candidate observations.');
            }
            foreach ($series as $value) {
                if (!is_numeric($value) || !is_finite((float) $value)) {
                    throw new \InvalidArgumentException('Nonfinite excess return.');
                }
            }
            $mean = array_sum($series) / $n;
            $means[$id] = $mean;
            $prefix = [0.0];
            foreach (array_merge($series, $series) as $value) {
                $prefix[] = $prefix[array_key_last($prefix)] + $value - $mean;
            }
            for ($i = 0; $i < $n; $i++) {
                $sums[$id][$i] = $prefix[$i + $block] - $prefix[$i];
                $tails[$id][$i] = $prefix[$i + $remainder] - $prefix[$i];
            }
        }
        $observed = max(0.0, max($means));
        $exceeds = 0;
        for ($iteration = 0; $iteration < $iterations; $iteration++) {
            $starts = [];
            for ($b = 0; $b < intdiv($n, $block) + (int) ($remainder > 0); $b++) {
                $starts[] = (int) hexdec(substr(hash('sha256', "$seed:$iteration:$b"), 0, 8)) % $n;
            }
            $tailStart = $remainder > 0 ? array_pop($starts) : null;
            $maximum = 0.0;
            foreach ($means as $id => $mean) {
                $sum = $tailStart === null ? 0.0 : $tails[$id][$tailStart];
                foreach ($starts as $start) {
                    $sum += $sums[$id][$start];
                }
                $maximum = max($maximum, $sum / $n);
            }
            $exceeds += (int) ($maximum >= $observed - 1.0e-15);
        }
        arsort($means, SORT_NUMERIC);
        return ['method' => 'centered circular block-bootstrap max-mean log-excess test, least-favorable null',
            'cases' => count($means), 'sessions' => $n, 'block' => $block, 'iterations' => $iterations, 'seed' => $seed,
            'best_mean_id' => array_key_first($means), 'observed_max_mean_log_excess' => $observed,
            'familywise_p_value' => (1.0 + $exceeds) / (1.0 + $iterations),
            'scope' => 'Only this fixed candidate family; not prior searches, universe selection or publication selection. Assumes block resampling is appropriate.'];
    }
}
