<?php
declare(strict_types=1);

namespace FulltimeTrading\Research;

/** Exact accounting decomposition, not a causal estimate of expected alpha. */
final class CandidatePathAttribution
{
    public static function compare(array $base, array $variant, array $baseCircuit, array $variantCircuit): array
    {
        if ($base === [] || count($base) !== count($variant)) { throw new \InvalidArgumentException('Aligned nonempty curves required.'); }
        $years = $groups = []; $sum = 0.; $previousBase = $previousVariant = null; $previousDate = null;
        $basePaused = $variantPaused = 0; $first = null;
        foreach ($base as $i => $b) {
            $v = $variant[$i]; $date = $b['date'];
            if ($date !== $v['date'] || ($previousDate !== null && $date <= $previousDate)) { throw new \RuntimeException('Curve calendars differ.'); }
            foreach ([$b, $v] as $row) { foreach (['start_equity', 'equity'] as $field) {
                if (!is_numeric($row[$field] ?? null) || !is_finite((float) $row[$field]) || $row[$field] <= 0) { throw new \RuntimeException('Invalid equity.'); }
            } }
            if ($i === 0 && abs($b['start_equity'] - $v['start_equity']) > 1e-8) { throw new \RuntimeException('Different starting capital.'); }
            if ($previousDate !== null && (abs($b['start_equity'] / $previousBase - 1) > 1e-10 || abs($v['start_equity'] / $previousVariant - 1) > 1e-10)) {
                throw new \RuntimeException('Cash flow or missing session breaks compounding.');
            }
            foreach ([$baseCircuit, $variantCircuit] as $c) {
                if (!is_bool($c['history'][$date]['force_cash'] ?? null)) { throw new \RuntimeException('Missing close circuit state.'); }
            }
            $bc = $previousDate !== null && $baseCircuit['history'][$previousDate]['force_cash'];
            $vc = $previousDate !== null && $variantCircuit['history'][$previousDate]['force_cash'];
            $basePaused += (int) $bc; $variantPaused += (int) $vc;
            if ($bc !== $vc && $first === null) { $first = ['session' => $date, 'decided_at_close' => $previousDate, 'base_force_cash' => $bc, 'variant_force_cash' => $vc]; }
            $group = $bc ? ($vc ? 'both_forced_cash' : 'only_base_forced_cash') : ($vc ? 'only_variant_forced_cash' : 'neither_forced_cash');
            $diff = log($v['equity'] / $v['start_equity']) - log($b['equity'] / $b['start_equity']); $sum += $diff;
            $year = substr($date, 0, 4);
            $years[$year] ??= ['sessions' => 0, 'base_start' => $b['start_equity'], 'variant_start' => $v['start_equity'], 'log_relative_contribution' => 0.];
            ++$years[$year]['sessions']; $years[$year]['base_end'] = $b['equity']; $years[$year]['variant_end'] = $v['equity']; $years[$year]['log_relative_contribution'] += $diff;
            $groups[$group] ??= ['sessions' => 0, 'log_relative_contribution' => 0.]; ++$groups[$group]['sessions']; $groups[$group]['log_relative_contribution'] += $diff;
            $previousDate = $date; $previousBase = $b['equity']; $previousVariant = $v['equity'];
        }
        $terminalRatio = $previousVariant / $previousBase;
        if (abs(exp($sum) - $terminalRatio) > 1e-8 * max(1., $terminalRatio)) { throw new \RuntimeException('Compounding attribution does not reconcile.'); }
        foreach ($years as &$row) {
            $row['base_return'] = $row['base_end'] / $row['base_start'] - 1;
            $row['variant_return'] = $row['variant_end'] / $row['variant_start'] - 1;
            $row['relative_factor'] = exp($row['log_relative_contribution']);
        }
        unset($row);
        foreach ($groups as &$row) { $row['relative_factor'] = exp($row['log_relative_contribution']); }
        unset($row);
        return ['base_terminal' => $previousBase, 'variant_terminal' => $previousVariant, 'terminal_money_delta' => $terminalRatio - 1,
            'summed_log_relative_contribution' => $sum, 'reconciled_relative_factor' => exp($sum), 'years' => $years, 'prior_close_circuit_groups' => $groups,
            'base_forced_cash_sessions' => $basePaused, 'variant_forced_cash_sessions' => $variantPaused, 'first_circuit_divergence' => $first,
            'base_events' => $baseCircuit['events'], 'variant_events' => $variantCircuit['events'],
            'interpretation' => 'Accounting by PRIOR close circuit state. State is an order restriction, not proof every sleeve was flat. Groups are path-dependent, not independent causal effects; rounding/positions/costs remain coupled.'];
    }
}
