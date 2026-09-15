<?php
declare(strict_types=1);

namespace FulltimeTrading\Research;

use FulltimeTrading\Paper\CandidateSleeveState;
use FulltimeTrading\Trading\WholeShareSizing;

/** Compares persisted-close primitives to a replay; no broker or database access. */
final class CandidateComponentParity
{
    public static function inspect(array $books, array $bars, array $nominal, array $result, array $history, string $end, int $cost): array
    {
        if (count($books) !== 12 || array_diff_key($books, $result['sleeve_curves']) !== [] || !in_array($cost, [30, 60], true)) {
            throw new \InvalidArgumentException('Complete twelve-sleeve replay required.');
        }
        $checks = $sessions = $quantities = $gaps = 0; $lastStates = $stops = [];
        $check = static function (bool $ok, string $why) use (&$checks): void { ++$checks; if (!$ok) { throw new \RuntimeException($why); } };
        foreach ($books as $sleeve => $book) {
            $single = new PaperExecutionRotationBacktester($book['config']); $signals = $single->paperSignalContexts($bars, $end); $state = null;
            $rows = $result['sleeve_curves'][$sleeve];
            foreach ($rows as $i => $row) {
                $date = $row['date']; $weights = $row['holding'] === null ? [] : [$row['holding'] => (float) $row['gross_close']];
                $observation = ['date' => $date, 'equity' => (float) $row['equity'], 'weights' => $weights,
                    'execution_complete' => (bool) $row['rebalance'], 'stop_filled' => isset($row['standing_stop_event'])];
                $signal = $signals($date, $row['holding']); $feedback = array_intersect_key($history[$date], array_flip(['force_cash', 'scale']));
                $state = CandidateSleeveState::advance($book['config'], $state, $observation, $signal, $feedback);
                $check($state['risk_signal'] === $row['risk_signal'], 'Risk signal mismatch: ' . $sleeve . '/' . $date);
                $check($state['cooldown_left'] === $row['circuit_cooldown_left'], 'Cooldown mismatch: ' . $sleeve . '/' . $date);
                $check(CandidateSleeveState::advance($book['config'], $state, $observation, $signal, $feedback) === $state, 'Repeated close mutated state.');
                $next = $rows[$i + 1] ?? null;
                if ($next !== null && $state['target']['rebalance_due_next_session']) {
                    if (($next['standing_stop_event']['kind'] ?? '') === 'gap_open') { ++$gaps; }
                    else {
                        $prices = [];
                        foreach ($nominal as $symbol => $series) { if (isset($series[$date])) { $prices[$symbol] = $series[$date]['close']; } }
                        $q = WholeShareSizing::target((float) $row['equity'], array_map(static fn ($w): float => $w * $row['equity'], $weights),
                            $state['target']['symbol'], (float) $state['target']['gross'], $prices, $cost);
                        $check($q === $next['fixed_quantity_target'], 'Prior-close quantity mismatch: ' . $sleeve . '/' . $date); ++$quantities;
                    }
                }
                if (isset($row['standing_stop_event'])) { $stops[] = ['sleeve' => $sleeve] + $row['standing_stop_event']; }
                $state = json_decode(json_encode($state, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR); ++$sessions;
            }
            foreach ($result['next_targets'][$sleeve] as $field => $expected) {
                if (array_key_exists($field, $state['target'])) { $actual = $state['target'][$field]; $check(is_numeric($expected) ? abs($actual - $expected) < 1e-10 : $actual === $expected, 'Final target differs.'); }
            }
            $prefixDate = $rows[intdiv(count($rows), 2)]['date']; $prefixConfig = CandidateParityInputs::prefixConfig($book['config'], $bars, $prefixDate);
            $prefix = (new PaperExecutionRotationBacktester($prefixConfig))->paperSignalContexts($bars, $prefixDate);
            $futureSymbols = array_diff($book['config']['universe'], $prefixConfig['universe']);
            foreach ([null, 'MSFT', 'NVDA'] as $held) {
                $check(CandidateParityInputs::comparableContext($signals($prefixDate, $held), $futureSymbols)
                    === CandidateParityInputs::comparableContext($prefix($prefixDate, $held), $futureSymbols), 'Future bars changed prefix decision.');
            }
            $lastStates[$sleeve] = SelectedMaximumResearch::hash($state);
        }
        $check($sessions > 6000 && $quantities > 500, 'Insufficient historical parity coverage.');
        return ['assertions' => $checks, 'sleeve_sessions' => $sessions, 'quantity_decisions' => $quantities,
            'gap_preempted_decisions' => $gaps, 'last_state_sha256' => $lastStates, 'stop_events' => $stops];
    }
}
