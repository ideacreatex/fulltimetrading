<?php
declare(strict_types=1);

use FulltimeTrading\Paper\CandidateOrder as O;
use FulltimeTrading\Paper\CandidateRelease;
use FulltimeTrading\Research\PaperForwardExecutionAudit as Audit;

require dirname(__DIR__) . '/bootstrap.php';
set_error_handler(static function (int $n, string $s): never { throw new RuntimeException($s); });
$n = 0; $check = static function (bool $ok, string $why) use (&$n): void { ++$n; if (!$ok) { throw new RuntimeException($why); } };
$intent = static function (string $purpose, string $status, int $quantity, int $filled, float $price, string $epoch = 'first'): array {
    $i = O::make('audit-test', 'sleeve0', '2026-09-14', '2026-09-15', $epoch, $purpose, 'MSFT', $quantity,
        $purpose === 'protective_stop' ? 'gtc' : 'opg', $purpose === 'protective_stop' ? 88. : null);
    return $i + ['status' => $status, 'attempt_count' => 1, 'order_id' => 'broker-' . $i['decision_id'],
        'cumulative_filled_qty' => $filled, 'cumulative_fill_notional' => $filled * $price];
};
$broker = static fn (array $i): array => $i['payload']['body'] + ['id' => $i['order_id'], 'status' => $i['status'],
    'filled_qty' => (string) $i['cumulative_filled_qty'], 'filled_avg_price' => $i['cumulative_filled_qty'] > 0 ? (string) ($i['cumulative_fill_notional'] / $i['cumulative_filled_qty']) : null];
$buy = $intent('entry', 'filled', 3, 3, 100.); $stop = $intent('protective_stop', 'new', 3, 0, 0.);
$base = ['observation_complete' => true, 'ledger_stable' => true,
    'account' => ['equity' => 1000., 'cash' => 700., 'buying_power' => 1400.],
    'sleeves' => [['sleeve_id' => 'sleeve0', 'initial_equity' => 1000., 'cash' => 700.]],
    'owned_positions' => [['sleeve_id' => 'sleeve0', 'symbol' => 'MSFT', 'qty' => 3, 'cost_basis' => 300.]],
    'positions' => [['symbol' => 'MSFT', 'qty' => '3', 'side' => 'long']],
    'intents' => [$buy, $stop], 'open_orders' => [$broker($stop)], 'checkpoints' => [],
    'broker_order_observations' => [$buy['client_order_id'] => $broker($buy), $stop['client_order_id'] => $broker($stop)]];
