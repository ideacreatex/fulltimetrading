#!/usr/bin/env php
<?php
declare(strict_types=1);

use FulltimeTrading\Paper\CandidateDataSnapshot as Data;
use FulltimeTrading\Paper\CandidateDefinition as Definition;
use FulltimeTrading\Paper\CandidateOrder as Order;
use FulltimeTrading\Paper\CandidateRelease as Release;
use FulltimeTrading\Paper\CandidateSignalArtifact as Artifact;
use FulltimeTrading\Research\EconomicBenefitReview as Benefit;
use FulltimeTrading\Research\SelectedMaximumResearch as Hash;
use FulltimeTrading\Support\ProcessLock;
use FulltimeTrading\Support\WeeklyOpenReleaseEvidence as Repair;

require dirname(__DIR__) . '/bootstrap.php';
set_error_handler(static function (int $n, string $message): never { throw new RuntimeException($message); });
$root = dirname(__DIR__); $sourceRoot = realpath($argv[1] ?? '') ?: '';
$candidate = require $root . '/config/paper_candidate.php';
$repair = ($argv[2] ?? null) === '--weekly-open-repair';
if ((!$repair && count($argv) !== 2) || ($repair && count($argv) !== 4)) { throw new InvalidArgumentException('Invalid admission arguments'); }
$stagePath = $repair ? '/var/staging/weekly-open-repair-20260919' : '/var/staging/bull5-v1';
if ($sourceRoot === '' || realpath($sourceRoot . $stagePath) !== $root
    || (!$repair && !is_file($root . '/stage_sources.json')) || is_file($root . '/.env')
    || is_file($root . '/var/db/trading.sqlite') || is_file($root . '/var/run/candidate_commission.json')
    || Definition::PROFILE !== 'maximum-stop12-costband2-whole-bull5-v1'
    || Definition::INDICATOR_RECIPE !== 'bull_v110_ma50_boost105'
    || $candidate['paper_only'] !== true || $candidate['live_enabled'] !== false) {
    throw new RuntimeException('Admission builder requires the isolated, uncommissioned bull5 stage; never run it against the operational checkout.');
}
if ($repair) {
    Repair::configuration($candidate, require $sourceRoot . '/config/paper_candidate.php');
    $lock = ProcessLock::tryAcquire($root . '/var/run/weekly_open_regressions.lock');
    if ($lock === null) { exit(75); }
}
$proof = ['started_at' => gmdate(DATE_ATOM), 'manual_orders_submitted' => 0, 'operational_database_modified' => false,
    'runtime_hash' => Release::hash($root), 'files' => Release::files($root), 'tests' => [], 'lint' => [], 'evidence_sha256' => [], 'failures' => []];
