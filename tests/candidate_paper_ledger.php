<?php

declare(strict_types=1);

use FulltimeTrading\Paper\CandidateLedger as Ledger;
use FulltimeTrading\Paper\CandidateOrder as Order;
use FulltimeTrading\Paper\CandidateBrokerGateway;
use FulltimeTrading\Paper\CandidateOrderReconciler as Reconciler;
use FulltimeTrading\Paper\CandidateProtection as Protection;

require dirname(__DIR__) . '/bootstrap.php';
set_error_handler(static function (int $n, string $s): never { throw new RuntimeException($s); });
$n = 0;
$check = static function (bool $ok, string $why) use (&$n): void { ++$n; if (!$ok) { throw new RuntimeException($why); } };
$reject = static function (callable $fn, string $why) use ($check): void {
    try { $fn(); } catch (Throwable $e) { $check(true, $why); return; }
    $check(false, $why);
};
$path = tempnam(sys_get_temp_dir(), 'candidate-ledger-');
$allocations = array_fill_keys(array_map(static fn ($i): string => 'sleeve_' . $i, range(0, 11)), 1 / 12);
$identity = ['run_id' => 'candidate-test', 'profile' => 'maximum-stop12-costband2',
    'strategy_hash' => str_repeat('a', 64), 'runtime_hash' => str_repeat('b', 64),
    'data_contract' => ['execution_contract' => Order::CONTRACT, 'paper_only' => true]];
