<?php
declare(strict_types=1);

use FulltimeTrading\Paper\CandidateLedger as Ledger;
use FulltimeTrading\Paper\CandidateOrder as Order;
use FulltimeTrading\Paper\CandidateExecutionPlan as Planner;
use FulltimeTrading\Trading\TacticalRotationExecutionWindow as Window;

require dirname(__DIR__) . '/bootstrap.php';
$n = 0; $check = static function (bool $ok, string $why) use (&$n): void { ++$n; if (!$ok) { throw new RuntimeException($why); } };
foreach (['2026-09-15', '2026-11-03'] as $session) {
    foreach (['filled', 'partial', 'unfilled', 'unsent_remainder', 'ambiguous', 'stale', 'no_initial_post'] as $scenario) {
        $path = tempnam(sys_get_temp_dir(), 'opg-boundary-');
        try {
            $ledger = new Ledger($path); $names = array_map(static fn ($i): string => 'sleeve_' . $i, range(0, 11));
            $ledger->provision(['run_id' => 'boundary', 'profile' => 'fixture', 'strategy_hash' => str_repeat('a', 64), 'runtime_hash' => str_repeat('b', 64),
                'data_contract' => ['execution_contract' => Order::CONTRACT, 'paper_only' => true]], array_fill_keys($names, 1 / 12));
            $ledger->activate('boundary', 27567.66, ['positions' => [], 'open_orders' => [], 'adoption' => 'flat_account_only', 'stable_for_seconds' => 120]);
            $plans = [];
            foreach ($names as $k => $name) { $plans[$name] = ['target_quantities' => $k < 4 ? ['MSFT' => ($k === 0 ? 9 : 3)] : null,
                'target' => ['risk_exit_pending' => false], 'circuit_feedback' => ['force_cash' => false, 'scale' => 1.]]; }
            $date = (new DateTimeImmutable($session))->modify('-1 day')->format('Y-m-d');
            $close = ['date' => $date, 'scheduled_session' => $session, 'plans' => $plans, 'provenance' => ['fixture' => true]];
            $ledger->saveCheckpoint('boundary', 'close:' . $date, 0, $close);
            $guards = array_fill_keys(['identity', 'account', 'reconciliation', 'paper_admission', 'fresh_signal', 'risk_capacity', 'all_positions_protected'], true);
            $window = static fn ($time, $open = false): array => (new Window())->resolve($session,
                new DateTimeImmutable($session . ' ' . $time, new DateTimeZone('America/New_York')), $date, $open);
            $next = static fn ($w, $g): array => (new Planner(new Ledger($path)))->next('boundary', $close, $w, $g);
            $orders = [];
            $count = match ($scenario) { 'unsent_remainder', 'ambiguous' => 1, 'no_initial_post' => 0, default => 4 };
            for ($i = 0; $i < $count; ++$i) {
                $a = $next($window('09:26:59'), $guards); $check($a['action'] === 'submit', 'Pre-cutoff submit is allowed.');
                $intent = $ledger->intent($a['decision_id']); $ledger->claim($a['decision_id']);
                $order = $intent['payload']['body'] + ['id' => 'fake-' . $i, 'status' => 'new', 'filled_qty' => '0', 'filled_avg_price' => null];
                $orders[$a['decision_id']] = $order;
                if ($scenario === 'ambiguous') { $ledger->ambiguous($a['decision_id']); }
                else { $ledger->observe($a['decision_id'], $order); }
            }
            foreach (['09:27:00', '09:27:28', '09:28:42', '09:29:59', '09:30:00', '09:31:59'] as $time) {
                $w = $window($time, $time >= '09:30');
                $check(!$w['opg_submit_allowed'], 'New OPG POST remains forbidden after cutoff.');
                $a = $next($w, $guards);
                $check(!in_array($a['action'], ['submit', 'cancel'], true), 'Resting OPG must survive cutoff/open without new POST or DELETE: ' . $scenario . ' ' . $time . ' got ' . json_encode($a));
                if ($scenario === 'ambiguous') { $check($a['action'] === 'refresh', 'Ambiguous POST stays lookup-only.'); }
            }
            if ($count === 0) { $check($ledger->active('boundary') === [], 'No late-created order.'); continue; }
            if ($scenario === 'ambiguous') {
                $id = array_key_first($orders); $ledger->observe($id, $orders[$id]);
            }
            if (in_array($scenario, ['filled', 'partial'], true)) {
                foreach ($orders as $id => $o) {
                    $qty = $scenario === 'partial' ? 1 : (int) $o['qty'];
                    $ledger->observe($id, array_replace($o, ['status' => $scenario === 'partial' ? 'partially_filled' : 'filled', 'filled_qty' => (string) $qty, 'filled_avg_price' => '506.00']));
                }
                $a = $next($window('09:31:59', true), $guards);
                $check($scenario === 'partial' ? $a['action'] === 'cancel' && $a['reason'] === 'partial_fill_protection'
                    : $a['action'] === 'submit' && $ledger->intent($a['decision_id'])['leg'] === 'protective_stop', 'Confirmed fills must transition to native protection.');
            } elseif ($scenario === 'stale') {
                $a = $next($window('09:31:59', true), array_replace($guards, ['fresh_signal' => false]));
                $check($a['action'] === 'cancel', 'Lost freshness still aborts outstanding entry.');
            } else {
                $a = $next($window('09:32:00', true), $guards);
                $check($a['action'] === 'cancel' && $a['reason'] === 'entry_window_expired', 'Bounded unfilled cleanup after auction, never unlimited resting or chasing.');
            }
        } finally {
            unset($ledger);
            foreach ([$path, $path . '-wal', $path . '-shm'] as $file) { if (is_file($file)) { unlink($file); } }
        }
    }
}
echo "OPG auction boundary: $n assertions PASS; 14 restartable scenarios, no broker access\n";
