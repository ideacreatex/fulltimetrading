<?php

declare(strict_types=1);

use FulltimeTrading\Backtest\CausalTacticalRotationBacktester;
use FulltimeTrading\Backtest\CausalTacticalRotationEnsembleBacktester;
use FulltimeTrading\Domain\Bar;
use FulltimeTrading\Storage\TacticalPaperRepository;
use FulltimeTrading\Trading\TacticalPaperSignalEpoch;

require dirname(__DIR__) . '/bootstrap.php';

function epochAssert(bool $ok, string $message): void
{
    if (!$ok) { throw new RuntimeException($message); }
}
function epochReject(callable $call, string $message): void
{
    try { $call(); } catch (RuntimeException|InvalidArgumentException) { return; }
    throw new RuntimeException($message);
}
$epoch = ['mode' => 'fresh_flat_paper_model', 'run_id' => 'new', 'predecessor_run_id' => 'old',
    'seed_close' => '2024-01-06', 'initial_equity' => 27567.66];
$paper = ['run_id' => 'new', 'paper_only' => true, 'signal_epoch' => $epoch];
epochAssert(TacticalPaperSignalEpoch::fromConfig($paper, '2024-01-06') === $epoch, 'Valid epoch must retain its exact identity.');
epochReject(fn () => TacticalPaperSignalEpoch::fromConfig($paper, '2024-01-05'), 'Future seed must be rejected.');
epochReject(fn () => TacticalPaperSignalEpoch::fromConfig(array_replace($paper, ['paper_only' => false]), '2024-01-06'), 'Live must never accept a paper reset.');
epochReject(fn () => TacticalPaperSignalEpoch::assertActivationEquity($epoch, 27500.0), 'Changed broker capital requires a new verified baseline.');
TacticalPaperSignalEpoch::assertActivationEquity($epoch, 27567.66);
$bars = [];
foreach (['QQQ', 'AAA'] as $symbol) {
    foreach ([100.0, 101.0, 103.0, 106.0, 80.0, 85.0] as $i => $close) {
        $bars[$symbol][] = new Bar($symbol, new DateTimeImmutable('2024-01-01T21:00:00Z +' . $i . ' days'), $close, $close, $close, $close, 10000000.0);
    }
}
$config = ['benchmark' => 'QQQ', 'universe' => ['AAA'], 'factor_weights' => [1 => 1.0],
    'volatility_period' => 2, 'volatility_score_power' => 0.0, 'volatility_target' => 10.0,
    'benchmark_sma_period' => 2, 'dollar_volume_period' => 1, 'minimum_history_sessions' => 2,
    'drawdown_kill_pct' => 0.1, 'cost_bps' => 0.0, 'rebalance_sessions' => 1];
$tester = new CausalTacticalRotationBacktester($config);
$old = $tester->run($bars, '2024-01-01', '2024-01-06');
epochAssert($old['next_target']['circuit_cooldown_left'] > 0, 'Fixture must have an inherited historical circuit.');
epochAssert($old === $tester->run($bars, '2024-01-01', '2024-01-06', 30000.0, true), 'Optional seed support must not change historical replay.');
epochReject(fn () => $tester->run($bars, '2024-01-06', '2024-01-06'), 'Single-close replay requires explicit epoch opt-in.');
$fresh = $tester->run($bars, '2024-01-06', '2024-01-06', 27567.66, true);
epochAssert(count($fresh['curve']) === 1 && $fresh['curve'][0]['equity'] === 27567.66
    && $fresh['curve'][0]['turnover'] === 0.0 && $fresh['next_target']['current_symbol'] === null
    && $fresh['next_target']['circuit_cooldown_left'] === 0 && !$fresh['next_target']['risk_exit_pending'], 'Fresh epoch may use old indicators, never old positions, losses, or cooldown.');
epochAssert($fresh['next_target']['signal_date'] === '2024-01-06' && $fresh['next_target']['symbol'] === 'AAA', 'Warmup must still produce a completed-close next-open target.');
$ensemble = new CausalTacticalRotationEnsembleBacktester(['a' => ['allocation' => 0.6, 'config' => $config], 'b' => ['allocation' => 0.4, 'config' => $config]]);
$seed = $ensemble->run($bars, '2024-01-06', '2024-01-06', 27567.66, true);
epochAssert(abs(array_sum(array_column($seed['next_targets'], 'initial_equity')) - 27567.66) < 1.0e-10, 'New epoch sleeves must conserve actual initial capital.');
$file = tempnam(sys_get_temp_dir(), 'paper-epoch-');
try {
    $repo = new TacticalPaperRepository($file);
    $repo->migrate();
    $identity = ['run_id' => 'old', 'profile' => 'test', 'strategy_hash' => str_repeat('a', 64), 'runtime_hash' => str_repeat('b', 64), 'data_contract' => [], 'live_review_not_before' => '2024-02-01'];
    $allocations = ['aa' => 0.4, 'bb' => 0.2, 'cc' => 0.2, 'dd' => 0.2];
    $repo->ensureRun($identity, $allocations);
    $flat = ['positions' => [], 'open_orders' => [], 'adoption' => 'flat_account_only', 'stable_for_seconds' => 120];
    $repo->activate('old', 30000.0, $flat);
    $oldRun = $repo->run('old');
    $repo->ensureRun(array_replace($identity, ['run_id' => 'new', 'runtime_hash' => str_repeat('c', 64)]), $allocations);
    epochReject(fn () => $repo->activate('new', 27567.66, array_replace($flat, ['stable_for_seconds' => 0, 'predecessor_run_id' => 'old'])), 'Flat stability gate must survive the reset.');
    epochReject(fn () => $repo->activate('new', 27567.66, array_replace($flat, ['predecessor_run_id' => 'missing'])), 'Missing predecessor must roll back activation.');
    epochAssert($repo->run('new')['status'] === 'transition' && $repo->run('old')['status'] === 'active', 'Rejected resets must not mutate either run.');
    $repo->activate('new', 27567.66, array_replace($flat, ['predecessor_run_id' => 'old']));
    foreach (['initial_equity', 'activated_at', 'runtime_hash', 'strategy_hash'] as $key) {
        epochAssert($repo->run('old')[$key] === $oldRun[$key], 'Historical evidence must not be rewritten: ' . $key);
    }
    epochAssert($repo->run('old')['status'] === 'paused' && (float) $repo->run('new')['initial_equity'] === 27567.66, 'Only successor starts with current capital.');
} finally { unset($repo); unlink($file); }
echo "Tactical paper signal epoch tests OK\n";
