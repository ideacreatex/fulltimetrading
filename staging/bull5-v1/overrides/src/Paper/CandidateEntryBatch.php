<?php

declare(strict_types=1);

namespace FulltimeTrading\Paper;

/** Persists the cancel/entry/protect sequence required by Alpaca's account-wide opposite-order rule. */
final readonly class CandidateEntryBatch
{
    public function __construct(private CandidateLedger $ledger) { }

    public function abort(string $run, string $reason): void
    {
        $cp = $this->ledger->checkpoint($run, 'entry_batch');
        if (($cp['payload'] ?? []) === []) { return; }
        $state = $cp['payload']; $state['aborted'] = $reason;
        $this->ledger->saveCheckpoint($run, 'entry_batch', $cp['version'], $state);
    }

    public function active(string $run): bool { return ($this->ledger->checkpoint($run, 'entry_batch')['payload'] ?? []) !== []; }

    /** Starts only from protected/reconciled ownership. The exact request set is frozen before any stop cancellation. */
    public function start(string $run, array $close, array $window, array $guards): bool
    {
        if ($this->active($run)) { return true; }
        foreach (['identity', 'account', 'reconciliation', 'paper_admission', 'fresh_signal', 'risk_capacity', 'all_positions_protected'] as $gate) {
            if (($guards[$gate] ?? null) !== true) { return false; }
        }
        if (($this->ledger->run($run)['status'] ?? null) !== 'active') { return false; }
        $opg = ($window['opg_submit_allowed'] ?? false) === true;
        $day = ($window['rotation_reentry_allowed'] ?? false) === true;
        if (!$opg && !$day) { return false; }
        $positions = $this->ledger->views->positions($run); $active = $this->ledger->active($run); $requests = [];
        foreach ($close['plans'] as $name => $plan) {
            $target = $plan['target_quantities'];
            if ($target === null || $target === [] || $plan['circuit_feedback']['force_cash'] || $plan['target']['risk_exit_pending']) { continue; }
            $symbol = array_key_first($target); $owned = CandidateOrder::quantity($positions[$name][$symbol]['qty'] ?? 0, true);
            if ($owned >= $target[$symbol] || array_diff(array_keys($positions[$name] ?? []), [$symbol]) !== []) { continue; }
            if (($this->ledger->checkpoint($run, 'stop_latch:' . $name)['payload']['pending'] ?? false) === true) { continue; }
            $conflicting = array_filter($active, static fn ($i): bool => $i['leg'] !== 'protective_stop'
                && ($i['sleeve_id'] === $name || ($i['symbol'] === $symbol && $i['side'] === 'sell')));
            if ($conflicting !== []) { continue; }
            $samePlan = array_values(array_filter($this->ledger->orders($run, $name), static fn ($i): bool => $i['signal_date'] === $close['date'] && $i['leg'] !== 'protective_stop'));
            if (array_filter($samePlan, static fn ($i): bool => $i['side'] === 'buy') !== []) { continue; }
            $sells = array_values(array_filter($samePlan, static fn ($i): bool => $i['side'] === 'sell'));
            $sold = $sells !== [] && count(array_filter($sells, static fn ($i): bool => $i['status'] === 'filled')) === count($sells);
            if (!$opg && !$sold) { continue; }
            $requests[$name] = CandidateOrder::make($run, $name, $close['date'], $close['scheduled_session'], $close['date'] . ':entry',
                'entry', $symbol, (int) $target[$symbol] - $owned, $opg ? 'opg' : 'day');
        }
        if ($requests === []) { return false; }
        $symbols = array_values(array_unique(array_column($requests, 'symbol')));
        $hasHeld = false;
        foreach ($positions as $book) { if (array_intersect(array_keys($book), $symbols) !== []) { $hasHeld = true; } }
        // Do not remove overnight protection hours before the auction. Calendar/time comes from the caller's fresh broker clock.
        if ($hasHeld && $opg && ($window['candidate_preopen_stop_transition_allowed'] ?? false) !== true) { return false; }
        $cp = $this->ledger->checkpoint($run, 'entry_batch');
        $state = ['date' => $close['date'], 'session' => $close['scheduled_session'], 'symbols' => $symbols,
            'requests' => $requests, 'phase' => 'clearing', 'aborted' => null, 'started_at' => gmdate(DATE_ATOM),
            'close_sha256' => hash('sha256', CandidateOrder::json($close))];
        $this->ledger->saveCheckpoint($run, 'entry_batch', (int) ($cp['version'] ?? 0), $state);
        return true;
    }

    public function next(string $run, array $close, array $window, array $guards): ?array
    {
        $cp = $this->ledger->checkpoint($run, 'entry_batch'); $state = $cp['payload'] ?? [];
        if ($state === []) { return null; }
        foreach (['identity', 'account', 'reconciliation'] as $gate) {
            if (($guards[$gate] ?? null) !== true) { return ['action' => 'blocked', 'reason' => $gate]; }
        }
        $active = $this->ledger->active($run);
        $buyIds = array_column($state['requests'], 'decision_id');
        $buys = array_values(array_filter($active, static fn ($i): bool => in_array($i['decision_id'], $buyIds, true)));
        $restingOpg = self::mayAwaitAuction($buys, $window);
        $inWindow = (($window['opg_submit_allowed'] ?? false) || ($window['rotation_reentry_allowed'] ?? false) || $restingOpg)
            && $window['scheduled_session'] === $state['session'];
        if (!$inWindow || $close['date'] !== $state['date'] || hash('sha256', CandidateOrder::json($close)) !== $state['close_sha256']
            || ($this->ledger->run($run)['status'] ?? null) !== 'active'
            || ($guards['fresh_signal'] ?? false) !== true || ($guards['paper_admission'] ?? false) !== true || ($guards['risk_capacity'] ?? false) !== true) {
            $state['aborted'] ??= 'entry_admission_or_window_closed';
        }
        foreach ($buys as $i) {
            if (in_array($i['status'], ['submitting', 'ambiguous'], true)) {
                return ['action' => 'refresh', 'decision_id' => $i['decision_id'], 'reason' => 'batch_ambiguous_lookup_only'];
            }
        }
        foreach ($state['requests'] as $name => $_) {
            if (($this->ledger->checkpoint($run, 'stop_latch:' . $name)['payload']['pending'] ?? false) === true) {
                $state['aborted'] ??= 'standing_stop_filled_during_entry_transition';
            }
        }
        $observedFill = false;
        foreach ($buyIds as $id) { $observedFill = $observedFill || (float) ($this->ledger->intent($id)['cumulative_filled_qty'] ?? 0) > 0; }
        // At the first confirmed fill, finish or cancel every buy before placing any opposite stop.
        if ($observedFill || $state['aborted'] !== null || $state['phase'] === 'protecting') {
            $state['phase'] = 'protecting'; $this->save($run, $cp, $state);
            foreach ($buys as $i) {
                return ['action' => 'cancel', 'decision_id' => $i['decision_id'],
                    'reason' => $observedFill ? 'partial_fill_protection' : 'entry_window_expired'];
            }
            $positions = $this->ledger->views->positions($run); $protection = new CandidateProtection($this->ledger);
            foreach ($positions as $name => $book) {
                foreach ($book as $symbol => $_) {
                    if (!in_array($symbol, $state['symbols'], true)) { continue; }
                    $p = $protection->plan($run, $name, $symbol, $close['date'], $close['scheduled_session']);
                    if ($p['cancel'] !== []) { return ['action' => 'cancel', 'decision_id' => $p['cancel'][0], 'reason' => 'close_stop_update']; }
                    if ($p['submit'] !== []) { return ['action' => 'submit', 'decision_id' => $p['submit'][0], 'reason' => 'protect_filled_shares']; }
                    if ($p['status'] !== 'protected') { return ['action' => 'wait', 'reason' => 'batch_protection_ack_pending']; }
                }
            }
            $this->ledger->saveCheckpoint($run, 'entry_batch', $cp['version'], []);
            return ['action' => 'wait', 'reason' => 'entry_batch_completed'];
        }
        if ($state['phase'] === 'clearing') {
            foreach ($active as $i) {
                if (in_array($i['symbol'], $state['symbols'], true) && $i['side'] === 'sell') {
                    if ($i['leg'] !== 'protective_stop') { $this->abort($run, 'unexpected_opposite_market_exit'); return ['action' => 'wait', 'reason' => 'batch_abort']; }
                    return ['action' => 'cancel', 'decision_id' => $i['decision_id'], 'reason' => 'entry_batch'];
                }
            }
            $state['phase'] = 'entering'; $this->save($run, $cp, $state);
        }
        foreach ($state['requests'] as $request) {
            $intent = $this->ledger->intent($request['decision_id']);
            if ($intent !== null && $intent['status'] !== 'planned') { continue; }
            $tif = $request['payload']['body']['time_in_force'];
            // A missing remainder must not revoke accepted auction orders or turn into a late POST.
            if ($tif === 'opg' && $restingOpg && !($window['opg_submit_allowed'] ?? false)) { continue; }
            if (($tif === 'opg' && !($window['opg_submit_allowed'] ?? false)) || ($tif === 'day' && !($window['rotation_reentry_allowed'] ?? false))) {
                $this->abort($run, 'frozen_entry_time_in_force_expired'); return ['action' => 'wait', 'reason' => 'batch_abort'];
            }
            $intent ??= $this->ledger->create($request);
            return ['action' => 'submit', 'decision_id' => $intent['decision_id'], 'reason' => 'scheduled_entry'];
        }
        if ($buys === []) { $this->abort($run, 'batch_no_fills'); }
        return ['action' => 'wait', 'reason' => 'entry_batch_waiting_for_broker'];
    }

    /** Submission cutoff is not expiry of an already accepted opening-auction order. */
    private static function mayAwaitAuction(array $buys, array $window): bool
    {
        foreach (['resolved_at', 'opg_expires_at', 'rotation_reentry_expires_at'] as $key) {
            if (!is_string($window[$key] ?? null)) { return false; }
        }
        $now = new \DateTimeImmutable($window['resolved_at']);
        $cutoff = new \DateTimeImmutable($window['opg_expires_at']);
        $deadline = new \DateTimeImmutable($window['rotation_reentry_expires_at']);
        if ($now < $cutoff || $now >= $deadline || $cutoff >= $deadline) { return false; }
        foreach ($buys as $i) {
            if ((int) $i['attempt_count'] === 1 && $i['scheduled_session'] === $window['scheduled_session']
                && ($i['payload']['body']['time_in_force'] ?? null) === 'opg') { return true; }
        }
        return false;
    }

    private function save(string $run, array &$cp, array $state): void
    {
        if ($state === $cp['payload']) { return; }
        $this->ledger->saveCheckpoint($run, 'entry_batch', $cp['version'], $state);
        $cp = $this->ledger->checkpoint($run, 'entry_batch');
    }
}
