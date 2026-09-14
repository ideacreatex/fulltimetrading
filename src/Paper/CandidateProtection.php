<?php

declare(strict_types=1);

namespace FulltimeTrading\Paper;

/** Close-updated 12% stop, not the broker's intraday high-water trailing-stop product. */
final readonly class CandidateProtection
{
    public function __construct(private CandidateLedger $ledger) { }

    /** Record only a completed session. Intraday quotes must never move this stop. */
    public function completedClose(string $run, string $sleeve, string $symbol, string $date, float $close): void
    {
        CandidateOrder::date($date);
        if (!is_finite($close) || $close <= 0) { throw new \InvalidArgumentException('Invalid completed close.'); }
        $scope = 'protection:' . $sleeve . ':' . $symbol;
        $checkpoint = $this->ledger->checkpoint($run, $scope);
        $state = $checkpoint['payload'] ?? [];
        if (isset($state['last_close_date']) && $date <= $state['last_close_date']) {
            if ($date !== $state['last_close_date'] || abs($close - $state['last_close']) > 1.0e-8) {
                throw new \RuntimeException('Candidate close checkpoint drift.');
            }
            return;
        }
        $position = $this->ledger->position($run, $sleeve, $symbol);
        if (CandidateOrder::quantity($position['qty'], true) === 0) { return; }
        $state['peak_close'] = max((float) ($state['peak_close'] ?? ((float) $position['cost_basis'] / $position['qty'])), $close);
        $state['last_close_date'] = $date; $state['last_close'] = $close;
        $this->ledger->saveCheckpoint($run, $scope, (int) ($checkpoint['version'] ?? 0), $state);
    }

    /** Returns intents to submit/cancel; no broker mutation occurs here. */
    public function plan(string $run, string $sleeve, string $symbol, string $signalDate, string $session, bool $exitPending = false, int $retainQuantity = 0): array
    {
        CandidateOrder::date($signalDate); CandidateOrder::date($session);
        $position = $this->ledger->position($run, $sleeve, $symbol);
        $owned = CandidateOrder::quantity($position['qty'], true);
        if ($retainQuantity < 0 || $retainQuantity > $owned) { throw new \InvalidArgumentException('Invalid retained stop quantity.'); }
        $active = array_values(array_filter($this->ledger->active($run, $sleeve), static fn ($i): bool => $i['symbol'] === $symbol));
        $stops = array_values(array_filter($active, static fn ($i): bool => $i['leg'] === 'protective_stop'));
        $latch = $this->ledger->checkpoint($run, 'stop_latch:' . $sleeve);
        if ($exitPending || ($latch['payload']['pending'] ?? false) === true) {
            // Stop election cancels outstanding entry risk, but remaining shares stay protected
            // until the execution window permits a confirmed sequential exit.
            $buys = array_values(array_filter($active, static fn ($i): bool => $i['side'] === 'buy'));
            if ($buys !== []) { return ['status' => 'cancel_entry_before_exit', 'cancel' => array_column($buys, 'decision_id'), 'submit' => []]; }
        }
        $scope = 'protection:' . $sleeve . ':' . $symbol;
        $checkpoint = $this->ledger->checkpoint($run, $scope);
        $state = $checkpoint['payload'] ?? [];
        if ($owned === 0) {
            if ($stops !== []) { return ['status' => 'cancel_stale_protection', 'cancel' => array_column($stops, 'decision_id'), 'submit' => []]; }
            if ($state !== []) { $this->ledger->saveCheckpoint($run, $scope, (int) $checkpoint['version'], []); }
            return ['status' => 'flat', 'cancel' => [], 'submit' => []];
        }
        if ($exitPending) {
            $stopReserved = array_sum(array_map(static fn ($i): int => CandidateOrder::quantity($i['requested_qty'])
                - CandidateOrder::quantity($i['cumulative_filled_qty'], true), $stops));
            if ($stopReserved > $retainQuantity) {
                return ['status' => 'cancel_stops_before_exit', 'cancel' => array_column($stops, 'decision_id'), 'submit' => []];
            }
            if ($retainQuantity === 0) { return ['status' => 'exit_unreserved', 'cancel' => [], 'submit' => []]; }
        }
        $peak = (float) ($state['peak_close'] ?? ((float) $position['cost_basis'] / $owned));
        if ($state === []) {
            $state = ['peak_close' => $peak];
            $this->ledger->saveCheckpoint($run, $scope, (int) ($checkpoint['version'] ?? 0), $state);
        }
        $stopPrice = CandidateOrder::stopPrice($peak * .88);
        $cancel = []; $reserved = $protected = 0; $submit = [];
        foreach ($active as $i) {
            if ($i['side'] !== 'sell') { continue; }
            $remaining = CandidateOrder::quantity($i['requested_qty']) - CandidateOrder::quantity($i['cumulative_filled_qty'], true);
            $reserved += $remaining;
            if ($i['leg'] !== 'protective_stop') { continue; }
            if ((float) $i['payload']['body']['stop_price'] + 1.0e-8 < (float) $stopPrice) { $cancel[] = $i['decision_id']; }
            elseif ($i['status'] === 'planned') { $submit[] = $i['decision_id']; }
            elseif (!isset($i['payload']['cancel_request']) && in_array($i['status'], ['new', 'accepted', 'partially_filled', 'done_for_day'], true)) {
                $protected += $remaining;
            }
        }
        if ($reserved > $owned) { throw new \RuntimeException('Candidate protection reservations exceed ownership.'); }
        $protectionReserved = array_sum(array_map(static fn ($i): int => CandidateOrder::quantity($i['requested_qty'])
            - CandidateOrder::quantity($i['cumulative_filled_qty'], true), $stops));
        $requiredProtection = $exitPending ? $retainQuantity : $owned;
        $unreserved = min($owned - $reserved, max(0, $requiredProtection - $protectionReserved));
        if ($unreserved > 0) {
            $history = $this->ledger->orders($run, $sleeve);
            $sequence = count(array_filter($history, static fn ($i): bool => $i['leg'] === 'protective_stop')) + 1;
            $intent = CandidateOrder::make($run, $sleeve, $signalDate, $session, 'stop:' . $sequence,
                'protective_stop', $symbol, $unreserved, 'gtc', (float) $stopPrice);
            $submit[] = $this->ledger->create($intent)['decision_id'];
        }
        return ['status' => $protected === $requiredProtection && $cancel === [] ? ($exitPending ? 'exit_unreserved' : 'protected') : 'protection_pending',
            'owned_qty' => $owned, 'protected_qty' => $protected, 'stop_price' => $stopPrice, 'cancel' => $cancel, 'submit' => $submit];
    }
}
