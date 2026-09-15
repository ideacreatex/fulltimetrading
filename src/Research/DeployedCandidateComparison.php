<?php
declare(strict_types=1);

namespace FulltimeTrading\Research;

final class DeployedCandidateComparison
{
    public const CONDITIONS = ['continuous__30', 'continuous__60', 'fresh2023__30', 'fresh2023__60'];

    /** Descriptive comparisons only: these overlapping samples are not independent tests. */
    public static function compare(array $results, float $capital): array
    {
        if (!isset($results['deployed']) || !is_finite($capital) || $capital <= 0) { throw new \InvalidArgumentException('Control and positive capital required.'); }
        foreach ($results as $id => $conditions) {
            if (count($conditions) !== 4 || array_diff(self::CONDITIONS, array_keys($conditions)) !== []) { throw new \RuntimeException('Incomplete case: ' . $id); }
            foreach ($conditions as $m) {
                foreach (['return', 'cagr', 'max_drawdown', 'max_gross_bound', 'turnover'] as $key) {
                    if (!is_numeric($m[$key] ?? null) || !is_finite((float) $m[$key])) { throw new \RuntimeException('Invalid metric: ' . $key); }
                }
                if ($m['return'] <= -1 || $m['max_drawdown'] > 0 || $m['max_drawdown'] < -1 || $m['max_gross_bound'] < 0 || $m['turnover'] < 0) {
                    throw new \RuntimeException('Invalid metric range.');
                }
            }
        }
        $rows = [];
        foreach ($results as $id => $conditions) {
            $money = $drawdown = $gross = true; $changed = false; $deltas = []; $minCagr = INF; $minDd = INF; $maxDd = -INF;
            foreach (self::CONDITIONS as $condition) {
                $m = $conditions[$condition]; $b = $results['deployed'][$condition];
                $ret = $m['return'] - $b['return']; $dd = $m['max_drawdown'] - $b['max_drawdown']; $g = $m['max_gross_bound'] - $b['max_gross_bound'];
                $money = $money && $ret >= -1e-10; $drawdown = $drawdown && $dd >= -1e-10; $gross = $gross && $g <= 1e-10;
                $changed = $changed || abs($ret) > 1e-10 || abs($dd) > 1e-10;
                $minCagr = min($minCagr, 100 * ($m['cagr'] - $b['cagr'])); $minDd = min($minDd, 100 * $dd); $maxDd = max($maxDd, 100 * $dd);
                $deltas[$condition] = ['terminal_equity' => $capital * (1 + $m['return']), 'terminal_equity_delta' => $capital * $ret,
                    'terminal_equity_relative_delta' => (1 + $m['return']) / (1 + $b['return']) - 1,
                    'cagr_delta_pp' => 100 * ($m['cagr'] - $b['cagr']), 'drawdown_improvement_pp' => 100 * $dd, 'gross_delta' => $g];
            }
            $rows[$id] = ['metrics' => $conditions, 'deltas' => $deltas, 'money_nonworse_all_four' => $money,
                'drawdown_nonworse_all_four' => $drawdown, 'gross_nonhigher_all_four' => $gross,
                'dominates_control_money_drawdown' => $money && $drawdown && $changed,
                'same_money_drawdown_as_control' => !$changed, 'worst_cagr_delta_pp' => $minCagr,
                'worst_drawdown_improvement_pp' => $minDd, 'best_drawdown_improvement_pp' => $maxDd];
        }
        foreach ($rows as $id => &$row) {
            $row['dominated_by'] = [];
            foreach ($rows as $otherId => $other) {
                if ($id === $otherId) { continue; }
                $nonworse = true; $strict = false;
                foreach (self::CONDITIONS as $condition) {
                    foreach (['return', 'max_drawdown'] as $metric) {
                        $delta = $other['metrics'][$condition][$metric] - $row['metrics'][$condition][$metric];
                        $nonworse = $nonworse && $delta >= -1e-10; $strict = $strict || $delta > 1e-10;
                    }
                }
                if ($nonworse && $strict) { $row['dominated_by'][] = $otherId; }
            }
        }
        unset($row);
        return $rows;
    }
}
