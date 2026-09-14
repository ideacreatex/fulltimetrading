<?php

declare(strict_types=1);

namespace FulltimeTrading\Paper;

/** Plans one bounded broker mutation at a time. The caller refreshes broker state after each action. */
final readonly class CandidateExecutionPlan
{
    public function __construct(private CandidateLedger $ledger) { }

    public function next(string $run, array $close, array $window, array $guards): array
    {
        foreach (['identity', 'account', 'reconciliation'] as $gate) {
            if (($guards[$gate] ?? null) !== true) { return ['action' => 'blocked', 'reason' => $gate]; }
        }
        $date = $close['date']; $session = $close['scheduled_session'];
        CandidateOrder::date($date); CandidateOrder::date($session);
        $stored = $this->ledger->checkpoint($run, 'close:' . $date);
        if ($stored === null || CandidateOrder::json($stored['payload']) !== CandidateOrder::json($close)) {
            throw new \RuntimeException('Execution requires the exact committed close plan.');
        }
        if (($window['scheduled_session'] ?? null) !== $session) { throw new \RuntimeException('Candidate execution session drift.'); }
        $positions = $this->ledger->views->positions($run); $protection = new CandidateProtection($this->ledger);
        $active = $this->ledger->active($run);
        foreach ($active as $i) {
            if (in_array($i['status'], ['submitting', 'ambiguous'], true)) {
                return ['action' => 'refresh', 'decision_id' => $i['decision_id'], 'reason' => 'ambiguous_lookup_only'];
            }
        }
        $batch = new CandidateEntryBatch($this->ledger);
        $batchAction = $batch->next($run, $close, $window, $guards);
        if ($batchAction !== null) { return $batchAction; }
        foreach ($active as $i) {
            if ($i['side'] === 'buy' && (float) $i['cumulative_filled_qty'] > 0) {
                return ['action' => 'cancel', 'decision_id' => $i['decision_id'], 'reason' => 'partial_fill_protection'];
            }
        }
        $opg = ($window['opg_submit_allowed'] ?? false) === true;
        $daySell = ($window['risk_exit_day_allowed'] ?? false) === true;
        $dayBuy = ($window['rotation_reentry_allowed'] ?? false) === true;
        $sellWindow = $opg || $daySell;
        foreach ($close['plans'] as $name => $plan) {
            $target = $plan['target_quantities'];
            $targetSymbol = is_array($target) ? array_key_first($target) : null;
            $bookPositions = $positions[$name] ?? [];
            $latch = $this->ledger->checkpoint($run, 'stop_latch:' . $name);
            $stopPending = ($latch['payload']['pending'] ?? false) === true;
            $bookActive = array_values(array_filter($active, static fn ($i): bool => $i['sleeve_id'] === $name));
            // Even a fully stopped-out book may still have an unfilled entry remainder.
            foreach ($bookActive as $i) {
                if ($i['side'] === 'buy' && ($stopPending || $plan['circuit_feedback']['force_cash'] || $plan['target']['risk_exit_pending'])) {
                    return ['action' => 'cancel', 'decision_id' => $i['decision_id'], 'reason' => 'circuit'];
                }
            }
            foreach ($bookPositions as $symbol => $p) {
                $owned = CandidateOrder::quantity($p['qty']);
                $wanted = $target === null ? $owned : ($symbol === $targetSymbol ? (int) $target[$symbol] : 0);
                $sellQty = max(0, $owned - $wanted);
                // A protective stop is not removed until the market exit can actually be submitted.
                $pp = $protection->plan($run, $name, $symbol, $date, $session, $sellQty > 0 && $sellWindow,
                    $sellQty > 0 && $sellWindow ? $wanted : 0);
                if ($pp['cancel'] !== []) {
                    return ['action' => 'cancel', 'decision_id' => $pp['cancel'][0],
                        'reason' => $sellQty > 0 && $sellWindow ? 'rebalance' : 'close_stop_update'];
                }
                if ($pp['submit'] !== []) { return ['action' => 'submit', 'decision_id' => $pp['submit'][0], 'reason' => 'protect_filled_shares']; }
                if ($sellQty > 0 && $sellWindow) {
                    $pendingMarket = array_values(array_filter($bookActive, static fn ($i): bool => $i['leg'] !== 'protective_stop'));
                    if ($pendingMarket !== []) {
                        $pending = $pendingMarket[0];
                        if ($pending['status'] === 'planned' && $pending['side'] === 'sell') {
                            if (($pending['payload']['body']['time_in_force'] === 'opg' && $opg)
                                || ($pending['payload']['body']['time_in_force'] === 'day' && $daySell)) {
                                return ['action' => 'submit', 'decision_id' => $pending['decision_id'], 'reason' => 'recover_planned_exit'];
                            }
                            if ($daySell) { return ['action' => 'cancel', 'decision_id' => $pending['decision_id'], 'reason' => 'entry_window_expired']; }
                        }
                        continue;
                    }
                    $purpose = $plan['target']['risk_exit_pending'] || $plan['circuit_feedback']['force_cash'] ? 'circuit_exit' : 'rebalance_exit';
                    $tif = $opg ? 'opg' : 'day';
                    $intent = CandidateOrder::make($run, $name, $date, $session, $date . ':exit:' . $tif,
                        $purpose, $symbol, $sellQty, $tif);
                    $intent = $this->ledger->create($intent);
                    if (in_array($intent['status'], CandidateOrder::TERMINAL, true)) {
                        return ['action' => 'blocked', 'reason' => 'terminal_exit_requires_reconciliation'];
                    }
                    return ['action' => 'submit', 'decision_id' => $intent['decision_id'], 'reason' => $purpose];
                }
            }
        }
        if (($guards['paper_admission'] ?? null) !== true || ($guards['fresh_signal'] ?? null) !== true
            || ($guards['risk_capacity'] ?? null) !== true || ($guards['all_positions_protected'] ?? null) !== true) {
            return ['action' => 'blocked', 'reason' => 'new_risk_gate'];
        }
        if (($this->ledger->run($run)['status'] ?? null) !== 'active') { return ['action' => 'blocked', 'reason' => 'run_paused']; }
        if ($batch->start($run, $close, $window, $guards)) { return $batch->next($run, $close, $window, $guards); }
        return ['action' => 'wait', 'reason' => 'no_actionable_due_order'];
    }
}
