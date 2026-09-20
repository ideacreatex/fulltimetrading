#!/usr/bin/env php
<?php
declare(strict_types=1);

use FulltimeTrading\Paper\CandidateDataSnapshot as Data;
use FulltimeTrading\Paper\CandidateOrder as Order;
use FulltimeTrading\Paper\CandidateRelease as Release;
use FulltimeTrading\Paper\CandidateSignalArtifact as Artifact;
use FulltimeTrading\Support\ProcessLock;
use FulltimeTrading\Support\WeeklyOpenReleaseEvidence as Repair;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__); $reference = realpath($argv[1] ?? '') ?: '';
if (count($argv) !== 2 || $reference === $root || realpath($reference . '/var/staging/weekly-open-repair-20260919') !== $root
    || file_exists($root . '/.env') || file_exists($root . '/var/db/trading.sqlite') || file_exists($root . '/var/run/candidate_commission.json')) {
    throw new RuntimeException('Requires the isolated, credential-free, uncommissioned repair stage');
}
$lock = ProcessLock::tryAcquire($root . '/var/run/weekly_open_regressions.lock');
if ($lock === null) { exit(75); }
$n = 0; $check = static function (bool $ok, string $why) use (&$n): void { ++$n; if (!$ok) { throw new RuntimeException($why); } };
$candidate = require $root . '/config/paper_candidate.php'; $refConfig = require $reference . '/config/paper_candidate.php';
Repair::configuration($candidate, $refConfig);
$manifest = Release::verify($root, $candidate); $refManifest = Release::verify($reference, $refConfig);
$check($refManifest['runtime_hash'] === Repair::REFERENCE_HASH, 'Unexpected operational reference');
$admission = Data::read($root . '/' . $manifest['proof_path']);
Repair::inspect($root, $reference, $admission['repair_binding']['receipt_path']);
$sourcePath = 'var/reports/daily/candidate_signal.json'; $sourceHash = hash_file('sha256', $reference . '/' . $sourcePath);
$expected = Data::read($reference . '/' . $sourcePath);
Artifact::validate($expected, $refConfig, $refManifest['runtime_hash'], require $reference . '/config/tactical_rotation.php');
$date = $expected['as_of']; $next = $expected['scheduled_session'];
$suffix = str_replace('-', '', $date); $inputs = [];
foreach (['execution' => ['raw', 'split', 'protocol', 'raw_manifest', 'split_manifest'], 'external' => ['s5tw', 'vvix', 'manifest']] as $kind => $names) {
    foreach ($names as $name) {
        $path = 'var/reports/candidate_' . $kind . '_data_' . $suffix . '/' . $name . '.json';
        $sha = hash_file('sha256', $reference . '/' . $path);
        if (!is_dir(dirname($root . '/' . $path))) { mkdir(dirname($root . '/' . $path), 0700, true); }
        $check(copy($reference . '/' . $path, $root . '/' . $path) && hash_file('sha256', $root . '/' . $path) === $sha,
            'Dated input copy mismatch: ' . $path);
        $inputs[$path] = $sha;
    }
}
$loaded = Data::load($root, $date, $candidate);
$actual = Artifact::build($loaded, $candidate, $manifest['runtime_hash'], $next);
Artifact::validate($actual, $candidate, $manifest['runtime_hash'], require $root . '/config/tactical_rotation.php');
Data::verifyProvenance($root, $actual['provenance']);
$identity = array_fill_keys(['run_id', 'runtime_hash', 'content_sha256', 'generated_at'], true);
$check(Order::json(array_diff_key($actual, $identity)) === Order::json(array_diff_key($expected, $identity)),
    'Current dated signal must differ only in release identity, not decisions or data');
foreach ($inputs as $path => $sha) {
    $check(hash_file('sha256', $reference . '/' . $path) === $sha && hash_file('sha256', $root . '/' . $path) === $sha, 'Input drift during rebuild');
}
$check(hash_file('sha256', $reference . '/' . $sourcePath) === $sourceHash, 'Reference signal changed during rebuild');
$check(Release::verify($reference, $refConfig)['runtime_hash'] === $refManifest['runtime_hash']
    && Release::verify($root, $candidate)['runtime_hash'] === $manifest['runtime_hash'], 'Release changed during rebuild');
$check(memory_get_peak_usage(true) <= 512 * 1024 * 1024, 'Full signal exceeds service memory cap');
$signalPath = 'var/reports/weekly_open_current_signal/' . $suffix . '/signal.json';
Artifact::write($root . '/' . $signalPath, $actual);
$proof = ['schema' => 'weekly-open-dated-signal-parity-v1', 'completed_at' => gmdate(DATE_ATOM), 'passed' => true,
    'assertions' => $n, 'as_of' => $date, 'scheduled_session' => $next, 'run_id' => $candidate['run_id'],
    'runtime_hash' => $manifest['runtime_hash'], 'reference_runtime_hash' => $refManifest['runtime_hash'],
    'reference_signal_sha256' => $sourceHash, 'reference_manifest_sha256' => hash_file('sha256', $reference . '/' . $refConfig['release_manifest']),
    'admission_proof_sha256' => $manifest['proof_sha256'], 'input_sha256' => $inputs,
    'signal_path' => $signalPath, 'signal_sha256' => hash_file('sha256', $root . '/' . $signalPath),
    'script_sha256' => hash_file('sha256', __FILE__), 'peak_memory_bytes' => memory_get_peak_usage(true),
    'orders_submitted' => 0, 'broker_requests' => 0, 'operational_database_modified' => false,
    'deployed' => false, 'fresh_current_broker_preflight' => false,
    'scope' => 'Full rebuild from copied hash-verified Alpaca/external histories and comparison to the source-bound operational artifact. No independent current broker calendar/account call; not permission to install or enter.'];
$out = 'var/reports/weekly_open_current_signal/' . $suffix . '/verification.json';
Artifact::write($root . '/' . $out, $proof);
echo json_encode(['receipt' => $out] + $proof, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
