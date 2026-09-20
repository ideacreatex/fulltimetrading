<?php
declare(strict_types=1);

use FulltimeTrading\Paper\CandidateDataSnapshot as Data;
use FulltimeTrading\Paper\CandidateRelease as Release;
use FulltimeTrading\Paper\CandidateSignalArtifact as Artifact;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__); $candidate = require $root . '/config/paper_candidate.php';
if ((!is_file($root . '/stage_sources.json') && ($candidate['run_id'] ?? null) !== \FulltimeTrading\Support\WeeklyOpenReleaseEvidence::RUN)
    || is_file($root . '/.env') || is_file($root . '/var/db/trading.sqlite') || is_file($root . '/var/run/candidate_commission.json')) {
    throw new RuntimeException('Run this post-admission test inside the isolated stage.');
}
$manifest = Release::verify($root, $candidate); $n = 0;
$check = static function (bool $ok, string $why) use (&$n): void { ++$n; if (!$ok) { throw new RuntimeException($why); } };
$check($manifest['paper_admission'] === true && $manifest['live_approved'] === false, 'Only experimental paper admission.');
$check(!Release::commissioned($root, $candidate['run_id'], $manifest['runtime_hash']), 'Admission cannot commission or arm the stage.');
$check(!is_file($root . '/var/db/trading.sqlite'), 'Admission cannot provision the operational ledger.');
$fixture = sys_get_temp_dir() . '/bull5-release-test-' . bin2hex(random_bytes(8)); mkdir($fixture, 0700);
try {
    foreach (Release::files($root) as $file => $_) {
        if (!is_dir(dirname($fixture . '/' . $file))) { mkdir(dirname($fixture . '/' . $file), 0700, true); }
        copy($root . '/' . $file, $fixture . '/' . $file);
    }
    $proof = Data::read($root . '/' . $manifest['proof_path']);
    $reject = static function (array $m, array $p) use ($fixture, $candidate, $check): void {
        Artifact::write($fixture . '/' . $m['proof_path'], $p);
        $m['proof_sha256'] = hash_file('sha256', $fixture . '/' . $m['proof_path']);
        Artifact::write($fixture . '/' . $candidate['release_manifest'], $m);
        try { Release::verify($fixture, $candidate); }
        catch (RuntimeException) { $check(true, 'Rejected'); return; }
        $check(false, 'Corrupt or ineligible release accepted.');
    };
    foreach (['minute_audit_verified', 'close_parity_verified', 'capital_sensitivity_verified', 'full_command_contract_verified', 'fault_matrix_verified'] as $flag) {
        $bad = $proof; $bad[$flag] = false; $reject($manifest, $bad);
    }
    foreach (['profile', 'run_id', 'runtime_hash'] as $field) { $bad = $manifest; $bad[$field] = 'old-or-different-release'; $reject($bad, $proof); }
    $bad = $manifest; $bad['live_approved'] = true; $reject($bad, $proof);
    $bad = $manifest; $bad['capital_reviewed'] += 1; $reject($bad, $proof);
    file_put_contents($fixture . '/tools/verify_staged_bull5_release.php', "\n// integrity mutation in temporary fixture\n", FILE_APPEND);
    $reject($manifest, $proof);
    echo "Staged bull5 release: $n post-admission assertions PASS; no commission or broker access\n";
} finally {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
    rmdir($fixture);
}
