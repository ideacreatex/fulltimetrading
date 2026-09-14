<?php

declare(strict_types=1);

use FulltimeTrading\Paper\CandidateLedger as Ledger;
use FulltimeTrading\Paper\CandidateOrder as Order;
use FulltimeTrading\Paper\CandidateExecutionPlan as Planner;
use FulltimeTrading\Paper\CandidateAccountReconciliation as Reconciliation;
use FulltimeTrading\Trading\TacticalRotationExecutionWindow as Window;

require dirname(__DIR__) . '/bootstrap.php';
set_error_handler(static function (int $n, string $s): never { throw new RuntimeException($s); });
$n = 0; $check = static function (bool $ok, string $why) use (&$n): void { ++$n; if (!$ok) { throw new RuntimeException($why); } };
$path = tempnam(sys_get_temp_dir(), 'candidate-plan-'); $broker = []; $counter = 0;
try {
    $ledger = new Ledger($path); $alloc = array_fill_keys(array_map(static fn ($i): string => 'sleeve_' . $i, range(0, 11)), 1 / 12);
    $ledger->provision(['run_id' => 'plan-test', 'profile' => 'candidate', 'strategy_hash' => str_repeat('a', 64), 'runtime_hash' => str_repeat('b', 64),
        'data_contract' => ['execution_contract' => Order::CONTRACT, 'paper_only' => true]], $alloc);
    $ledger->activate('plan-test', 30000., ['positions' => [], 'open_orders' => [], 'adoption' => 'flat_account_only', 'stable_for_seconds' => 120]);
    $planner = new Planner($ledger);
    $guards = array_fill_keys(['identity', 'account', 'reconciliation', 'paper_admission', 'fresh_signal', 'risk_capacity', 'all_positions_protected'], true);
    $window = static fn ($date, $signal, $at, $open = false): array => (new Window())->resolve($date, new DateTimeImmutable($at, new DateTimeZone('America/New_York')), $signal, $open);
    $freeze = static function (string $date, string $session, ?array $quantities, string $sleeve = 'sleeve_0') use ($ledger, $alloc): array {
        $plans = [];
        foreach ($alloc as $name => $_) { $plans[$name] = ['target_quantities' => $name === $sleeve ? $quantities : null,
            'target' => ['risk_exit_pending' => false], 'circuit_feedback' => ['force_cash' => false, 'scale' => 1.0]]; }
        $close = ['date' => $date, 'scheduled_session' => $session, 'plans' => $plans, 'provenance' => ['fixture' => true]];
        $ledger->saveCheckpoint('plan-test', 'close:' . $date, 0, $close); return $close;
    };
    $submit = static function (array $action, string $status = 'new', int $qty = 0, ?float $price = null) use ($ledger, &$broker, &$counter, $check): array {
        $check($action['action'] === 'submit', 'Expected a bounded submit action.');
        $id = $action['decision_id']; $i = $ledger->intent($id);
        \FulltimeTrading\Paper\CandidateBrokerOrderPolicy::assertCompatible($i, $ledger->active('plan-test'));
        $check($ledger->claim($id), 'Persisted submission claim.');
        $broker[$id] = $i['payload']['body'] + ['id' => 'fake-' . ++$counter, 'status' => $status, 'filled_qty' => (string) $qty,
            'filled_avg_price' => $price === null ? null : (string) $price];
        return $ledger->observe($id, $broker[$id]);
    };
    $observe = static function (string $id, string $status, int $qty, ?float $price) use ($ledger, &$broker): void {
        $broker[$id] = array_replace($broker[$id], ['status' => $status, 'filled_qty' => (string) $qty, 'filled_avg_price' => $price === null ? null : (string) $price]);
        $ledger->observe($id, $broker[$id]);
    };
    $close = $freeze('2026-09-14', '2026-09-15', ['AAA' => 10]);
    $w = $window('2026-09-15', '2026-09-14', '2026-09-15 09:20');
    $check($planner->next('plan-test', $close, $w, array_replace($guards, ['account' => false]))['action'] === 'blocked', 'Account guard blocks all mutations.');
    $action = $planner->next('plan-test', $close, $w, $guards);
    $check($ledger->intent($action['decision_id'])['payload']['body']['time_in_force'] === 'opg', 'First cash entry is OPG.');
    $check($planner->next('plan-test', $close, $w, $guards)['decision_id'] === $action['decision_id'], 'Restart before POST preserves the entry id.');
    $entry = $submit($action, 'partially_filled', 3, 100.);
    $protection = $planner->next('plan-test', $close, $w, array_replace($guards, ['fresh_signal' => false]));
    $check($protection['action'] === 'cancel' && $protection['reason'] === 'partial_fill_protection', 'Partial entry remainder is canceled before the opposing protective stop.');
    $ledger->requestCancel($entry['decision_id'], 'partial_fill_protection');
    // The broker may complete the entry while its cancellation is in flight.
    $observe($entry['decision_id'], 'filled', 10, 100.);
    $protection = $planner->next('plan-test', $close, $w, array_replace($guards, ['fresh_signal' => false]));
    $check($protection['reason'] === 'protect_filled_shares', 'Stale entry data cannot suppress protection after the buy is terminal.');
    $stop1 = $submit($protection);
    $check($planner->next('plan-test', $close, $w, $guards)['action'] === 'wait', 'Completed target is not bought again.');
    $expected = $ledger->views->expectedBrokerPositions('plan-test');
    $cash = array_sum(array_column($ledger->views->sleeves('plan-test'), 'cash'));
    $open = array_values(array_filter($broker, static fn ($o): bool => !in_array($o['status'], Order::TERMINAL, true)));
    $account = ['equity' => '30000', 'cash' => (string) $cash, 'buying_power' => '50000'];
    $positions = [['symbol' => 'AAA', 'qty' => '10', 'side' => 'long']];
    $reconciliation = Reconciliation::inspect($expected, $cash, $account, $positions, $open, $ledger->active('plan-test'));
    $check($reconciliation['ok'], 'Confirmed fills, cash and native stops reconcile.');
    $check(!Reconciliation::inspect($expected, $cash, array_replace($account, ['cash' => '123']), $positions, $open, $ledger->active('plan-test'))['ok'], 'Unexplained cash changes are not silently allocated.');
    $unknown = $open[0]; $unknown['client_order_id'] = 'foreign';
    $check(!Reconciliation::inspect($expected, $cash, $account, $positions, [$unknown], $ledger->active('plan-test'))['ok'], 'Foreign open orders block ownership reconciliation.');
    $close = $freeze('2026-09-15', '2026-09-16', ['BBB' => 5]);
    $w = $window('2026-09-16', '2026-09-15', '2026-09-16 09:20');
    foreach ([$stop1] as $_) {
        $a = $planner->next('plan-test', $close, $w, $guards);
        $check($a['action'] === 'cancel', 'Rotation cancels only owned stops before selling.');
        $ledger->requestCancel($a['decision_id'], $a['reason']);
        $check($planner->next('plan-test', $close, $w, $guards)['action'] === 'cancel', 'Unconfirmed cancel cannot free the shares.');
        $observe($a['decision_id'], 'canceled', 0, null);
    }
    $exit = $planner->next('plan-test', $close, $w, $guards);
    $check($planner->next('plan-test', $close, $w, $guards)['decision_id'] === $exit['decision_id'], 'Planned market exit recovers after restart.');
    $sell = $submit($exit, 'filled', 10, 102.);
    $check($sell['side'] === 'sell' && $sell['symbol'] === 'AAA', 'Sell original holding first.');
    $w = $window('2026-09-16', '2026-09-15', '2026-09-16 09:31', true);
    $replacement = $planner->next('plan-test', $close, $w, $guards);
    $buy = $submit($replacement, 'filled', 5, 80.);
    $check($buy['symbol'] === 'BBB' && $buy['payload']['body']['time_in_force'] === 'day', 'Replacement DAY entry follows confirmed exit inside 09:32 cutoff.');
    $stop3 = $submit($planner->next('plan-test', $close, $w, $guards));
    $planner->next('plan-test', $close, $w, $guards);
    $check($stop3['payload']['body']['stop_price'] === '70.40', 'Protect replacement using its actual fill.');
    $close = $freeze('2026-09-16', '2026-09-17', ['AAA' => 3], 'sleeve_1');
    $w = $window('2026-09-17', '2026-09-16', '2026-09-17 09:34', true);
    $check($planner->next('plan-test', $close, $w, $guards)['action'] === 'wait', 'No late cash-entry chase in the risk-only window.');
    $w = $window('2026-09-17', '2026-09-16', '2026-09-17 09:20');
    $check($planner->next('plan-test', $close, $w, array_replace($guards, ['paper_admission' => false]))['action'] === 'blocked', 'Paper release admission is independent of buying power.');
    $ledger->pause('plan-test', 'fixture');
    $check($planner->next('plan-test', $close, $w, $guards)['action'] === 'blocked', 'Operational pause blocks entries.');
    $close = $freeze('2026-09-17', '2026-09-18', []);
    $w = $window('2026-09-18', '2026-09-17', '2026-09-18 10:00', true);
    $a = $planner->next('plan-test', $close, $w, $guards);
    $check($a['action'] === 'cancel', 'Paused run retains controlled risk-reducing exit capability.');
    $ledger->requestCancel($a['decision_id'], 'circuit'); $observe($a['decision_id'], 'canceled', 0, null);
    $riskExit = $submit($planner->next('plan-test', $close, $w, $guards), 'filled', 5, 79.);
    $check($riskExit['side'] === 'sell' && $riskExit['payload']['body']['time_in_force'] === 'day', 'Paused risk exit remains DAY and sell-only.');
    echo "candidate_execution_plan: {$n} assertions PASS\n";
} finally {
    unset($ledger, $planner);
    foreach ([$path, $path . '-wal', $path . '-shm'] as $file) { if (is_file($file)) { unlink($file); } }
}
