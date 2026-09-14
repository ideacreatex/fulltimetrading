<?php
declare(strict_types=1);

use FulltimeTrading\Paper\CandidateLedger as Ledger;
use FulltimeTrading\Paper\CandidateOrder as Order;
use FulltimeTrading\Paper\CandidateDefinition as Definition;
use FulltimeTrading\Paper\CandidateRuntimeSelection as Selection;
use FulltimeTrading\Paper\CandidateMessages as Messages;

require dirname(__DIR__) . '/bootstrap.php';
$n = 0; $check = static function (bool $ok, string $why) use (&$n): void { ++$n; if (!$ok) { throw new RuntimeException($why); } };
$root = dirname(__DIR__); $candidate = require $root . '/config/paper_candidate.php'; $legacy = require $root . '/config/tactical_paper.php';
$path = tempnam(sys_get_temp_dir(), 'candidate-report-');
try {
    $ledger = new Ledger($path);
    $check(Selection::select($root, $legacy, $ledger->views) === $legacy, 'Staged config does not pretend to be deployed.');
    $identity = ['run_id' => $candidate['run_id'], 'profile' => Definition::PROFILE, 'strategy_hash' => str_repeat('a', 64), 'runtime_hash' => str_repeat('b', 64),
        'data_contract' => ['execution_contract' => Order::CONTRACT, 'paper_only' => true]];
    $books = Definition::books(require $root . '/config/tactical_rotation.php', [], [], []);
    $alloc = array_map(static fn ($b): float => $b['allocation'], $books); $ledger->provision($identity, $alloc);
    $check(Selection::select($root, $legacy, $ledger->views) === $legacy, 'Transition is not an activated replacement.');
    $ledger->activate($candidate['run_id'], 30000., ['positions' => [], 'open_orders' => [], 'adoption' => 'flat_account_only', 'stable_for_seconds' => 120]);
    $selected = Selection::select($root, $legacy, $ledger->views);
    $check($selected['run_id'] === $candidate['run_id'], 'Reports select actual candidate activation.');
    $run = $ledger->run($candidate['run_id']);
    $check(strtotime($selected['live_review_not_before']) - strtotime($run['activated_at']) === 31 * 86400, 'Report cannot inherit predecessor age.');
    $db = new PDO('sqlite:' . $path); $db->exec("UPDATE tactical_paper_run SET live_review_not_before='2020-01-01'");
    $ledger->provision($identity, $alloc);
    $check(strtotime($ledger->run($candidate['run_id'])['live_review_not_before']) - strtotime($run['activated_at']) === 31 * 86400, 'Interrupted activation repairs only the derived review boundary.');
    $i = Order::make($candidate['run_id'], array_key_first($books), '2026-09-14', '2026-09-15', 'stop', 'protective_stop', 'MSFT', 3, 'gtc', 88.);
    $i += ['status' => 'new', 'order_id' => 'fake', 'cumulative_filled_qty' => 0.];
    $check(Selection::isStandingProtection($i), 'Acknowledged GTC stop is protection, not an unresolved execution.');
    foreach (['planned', 'pending_cancel', 'partially_filled', 'ambiguous'] as $status) {
        $check(!Selection::isStandingProtection(array_replace($i, ['status' => $status])), 'Incomplete stop must remain in monthly unresolved gate.');
    }
    $i['payload']['cancel_request'] = ['at' => gmdate(DATE_ATOM)];
    $check(!Selection::isStandingProtection($i), 'Cancel intent alone is unresolved even before broker status changes.');
    $i['side'] = 'buy'; $i['cumulative_filled_qty'] = 1.; $i['cumulative_fill_notional'] = 100.;
    $check(str_contains(Messages::fill($i, 0), 'Брокер подтвердил покупку'), 'Positive broker fill uses confirmed wording.');
    $rejected = false; try { Messages::fill($i, 1); } catch (InvalidArgumentException) { $rejected = true; }
    $check($rejected, 'No fill increment cannot become a purchase notification.');
    $message = Messages::error(['candidate_signal_invalid:secret bearer token']);
    $check(!str_contains($message, 'secret') && str_contains($message, 'S5TW'), 'Source errors are clear without echoing unsafe broker text.');
    $ledger->pause($candidate['run_id'], 'fixture');
    $check(Selection::select($root, $legacy, $ledger->views)['run_id'] === $candidate['run_id'], 'Pause does not silently show the retired predecessor.');
    echo "candidate_runtime_selection: {$n} assertions PASS\n";
} finally {
    unset($ledger, $db);
    foreach ([$path, $path . '-wal', $path . '-shm'] as $file) { if (is_file($file)) { unlink($file); } }
}