$r = Audit::inspect($base);
$check($r['ok'] && $r['status'] === 'positions_protected' && $r['coverage']['sleeve0:MSFT']['acknowledged_qty'] === 3, 'Confirmed stop covers actual shares.');
$check($r['filled_buy_shares'] === 3 && $r['orders_submitted'] === 0, 'Audit reports cumulative real fills, no new orders.');
$bad = static function (callable $change, string $why) use ($base, $check): void {
    $s = $base; $change($s);
    try { $result = Audit::inspect($s); } catch (Throwable) { $check(true, $why); return; }
    $check(!$result['ok'], $why);
};
foreach (['observation_complete', 'ledger_stable'] as $field) {
    $s = $base; $s[$field] = false; $r = Audit::inspect($s);
    $check(!$r['ok'] && $r['status'] === 'unconfirmed' && $r['coverage'] === [], 'Missing/racing snapshot cannot claim coverage.');
}
$bad(static function (&$s) { $s['open_orders'] = []; }, 'Missing broker stop.');
$bad(static function (&$s) { $s['intents'][1]['payload']['cancel_request'] = ['at' => 'now']; }, 'Cancel-requested stop cannot count as protection.');
foreach (['pending_cancel', 'pending_new', 'pending_replace', 'canceled', 'rejected', 'expired'] as $status) {
    $bad(static function (&$s) use ($status) { $s['open_orders'][0]['status'] = $status; }, 'Unacknowledged broker state is not coverage.');
}
foreach (['new', 'accepted', 'partially_filled', 'done_for_day'] as $status) {
    $s = $base; $s['open_orders'][0]['status'] = $s['intents'][1]['status'] = $status;
    $s['broker_order_observations'][$stop['client_order_id']]['status'] = $status;
    $check(Audit::inspect($s)['ok'], 'Resting acknowledged stop accepted.');
}
$bad(static function (&$s) { $s['open_orders'][0]['stop_price'] = '87.00'; }, 'Stale lower stop rejected.');
$bad(static function (&$s) { $s['checkpoints']['protection:sleeve0:MSFT'] = ['peak_close' => 110.]; }, 'Close-raised requirement cannot be ignored.');
$bad(static function (&$s) { $s['intents'][0]['attempt_count'] = 2; }, 'Duplicate POST attempt rejected.');
$bad(static function (&$s) { $s['intents'][0]['attempt_count'] = -1; }, 'Invalid negative attempt count rejected.');
$bad(static function (&$s) { $s['sleeves'][0]['initial_equity'] = NAN; }, 'Nonfinite initial capital cannot evade cash checks.');
$bad(static function (&$s) { $s['sleeves'][] = $s['sleeves'][0]; }, 'Duplicate sleeve capital rejected.');
$bad(static function (&$s) { $s['intents'][] = $s['intents'][0]; }, 'Duplicate client id rejected.');
$bad(static function (&$s) { $s['intents'][0]['cumulative_fill_notional'] = 299.; }, 'Fill cash inconsistency rejected.');
$bad(static function (&$s) { $s['intents'][0]['status'] = 'planned'; }, 'Planned order cannot have fills.');
$bad(static function (&$s) { $s['sleeves'][0]['cash'] = 701.; }, 'Sleeve cash mismatch rejected.');
$bad(static function (&$s) { $s['account']['cash'] = 701.; }, 'Broker cash mismatch rejected.');
$bad(static function (&$s) { unset($s['account']['cash']); }, 'Missing cash is not zero.');
$bad(static function (&$s) { $s['positions'] = []; }, 'Missing broker shares rejected.');
$bad(static function (&$s) { $s['positions'][0]['qty'] = '3.5'; }, 'Unexpected fractional shares rejected.');
$bad(static function (&$s) { $s['positions'][0]['side'] = 'short'; }, 'Unexpected short position rejected.');
$bad(static function (&$s) { $s['owned_positions'][0]['qty'] = 2; }, 'Ledger ownership mismatch rejected.');
$bad(static function (&$s) { $s['owned_positions'][0]['cost_basis'] = NAN; }, 'Nonfinite cost basis rejected.');
$bad(static function (&$s) { $s['open_orders'][0]['client_order_id'] = 'foreign'; }, 'Foreign broker order rejected.');
$bad(static function (&$s) { $s['open_orders'] = array_fill(0, 50, $s['open_orders'][0]); }, 'Possibly truncated broker page rejected.');
$bad(static function (&$s) use ($buy) { $s['broker_order_observations'][$buy['client_order_id']] = null; }, 'Failed lookup cannot mean no fills.');
$bad(static function (&$s) use ($buy) { $s['broker_order_observations'][$buy['client_order_id']]['filled_avg_price'] = '101'; }, 'Actual price mismatch rejected.');
$bad(static function (&$s) use ($buy) { $s['broker_order_observations'][$buy['client_order_id']]['filled_qty'] = '2'; }, 'Broker fill race requires another observation.');
$bad(static function (&$s) use ($buy) { $s['broker_order_observations'][$buy['client_order_id']]['symbol'] = 'AAPL'; }, 'Even a historical filled order must match the immutable body.');
$bad(static function (&$s) use ($buy) { $s['broker_order_observations'][$buy['client_order_id']]['qty'] = '4'; }, 'Direct lookup validates original quantity.');
$bad(static function (&$s) use ($stop) { $s['broker_order_observations'][$stop['client_order_id']]['stop_price'] = '87'; }, 'Direct lookup validates stop price.');
$s = $base; $partial = $intent('entry', 'canceled', 5, 3, 100.); $s['intents'][0] = $partial;
$s['broker_order_observations'] = [$partial['client_order_id'] => $broker($partial), $stop['client_order_id'] => $broker($stop)];
$check(Audit::inspect($s)['ok'], 'Canceled partial entry needs protection for three filled, not five requested.');
$s = $base; $s['intents'] = [$intent('entry', 'new', 3, 0, 0.)]; $s['open_orders'] = [$broker($s['intents'][0])];
$s['broker_order_observations'] = [$s['intents'][0]['client_order_id'] => $s['open_orders'][0]];
$s['positions'] = $s['owned_positions'] = []; $s['account']['cash'] = $s['sleeves'][0]['cash'] = 1000.;
$r = Audit::inspect($s); $check($r['ok'] && $r['status'] === 'flat_or_waiting' && $r['filled_buy_shares'] === 0, 'Accepted entry is not an opened position.');
$files = CandidateRelease::files(dirname(__DIR__));
$check(!isset($files['src/Research/PaperForwardExecutionAudit.php']) && !isset($files['tools/audit_candidate_forward.php']), 'Auditor is outside deployed identity.');
$tool = file_get_contents(dirname(__DIR__) . '/tools/audit_candidate_forward.php');
$check(!str_contains($tool, '->submitOrder(') && !str_contains($tool, '->cancelOrder(') && str_contains($tool, '?mode=ro'), 'Tool has no execution path and opens SQLite read-only.');
echo "Paper forward execution audit: $n assertions PASS\n";
