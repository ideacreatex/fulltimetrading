<?php
declare(strict_types=1);

use FulltimeTrading\Paper\CandidateOrder as Order;
use FulltimeTrading\Support\WeeklyOpenReleaseEvidence as Evidence;

require dirname(__DIR__) . '/bootstrap.php';
$n = 0;
$check = static function (bool $ok, string $why) use (&$n): void { ++$n; if (!$ok) { throw new RuntimeException($why); } };
$reject = static function (callable $test, string $why) use ($check): void {
    try { $test(); } catch (RuntimeException) { $check(true, $why); return; }
    $check(false, 'Accepted corrupt evidence: ' . $why);
};
$refConfig = ['run_id' => 'hybrid-v4-bull5-2026-09-15', 'predecessor_run_id' => 'older', 'release_manifest' => 'old.json',
    'paper_only' => true, 'live_enabled' => false, 'entry_limits' => ['gross' => 1.3]];
$candidate = array_replace($refConfig, ['run_id' => Evidence::RUN, 'predecessor_run_id' => $refConfig['run_id'], 'release_manifest' => Evidence::MANIFEST]);
Evidence::configuration($candidate, $refConfig); $check(true, 'Distinct package identity with unchanged execution settings');
foreach (['run_id' => $refConfig['run_id'], 'predecessor_run_id' => 'other', 'release_manifest' => 'old.json',
    'paper_only' => false, 'live_enabled' => true, 'entry_limits' => ['gross' => 9.]] as $key => $value) {
    $reject(static fn () => Evidence::configuration(array_replace($candidate, [$key => $value]), $refConfig), $key);
}
$sha = str_repeat('a', 64); $other = str_repeat('b', 64);
$files = ['config/paper_candidate.php' => $sha, 'src/Paper/CandidateEntryBatch.php' => $sha,
    Evidence::SCHEDULER => Evidence::SCHEDULER_HASH, 'tools/verify_staged_bull5_release.php' => $sha];
$priorFiles = $files;
foreach (['config/paper_candidate.php', Evidence::SCHEDULER, 'tools/verify_staged_bull5_release.php'] as $path) { $priorFiles[$path] = $other; }
$reference = ['runtime_hash' => Evidence::REFERENCE_HASH, 'proof_sha256' => $sha, 'files' => $priorFiles];
$required = Evidence::requiredPrograms(['tests' => ['candidate_cycle_contract' => [], 'tests/candidate_cycle_contract.php' => []]]);
$check(count(array_keys($required, 'tests/candidate_cycle_contract.php', true)) === 1, 'Legacy and path test keys deduplicated');
$reject(static fn () => Evidence::requiredPrograms(['tests' => ['../escape.php' => []]]), 'Unsafe reference test path');
$runtime = hash('sha256', Order::json($files));
$inputPaths = ['candidate_execution_data_20260914/raw', 'candidate_execution_data_20260914/split',
    'candidate_execution_data_20260914/protocol', 'candidate_execution_data_20260914/raw_manifest', 'candidate_execution_data_20260914/split_manifest',
    'candidate_external_data_20260914/s5tw', 'candidate_external_data_20260914/vvix', 'candidate_external_data_20260914/manifest', 'staging/expected_snapshot'];
$inputs = []; foreach ($inputPaths as $path) { $inputs['var/reports/' . $path . '.json'] = $sha; }
$proof = array_fill_keys(['passed', 'complete', 'reference_runtime_unchanged', 'tested_runtime_unchanged', 'support_files_unchanged',
    'stage_inputs_unchanged', 'isolation_verified', 'credential_environment_stripped', 'network_transport_disabled'], true)
    + array_fill_keys(['release_admission_proof', 'release_manifest_published', 'deployed', 'operational_database_modified'], false)
    + ['schema' => 'weekly-open-isolated-regressions-v1', 'real_orders_submitted' => 0, 'failed_programs' => [], 'deferred_programs' => [],
        'runtime_hash' => $runtime, 'runtime_files' => $files, 'reference_runtime_hash' => Evidence::REFERENCE_HASH, 'reference_proof_sha256' => $sha,
        'results' => array_fill_keys($required, ['exit_code' => 0, 'sha256' => $sha]), 'programs' => count($required),
        'support_files' => [Evidence::HELPER => $sha, 'tests/fixtures/candidate_weekly_cli_runtime.php' => $sha], 'runner_sha256' => $sha,
        'stage_input_inventory' => ['runtime_files' => $files, 'runtime_hash' => $runtime, 'reference_runtime_hash' => Evidence::REFERENCE_HASH,
            'credentials_copied' => false, 'release_admitted' => false, 'input_sha256' => $inputs],
        'snapshot_verification' => ['passed' => true, 'runtime_hash' => $runtime, 'orders_submitted' => 0, 'release_admitted' => false,
            'peak_memory_bytes' => 369098752, 'signal_sha256' => $sha]];
