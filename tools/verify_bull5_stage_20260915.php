#!/usr/bin/env php
<?php
declare(strict_types=1);
use FulltimeTrading\Paper\CandidateRelease as Release;
use FulltimeTrading\Research\AlgorithmTrendResearch as Writer;
require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__); $stage = $root . '/var/staging/bull5-v1'; $out = $root . '/var/reports/bull5_admission_20260915';
$lock = \FulltimeTrading\Support\ProcessLock::tryAcquire($root . '/var/run/bull5_stage_verification.lock'); if ($lock === null) { exit(75); }
$read = static fn ($p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
$active = Release::verify($root, require $root . '/config/paper_candidate.php'); $source = $read($stage . '/stage_sources.json');
foreach ($source['files'] as $path => $record) {
    if (hash_file('sha256', $stage . '/' . $path) !== $record['sha256'] || hash_file('sha256', $root . '/' . $record['source']) !== $record['sha256']) {
        throw new RuntimeException('Rebuild stage after source changes.');
    }
}
if (!is_dir($out)) { mkdir($out, 0775, true); }
$prior = $read($root . '/' . $active['proof_path']);
$commands = [['tools/verify_staged_bull5_snapshot.php']];
foreach (array_keys($prior['tests']) as $test) { $commands[] = ['tests/' . $test . '.php']; }
foreach (['staged_bull5_fault_matrix', 'staged_bull5_maps', 'paper_market_commentary', 'candidate_opg_auction_boundary'] as $test) { $commands[] = ['tests/' . $test . '.php']; }
$results = []; $env = ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => $stage . '/home', 'TMPDIR' => sys_get_temp_dir()];
foreach ($commands as [$file]) {
    $command = [PHP_BINARY, '-d', 'memory_limit=512M', '-d', 'allow_url_fopen=0', '-d', 'disable_functions=curl_exec,fsockopen,stream_socket_client', $stage . '/' . $file];
    $p = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $stage, $env);
    if (!is_resource($p)) { throw new RuntimeException('Cannot start staged verification.'); }
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]); $exit = proc_close($p);
    $results[$file] = ['exit_code' => $exit, 'output' => $output, 'sha256' => hash_file('sha256', $stage . '/' . $file)];
    echo $file, $exit === 0 ? " PASS\n" : " FAIL\n";
    Writer::write($out . '/stage_progress.json', ['results' => $results, 'complete' => false]);
}
$bad = array_keys(array_filter($results, static fn ($r): bool => $r['exit_code'] !== 0));
$unchanged = Release::verify($root, require $root . '/config/paper_candidate.php')['runtime_hash'] === $active['runtime_hash'];
$isolated = !is_file($stage . '/.env') && !is_file($stage . '/var/db/trading.sqlite') && !is_file($stage . '/var/run/candidate_commission.json');
$proof = ['completed_at' => gmdate(DATE_ATOM), 'stage_source_sha256' => hash_file('sha256', $stage . '/stage_sources.json'),
    'stage_source_files' => $source['files'], 'script_sha256' => hash_file('sha256', __FILE__), 'results' => $results,
    'programs' => count($results), 'failed_programs' => $bad, 'snapshot' => $read($stage . '/var/reports/staging/snapshot_verification.json'),
    'passed' => $bad === [] && $unchanged && $isolated, 'active_runtime_unchanged' => $unchanged, 'isolation_verified' => $isolated,
    'network_disabled' => true, 'credential_environment_stripped' => true, 'real_orders_submitted' => 0,
    'new_release_admitted' => false, 'scope' => 'Uncommissioned credential-free staged runtime, full CLI refusal contract, real dated snapshot -> ledger -> fake-broker fault path. Test manifests live only in temporary fixtures and are removed, never published as release evidence. A separate variant-specific verifier is required for the staged release manifest.'];
Writer::write($out . '/stage.json', $proof); echo json_encode(array_diff_key($proof, ['results' => true, 'stage_source_files' => true, 'snapshot' => true]), JSON_PRETTY_PRINT), "\n";
exit($proof['passed'] ? 0 : 2);
