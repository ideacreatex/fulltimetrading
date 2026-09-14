<?php

declare(strict_types=1);

use FulltimeTrading\Paper\CandidateCloseEngine;
use FulltimeTrading\Paper\CandidateDefinition;
use FulltimeTrading\Paper\CandidateLedger;
use FulltimeTrading\Paper\CandidateOrder;
use FulltimeTrading\Research\PaperExecutionRotationBacktester;
use FulltimeTrading\Research\DailyDataAudit;
use FulltimeTrading\Domain\Bar;

require dirname(__DIR__) . '/bootstrap.php';
set_error_handler(static function (int $n, string $s): never { throw new RuntimeException($s); });
$n = 0; $check = static function (bool $ok, string $why) use (&$n): void { ++$n; if (!$ok) { throw new RuntimeException($why); } };
$reject = static function (callable $fn, string $why) use ($check): void { try { $fn(); } catch (Throwable) { $check(true, $why); return; } $check(false, $why); };
$base = DailyDataAudit::withUniverse(require dirname(__DIR__) . '/config/tactical_rotation.php', ['AAA', 'BBB']);
$bars = $nominal = $scale = $features = [];
$dates = []; $time = new DateTimeImmutable('2024-01-02 16:00', new DateTimeZone('America/New_York'));
for ($day = 0; $day < 270; ++$day) {
    $date = $time->format('Y-m-d'); $dates[] = $date; $scale[$date] = 1.0;
    foreach (['AAA', 'BBB', 'SPY', 'QQQ'] as $j => $symbol) {
        $price = 50. * exp(.001 * $day + .002 * sin($day / (5 + $j)));
        $bars[$symbol][] = new Bar($symbol, $time, $price * .998, $price * 1.01, $price * .99, $price, 10000000.);
        $nominal[$symbol][$date] = ['open' => $price * .998, 'close' => $price];
        $features[$symbol][$date] = ['drift' => .001 * (1 + $j)];
    }
    $time = $time->modify('+1 weekday');
}
$books = CandidateDefinition::books($base, $scale, $features, $nominal);
$contexts = [];
foreach ($books as $name => $book) { $contexts[$name] = (new PaperExecutionRotationBacktester($book['config']))->paperSignalContexts($bars, end($dates)); }
$path = tempnam(sys_get_temp_dir(), 'candidate-close-');
try {
    $ledger = new CandidateLedger($path);
    $identity = ['run_id' => 'close-test', 'profile' => CandidateDefinition::PROFILE, 'strategy_hash' => str_repeat('a', 64),
        'runtime_hash' => str_repeat('b', 64), 'data_contract' => ['execution_contract' => CandidateOrder::CONTRACT, 'paper_only' => true]];
    $ledger->provision($identity, array_column($books, 'allocation', null));
} catch (InvalidArgumentException) {
    // Keyed books are mandatory; list allocations must fail before writing a run.
    $check($ledger->run('close-test') === null, 'Bad sleeve definitions did not partially provision a run.');
}
try {
    $allocations = array_map(static fn ($b): float => $b['allocation'], $books);
    $ledger->provision($identity, $allocations);
    $ledger->activate('close-test', 30000., ['positions' => [], 'open_orders' => [], 'adoption' => 'flat_account_only', 'stable_for_seconds' => 120]);
    $engine = new CandidateCloseEngine($ledger); $date = $dates[265]; $next = $dates[266];
    $prices = array_map(static fn ($s): float => $s[$date]['close'], $nominal);
    $proof = ['data_sha256' => str_repeat('c', 64)];
    $prepared = $engine->prepare('close-test', $date, $next, $books, $contexts, $prices, true, $proof);
    $check(!$prepared['already_committed'] && count($prepared['plans']) === 12, 'Preparation covers twelve books.');
    $check(abs($prepared['account_close_nav'] - 30000) < 1e-8, 'Close NAV preserves independent cash books.');
    $check(count(array_filter($prepared['plans'], static fn ($p): bool => $p['target']['rebalance_due_next_session'])) === 4, 'Only phase zero is initially due.');
    foreach ($prepared['plans'] as $name => $plan) {
        $check($plan['executable_permission'] === false, 'Signal preparation does not grant entry permission.');
        if ($plan['target_quantities'] !== null) {
            foreach ($plan['target_quantities'] as $q) { $check(is_int($q), 'Frozen target uses whole shares.'); }
        }
    }
    $bad = $prepared; $first = array_key_first($books); $bad['updates']['sleeve:' . $first]['version'] = 9;
    $reject(fn () => $engine->commit($bad), 'Stale checkpoint is rejected.');
    $check($ledger->checkpoint('close-test', 'circuit') === null && $ledger->checkpoint('close-test', 'latest_close') === null,
        'Failed atomic close rolls back every checkpoint.');
    $payload = $engine->commit($prepared);
    $check($engine->commit($prepared) === $payload, 'Duplicate close is idempotent.');
    $ledger = new CandidateLedger($path); $engine = new CandidateCloseEngine($ledger);
    $check($engine->prepare('close-test', $date, $next, $books, $contexts, $prices, true, $proof)['payload'] === $payload,
        'Frozen plans survive restart.');
    $reject(fn () => $engine->prepare('close-test', $date, $next, $books, $contexts, $prices, true, ['data_sha256' => 'changed']), 'Frozen signal data cannot be revised.');
    $date = $next; $next = $dates[267]; $prices = array_map(static fn ($s): float => $s[$date]['close'], $nominal);
    $prepared = $engine->prepare('close-test', $date, $next, $books, $contexts, $prices, true, $proof);
    $check(count(array_filter($prepared['plans'], static fn ($p): bool => $p['target']['rebalance_due_next_session'])) === 4, 'Next close rotates to phase one, not all twelve books.');
    $entry = CandidateOrder::make('close-test', $first, $dates[265], $dates[266], 'entry-race', 'entry', 'AAA', 1);
    $ledger->create($entry); $ledger->claim($entry['decision_id']);
    $ledger->observe($entry['decision_id'], $entry['payload']['body'] + ['id' => 'fake-fill', 'status' => 'filled', 'filled_qty' => '1', 'filled_avg_price' => '65']);
    $reject(fn () => $engine->commit($prepared), 'Fill during close preparation invalidates the whole transaction.');
    $check($ledger->checkpoint('close-test', 'latest_close')['payload']['date'] === $dates[265], 'Failed close leaves previous plans intact.');
    $reject(fn () => $engine->prepare('close-test', $date, $next, $books, $contexts, $prices, true, $proof), 'A current fill cannot enter a historical close.');
    // This isolated fixture represents a fill observed during that historical session.
    $fixture = new PDO('sqlite:' . $path);
    $q = $fixture->prepare('UPDATE tactical_paper_position SET updated_at=?'); $q->execute([$date . 'T15:00:00-05:00']);
    unset($fixture, $q);
    $prepared = $engine->prepare('close-test', $date, $next, $books, $contexts, $prices, true, $proof);
    $engine->commit($prepared);
    $check($ledger->checkpoint('close-test', 'protection:' . $first . ':AAA')['payload']['last_close_date'] === $date,
        'Completed-close stop update is committed with the risk books.');
    $reject(fn () => $engine->prepare('close-test', $dates[269], '2025-01-20', $books, $contexts, $prices, true, $proof), 'Missing market close cannot silently advance a pause.');
    echo "candidate_close_engine: {$n} assertions PASS\n";
} finally {
    unset($engine, $ledger);
    foreach ([$path, $path . '-wal', $path . '-shm'] as $file) { if (is_file($file)) { unlink($file); } }
}
