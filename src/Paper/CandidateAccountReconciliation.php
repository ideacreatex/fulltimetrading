<?php

declare(strict_types=1);

namespace FulltimeTrading\Paper;

final class CandidateAccountReconciliation
{
    public static function inspect(array $expected, float $ledgerCash, array $account, array $positions, array $openOrders, array $intents): array
    {
        $errors = []; $actual = []; $ordersByClient = [];
        foreach (['equity', 'cash', 'buying_power'] as $field) {
            if (!is_numeric($account[$field] ?? null) || !is_finite((float) $account[$field])) { $errors[] = 'invalid_account_' . $field; }
        }
        if ($errors !== []) { return ['ok' => false, 'errors' => $errors]; }
        if ((float) $account['equity'] <= 0) { $errors[] = 'invalid_account_capacity'; }
        if (!is_finite($ledgerCash) || abs((float) $account['cash'] - $ledgerCash) > .02) { $errors[] = 'unreconciled_cash_activity'; }
        foreach ($positions as $p) {
            $symbol = $p['symbol'] ?? null;
            if (!is_string($symbol) || !preg_match('/^[A-Z][A-Z0-9.-]{0,14}$/D', $symbol) || isset($actual[$symbol]) || ($p['side'] ?? null) !== 'long') {
                $errors[] = 'invalid_broker_position'; continue;
            }
            try { $actual[$symbol] = CandidateOrder::quantity($p['qty'] ?? null); }
            catch (\Throwable) { $errors[] = 'nonwhole_or_short_position:' . $symbol; }
        }
        foreach ($expected as $symbol => $qty) {
            try { $expected[$symbol] = CandidateOrder::quantity($qty); }
            catch (\Throwable) { $errors[] = 'invalid_ledger_position:' . $symbol; }
        }
        ksort($expected); ksort($actual);
        if ($expected !== $actual) { $errors[] = 'broker_ledger_position_mismatch'; }
        // The shared client's endpoint has a 50-order default. Never assume a full page is complete.
        if (count($openOrders) >= 50) { $errors[] = 'open_order_page_may_be_truncated'; }
        $owned = [];
        foreach ($intents as $i) { CandidateOrder::assert($i); $owned[$i['client_order_id']] = $i; }
        foreach ($openOrders as $order) {
            $client = $order['client_order_id'] ?? '';
            if (!is_string($client) || isset($ordersByClient[$client]) || !isset($owned[$client])) { $errors[] = 'unowned_or_duplicate_open_order'; continue; }
            $ordersByClient[$client] = $order; $i = $owned[$client];
            if ((int) $i['attempt_count'] !== 1 || $i['order_id'] !== ($order['id'] ?? null)
                || in_array($i['status'], CandidateOrder::TERMINAL, true)) { $errors[] = 'unreconciled_open_order:' . substr($client, 0, 16); }
            foreach (['symbol', 'side', 'type', 'time_in_force', 'extended_hours'] as $field) {
                if (($order[$field] ?? null) !== $i['payload']['body'][$field]) { $errors[] = 'open_order_body_drift:' . $field; }
            }
            foreach (['qty' => 'requested_qty', 'filled_qty' => 'cumulative_filled_qty'] as $field => $stored) {
                try {
                    if (CandidateOrder::quantity($order[$field] ?? null, $field === 'filled_qty') !== CandidateOrder::quantity($i[$stored], $field === 'filled_qty')) {
                        $errors[] = 'open_order_fill_race';
                    }
                } catch (\Throwable) { $errors[] = 'invalid_open_order_quantity'; }
            }
            if (isset($i['payload']['body']['stop_price']) && (!is_numeric($order['stop_price'] ?? null)
                || abs((float) $order['stop_price'] - (float) $i['payload']['body']['stop_price']) > 1e-8)) { $errors[] = 'open_stop_price_drift'; }
        }
        foreach ($owned as $client => $i) {
            if ((int) $i['attempt_count'] > 0 && !in_array($i['status'], CandidateOrder::TERMINAL, true)
                && !isset($ordersByClient[$client])) { $errors[] = 'claimed_order_missing_from_snapshot:' . substr($client, 0, 16); }
        }
        return ['ok' => $errors === [], 'errors' => array_values(array_unique($errors)), 'positions' => $actual,
            'cash_difference' => (float) $account['cash'] - $ledgerCash, 'open_orders' => count($openOrders)];
    }
}
