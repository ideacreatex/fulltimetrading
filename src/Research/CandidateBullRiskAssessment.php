<?php
declare(strict_types=1);

namespace FulltimeTrading\Research;

final class CandidateBullRiskAssessment
{
    public static function assess(array $results, float $capital): array
    {
        $cases = CandidateBullRiskStudy::cases(); $expected = [];
        foreach (CandidateInteractionStudy::conditions() as $scenario => $_) { foreach ([30, 60] as $cost) { $expected[] = $scenario . '__' . $cost; } }
        if (!is_finite($capital) || $capital <= 0 || array_diff_key($cases, $results) !== [] || array_diff_key($results, $cases) !== []) { throw new \InvalidArgumentException('Full declared matrix and positive capital required.'); }
        foreach ($results as $conditions) {
            if (count($conditions) !== count($expected) || array_diff($expected, array_keys($conditions)) !== []) { throw new \InvalidArgumentException('Incomplete comparison conditions.'); }
            foreach ($conditions as $m) {
                foreach (['return', 'cagr', 'max_drawdown', 'max_gross_bound', 'turnover'] as $field) {
                    if (!is_numeric($m[$field] ?? null) || !is_finite((float) $m[$field])) { throw new \InvalidArgumentException('Nonfinite metric.'); }
                }
                if ($m['return'] <= -1 || $m['cagr'] <= -1 || $m['max_drawdown'] > 0 || $m['max_drawdown'] < -1 || $m['max_gross_bound'] < 0 || $m['turnover'] < 0) { throw new \InvalidArgumentException('Metric outside domain.'); }
            }
        }
        $rows = [];
        foreach ($cases as $id => $case) {
            $comparisons = ['deployed' => 'deployed', 'anchor' => $case['reference']];
            if ($case['bull'] !== null) { $comparisons['constant_risk'] = $case['constant_reference']; }
            $row = ['parameters' => $case, 'metrics' => $results[$id], 'comparisons' => [], 'neighbors' => []];
            foreach ($comparisons as $name => $control) { $row['comparisons'][$name] = ['reference' => $control] + self::compare($results[$id], $results[$control], $capital); }
            if ($case['bull'] !== null) {
                foreach ($cases as $otherId => $other) {
                    if ($otherId === $id || $other['bull'] === null) { continue; }
                    $different = [];
                    if ($case['reference'] !== $other['reference']) { $different[] = 'vvix'; }
                    foreach (['window', 'boost'] as $factor) { if ($case['bull'][$factor] !== $other['bull'][$factor]) { $different[] = $factor; } }
                    if (count($different) === 1) { $row['neighbors'][$otherId] = ['factor' => $different[0]] + self::compare($results[$id], $results[$otherId], $capital); }
                }
            }
            $rows[$id] = $row;
        }
        return ['rows' => $rows, 'independent_holdout' => false, 'deployment_authority' => false,
            'interpretation' => 'All-eight flags describe retrospective robustness, not admission gates. Money-only and drawdown-only improvements, and their adverse tradeoffs, must remain visible.'];
    }

    private static function compare(array $candidate, array $control, float $capital): array
    {
        $deltas = []; $cash = $dd = $gross = true; $changed = false;
        foreach ($candidate as $key => $a) {
            $b = $control[$key];
            $deltas[$key] = ['terminal_equity' => $capital * (1 + $a['return']), 'capital_delta_dollars' => $capital * ($a['return'] - $b['return']),
                'capital_delta_pct' => 100 * ((1 + $a['return']) / (1 + $b['return']) - 1), 'cagr_delta_pp' => 100 * ($a['cagr'] - $b['cagr']),
                'drawdown_improvement_pp' => 100 * ($a['max_drawdown'] - $b['max_drawdown']), 'gross_delta' => $a['max_gross_bound'] - $b['max_gross_bound']];
            $cash = $cash && $a['return'] >= $b['return'] - 1e-10; $dd = $dd && $a['max_drawdown'] >= $b['max_drawdown'] - 1e-10;
            $gross = $gross && $a['max_gross_bound'] <= $b['max_gross_bound'] + 1e-10;
            $changed = $changed || abs($a['return'] - $b['return']) > 1e-10 || abs($a['max_drawdown'] - $b['max_drawdown']) > 1e-10;
        }
        return ['deltas' => $deltas, 'capital_nonworse_all_eight' => $cash, 'drawdown_nonworse_all_eight' => $dd, 'gross_nonhigher_all_eight' => $gross,
            'money_drawdown_dominates' => $cash && $dd && $changed, 'unchanged_money_drawdown' => !$changed,
            'worst_capital_delta_pct' => min(array_column($deltas, 'capital_delta_pct')),
            'best_capital_delta_pct' => max(array_column($deltas, 'capital_delta_pct')),
            'worst_drawdown_improvement_pp' => min(array_column($deltas, 'drawdown_improvement_pp')),
            'best_drawdown_improvement_pp' => max(array_column($deltas, 'drawdown_improvement_pp'))];
    }
}
