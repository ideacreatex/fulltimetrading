#!/usr/bin/env php
<?php
declare(strict_types=1);

use FulltimeTrading\Paper\CandidateDataSnapshot as Data;
use FulltimeTrading\Paper\CandidateRelease as Release;
use FulltimeTrading\Paper\CandidateSignalArtifact as Writer;
use FulltimeTrading\Support\ProcessLock;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__); $reference = realpath($argv[1] ?? '') ?: '';
$withData = ($argv[2] ?? null) === '--with-data-bound';
if ((isset($argv[2]) && !$withData) || count($argv) > 3) { throw new InvalidArgumentException('Unknown verification option'); }
if ($reference === '' || $reference === $root || is_file($root . '/.env')
    || is_file($root . '/var/db/trading.sqlite') || is_file($root . '/var/run/candidate_commission.json')) {
    throw new RuntimeException('Requires a separate credential-free, uncommissioned repair worktree and read-only reference root.');
}
$lock = ProcessLock::tryAcquire($root . '/var/run/weekly_open_regressions.lock');
if ($lock === null) { exit(75); }
$manifest = Release::verify($reference, require $reference . '/config/paper_candidate.php');
$prior = Data::read($reference . '/' . $manifest['proof_path']);
$inputInventory = null;
if ($withData) {
    if (is_file($root . '/stage_sources.json')) { throw new RuntimeException('Do not overwrite an existing stage inventory.'); }
    $receiptPath = 'docs/HYBRID_V4_BULL_EXECUTION_2026-09-15.json';
    if (hash_file('sha256', $reference . '/' . $receiptPath) !== ($prior['evidence_sha256'][$receiptPath] ?? null)) {
        throw new RuntimeException('Independent snapshot receipt is not bound to the reference admission.');
    }
    $receipt = Data::read($reference . '/' . $receiptPath);
    $snapshotPath = 'var/reports/candidate_bull_snapshot_20260915/bull_v110_ma50_boost105.json';
    $snapshotHash = hash_file('sha256', $reference . '/' . $snapshotPath);
    if ($snapshotHash !== ($receipt['snapshot_binding']['receipts']['bull_v110_ma50_boost105']['artifact_sha256'] ?? null)) {
        throw new RuntimeException('Independent expected snapshot changed.');
    }
    $snapshot = Data::read($reference . '/' . $snapshotPath);
    $inputHashes = [];
    foreach ($snapshot['source_files'] as $id => $path) {
        if (preg_match('~^var/reports/candidate_(execution|external)_data_20260914/(raw|split|protocol|raw_manifest|split_manifest|s5tw|vvix|manifest)\.json$~D', $path) !== 1
            || hash_file('sha256', $reference . '/' . $path) !== $snapshot['source_sha256'][$id]) {
            throw new RuntimeException('Invalid or changed dated input: ' . $id);
        }
        if (!is_dir(dirname($root . '/' . $path))) { mkdir(dirname($root . '/' . $path), 0700, true); }
        if (!copy($reference . '/' . $path, $root . '/' . $path)
            || hash_file('sha256', $root . '/' . $path) !== $snapshot['source_sha256'][$id]) {
            throw new RuntimeException('Dated input copy mismatch: ' . $id);
        }
        $inputHashes[$path] = $snapshot['source_sha256'][$id];
    }
    if (!is_dir($root . '/var/reports/staging')) { mkdir($root . '/var/reports/staging', 0700, true); }
    if (!copy($reference . '/' . $snapshotPath, $root . '/var/reports/staging/expected_snapshot.json')) {
        throw new RuntimeException('Expected snapshot copy failed.');
    }
    $inputHashes['var/reports/staging/expected_snapshot.json'] = $snapshotHash;
    $inputInventory = ['schema' => 'weekly-open-repair-input-inventory-v1', 'reference_runtime_hash' => $manifest['runtime_hash'],
        'runtime_hash' => Release::hash($root), 'runtime_files' => Release::files($root), 'input_sha256' => $inputHashes,
        'snapshot_receipt_sha256' => hash_file('sha256', $reference . '/' . $receiptPath),
        'credentials_copied' => false, 'release_admitted' => false];
    Writer::write($root . '/stage_sources.json', $inputInventory);
}
$commands = ['tools/verify_staged_bull5_snapshot.php' => true];
foreach (array_keys($prior['tests']) as $test) {
    $file = str_ends_with($test, '.php') ? $test : 'tests/' . $test . '.php';
    if (preg_match('~^(tests|tools)/[a-z0-9_]+\.php$~D', $file) !== 1) { throw new RuntimeException('Invalid test path in reference proof.'); }
    $commands[$file] = true;
}
foreach (['staged_bull5_fault_matrix', 'staged_bull5_maps', 'paper_market_commentary', 'candidate_opg_auction_boundary'] as $name) {
    $commands['tests/' . $name . '.php'] = true;
}
$referenceCommands = array_keys($commands);
$deferred = [
    'tools/verify_staged_bull5_snapshot.php' => 'Requires independently bound full historical snapshot inputs and stage_sources.json.',
    'tests/staged_bull5_fault_matrix.php' => 'Requires the verified real-snapshot fault_input.json, not a synthetic replacement.',
    'tests/staged_bull5_maps.php' => 'Synthetic map test requires an explicit isolated stage inventory.',
];
if ($withData) { $deferred = []; }
foreach (['candidate_weekly_cli', 'candidate_weekly_open_integration', 'tactical_weekly_session_boundary', 'tactical_notification_health_guard'] as $name) {
    $commands['tests/' . $name . '.php'] = true;
}
$commands = array_diff_key($commands, $deferred);
$proof = ['schema' => 'weekly-open-isolated-regressions-v1', 'started_at' => gmdate(DATE_ATOM),
    'runtime_hash' => Release::hash($root), 'runtime_files' => Release::files($root),
    'reference_runtime_hash' => $manifest['runtime_hash'], 'reference_proof_sha256' => $manifest['proof_sha256'],
    'reference_programs' => $referenceCommands, 'deferred_programs' => $deferred, 'results' => [],
    'support_files' => ['tests/fixtures/candidate_weekly_cli_runtime.php' => hash_file('sha256', $root . '/tests/fixtures/candidate_weekly_cli_runtime.php')],
    'stage_input_inventory' => $inputInventory,
    'release_admission_proof' => false, 'release_manifest_published' => false, 'deployed' => false,
    'real_orders_submitted' => 0, 'operational_database_modified' => false,
    'credential_environment_stripped' => true, 'network_transport_disabled' => true,
    'scope' => 'Software regressions only, including data-bound programs when explicitly requested. This is not exact-source release admission or a new historical return study.'];
