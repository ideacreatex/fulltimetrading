<?php
declare(strict_types=1);

namespace FulltimeTrading\Research;

use FulltimeTrading\Paper\CandidateAccountReconciliation;
use FulltimeTrading\Paper\CandidateOrder;

/** Read-only observations, never an execution gate or a repair mechanism. */
final class PaperForwardExecutionAudit
{
    public static function inspect(array $s): array
    {
        $errors = []; $coverage = []; $byClient = []; $expected = []; $net = []; $cash = [];
        if (($s['observation_complete'] ?? false) !== true || ($s['ledger_stable'] ?? false) !== true) {
            return ['status' => 'unconfirmed', 'ok' => false, 'errors' => ['incomplete_or_moving_snapshot'], 'coverage' => [], 'orders_submitted' => 0];
        }
        if ($s['sleeves'] === []) { $errors[] = 'missing_sleeve_capital'; }
        foreach ($s['sleeves'] as $sleeve) {
            if (isset($cash[$sleeve['sleeve_id']]) || !is_numeric($sleeve['initial_equity'])
                || !is_finite((float) $sleeve['initial_equity']) || $sleeve['initial_equity'] <= 0) { $errors[] = 'invalid_sleeve_capital'; }
            $cash[$sleeve['sleeve_id']] = (float) $sleeve['initial_equity'];
        }
        foreach ($s['intents'] as $i) {
            CandidateOrder::assert($i);
            if (isset($byClient[$i['client_order_id']])) { $errors[] = 'duplicate_client_identity'; }
            $byClient[$i['client_order_id']] = $i;
            $q = CandidateOrder::quantity($i['cumulative_filled_qty'], true);
            if ($q > CandidateOrder::quantity($i['requested_qty']) || !is_numeric($i['attempt_count']) || !in_array((float) $i['attempt_count'], [0., 1.], true)
                || ($q > 0 && (int) $i['attempt_count'] !== 1)) { $errors[] = 'invalid_submission_or_fill_count'; }
            if (!isset($cash[$i['sleeve_id']])) { $errors[] = 'unowned_sleeve'; continue; }
            if (!is_numeric($i['cumulative_fill_notional']) || !is_finite((float) $i['cumulative_fill_notional'])
                || ($q > 0 && $i['cumulative_fill_notional'] <= 0) || ($q === 0 && (float) $i['cumulative_fill_notional'] !== 0.)) {
                $errors[] = 'invalid_fill_notional'; continue;
            }
            $sign = $i['side'] === 'buy' ? 1 : -1; $key = $i['sleeve_id'] . ':' . $i['symbol'];
            $net[$key] = ($net[$key] ?? 0) + $sign * $q;
            $cash[$i['sleeve_id']] -= $sign * (float) $i['cumulative_fill_notional'];
            if (($i['status'] === 'filled' && $q !== (int) $i['requested_qty']) || ($q > 0 && $i['status'] === 'planned')) {
                $errors[] = 'invalid_fill_status';
            }
        }
        $owned = [];
        foreach ($s['owned_positions'] as $p) {
            $qty = CandidateOrder::quantity($p['qty'], true); $key = $p['sleeve_id'] . ':' . $p['symbol'];
            if (isset($owned[$key])) { $errors[] = 'duplicate_owned_position'; }
            $owned[$key] = $qty;
            if ($qty === 0) { continue; }
            if (!is_numeric($p['cost_basis']) || !is_finite((float) $p['cost_basis']) || $p['cost_basis'] <= 0) {
                $errors[] = 'invalid_owned_cost_basis'; continue;
            }
            $expected[$p['symbol']] = ($expected[$p['symbol']] ?? 0) + $qty;
            $peak = (float) ($s['checkpoints']['protection:' . $key]['peak_close'] ?? ($p['cost_basis'] / $qty));
            $coverage[$key] = ['symbol' => $p['symbol'], 'owned_qty' => $qty, 'acknowledged_qty' => 0,
                'minimum_stop_price' => CandidateOrder::stopPrice($peak * .88), 'stops' => []];
        }
        foreach (array_unique([...array_keys($net), ...array_keys($owned)]) as $key) {
            if (($net[$key] ?? 0) !== ($owned[$key] ?? 0)) { $errors[] = 'fill_ownership_mismatch:' . $key; }
        }
        foreach ($s['sleeves'] as $sleeve) {
            if (!is_numeric($sleeve['cash']) || !is_finite((float) $sleeve['cash'])
                || abs($cash[$sleeve['sleeve_id']] - (float) $sleeve['cash']) > .02) { $errors[] = 'fill_cash_mismatch:' . $sleeve['sleeve_id']; }
        }
        $active = array_values(array_filter($s['intents'], static fn ($i): bool => !in_array($i['status'], CandidateOrder::TERMINAL, true)));
        $reconciliation = CandidateAccountReconciliation::inspect($expected, array_sum(array_column($s['sleeves'], 'cash')),
            $s['account'], $s['positions'], $s['open_orders'], $active);
        $errors = [...$errors, ...$reconciliation['errors']];
        foreach ($s['broker_order_observations'] as $client => $order) {
            $i = $byClient[$client] ?? null;
            if ($i === null || $order === null || ($order['id'] ?? null) !== $i['order_id']
                || ($order['client_order_id'] ?? null) !== $client) { $errors[] = 'broker_order_lookup_unconfirmed'; continue; }
            foreach (['symbol', 'side', 'type', 'time_in_force', 'extended_hours'] as $field) {
                if (($order[$field] ?? null) !== $i['payload']['body'][$field]) { $errors[] = 'broker_order_body_drift:' . $field; }
            }
            if (CandidateOrder::quantity($order['qty'] ?? null) !== (int) $i['requested_qty']) { $errors[] = 'broker_order_quantity_drift'; }
            if (isset($i['payload']['body']['stop_price']) && (!is_numeric($order['stop_price'] ?? null)
                || abs((float) $order['stop_price'] - (float) $i['payload']['body']['stop_price']) > 1e-8)) { $errors[] = 'broker_order_stop_drift'; }
            $q = CandidateOrder::quantity($order['filled_qty'] ?? null, true);
            if ($q !== (int) $i['cumulative_filled_qty'] || ($order['status'] ?? null) !== $i['status']) { $errors[] = 'broker_order_observation_race'; }
            if ($q > 0 && (!is_numeric($order['filled_avg_price'] ?? null)
                || abs($q * (float) $order['filled_avg_price'] - (float) $i['cumulative_fill_notional']) > .02)) { $errors[] = 'broker_fill_notional_mismatch'; }
        }
        foreach ($s['open_orders'] as $order) {
            $i = $byClient[$order['client_order_id'] ?? ''] ?? null;
            if ($i === null || $i['leg'] !== 'protective_stop' || isset($i['payload']['cancel_request'])
                || !in_array($i['status'], ['new', 'accepted', 'partially_filled', 'done_for_day'], true)
                || !in_array($order['status'] ?? '', ['new', 'accepted', 'partially_filled', 'done_for_day'], true)) { continue; }
            $key = $i['sleeve_id'] . ':' . $i['symbol'];
            if (!isset($coverage[$key])) { $errors[] = 'stop_without_owned_position'; continue; }
            if (!is_numeric($order['stop_price'] ?? null) || (float) $order['stop_price'] + 1e-8 < (float) $coverage[$key]['minimum_stop_price']) {
                $errors[] = 'stop_below_close_based_requirement:' . $key; continue;
            }
            $coverage[$key]['acknowledged_qty'] += CandidateOrder::quantity($order['qty']) - CandidateOrder::quantity($order['filled_qty'], true);
            $coverage[$key]['stops'][] = ['order_id' => $order['id'], 'stop_price' => $order['stop_price'], 'status' => $order['status']];
        }
        foreach ($coverage as $key => &$row) {
            $row['uncovered_qty'] = max(0, $row['owned_qty'] - $row['acknowledged_qty']);
            if ($row['uncovered_qty'] > 0) { $errors[] = 'native_protection_pending:' . $key; }
            if ($row['acknowledged_qty'] > $row['owned_qty']) { $errors[] = 'native_protection_exceeds_ownership:' . $key; }
        }
        unset($row);
        $errors = array_values(array_unique($errors));
        return ['status' => $errors !== [] ? 'attention' : ($coverage === [] ? 'flat_or_waiting' : 'positions_protected'),
            'ok' => $errors === [], 'errors' => $errors, 'coverage' => $coverage, 'reconciliation' => $reconciliation,
            'filled_buy_shares' => array_sum(array_map(static fn ($i): int => $i['side'] === 'buy' ? (int) $i['cumulative_filled_qty'] : 0, $s['intents'])),
            'open_order_count' => count($s['open_orders']), 'direct_order_lookups' => count($s['broker_order_observations']),
            'orders_submitted' => 0, 'limitations' => 'Sequential broker GETs are not atomic; retry attention before diagnosing. A resting stop does not guarantee execution price or extended-hours protection.'];
    }
}