$require = static function (bool $ok, string $why): void { if (!$ok) { throw new RuntimeException($why); } };
$read = static function (string $relative) use ($sourceRoot, &$proof): array {
    $data = Data::read($sourceRoot . '/' . $relative);
    $proof['evidence_sha256'][$relative] = hash_file('sha256', $sourceRoot . '/' . $relative); return $data;
};
$checkHashes = static function (array $files, string $base) use ($require, $sourceRoot, &$proof): void {
    foreach ($files as $file => $sha) {
        $require(hash_file('sha256', $base . '/' . $file) === $sha, 'Evidence/source drift: ' . $file);
        $proof['verified_reference_files'][substr($base . '/' . $file, strlen($sourceRoot) + 1)] = $sha;
    }
};
$run = static function (array $command, string $cwd): array {
    $p = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd,
        ['PATH' => getenv('PATH') ?: '/usr/bin:/bin', 'HOME' => sys_get_temp_dir(), 'TMPDIR' => sys_get_temp_dir()]);
    if (!is_resource($p)) { throw new RuntimeException('Cannot start verification command.'); }
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]); return ['exit_code' => proc_close($p), 'output' => $output];
};
try {
    $activeConfig = require $sourceRoot . '/config/paper_candidate.php'; $activeManifest = $read($activeConfig['release_manifest']);
    $checkHashes($activeManifest['files'], $sourceRoot);
    $require($activeManifest['proof_sha256'] === hash_file('sha256', $sourceRoot . '/' . $activeManifest['proof_path']), 'Operational release changed while staging.');
    $activeProof = $read($activeManifest['proof_path']);
    if ($repair) {
        $stage = Repair::inspect($root, $sourceRoot, $argv[3]);
        $proof['repair_binding'] = array_diff_key($stage, ['results' => true, 'snapshot' => true]);
        $checkHashes($activeProof['evidence_sha256'], $sourceRoot);
        $proof['historical_evidence_reused'] = true;
        $proof['historical_reuse_scope'] = 'Unchanged research/strategy/price code and exact prior evidence hashes. Criteria below are recomputed from saved receipts; no new return simulation or holdout.';
    } else {
        $source = Data::read($root . '/stage_sources.json'); $stage = $read('var/reports/bull5_admission_20260915/stage.json');
        $require($activeManifest['runtime_hash'] === $source['reference_runtime_hash'], 'Reference runtime changed');
        $require($stage['passed'] === true && $stage['programs'] >= 55 && $stage['failed_programs'] === []
            && $stage['network_disabled'] === true && $stage['credential_environment_stripped'] === true
            && $stage['stage_source_sha256'] === hash_file('sha256', $root . '/stage_sources.json')
            && $stage['script_sha256'] === hash_file('sha256', $sourceRoot . '/tools/verify_bull5_stage_20260915.php'), 'Staged regression proof invalid.');
        foreach ($source['files'] as $file => $record) {
            $require(hash_file('sha256', $root . '/' . $file) === $record['sha256']
                && hash_file('sha256', $sourceRoot . '/' . $record['source']) === $record['sha256'], 'Staged build drift: ' . $file);
        }
    }
    foreach ($stage['results'] as $file => $result) {
        $require($result['exit_code'] === 0 && hash_file('sha256', $root . '/' . $file) === $result['sha256'], 'Staged test drift.');
        $proof['tests'][$file] = $result;
    }
    $proof['test_count'] = count($proof['tests']); $proof['tests_passed'] = true;
    $proof['full_command_contract_verified'] = $stage['results']['tests/candidate_cycle_contract.php']['exit_code'] === 0;
    $proof['fault_matrix_verified'] = $stage['results']['tests/staged_bull5_fault_matrix.php']['exit_code'] === 0;
    $proof['auction_boundary_verified'] = ($stage['results']['tests/candidate_opg_auction_boundary.php']['exit_code'] ?? -1) === 0;
    $require($proof['auction_boundary_verified'], 'The observed OPG submission-cutoff regression must be fixed.');
    $lintFiles = $proof['files'] + ['tools/verify_staged_bull5_release.php' => hash_file('sha256', __FILE__)];
    if ($repair) { $lintFiles[Repair::HELPER] = hash_file('sha256', $root . '/' . Repair::HELPER); }
    foreach ($lintFiles as $file => $_) {
        $proof['lint'][$file] = $run(str_ends_with($file, '.php') || $file === 'bin/trade'
            ? [PHP_BINARY, '-l', $root . '/' . $file] : ['sh', '-n', $root . '/' . $file], $root);
    }
    $proof['lint_passed'] = array_filter($proof['lint'], static fn ($r): bool => $r['exit_code'] !== 0) === [];
    $proof['diff_clean'] = $run(['git', 'diff', '--check'], $sourceRoot)['exit_code'] === 0
        && $run(['git', 'diff', '--check'], $root)['exit_code'] === 0;
    $frozen = $read('var/reports/opportunity_calendar_20260910/protocol.json'); $checkHashes($frozen['code_sha256'], $sourceRoot);
    $proof['frozen_research_unchanged'] = true;
    $parent = $read('var/reports/candidate_bull_risk_20260915/protocol.json');
    $parentReceipt = $read('docs/HYBRID_V4_BULL_RISK_2026-09-15.json');
    $require($parentReceipt['protocol_sha256'] === hash_file('sha256', $sourceRoot . '/var/reports/candidate_bull_risk_20260915/protocol.json'), 'Bull protocol binding failed.');
    $checkHashes($parent['code_sha256'], $sourceRoot);
    foreach ($parent['input_paths'] as $key => $file) { $require(hash_file('sha256', $sourceRoot . '/' . $file) === $parent['input_sha256'][$key], 'Bull input drift.'); }
    $proof['replay_inputs_verified'] = true;
    $parityProtocol = $read('var/reports/candidate_bull_parity_20260915/protocol.json');
    $parityReceipt = $read('docs/HYBRID_V4_BULL_EXECUTION_2026-09-15.json');
    $require($parityReceipt['protocol_sha256'] === hash_file('sha256', $sourceRoot . '/var/reports/candidate_bull_parity_20260915/protocol.json'), 'Bull component protocol changed.');
    $checkHashes($parityProtocol['code_sha256'], $sourceRoot);
    foreach (['early2017', 'early2019', 'continuous', 'fresh2023'] as $scenario) { foreach ([30, 60] as $cost) {
        $name = Definition::INDICATOR_RECIPE . '__' . $scenario . '__' . $cost;
        $path = 'var/reports/candidate_bull_parity_20260915/' . $name . '.json'; $p = $read($path);
        $require($p['passed'] === true && $p['orders_submitted'] === 0 && $p['database_modified'] === false
            && $p['protocol_sha256'] === $parityReceipt['protocol_sha256']
            && hash_file('sha256', $sourceRoot . '/' . $path) === $parityReceipt['receipts'][$name]['proof_sha256'], 'Bull component proof invalid.');
        $proof['close_parity'][$name] = array_intersect_key($p, array_flip(['assertions', 'sleeve_sessions', 'quantity_decisions']));
    } }
    $proof['close_parity_verified'] = true;
    $minutes = $read('var/reports/bull5_admission_20260915/minutes.json');
    $minuteBase = $sourceRoot . '/var/reports/candidate_execution_20260915/minutes';
    $require($minutes['unique_events'] === 64 && $minutes['rth_touch'] === 64 && $minutes['missing_opening_minute'] === 0
        && $minutes['orders_submitted'] === 0 && $minutes['script_sha256'] === hash_file('sha256', $sourceRoot . '/tools/verify_bull5_minute_reuse_20260915.php')
        && $minutes['minute_protocol_sha256'] === hash_file('sha256', $minuteBase . '/protocol.json')
        && $minutes['calendar_sha256'] === hash_file('sha256', $minuteBase . '/calendar.json'), 'Bull minute proof invalid.');
    $checkHashes($minutes['source_sha256'], $sourceRoot); $checkHashes($minutes['minute_snapshots_sha256'], $minuteBase);
    $proof['minute_audit_verified'] = true;
    $capitalDir = 'var/reports/bull5_capital_sensitivity_20260915/'; $cp = $read($capitalDir . 'protocol.json'); $cs = $read($capitalDir . 'summary.json');
    $require($cs['complete'] === true && count($cs['results']) === 40 && $cs['orders_submitted'] === 0
        && $cs['protocol_sha256'] === hash_file('sha256', $sourceRoot . '/' . $capitalDir . 'protocol.json')
        && $cp['input_sha256'] === $parent['input_sha256'], 'Capital comparisons incomplete or incomparable.');
    $checkHashes($cp['code_sha256'], $sourceRoot);
    foreach ($cs['results'] as $key => $summary) {
        $row = $read($capitalDir . $key . '.json');
        $require(hash_file('sha256', $sourceRoot . '/' . $capitalDir . $key . '.json') === $summary['sha256']
            && $row['protocol_sha256'] === $cs['protocol_sha256'] && Hash::hash($row['metrics']) === Hash::hash($summary['metrics']), 'Capital receipt changed.');
        if (isset($row['curve_path'])) { $require(hash_file('sha256', $sourceRoot . '/' . $row['curve_path']) === $row['curve_sha256'], 'Capital curve changed.'); }
        else {
            $name = $row['recipe'] . '__' . $row['scenario'] . '__' . $row['cost'];
            $originalPath = 'var/reports/candidate_bull_risk_20260915/' . $name . '.json'; $original = $read($originalPath);
            $require($row['reused_sha256'] === $parentReceipt['receipts'][$name]['case_sha256']
                && hash_file('sha256', $sourceRoot . '/' . $originalPath) === $row['reused_sha256']
                && Hash::hash($row['metrics']) === Hash::hash($original['metrics']), 'Reused capital receipt changed.');
        }
        $require($row['metrics']['max_gross_bound'] <= 1.30 && $row['metrics']['ex_top5_days_cagr'] > 0
            && $row['metrics']['top5_positive_episode_share'] <= .4, 'Gross/concentration sensitivity failed.');
    }
    $baseProtocol = $read('var/reports/candidate_execution_20260915/protocol.json');
    $baseSummary = $read('var/reports/candidate_execution_20260915/summary.json');
    $actualProtocol = $read('var/reports/candidate_capital_27567_66_20260915/protocol.json');
    $actualSummary = $read('var/reports/candidate_capital_27567_66_20260915/summary.json');
    foreach (['var/reports/candidate_execution_20260915/protocol.json', 'var/reports/candidate_execution_20260915/summary.json',
        'var/reports/candidate_capital_27567_66_20260915/protocol.json', 'var/reports/candidate_capital_27567_66_20260915/summary.json'] as $path) {
        $require(hash_file('sha256', $sourceRoot . '/' . $path) === $activeProof['evidence_sha256'][$path], 'Original-maximum evidence no longer matches the prior admission.');
    }
    $require($baseSummary['protocol_sha256'] === hash_file('sha256', $sourceRoot . '/var/reports/candidate_execution_20260915/protocol.json')
        && $actualSummary['protocol_sha256'] === hash_file('sha256', $sourceRoot . '/var/reports/candidate_capital_27567_66_20260915/protocol.json')
        && $actualProtocol['input_sha256'] === $baseProtocol['input_sha256'], 'Original-maximum comparison drift.');
    $checkHashes($actualProtocol['code_sha256'], $sourceRoot);
    foreach ([27567.66, 30000.] as $capital) { foreach (['continuous', 'fresh2023'] as $scenario) { foreach ([30, 60] as $cost) {
        $base = ($capital === 27567.66 ? $actualSummary : $baseSummary)['results']['split_' . $scenario . '_' . $cost . '_maximum_whole_previous_close'];
        $suffix = '__' . $scenario . '__' . $cost . '__' . str_replace('.', '_', number_format($capital, 2, '.', ''));
        $bull = $cs['results'][Definition::INDICATOR_RECIPE . $suffix]['metrics']; $rows = [];
        $require($baseProtocol['starts'][$scenario] === $cp['conditions'][$scenario]['start'] && $baseProtocol['end'] === $cp['conditions'][$scenario]['end']
            && $baseProtocol['input_sha256']['split'] === $cp['input_sha256']['split'], 'Economic windows/data differ.');
        foreach (['original_maximum' => $base, 'bull5' => $bull] as $name => $metrics) {
            $rows[$name] = ['start' => $cp['conditions'][$scenario]['start'], 'end' => $cp['conditions'][$scenario]['end'], 'initial_equity' => $capital,
                'terminal_equity' => $capital * (1 + $metrics['return']), 'cagr' => $metrics['cagr'], 'max_drawdown' => $metrics['max_drawdown'],
                'data_contract' => 'Alpaca SIP split/raw whole previous close', 'cost_bps' => $cost, 'calendar_sha256' => $cp['input_sha256']['split']];
        }
        $review = Benefit::compare($rows['original_maximum'], $rows['bull5']);
        $require($cost === 30 ? $review['economically_interesting'] : $review['strictly_dominates'], 'Existing economic benefit/cost criterion failed.');
        $proof['economic_review'][$capital . '_' . $scenario . '_' . $cost] = $review;
    } } }
    $proof['comparative_benefit_verified'] = true; $proof['capital_sensitivity_verified'] = true; $proof['capital_reviewed'] = 27567.66;
    $snapshot = Data::read($root . '/var/reports/staging/snapshot_verification.json');
    $require($snapshot === $stage['snapshot'] && $snapshot['passed'] === true && $snapshot['orders_submitted'] === 0
        && $snapshot['runtime_hash'] === $proof['runtime_hash'] && $snapshot['peak_memory_bytes'] <= 512 * 1024 * 1024
        && $snapshot['signal_sha256'] === hash_file('sha256', $root . '/var/reports/staging/signal.json'), 'Real snapshot proof changed.');
    $proof['snapshot_contract_verified'] = true; $proof['snapshot_peak_memory_bytes'] = $snapshot['peak_memory_bytes'];
    $require(Release::files($root) === $proof['files'] && Release::files($sourceRoot) === $activeManifest['files'], 'Runtime changed during admission');
    if ($repair) {
        $require(Repair::inspect($root, $sourceRoot, $argv[3]) === $stage, 'Repair evidence changed during admission');
        $proof['weekly_open_cli_verified'] = $stage['results']['tests/candidate_weekly_cli.php']['exit_code'] === 0;
    }
} catch (Throwable $e) { $proof['failures'][] = $e->getMessage(); }
$proof['completed_at'] = gmdate(DATE_ATOM); $proof['assessment'] = Release::assess($proof);
$proof['scope'] = 'Uncommissioned staged paper package, not a deployed strategy, independent validation PASS or month-gate completion.';
$proof['verifier_sha256'] = hash_file('sha256', __FILE__);
$proofPath = $repair ? 'var/reports/bull5_weekly_release_20260920/verification.json' : 'var/reports/bull5_release_20260915/verification.json';
Artifact::write($root . '/' . $proofPath, $proof);
$manifest = ['generated_at' => gmdate(DATE_ATOM), 'run_id' => $candidate['run_id'], 'profile' => Definition::PROFILE,
    'execution_contract' => Order::CONTRACT, 'paper_only' => true, 'live_approved' => false, 'paper_admission' => $proof['assessment']['paper_admission'],
    'policy' => $proof['assessment']['policy'], 'runtime_hash' => $proof['runtime_hash'], 'files' => $proof['files'],
    'proof_path' => $proofPath, 'proof_sha256' => hash_file('sha256', $root . '/' . $proofPath), 'capital_reviewed' => $proof['capital_reviewed'] ?? null,
    'limits' => ['Adaptively selected history, not an independent holdout.', 'Incremental money gain is capital/circuit-path sensitive.',
        'S5TW/VVIX external inputs are not Alpaca data. Minute touches are not broker fills.',
        'Fresh source-bound signal, starting-capital match, flat-account handoff and LaunchAgent commissioning remain mandatory.']];
Artifact::write($root . '/' . $candidate['release_manifest'], $manifest);
if ($manifest['paper_admission']) { Release::verify($root, $candidate); }
echo json_encode(['assessment' => $proof['assessment'], 'tests' => $proof['test_count'] ?? 0, 'failures' => $proof['failures'],
    'runtime_hash' => $proof['runtime_hash'], 'manifest' => $root . '/' . $candidate['release_manifest'], 'deployed' => false], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
exit($manifest['paper_admission'] ? 0 : 2);