$out = $root . '/var/reports/weekly_open_regressions/' . gmdate('Ymd_His') . '_' . getmypid() . '.json';
$env = ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => sys_get_temp_dir(), 'TMPDIR' => sys_get_temp_dir()];
foreach (array_keys($commands) as $file) {
    if (!is_file($root . '/' . $file)) { throw new RuntimeException('Missing regression: ' . $file); }
    $p = proc_open([PHP_BINARY, '-d', 'memory_limit=512M', '-d', 'allow_url_fopen=0',
        '-d', 'disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client', $root . '/' . $file],
        [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $root, $env);
    if (!is_resource($p)) { throw new RuntimeException('Cannot start regression: ' . $file); }
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]); $code = proc_close($p);
    $proof['results'][$file] = ['exit_code' => $code, 'output' => $output, 'sha256' => hash_file('sha256', $root . '/' . $file)];
    Writer::write($out, $proof + ['complete' => false]);
    echo $file, $code === 0 ? " PASS\n" : " FAIL\n";
}
$proof['failed_programs'] = array_keys(array_filter($proof['results'], static fn ($r): bool => $r['exit_code'] !== 0));
$proof['programs'] = count($proof['results']);
$proof['reference_runtime_unchanged'] = Release::verify($reference, require $reference . '/config/paper_candidate.php')['runtime_hash'] === $manifest['runtime_hash'];
$proof['tested_runtime_unchanged'] = Release::files($root) === $proof['runtime_files'];
$proof['support_files_unchanged'] = true;
foreach ($proof['support_files'] as $path => $sha) {
    $proof['support_files_unchanged'] = $proof['support_files_unchanged'] && hash_file('sha256', $root . '/' . $path) === $sha;
}
$proof['stage_inputs_unchanged'] = true;
foreach ($inputInventory['input_sha256'] ?? [] as $path => $sha) {
    $proof['stage_inputs_unchanged'] = $proof['stage_inputs_unchanged'] && hash_file('sha256', $root . '/' . $path) === $sha;
}
if ($withData) {
    $proof['snapshot_verification'] = Data::read($root . '/var/reports/staging/snapshot_verification.json');
    $proof['stage_inputs_unchanged'] = $proof['stage_inputs_unchanged']
        && $proof['snapshot_verification']['passed'] === true
        && $proof['snapshot_verification']['runtime_hash'] === $proof['runtime_hash']
        && hash_file('sha256', $root . '/var/reports/staging/signal.json') === $proof['snapshot_verification']['signal_sha256'];
}
$proof['isolation_verified'] = !is_file($root . '/.env') && !is_file($root . '/var/db/trading.sqlite')
    && !is_file($root . '/var/run/candidate_commission.json');
$proof['passed'] = $proof['failed_programs'] === [] && $proof['reference_runtime_unchanged']
    && $proof['tested_runtime_unchanged'] && $proof['support_files_unchanged'] && $proof['stage_inputs_unchanged'] && $proof['isolation_verified'];
$proof['complete'] = true; $proof['completed_at'] = gmdate(DATE_ATOM);
$proof['runner_sha256'] = hash_file('sha256', __FILE__);
Writer::write($out, $proof);
if ($withData) { unlink($root . '/stage_sources.json'); }
echo json_encode(['receipt' => $out, 'passed' => $proof['passed'], 'programs' => $proof['programs'],
    'failed_programs' => $proof['failed_programs'], 'deferred' => array_keys($deferred),
    'release_admission_proof' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
exit($proof['passed'] ? 0 : 2);