$hash = static fn (string $path): string => $sha;
$validate = static fn (array $p) => Evidence::regression($p, $files, $reference, $required, $hash);
$validate($proof); $check(true, 'Complete tested package accepted as evidence, not commission');
foreach (array_keys($proof) as $key) {
    $bad = $proof; unset($bad[$key]); $reject(static fn () => $validate($bad), 'Missing ' . $key);
}
foreach (['passed' => 'true', 'complete' => false, 'deployed' => true, 'real_orders_submitted' => 1,
    'operational_database_modified' => true, 'deferred_programs' => ['skipped'], 'failed_programs' => ['failed'],
    'runtime_hash' => $other, 'reference_runtime_hash' => $other, 'reference_proof_sha256' => $other, 'programs' => 999,
    'runner_sha256' => $other] as $key => $value) {
    $bad = array_replace($proof, [$key => $value]); $reject(static fn () => $validate($bad), 'Changed ' . $key);
}
foreach (['test_exit', 'test_hash', 'missing_test', 'substituted_test', 'support_hash', 'missing_support', 'input_hash',
    'missing_input', 'escaped_input', 'inventory_runtime', 'snapshot_hash', 'snapshot_runtime', 'snapshot_memory', 'snapshot_orders'] as $fault) {
    $bad = $proof; $test = $required[0]; $input = array_key_first($inputs);
    switch ($fault) {
        case 'test_exit': $bad['results'][$test]['exit_code'] = 1; break;
        case 'test_hash': $bad['results'][$test]['sha256'] = $other; break;
        case 'missing_test': unset($bad['results'][$test]); break;
        case 'substituted_test': $bad['results']['tests/not_the_required_test.php'] = $bad['results'][$test]; unset($bad['results'][$test]); break;
        case 'support_hash': $bad['support_files'][Evidence::HELPER] = $other; break;
        case 'missing_support': unset($bad['support_files'][Evidence::HELPER]); break;
        case 'input_hash': $bad['stage_input_inventory']['input_sha256'][$input] = $other; break;
        case 'missing_input': unset($bad['stage_input_inventory']['input_sha256'][$input]); break;
        case 'escaped_input': $bad['stage_input_inventory']['input_sha256']['../escape'] = $sha; unset($bad['stage_input_inventory']['input_sha256'][$input]); break;
        case 'inventory_runtime': $bad['stage_input_inventory']['runtime_hash'] = $other; break;
        case 'snapshot_hash': $bad['snapshot_verification']['signal_sha256'] = $other; break;
        case 'snapshot_runtime': $bad['snapshot_verification']['runtime_hash'] = $other; break;
        case 'snapshot_memory': $bad['snapshot_verification']['peak_memory_bytes'] = 600 * 1024 * 1024; break;
        case 'snapshot_orders': $bad['snapshot_verification']['orders_submitted'] = 1; break;
    }
    $reject(static fn () => $validate($bad), $fault);
}
// Even a fully re-signed receipt cannot expand the explicitly reviewed source delta.
foreach (['src/Paper/CandidateEntryBatch.php', Evidence::SCHEDULER, 'src/Paper/Unreviewed.php'] as $path) {
    $changed = $files; $changed[$path] = $other; ksort($changed, SORT_STRING); $bad = $proof;
    $bad['runtime_files'] = $changed; $bad['runtime_hash'] = hash('sha256', Order::json($changed));
    $bad['stage_input_inventory']['runtime_files'] = $changed; $bad['stage_input_inventory']['runtime_hash'] = $bad['runtime_hash'];
    $bad['snapshot_verification']['runtime_hash'] = $bad['runtime_hash'];
    $reject(static fn () => Evidence::regression($bad, $changed, $reference, $required, $hash), 'Unreviewed source ' . $path);
}
$reject(static fn () => Evidence::regression($proof, $files, $reference, $required,
    static fn (string $path): string => $path === Evidence::HELPER ? $other : $sha), 'Helper changed after successful test receipt');
echo "Weekly-open release evidence: $n assertions PASS; no database, broker or manifest writes\n";
