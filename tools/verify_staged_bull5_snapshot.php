#!/usr/bin/env php
<?php
declare(strict_types=1);
use FulltimeTrading\Paper\CandidateDefinition as Definition;
use FulltimeTrading\Paper\CandidateDataSnapshot as Data;
use FulltimeTrading\Paper\CandidateSignalArtifact as Artifact;
use FulltimeTrading\Paper\CandidateRelease as Release;
use FulltimeTrading\Paper\CandidateOrder as Order;
use FulltimeTrading\Paper\CandidateLedger as Ledger;
use FulltimeTrading\Paper\CandidateCloseEngine as Engine;
use FulltimeTrading\Research\SelectedMaximumResearch as Hash;
use FulltimeTrading\Research\AlgorithmTrendResearch as Writer;
require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__); $candidate = require $root . '/config/paper_candidate.php';
if (!is_file($root . '/stage_sources.json') || is_file($root . '/.env') || is_file($root . '/var/run/candidate_commission.json')
    || $candidate['paper_only'] !== true || $candidate['live_enabled'] !== false
    || Definition::PROFILE !== 'maximum-stop12-costband2-whole-bull5-v1') { throw new RuntimeException('Run only inside uncommissioned, credential-free bull5 stage.'); }
$expected = Data::read($root . '/var/reports/staging/expected_snapshot.json'); $date = $expected['as_of']; $next = '2026-09-15';
$n = 0; $check = static function (bool $ok, string $why) use (&$n): void { ++$n; if (!$ok) { throw new RuntimeException($why); } };
$inputs = Data::load($root, $date, $candidate); $hash = Release::hash($root); $artifact = Artifact::build($inputs, $candidate, $hash, $next);
Artifact::write($root . '/var/reports/staging/signal.json', $artifact);
$decoded = Artifact::validate(Data::read($root . '/var/reports/staging/signal.json'), $candidate, $hash, require $root . '/config/tactical_rotation.php');
foreach (['books' => 'books', 'contexts' => 'context_answers', 'nominal_closes' => 'nominal_closes', 'confirmation' => 'confirmation', 'provenance' => 'provenance'] as $expectedKey => $inputKey) {
    $actual = $expectedKey === 'books' ? $artifact['books'] : $inputs[$inputKey];
    $check(json_encode($actual) === json_encode($expected[$expectedKey]), 'Independent snapshot mismatch: ' . $expectedKey);
}
$check($artifact['indicator_recipe'] === $expected['recipe_id'], 'Explicit indicator recipe.');
foreach (['indicator_recipe', 'profile', 'runtime_hash', 'allocation'] as $fault) {
    $bad = $artifact;
    if ($fault === 'allocation') { $bad['books'][array_key_first($bad['books'])]['allocation'] = .9; }
    else { $bad[$fault] = 'wrong-recipe-or-identity'; }
    $bad['content_sha256'] = hash('sha256', Order::json(array_diff_key($bad, ['content_sha256' => true, 'generated_at' => true])));
    try { Artifact::validate($bad, $candidate, $hash, require $root . '/config/tactical_rotation.php'); }
    catch (RuntimeException) { $check(true, $fault); continue; }
    $check(false, 'Re-signed identity corruption accepted.');
}
$path = tempnam(sys_get_temp_dir(), 'bull5-snapshot-ledger-');
try {
    $ledger = new Ledger($path); $run = 'bull5-snapshot-fixture'; $allocations = array_map(static fn ($b): float => $b['allocation'], $inputs['books']);
    $ledger->provision(['run_id' => $run, 'profile' => Definition::PROFILE, 'strategy_hash' => str_repeat('a', 64), 'runtime_hash' => $hash,
        'data_contract' => ['execution_contract' => Order::CONTRACT, 'paper_only' => true]], $allocations);
    $ledger->activate($run, 27567.66, ['positions' => [], 'open_orders' => [], 'adoption' => 'flat_account_only', 'stable_for_seconds' => 120]);
    $engine = new Engine($ledger); $plan = $engine->commit($engine->prepare($run, $date, $next, $decoded['books'], $decoded['contexts'], $decoded['nominal_closes'], $decoded['confirmation'], $decoded['provenance']));
    $check(count($plan['plans']) === 12, 'All twelve persisted books.');
    $check($ledger->checkpoint($run, 'latest_close')['payload'] === $plan, 'Persisted close survives serialization.');
    $targets = [];
    foreach ($plan['plans'] as $name => $p) { if ($p['target_quantities'] !== null) { $targets[$name] = $p['target_quantities']; } }
    $check(count($targets) === 4 && array_sum(array_map(static fn ($q): int => array_sum($q), $targets)) === 18, 'Latest unboosted close yields four frozen entry targets.');
    Writer::write($root . '/var/reports/staging/fault_input.json', ['capital' => 27567.66, 'allocations' => $allocations, 'close' => $plan, 'prices' => $decoded['nominal_closes']]);
    Writer::write($root . '/var/reports/staging/snapshot_verification.json', ['passed' => true, 'assertions' => $n, 'runtime_hash' => $hash,
        'recipe' => Definition::INDICATOR_RECIPE, 'targets' => $targets, 'peak_memory_bytes' => memory_get_peak_usage(true),
        'signal_sha256' => hash_file('sha256', $root . '/var/reports/staging/signal.json'), 'orders_submitted' => 0, 'release_admitted' => false]);
    echo "Staged bull5 snapshot/ledger: $n assertions PASS\n";
} finally {
    unset($engine, $ledger);
    foreach ([$path, $path . '-wal', $path . '-shm'] as $file) { if (is_file($file)) { unlink($file); } }
}
