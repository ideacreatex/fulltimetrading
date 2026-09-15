#!/usr/bin/env php
<?php
declare(strict_types=1);
use FulltimeTrading\Paper\CandidateRelease;
use FulltimeTrading\Paper\CandidateSignalArtifact;
use FulltimeTrading\Research\AlgorithmTrendResearch as Writer;
use FulltimeTrading\Research\CandidateBullSnapshot as Snapshot;
use FulltimeTrading\Research\SelectedMaximumResearch as Hash;
require dirname(__DIR__) . '/bootstrap.php';
set_error_handler(static function (int $n, string $s): never { throw new RuntimeException($s); });
$root = dirname(__DIR__); $candidate = require $root . '/config/paper_candidate.php'; $profile = require $root . '/config/tactical_rotation.php';
$dir = $root . '/var/reports/candidate_bull_snapshot_20260915';
$lock = \FulltimeTrading\Support\ProcessLock::tryAcquire($root . '/var/run/candidate_bull_snapshot_20260915.lock');
if ($lock === null) { exit(75); }
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) { throw new RuntimeException('Cannot create isolated verification directory.'); }
$runtime = CandidateRelease::verify($root, $candidate)['runtime_hash']; $code = [];
foreach (['src/Research/CandidateBullSnapshot.php', 'src/Research/CandidateBullRiskStudy.php', 'src/Research/CandidateInteractionStudy.php',
    'src/Research/DeployedCandidateStudy.php', 'tests/candidate_bull_snapshot.php', 'tools/verify_candidate_bull_snapshot_20260915.php'] as $path) {
    $code[$path] = hash_file('sha256', $root . '/' . $path);
}
$protocol = ['schema' => 'bull-snapshot-binding-verification-v1', 'date' => '2026-09-14', 'recipes' => Snapshot::IDS,
    'runtime_hash' => $runtime, 'code_sha256' => $code, 'orders_submitted' => 0, 'operational_database_modified' => false,
    'purpose' => 'Two independent filesystem reconstructions, serialized artifacts, self-checksummed tamper rejection and active schema rejection. Not release admission.'];
if (is_file($dir . '/protocol.json') && Hash::hash(json_decode(file_get_contents($dir . '/protocol.json'), true, 512, JSON_THROW_ON_ERROR)) !== Hash::hash($protocol)) {
    throw new RuntimeException('Snapshot verification protocol drift.');
}
Writer::write($dir . '/protocol.json', $protocol); $sha = hash_file('sha256', $dir . '/protocol.json');
echo "Building three recipe-bound close snapshots from verified provider artifacts.\n";
$built = Snapshot::buildAll($root, $protocol['date'], $candidate); $artifacts = [];
foreach ($built as $id => $a) { Writer::write($dir . '/' . $id . '.json', $a); $artifacts[$id] = json_decode(file_get_contents($dir . '/' . $id . '.json'), true, 512, JSON_THROW_ON_ERROR); }
unset($built); gc_collect_cycles();
echo "Recomputing trusted expectations independently of saved artifacts.\n";
$trusted = Snapshot::buildAll($root, $protocol['date'], $candidate); $receipts = []; $n = 0;
$check = static function (bool $ok, string $why) use (&$n): void { ++$n; if (!$ok) { throw new RuntimeException($why); } };
foreach ($artifacts as $id => $a) {
    Snapshot::assertMatches($a, $trusted[$id], $id); $check(true, 'Exact reconstruction');
    $check(count($a['books']) === 12 && count($a['contexts']) === 12, 'Twelve sleeves');
    foreach (['confirmation', 'context', 'source', 'scale', 'profile', 'future_date', 'recipe'] as $fault) {
        $bad = $a; $first = array_key_first($bad['contexts']);
        switch ($fault) {
            case 'confirmation': $bad['confirmation'] = !$bad['confirmation']; break;
            case 'context': $bad['contexts'][$first]['']['desired'] = []; break;
            case 'source': $bad['source_sha256']['split'] = str_repeat('0', 64); break;
            case 'scale': $bad['daily_scale'] += .05; break;
            case 'profile': $bad['base_profile_sha256'] = str_repeat('0', 64); break;
            case 'future_date': $bad['as_of'] = '2026-09-15'; break;
            case 'recipe': $bad['recipe']['bull']['boost'] = 1.10; break;
        }
        $bad['content_sha256'] = Snapshot::contentHash($bad);
        try { Snapshot::assertMatches($bad, $trusted[$id], $id); } catch (RuntimeException) { $check(true, $fault); continue; }
        $check(false, 'Tampered snapshot accepted: ' . $fault);
    }
    try { CandidateSignalArtifact::validate($a, $candidate, $runtime, $profile); }
    catch (RuntimeException) {
        $check(true, 'Active contract rejects research artifact');
        $receipts[$id] = ['passed' => true, 'artifact_sha256' => hash_file('sha256', $dir . '/' . $id . '.json'),
            'recipe_sha256' => $a['recipe_sha256'], 'content_sha256' => $a['content_sha256'], 'bullish' => $a['bullish'],
            'daily_scale' => $a['daily_scale'], 'confirmation' => $a['confirmation'], 'matches_deployed_close_contexts' => $a['matches_deployed_close_contexts']];
        continue;
    }
    $check(false, 'Active schema accepted research snapshot.');
}
foreach ($code as $path => $hash) { $check(hash_file('sha256', $root . '/' . $path) === $hash, 'Builder changed during verification.'); }
$check(CandidateRelease::verify($root, $candidate)['runtime_hash'] === $runtime, 'Active release changed.');
$summary = ['protocol_sha256' => $sha, 'complete' => count($receipts) === 3, 'assertions' => $n, 'receipts' => $receipts,
    'peak_memory_bytes' => memory_get_peak_usage(true), 'orders_submitted' => 0, 'operational_database_modified' => false,
    'runtime_unchanged' => true, 'release_admitted' => false, 'scope' => 'Isolated dated snapshot binding, not broker/persisted-ledger admission.'];
Writer::write($dir . '/summary.json', $summary); echo json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
