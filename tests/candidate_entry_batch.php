<?php
declare(strict_types=1);

use FulltimeTrading\Paper\CandidateLedger as Ledger;
use FulltimeTrading\Paper\CandidateOrder as Order;
use FulltimeTrading\Paper\CandidateExecutionPlan as Planner;
use FulltimeTrading\Paper\CandidateBrokerOrderPolicy as Policy;
use FulltimeTrading\Paper\CandidateEntryBatch as Batch;
use FulltimeTrading\Trading\TacticalRotationExecutionWindow as Window;

require dirname(__DIR__) . '/bootstrap.php';
set_error_handler(static function (int $n, string $s): never { throw new RuntimeException($s); });
$n = 0; $check = static function (bool $ok, string $why) use (&$n): void { ++$n; if (!$ok) { throw new RuntimeException($why); } };
$path = tempnam(sys_get_temp_dir(), 'candidate-batch-'); $broker = []; $posts = 0;
try {
    $ledger = new Ledger($path); $names = array_map(static fn ($i): string => 'sleeve_' . $i, range(0, 11));
    $ledger->provision(['run_id' => 'batch-test', 'profile' => 'candidate', 'strategy_hash' => str_repeat('a', 64), 'runtime_hash' => str_repeat('b', 64),
        'data_contract' => ['execution_contract' => Order::CONTRACT, 'paper_only' => true]], array_fill_keys($names, 1 / 12));
    $ledger->activate('batch-test', 30000., ['positions' => [], 'open_orders' => [], 'adoption' => 'flat_account_only', 'stable_for_seconds' => 120]);
    $guards = array_fill_keys(['identity', 'account', 'reconciliation', 'paper_admission', 'fresh_signal', 'risk_capacity', 'all_positions_protected'], true);
    $freeze = static function (string $date, string $session, array $targets) use ($ledger, $names): array {
        $plans = [];
        foreach ($names as $name) { $plans[$name] = ['target_quantities' => $targets[$name] ?? null,
            'target' => ['risk_exit_pending' => false], 'circuit_feedback' => ['force_cash' => false, 'scale' => 1.]]; }
        $close = ['date' => $date, 'scheduled_session' => $session, 'plans' => $plans, 'provenance' => ['fixture' => true]];
        $ledger->saveCheckpoint('batch-test', 'close:' . $date, 0, $close); return $close;
    };
    $window = static fn (array $close, string $time, bool $open = false): array => (new Window())->resolve($close['scheduled_session'],
        new DateTimeImmutable($close['scheduled_session'] . ' ' . $time, new DateTimeZone('America/New_York')), $close['date'], $open)
        + ['candidate_preopen_stop_transition_allowed' => !$open && $time >= '09:15' && $time < '09:28'];
    $next = static fn ($close, $w, $g): array => (new Planner(new Ledger($path)))->next('batch-test', $close, $w, $g);
    $submit = static function (array $a) use ($ledger, &$broker, &$posts, $check): array {
        $check($a['action'] === 'submit', 'Expected submit.'); $i = $ledger->intent($a['decision_id']);
        Policy::assertCompatible($i, $ledger->active('batch-test'));
        // Independently enforce the documented broker rule, not merely the planner's local check.
        foreach ($broker as $o) {
            $check($o['symbol'] !== $i['symbol'] || $o['side'] === $i['side'] || in_array($o['status'], Order::TERMINAL, true), 'No opposing active broker order.');
        }
        $check($ledger->claim($i['decision_id']), 'Only one POST claim.');
        $broker[$i['decision_id']] = $i['payload']['body'] + ['id' => 'fake-' . ++$posts, 'status' => 'new', 'filled_qty' => '0', 'filled_avg_price' => null];
        return $ledger->observe($i['decision_id'], $broker[$i['decision_id']]);
    };
    $observe = static function (array $i, string $status, int $qty, ?float $price = null) use ($ledger, &$broker): array {
        $id = $i['decision_id']; $broker[$id] = array_replace($broker[$id], ['status' => $status, 'filled_qty' => (string) $qty, 'filled_avg_price' => $price === null ? null : (string) $price]);
        return $ledger->observe($id, $broker[$id]);
    };
    $cancel = static function (array $a) use ($ledger, $observe, $check): void {
        $check($a['action'] === 'cancel', 'Expected cancel before conflicting order.');
        $i = $ledger->intent($a['decision_id']); $ledger->requestCancel($i['decision_id'], $a['reason']);
        $q = (int) $i['cumulative_filled_qty']; $observe($i, 'canceled', $q, $q > 0 ? $i['cumulative_fill_notional'] / $q : null);
    };
    $c = $freeze('2026-09-14', '2026-09-15', ['sleeve_0' => ['AAA' => 10], 'sleeve_1' => ['AAA' => 5]]); $w = $window($c, '09:20');
    $b0 = $submit($next($c, $w, $guards)); $b1 = $submit($next($c, $w, $guards));
    $check($b0['sleeve_id'] !== $b1['sleeve_id'], 'Same-symbol entries belong to separate books.');
    $check($next($c, $w, $guards)['action'] === 'wait', 'Never post duplicate pending buys.');
    $observe($b0, 'filled', 10, 100.); $observe($b1, 'filled', 5, 100.); $w = $window($c, '09:30', true);
    $s0 = $submit($next($c, $w, $guards)); $s1 = $submit($next($c, $w, $guards));
    $check($next($c, $w, $guards)['reason'] === 'entry_batch_completed', 'All batch fills get independent acknowledged protection.');
    $check(!(new Batch($ledger))->active('batch-test'), 'Completed batch is cleared durably.');
    $c = $freeze('2026-09-15', '2026-09-16', ['sleeve_0' => ['AAA' => 7]]); $w = $window($c, '09:20');
    $cancel($next($c, $w, $guards));
    $retained = $submit($next($c, $w, $guards));
    $check((int) $retained['requested_qty'] === 7 && $retained['leg'] === 'protective_stop', 'Resize protects the seven retained shares before selling three.');
    $sell = $submit($next($c, $w, $guards)); $check((int) $sell['requested_qty'] === 3 && $sell['side'] === 'sell', 'Sell only the unreserved resize difference.');
    $observe($sell, 'filled', 3, 101.);
    $check((int) $ledger->position('batch-test', 'sleeve_0', 'AAA')['qty'] === 7, 'Resize cannot oversell another sleeve.');
    $c = $freeze('2026-09-16', '2026-09-17', ['sleeve_0' => ['AAA' => 9]]);
    $check($next($c, $window($c, '08:00'), $guards)['action'] === 'wait', 'No early overnight removal of native stops.');
    $w = $window($c, '09:20');
    $cancel($next($c, $w, $guards)); $cancel($next($c, $w, $guards));
    $buy = $submit($next($c, $w, array_replace($guards, ['all_positions_protected' => false])));
    $check((int) $buy['requested_qty'] === 2, 'Frozen admitted batch alone can finish its temporary stop transition.');
    $observe($buy, 'partially_filled', 1, 102.); $w = $window($c, '09:30', true);
    $a = $next($c, $w, $guards); $check($a['reason'] === 'partial_fill_protection', 'Partial remainder cancellation precedes opposite stops.'); $cancel($a);
    $stopA = $submit($next($c, $w, $guards)); $stopB = $submit($next($c, $w, $guards));
    $next($c, $w, $guards);
    $check(array_sum([$stopA['requested_qty'], $stopB['requested_qty']]) === 13., 'Cover actual eight plus five, not the intended fourteen.');
    $check($ledger->run('batch-test')['status'] === 'active', 'Expected partial-fill cancellation does not permanently pause the strategy.');
    $check($next($c, $w, $guards)['action'] === 'wait', 'Unfilled remainder is not chased after a partial cancellation.');
    $c = $freeze('2026-09-17', '2026-09-18', ['sleeve_0' => ['AAA' => 9]]); $w = $window($c, '09:20');
    $cancel($next($c, $w, $guards)); $before = $posts;
    $g = array_replace($guards, ['fresh_signal' => false]);
    $a = $next($c, $w, $g); $check($a['reason'] === 'protect_filled_shares', 'Lost freshness restores canceled protection without buying.');
    $restore = $submit($a); $check($restore['side'] === 'sell', 'Abort is protection-only.');
    $next($c, $w, $g); $check($posts === $before + 1, 'No entry POST on aborted data gate.');
    echo "candidate_entry_batch: {$n} assertions PASS\n";
} finally {
    unset($ledger);
    foreach ([$path, $path . '-wal', $path . '-shm'] as $file) { if (is_file($file)) { unlink($file); } }
}