$flat = ['positions' => [], 'open_orders' => [], 'adoption' => 'flat_account_only', 'stable_for_seconds' => 120];
$broker = new class implements CandidateBrokerGateway {
    public array $orders = [];
    public int $posts = 0;
    public int $deletes = 0;
    public bool $timeout = false;
    public bool $visible = true;
    public function orderByClientOrderId(string $id): ?array { return $this->visible ? ($this->orders[$id] ?? null) : null; }
    public function submitOrder(array $body): array {
        ++$this->posts;
        $order = $body + ['id' => 'broker-' . $this->posts, 'status' => 'new', 'filled_qty' => '0', 'filled_avg_price' => null];
        $this->orders[$body['client_order_id']] = $order;
        if ($this->timeout) { throw new RuntimeException('Simulated timeout after broker accepted the order.'); }
        return $order;
    }
    public function cancelOrder(string $id): array { ++$this->deletes; return ['status' => 204]; }
    public function update(array $i, string $status, int $qty, ?float $price): array {
        $id = $i['client_order_id'];
        $this->orders[$id] = array_replace($this->orders[$id], ['status' => $status, 'filled_qty' => (string) $qty,
            'filled_avg_price' => $price === null ? null : (string) $price]);
        return $this->orders[$id];
    }
};
try {
    $ledger = new Ledger($path);
    $run = $ledger->provision($identity, $allocations);
    $check($run['status'] === 'transition', 'Provision returns the committed candidate.');
    $reject(fn () => $ledger->provision($identity, array_slice($allocations, 0, 4, true)), 'Reject four-sleeve candidate.');
    $reject(fn () => $ledger->provision(array_replace($identity, ['runtime_hash' => str_repeat('c', 64)]), $allocations), 'Never silently refresh candidate runtime identity.');
    $ledger->activate('candidate-test', 30000., $flat);
    $ledger->activate('candidate-test', 90000., $flat);
    $run = $ledger->run('candidate-test');
    $check((float) $run['initial_equity'] === 30000., 'Repeated activation cannot reset capital.');
    $check((new DateTimeImmutable($run['live_review_not_before']))->getTimestamp() - (new DateTimeImmutable($run['activated_at']))->getTimestamp() === 31 * 86400,
        'Monthly review starts from actual activation.');
    $check(abs(array_sum(array_column($ledger->views->sleeves('candidate-test'), 'cash')) - 30000) < 1e-8, 'Twelve books conserve initial capital.');
    $i = Order::make('candidate-test', 'sleeve_0', '2026-09-14', '2026-09-15', 'entry:1', 'entry', 'AAA', 10);
    $ledger->create($i); $ledger->create($i);
    $reject(fn () => $ledger->create(Order::make('candidate-test', 'sleeve_0', '2026-09-14', '2026-09-15', 'entry:1', 'entry', 'AAA', 11)), 'Target quantity is immutable.');
    $tampered = $i; $tampered['payload']['body']['qty'] = '11';
    $reject(fn () => $ledger->create($tampered), 'Body cannot diverge from identity.');
    $reconciler = new Reconciler($ledger, $broker);
    $broker->timeout = true; $broker->visible = false;
    $check($reconciler->submit($i['decision_id'])['status'] === 'ambiguous_submission', 'Transport timeout persists ambiguity.');
    $ledger = new Ledger($path); $reconciler = new Reconciler($ledger, $broker);
    $check($reconciler->submit($i['decision_id'])['status'] === 'unresolved_lookup', 'Restart plus 404 remains lookup-only.');
    $check($broker->posts === 1, 'Ambiguous submission cannot duplicate the order.');
    $broker->visible = true; $broker->timeout = false;
    $check($reconciler->refresh($i['decision_id'])['intent']['status'] === 'new', 'Recover accepted order by deterministic id.');
    $broker->update($i, 'partially_filled', 3, 100.);
    $reconciler->refresh($i['decision_id']); $reconciler->refresh($i['decision_id']);
    $check((float) $ledger->position('candidate-test', 'sleeve_0', 'AAA')['qty'] === 3., 'Cumulative fills are applied exactly once.');
    $check(abs($ledger->views->sleeves('candidate-test')['sleeve_0']['cash'] - 2200.) < 1e-8, 'Only the owning sleeve pays for fills.');
    $check(abs($ledger->views->sleeves('candidate-test')['sleeve_1']['cash'] - 2500.) < 1e-8, 'Other sleeve capital is untouched.');
    $protection = new Protection($ledger);
    $plan = $protection->plan('candidate-test', 'sleeve_0', 'AAA', '2026-09-14', '2026-09-15');
    $stop1 = $ledger->intent($plan['submit'][0]);
    $check((int) $stop1['requested_qty'] === 3 && $stop1['payload']['body']['stop_price'] === '88.00', 'Protect only confirmed partial fills.');
    $repeat = $protection->plan('candidate-test', 'sleeve_0', 'AAA', '2026-09-14', '2026-09-15');
    $check($repeat['submit'] === $plan['submit'], 'Crash before stop POST recovers the same planned stop.');
    $reject(fn () => $reconciler->submit($stop1['decision_id']), 'Alpaca rejects a stop while the opposite entry is still open.');
    $check((int) $ledger->intent($stop1['decision_id'])['attempt_count'] === 0, 'Compatibility check runs before the durable POST claim.');
    $broker->update($i, 'filled', 10, 101.);
    $reconciler->refresh($i['decision_id']);
    $reconciler->submit($stop1['decision_id']);
    $plan = $protection->plan('candidate-test', 'sleeve_0', 'AAA', '2026-09-14', '2026-09-15');
    $stop2 = $ledger->intent($plan['submit'][0]);
    $check((int) $stop2['requested_qty'] === 7 && $stop2['payload']['body']['stop_price'] === '88.00', 'Additional fills add protection without canceling the first tranche.');
    $reconciler->submit($stop2['decision_id']);
    $check($protection->plan('candidate-test', 'sleeve_0', 'AAA', '2026-09-14', '2026-09-15')['status'] === 'protected', 'All filled shares have acknowledged native stops.');
    $reject(fn () => $ledger->create(Order::make('candidate-test', 'sleeve_0', '2026-09-14', '2026-09-15', 'exit:1', 'rebalance_exit', 'AAA', 10)), 'Cannot oversell stop-reserved shares.');
    $reject(fn () => $ledger->create(Order::make('candidate-test', 'sleeve_1', '2026-09-14', '2026-09-15', 'exit:1', 'rebalance_exit', 'AAA', 1)), 'Cannot sell shares owned by a different sleeve.');
    $protection->completedClose('candidate-test', 'sleeve_0', 'AAA', '2026-09-15', 110.);
    $protection->completedClose('candidate-test', 'sleeve_0', 'AAA', '2026-09-15', 110.);
    $reject(fn () => $protection->completedClose('candidate-test', 'sleeve_0', 'AAA', '2026-09-15', 111.), 'No silent revision of a completed close.');
    $ledger = new Ledger($path); $reconciler = new Reconciler($ledger, $broker); $protection = new Protection($ledger);
    $plan = $protection->plan('candidate-test', 'sleeve_0', 'AAA', '2026-09-15', '2026-09-16');
    $check(count($plan['cancel']) === 2 && $plan['submit'] === [] && $plan['stop_price'] === '96.80', 'Close stop update survives restart and waits for cancellation.');
    $reconciler->cancel($stop1['decision_id'], 'close_stop_update');
    $check($protection->plan('candidate-test', 'sleeve_0', 'AAA', '2026-09-15', '2026-09-16')['submit'] === [], 'HTTP 204 does not free reservations.');
    $reconciler->cancel($stop1['decision_id'], 'close_stop_update');
    $check($broker->deletes === 1, 'Cancel intent is persisted and not blindly repeated.');
    $broker->update($stop1, 'canceled', 1, 87.);
    $reconciler->refresh($stop1['decision_id']);
    $check((float) $ledger->position('candidate-test', 'sleeve_0', 'AAA')['qty'] === 9., 'Late fill during cancellation reduces real ownership.');
    $check($ledger->run('candidate-test')['status'] === 'active', 'Expected partial stop cancellation does not permanently pause the run.');
    $plan = $protection->plan('candidate-test', 'sleeve_0', 'AAA', '2026-09-15', '2026-09-16');
    $stop3 = $ledger->intent($plan['submit'][0]);
    $check((int) $stop3['requested_qty'] === 2, 'Replacement protects remaining two shares, not stale three.');
    $reconciler->submit($stop3['decision_id']);
    $reconciler->cancel($stop2['decision_id'], 'close_stop_update');
    $broker->update($stop2, 'canceled', 0, null); $reconciler->refresh($stop2['decision_id']);
    $plan = $protection->plan('candidate-test', 'sleeve_0', 'AAA', '2026-09-15', '2026-09-16');
    $stop4 = $ledger->intent($plan['submit'][0]); $reconciler->submit($stop4['decision_id']);
    $check((int) $stop4['requested_qty'] === 7, 'Nonfilled canceled tranche can be reprotected.');
    $forged = $broker->orders[$stop4['client_order_id']]; $forged['stop_price'] = '99.00';
    $reject(fn () => $ledger->observe($stop4['decision_id'], $forged), 'Stop-price drift blocks reconciliation.');
    $forged = $broker->orders[$stop4['client_order_id']]; $forged['filled_qty'] = '0.5'; $forged['filled_avg_price'] = '96';
    $reject(fn () => $ledger->observe($stop4['decision_id'], $forged), 'Unexpected fractional fill fails closed.');
    $forged = $broker->orders[$stop4['client_order_id']]; $forged['status'] = 'filled';
    $reject(fn () => $ledger->observe($stop4['decision_id'], $forged), 'Filled status cannot conceal missing shares.');
    $broker->update($stop3, 'filled', 2, 96.); $reconciler->refresh($stop3['decision_id']);
    $broker->update($stop4, 'filled', 7, 95.); $reconciler->refresh($stop4['decision_id']);
    $check((float) $ledger->position('candidate-test', 'sleeve_0', 'AAA')['qty'] === 0., 'Stops close only sleeve-owned shares.');
    $check(abs($ledger->views->sleeves('candidate-test')['sleeve_0']['cash'] - (2500 - 1010 + 87 + 192 + 665)) < 1e-8, 'Cash reflects actual gap fills, not assumed stop prices.');
    $check($ledger->checkpoint('candidate-test', 'protection:sleeve_0:AAA')['payload'] === [], 'Completed exit clears the old price peak atomically.');
    $ledger->saveCheckpoint('candidate-test', 'circuit', 0, ['paused' => true, 'elapsed' => 2]);
    $reject(fn () => $ledger->saveCheckpoint('candidate-test', 'circuit', 0, ['paused' => false]), 'Stale process cannot reset the circuit.');
    $ledger = new Ledger($path);
    $check($ledger->checkpoint('candidate-test', 'circuit')['payload']['paused'] === true, 'Circuit pause survives process restart.');
    $i2 = Order::make('candidate-test', 'sleeve_1', '2026-09-15', '2026-09-16', 'entry:2', 'entry', 'BBB', 5);
    $ledger->create($i2); $reconciler = new Reconciler($ledger, $broker); $reconciler->submit($i2['decision_id']);
    $broker->update($i2, 'rejected', 0, null); $reconciler->refresh($i2['decision_id']);
    $check($ledger->run('candidate-test')['status'] === 'paused', 'Unexpected rejection prevents new risk.');
    $reject(fn () => $ledger->create(Order::make('candidate-test', 'sleeve_2', '2026-09-15', '2026-09-16', 'entry:3', 'entry', 'BBB', 5)), 'Paused run blocks new entries.');
    $db = new PDO('sqlite:' . $path);
    $check((int) $db->query('SELECT COUNT(*) FROM tactical_paper_fill_audit')->fetchColumn() === 5, 'Fill audit has exactly one row per positive cumulative increment.');
    $check($db->query('PRAGMA integrity_check')->fetchColumn() === 'ok', 'SQLite database integrity.');
    echo "candidate_paper_ledger: {$n} assertions PASS\n";
} finally {
    unset($ledger, $reconciler, $protection, $db);
    foreach ([$path, $path . '-wal', $path . '-shm'] as $file) { if (is_file($file)) { unlink($file); } }
}
