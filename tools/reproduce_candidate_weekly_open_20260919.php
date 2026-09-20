<?php
declare(strict_types=1);

use FulltimeTrading\Paper\CandidateExecutionPlan as Planner;
use FulltimeTrading\Paper\CandidateLedger as Ledger;
use FulltimeTrading\Paper\CandidateOrder as Order;
use FulltimeTrading\Paper\CandidateProtection as Protection;
use FulltimeTrading\Trading\TacticalPortfolioNotificationSchedule as Schedule;
use FulltimeTrading\Trading\TacticalRotationExecutionWindow as Window;

require dirname(__DIR__) . '/bootstrap.php';

// Default mode reproduces the old incident. The explicit repaired mode verifies
// only the scheduler repair and preserves all existing admission/expiry guards.
// No broker client, credentials, operational database or Telegram sender is used.
$expectRepaired = in_array('--expect-repaired', $argv, true);
$checks = 0;
$check = static function (bool $ok, string $message) use (&$checks): void {
    ++$checks;
    if (!$ok) { throw new RuntimeException($message); }
};
$path = tempnam(sys_get_temp_dir(), 'weekly-open-incident-');
if ($path === false) { throw new RuntimeException('Cannot create isolated database.'); }
$run = 'weekly-open-fixture';
$orders = [];
try {
    $ledger = new Ledger($path);
    $names = array_map(static fn ($i): string => 'sleeve_' . $i, range(0, 11));
    $ledger->provision(['run_id' => $run, 'profile' => 'fixture', 'strategy_hash' => str_repeat('a', 64),
        'runtime_hash' => str_repeat('b', 64), 'data_contract' => ['execution_contract' => Order::CONTRACT, 'paper_only' => true]],
        array_fill_keys($names, 1 / 12));
    $ledger->activate($run, 27567.66, ['positions' => [], 'open_orders' => [], 'adoption' => 'flat_account_only', 'stable_for_seconds' => 120]);
    $submit = static function (string $id) use ($ledger, &$orders, $check): array {
        $i = $ledger->intent($id);
        $check($ledger->claim($id), 'Exactly one local submission claim.');
        foreach ($orders as $o) {
            $check(in_array($o['status'], Order::TERMINAL, true) || $o['side'] === $i['side'], 'No opposing open simple orders.');
        }
        $orders[$id] = $i['payload']['body'] + ['id' => 'fake-' . count($orders), 'status' => 'new', 'filled_qty' => '0', 'filled_avg_price' => null];
        return $ledger->observe($id, $orders[$id]);
    };
    $cancel = static function (array $action) use ($ledger, &$orders, $check): void {
        $check($action['action'] === 'cancel', 'Expected bounded cancellation intent.');
        $id = $action['decision_id'];
        $ledger->requestCancel($id, $action['reason']);
        $orders[$id]['status'] = 'canceled';
        $ledger->observe($id, $orders[$id]);
    };
    $seed = $ledger->create(Order::make($run, 'sleeve_4', '2026-09-15', '2026-09-16', 'seed', 'entry', 'MSFT', 2, 'opg'));
    $submit($seed['decision_id']);
    $orders[$seed['decision_id']] = array_replace($orders[$seed['decision_id']], ['status' => 'filled', 'filled_qty' => '2', 'filled_avg_price' => '492']);
    $ledger->observe($seed['decision_id'], $orders[$seed['decision_id']]);
    $protection = new Protection($ledger);
    $protection->completedClose($run, 'sleeve_4', 'MSFT', '2026-09-17', 497.75);
    $stop = $protection->plan($run, 'sleeve_4', 'MSFT', '2026-09-17', '2026-09-18');
    $submit($stop['submit'][0]);
    $check($stop['stop_price'] === '438.02', 'Preserve the actual completed-close stop basis.');
    $plans = [];
    foreach ($names as $i => $name) {
        $plans[$name] = ['target_quantities' => $i < 4 ? ['MSFT' => $i === 0 ? 10 : 3] : null,
            'target' => ['risk_exit_pending' => false], 'circuit_feedback' => ['force_cash' => false, 'scale' => 1.]];
    }
    $close = ['date' => '2026-09-17', 'scheduled_session' => '2026-09-18', 'plans' => $plans];
    $ledger->saveCheckpoint($run, 'close:2026-09-17', 0, $close);
    $closeKey = 'portfolio-close:' . $run . ':2026-09-17';
    $ledger->views->queueNotification($closeKey, 'Fixture close', ['run_id' => $run]);
    $ledger->views->markNotificationDelivered($closeKey);
    $gates = array_fill_keys(['identity', 'account', 'reconciliation', 'paper_admission', 'fresh_signal', 'risk_capacity', 'all_positions_protected'], true);
    $window = static fn (string $time, bool $open): array => (new Window())->resolve('2026-09-18',
        new DateTimeImmutable('2026-09-18 ' . $time, new DateTimeZone('America/New_York')), '2026-09-17', $open)
        + ['candidate_preopen_stop_transition_allowed' => !$open && $time >= '09:15' && $time < '09:28'];
    $next = static fn (array $w, array $g): array => (new Planner(new Ledger($path)))->next($run, $close, $w, $g);
    $cancel($next($window('09:15:23', false), $gates));
    $buyIds = [];
    for ($i = 0; $i < 4; ++$i) {
        $a = $next($window('09:20:00', false), $gates);
        $check($a['action'] === 'submit', 'Admitted frozen batch can submit its four OPG orders.');
        $submit($a['decision_id']); $buyIds[] = $a['decision_id'];
    }
    $check($next($window('09:29:40', false), $gates)['action'] === 'wait', 'Existing cutoff fix preserves resting OPG at 09:29.');
    $w = $window('09:30:05', true);
    $check($next($w, $gates)['action'] === 'wait', 'Control: same open window without new pending notification does not cancel.');
    $signal = ['as_of' => '2026-09-17', 'intended_session' => '2026-09-18', 'decision_sha256' => hash('sha256', Order::json($close))];
    $clock = ['timestamp' => '2026-09-18T09:29:40-04:00', 'is_open' => false, 'next_open' => '2026-09-18T09:30:00-04:00'];
    $account = ['id' => 'offline-account'];
    $check(Schedule::weeklyCloseStatus($clock, $account, $signal, new DateTimeImmutable($clock['timestamp'])) === null, 'No weekly report before Friday open.');
    $clock = ['timestamp' => '2026-09-18T09:30:05-04:00', 'is_open' => true, 'next_open' => '2026-09-21T09:30:00-04:00'];
    $weekly = Schedule::weeklyCloseStatus($clock, $account, $signal, new DateTimeImmutable($clock['timestamp']));
    if ($expectRepaired) {
        $check($weekly === null, 'Repaired scheduler must not invent a Thursday weekly report at Friday open.');
        $check($ledger->views->pendingNotifications() === [], 'No spurious notification closes entry admission.');
        foreach (['09:30:05', '09:30:06', '09:30:07'] as $time) {
            $a = $next($window($time, true), $gates);
            $check($a['action'] === 'wait', 'Accepted OPG keeps waiting through successive opening observations.');
        }
        $w = $window('09:30:08', true);
        $check($ledger->checkpoint($run, 'entry_batch')['payload']['aborted'] === null, 'No sticky batch abort from the corrected scheduler.');
        // Negative control: an actual pending notification must still close
        // admission. Do not change the gate merely to make the test pass.
        $weekly = ['key' => 'fixture-real-pending-notification'];
    } else {
        $check(($weekly['session_date'] ?? null) === '2026-09-17', 'KNOWN DEFECT: Friday open schedules a Thursday weekly close.');
    }
    $ledger->views->queueNotification($weekly['key'], 'Fixture weekly', ['run_id' => $run]);
    // Exact current executor rule: any due pending notification for this run closes admission.
    $outboxReady = $ledger->views->notificationDelivered($closeKey);
    foreach ($ledger->views->pendingNotifications(100) as $pending) {
        if (($pending['payload']['run_id'] ?? '') === $run) { $outboxReady = false; }
    }
    $check(!$outboxReady, 'An actual pending outbox row still closes paper_admission before delivery.');
    $a = $next($w, array_replace($gates, ['paper_admission' => $outboxReady]));
    $check($a['action'] === 'cancel' && $a['reason'] === 'entry_window_expired', 'Existing admission-loss cleanup and its current reason code remain unchanged.');
    $cancel($a);
    $ledger->views->markNotificationDelivered($weekly['key']);
    $check($ledger->views->pendingNotifications() === [], 'Delivery clears the notification, not the sticky batch abort.');
    $a = $next($window('09:30:31', true), $gates);
    $check($a['action'] === 'cancel', 'Batch cancellation persists after notification delivery.');
    $cancel($a);
    $expired = $buyIds[2];
    $orders[$expired]['status'] = 'expired';
    $ledger->observe($expired, $orders[$expired]);
    $check($ledger->run($run)['status'] === 'paused', 'Unexpected zero-fill expiry retains the existing fail-closed pause.');
    $check($ledger->run($run)['last_error_code'] === 'candidate_terminal_incomplete:' . substr($expired, 0, 12), 'Pause identifies the incomplete order.');
    $cancel($next($window('09:31:04', true), $gates));
    $a = $next($window('09:31:27', true), $gates);
    $i = $ledger->intent($a['decision_id']);
    $check($a['action'] === 'submit' && $i['leg'] === 'protective_stop' && (int) $i['requested_qty'] === 2, 'Paused cleanup still restores protection of the two original shares.');
    $submit($a['decision_id']);
    $check($next($window('09:31:51', true), $gates)['reason'] === 'entry_batch_completed', 'Protection acknowledged before clearing batch.');
    $check(count($ledger->active($run)) === 1 && $ledger->run($run)['status'] === 'paused', 'Only the stop remains; no implicit resume.');
    $check($next($window('09:31:55', true), $gates)['reason'] === 'run_paused', 'No late retry or gate bypass.');
    echo json_encode(['result' => $expectRepaired ? 'scheduler_repair_verified_existing_guards_preserved' : 'known_failure_reproduced_not_fixed', 'assertions' => $checks,
        'real_broker_mutations' => 0, 'operational_database_access' => false,
        'findings' => $expectRepaired ? ['no_premature_weekly', 'no_spurious_outbox_abort', 'actual_pending_outbox_still_aborts']
            : ['premature_weekly_at_friday_open', 'pending_outbox_aborts_resting_opg', 'admission_loss_mislabeled_as_expiry'],
        'safety_verified' => ['terminal_expiry_pauses', 'original_two_shares_reprotected', 'no_automatic_resume']], JSON_PRETTY_PRINT), PHP_EOL;
} finally {
    unset($protection, $ledger, $submit, $cancel, $next);
    foreach ([$path, $path . '-wal', $path . '-shm'] as $file) { if (is_file($file)) { unlink($file); } }
}
