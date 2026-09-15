<?php
declare(strict_types=1);

namespace FulltimeTrading\Research;

final class CandidateInteractionAssessment
{
    public static function validStopEvent(array $stop, array $sessions): bool
    {
        if (!is_string($stop['date'] ?? null) || !isset($sessions[$stop['date']])
            || !in_array($stop['kind'] ?? null, ['gap_open', 'stop_level', 'daily_low'], true)) { return false; }
        foreach (['fill', 'stop'] as $field) {
            if (!is_numeric($stop[$field] ?? null) || !is_finite((float) $stop[$field]) || $stop[$field] <= 0) { return false; }
        }
        if ($stop['fill'] > $stop['stop'] + 1e-8) { return false; }
        // Gap exits use the opening rebalance accounting, not the intraday-notional field.
        return $stop['kind'] === 'gap_open' || (is_numeric($stop['notional'] ?? null)
            && is_finite((float) $stop['notional']) && $stop['notional'] > 0);
    }

    public static function assess(array $results, float $capital): array
    {
        $expected = [];
        foreach (CandidateInteractionStudy::conditions() as $scenario => $_) { foreach ([30, 60] as $cost) { $expected[] = $scenario . '__' . $cost; } }
        $cases = CandidateInteractionStudy::cases();
        if (!is_finite($capital) || $capital <= 0 || array_diff_key($cases, $results) !== [] || array_diff_key($results, $cases) !== []) { throw new \RuntimeException('Full factorial results and finite positive capital required.'); }
        foreach ($results as $conditions) {
            if (count($conditions) !== 8 || array_diff($expected, array_keys($conditions)) !== []) { throw new \RuntimeException('Incomplete conditions.'); }
            foreach ($conditions as $m) {
                foreach (['return', 'cagr', 'max_drawdown', 'max_gross_bound', 'turnover'] as $field) {
                    if (!is_numeric($m[$field] ?? null) || !is_finite((float) $m[$field])) { throw new \RuntimeException('Nonfinite metric.'); }
                }
                if ($m['return'] <= -1 || $m['max_drawdown'] > 0 || $m['max_drawdown'] < -1 || $m['max_gross_bound'] < 0 || $m['turnover'] < 0) { throw new \RuntimeException('Metric outside domain.'); }
            }
        }
        $rows = [];
        foreach ($cases as $id => $case) {
            $cash = $dd = $gross = true; $changed = false; $deltas = [];
            foreach ($expected as $condition) {
                $a = $results[$id][$condition]; $b = $results['deployed'][$condition];
                $difference = self::delta($a, $b, $capital); $deltas[$condition] = $difference;
                $cash = $cash && $a['return'] >= $b['return'] - 1e-10;
                $dd = $dd && $a['max_drawdown'] >= $b['max_drawdown'] - 1e-10;
                $gross = $gross && $a['max_gross_bound'] <= $b['max_gross_bound'] + 1e-10;
                $changed = $changed || abs($a['return'] - $b['return']) > 1e-10 || abs($a['max_drawdown'] - $b['max_drawdown']) > 1e-10;
            }
            $neighbors = [];
            foreach (CandidateInteractionStudy::neighbors($id) as $neighbor => $factor) {
                $edges = [];
                foreach ($expected as $condition) { $edges[$condition] = self::delta($results[$id][$condition], $results[$neighbor][$condition], $capital); }
                $neighbors[$neighbor] = ['factor' => $factor, 'conditions' => $edges,
                    'worst_capital_delta_pct' => min(array_column($edges, 'capital_delta_pct')),
                    'worst_drawdown_improvement_pp' => min(array_column($edges, 'drawdown_improvement_pp'))];
            }
            $rows[$id] = ['factors' => $case['factors'], 'metrics' => $results[$id], 'deltas' => $deltas,
                'capital_nonworse_all_eight' => $cash, 'drawdown_nonworse_all_eight' => $dd, 'gross_nonhigher_all_eight' => $gross,
                'money_drawdown_dominates_control' => $cash && $dd && $changed, 'unchanged_money_drawdown' => !$changed,
                'worst_capital_delta_pct' => min(array_column($deltas, 'capital_delta_pct')),
                'worst_drawdown_improvement_pp' => min(array_column($deltas, 'drawdown_improvement_pp')),
                'best_drawdown_improvement_pp' => max(array_column($deltas, 'drawdown_improvement_pp')), 'neighbors' => $neighbors];
        }
        $dominators = $capitalNonworse = $drawdownNonworse = [];
        foreach ($rows as $id => $r) {
            if ($r['unchanged_money_drawdown']) { continue; }
            if ($r['money_drawdown_dominates_control']) { $dominators[] = $id; }
            if ($r['capital_nonworse_all_eight']) { $capitalNonworse[] = $id; }
            if ($r['drawdown_nonworse_all_eight']) { $drawdownNonworse[] = $id; }
        }
        return ['rows' => $rows, 'money_drawdown_dominators' => $dominators, 'capital_nonworse' => $capitalNonworse,
            'drawdown_nonworse' => $drawdownNonworse, 'independent_holdout' => false, 'deployment_authority' => false,
            'interpretation' => 'Retrospective overlapping conditions and adaptively selected factors. Neighbor deltas are descriptive sensitivity, not causal financial alpha or independent statistical evidence.'];
    }

    private static function delta(array $a, array $b, float $capital): array
    {
        return ['terminal_equity' => $capital * (1 + $a['return']), 'capital_delta_dollars' => $capital * ($a['return'] - $b['return']),
            'capital_delta_pct' => 100 * ((1 + $a['return']) / (1 + $b['return']) - 1),
            'cagr_delta_pp' => 100 * ($a['cagr'] - $b['cagr']), 'drawdown_improvement_pp' => 100 * ($a['max_drawdown'] - $b['max_drawdown']),
            'gross_delta' => $a['max_gross_bound'] - $b['max_gross_bound']];
    }
}
