<?php
declare(strict_types=1);

use FulltimeTrading\Paper\CandidateBrokerGateway;
use FulltimeTrading\Paper\CandidateLedger as Ledger;
use FulltimeTrading\Paper\CandidateOrder as Order;
use FulltimeTrading\Paper\CandidateOrderReconciler as Reconciler;
use FulltimeTrading\Paper\CandidateExecutionPlan as Planner;
use FulltimeTrading\Trading\TacticalRotationExecutionWindow as Window;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__);
if (!is_file($root . '/stage_sources.json') || is_file($root . '/.env')
    || \FulltimeTrading\Paper\CandidateDefinition::PROFILE !== 'maximum-stop12-costband2-whole-bull5-v1') { throw new RuntimeException('Only credential-free bull5 staging may run this fixture.'); }
$fixture = json_decode(file_get_contents($root . '/var/reports/staging/fault_input.json'), true, 512, JSON_THROW_ON_ERROR);
$capital = $fixture['capital'];
set_error_handler(static function (int $n, string $s): never { throw new RuntimeException($s); });
$n = 0; $check = static function (bool $ok, string $why) use (&$n): void { ++$n; if (!$ok) { throw new RuntimeException($why); } };
for ($seed = 1; $seed <= 100; ++$seed) {
    mt_srand($seed); $path = tempnam(sys_get_temp_dir(), 'candidate-fault-');
    $broker = new class($seed, $fixture['prices']) implements CandidateBrokerGateway {
        public array $orders = []; public array $invisible = []; public array $cancelPending = []; public int $posts = 0;
        public function __construct(private int $seed, private array $prices) { }
        public function submitOrder(array $body): array {
            foreach ($this->orders as $old) {
                if ($old['symbol'] === $body['symbol'] && $old['side'] !== $body['side'] && !in_array($old['status'], Order::TERMINAL, true)) {
                    throw new LogicException('Fixture detected a forbidden opposing broker order.');
                }
            }
            if (isset($this->orders[$body['client_order_id']])) { throw new LogicException('Duplicate broker POST.'); }
            ++$this->posts;
            $order = $body + ['id' => 'fake-' . $this->posts, 'status' => 'new', 'filled_qty' => '0', 'filled_avg_price' => null];
            if ($this->seed % 11 === 0 && $this->posts === 2 && $body['side'] === 'buy') { $order['status'] = 'rejected'; }
            $this->orders[$body['client_order_id']] = $order;
            if ($this->seed % 3 === 0 && $this->posts === 1) {
                $this->invisible[$body['client_order_id']] = 1;
                throw new RuntimeException('Transport timed out after acceptance.');
            }
            return $order;
        }
        public function orderByClientOrderId(string $id): ?array {
            if (($this->invisible[$id] ?? 0) > 0) { --$this->invisible[$id]; return null; }
            if (isset($this->cancelPending[$id]) && --$this->cancelPending[$id] <= 0) {
                if ($this->seed % 4 === 0 && (float) $this->orders[$id]['filled_qty'] > 0) {
                    $this->orders[$id]['status'] = 'filled'; $this->orders[$id]['filled_qty'] = $this->orders[$id]['qty'];
                } else { $this->orders[$id]['status'] = 'canceled'; }
                unset($this->cancelPending[$id]);
            }
            return $this->orders[$id] ?? null;
        }
        public function cancelOrder(string $id): array {
            foreach ($this->orders as $client => $order) { if ($order['id'] === $id) { $this->cancelPending[$client] = 2; } }
            return ['status' => 204];
        }
        public function openingFills(): void {
            foreach ($this->orders as &$o) {
                if ($o['side'] !== 'buy' || $o['status'] !== 'new') { continue; }
                $partial = $this->seed % 2 === 0 && (int) $o['qty'] > 1;
                $o['status'] = $partial ? 'partially_filled' : 'filled';
                $o['filled_qty'] = (string) ($partial ? max(1, intdiv((int) $o['qty'], 2)) : (int) $o['qty']);
                $o['filled_avg_price'] = (string) $this->prices[$o['symbol']];
            }
            unset($o);
        }
    };
    try {
        $ledger = new Ledger($path); $names = array_keys($fixture['allocations']);
        $ledger->provision(['run_id' => 'fault', 'profile' => \FulltimeTrading\Paper\CandidateDefinition::PROFILE, 'strategy_hash' => str_repeat('a', 64), 'runtime_hash' => str_repeat('b', 64),
            'data_contract' => ['execution_contract' => Order::CONTRACT, 'paper_only' => true]], $fixture['allocations']);
        $ledger->activate('fault', $capital, ['positions' => [], 'open_orders' => [], 'adoption' => 'flat_account_only', 'stable_for_seconds' => 120]);
        $plans = $fixture['close']['plans'];
        $close = $fixture['close'];
        $ledger->saveCheckpoint('fault', 'close:2026-09-14', 0, $close);
        $guards = array_fill_keys(['identity', 'account', 'reconciliation', 'paper_admission', 'fresh_signal', 'risk_capacity', 'all_positions_protected'], true);
        $opened = false; $complete = false;
        for ($step = 0; $step < 160; ++$step) {
            $ledger = new Ledger($path); $reconciler = new Reconciler($ledger, $broker); $planner = new Planner($ledger);
            foreach ($ledger->active('fault') as $i) { if ((int) $i['attempt_count'] > 0) { $reconciler->refresh($i['decision_id']); } }
            $w = (new Window())->resolve('2026-09-15', new DateTimeImmutable('2026-09-15 ' . ($opened ? '09:30' : '09:20'), new DateTimeZone('America/New_York')), '2026-09-14', $opened)
                + ['candidate_preopen_stop_transition_allowed' => !$opened];
            if ($seed % 5 === 0 && $opened) { $guards['fresh_signal'] = false; }
            $action = $planner->next('fault', $close, $w, $guards);
            if ($action['action'] === 'submit') { $reconciler->submit($action['decision_id']); }
            elseif ($action['action'] === 'cancel') { $reconciler->cancel($action['decision_id'], $action['reason']); }
            elseif ($action['action'] === 'refresh') { $reconciler->refresh($action['decision_id']); }
            elseif ($action['reason'] === 'entry_batch_waiting_for_broker') { $opened = true; $broker->openingFills(); }
            elseif ($action['reason'] === 'entry_batch_completed') { $complete = true; break; }
        }
        $check($complete, 'Fault batch did not settle for seed ' . $seed);
        $check($broker->posts === count($broker->orders), 'Exactly one POST per client id for seed ' . $seed);
        $cash = $capital;
        foreach ($broker->orders as $o) { $cash += ($o['side'] === 'buy' ? -1 : 1) * (float) $o['filled_qty'] * (float) $o['filled_avg_price']; }
        $check(abs(array_sum(array_column($ledger->views->sleeves('fault'), 'cash')) - $cash) < 1e-8, 'Cash survives cumulative observations and restart.');
        foreach ($ledger->views->positions('fault') as $name => $positions) {
            foreach ($positions as $symbol => $p) {
                $protected = 0.;
                foreach ($ledger->active('fault', $name) as $i) {
                    if ($i['symbol'] === $symbol && $i['leg'] === 'protective_stop' && $i['status'] === 'new') { $protected += $i['requested_qty'] - $i['cumulative_filled_qty']; }
                }
                $check($p['qty'] > 0 && $p['qty'] <= $plans[$name]['target_quantities'][$symbol], 'Only confirmed whole target shares are owned.');
                $check($protected === (float) $p['qty'], 'Every settled share has acknowledged native protection.');
            }
        }
        $check($seed % 11 !== 0 || $ledger->run('fault')['status'] === 'paused', 'Actual broker rejection remains fail-closed.');
    } finally {
        unset($ledger, $planner, $reconciler);
        foreach ([$path, $path . '-wal', $path . '-shm'] as $file) { if (is_file($file)) { unlink($file); } }
    }
}
echo "staged_bull5_fault_matrix: 100 actual-snapshot deterministic scenarios, {$n} assertions PASS\n";
