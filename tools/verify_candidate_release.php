#!/usr/bin/env php
<?php
declare(strict_types=1);

use FulltimeTrading\Paper\CandidateDataSnapshot as Data;
use FulltimeTrading\Paper\CandidateDefinition as Definition;
use FulltimeTrading\Paper\CandidateOrder as Order;
use FulltimeTrading\Paper\CandidateRelease as Release;
use FulltimeTrading\Paper\CandidateSignalArtifact as Artifact;
use FulltimeTrading\Research\EconomicBenefitReview as Benefit;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__); $dir = $root . '/var/reports/candidate_release_20260915';
$candidate = require $root . '/config/paper_candidate.php';
$proof = ['started_at' => gmdate(DATE_ATOM), 'manual_orders_submitted' => 0, 'operational_database_modified' => false,
    'runtime_hash' => Release::hash($root), 'files' => Release::files($root), 'tests' => [], 'lint' => [], 'evidence_sha256' => [], 'failures' => []];
$run = static function (array $command) use ($root): array {
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $root);
    if (!is_resource($process)) { throw new RuntimeException('Verification process failed to start.'); }
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]); return ['exit_code' => proc_close($process), 'output' => $output];
};
$read = static function (string $relative) use ($root, &$proof): array {
    $value = Data::read($root . '/' . $relative); $proof['evidence_sha256'][$relative] = hash_file('sha256', $root . '/' . $relative); return $value;
};
try {
    $prior = $read('var/reports/paper_clarity_20260914/verification.json');
    $tests = array_keys($prior['tests']);
    foreach (glob($root . '/tests/candidate_*.php') as $file) { $tests[] = basename($file, '.php'); }
    $tests[] = 'whole_share_sizing'; $tests = array_values(array_unique($tests)); sort($tests);
    foreach ($tests as $test) {
        $result = $run([PHP_BINARY, '-d', 'memory_limit=512M', $root . '/tests/' . $test . '.php']);
        $proof['tests'][$test] = $result + ['sha256' => hash_file('sha256', $root . '/tests/' . $test . '.php')];
        printf("%s %s\n", $test, $result['exit_code'] === 0 ? 'PASS' : 'FAIL');
    }
    $proof['test_count'] = count($tests); $proof['tests_passed'] = count(array_filter($proof['tests'], static fn ($r): bool => $r['exit_code'] !== 0)) === 0;
    $proof['full_command_contract_verified'] = ($proof['tests']['candidate_cycle_contract']['exit_code'] ?? -1) === 0;
    $proof['fault_matrix_verified'] = ($proof['tests']['candidate_fault_matrix']['exit_code'] ?? -1) === 0;
    foreach ($proof['files'] as $file => $_) {
        $proof['lint'][$file] = $run(str_ends_with($file, '.php') || $file === 'bin/trade'
            ? [PHP_BINARY, '-l', $root . '/' . $file] : ['sh', '-n', $root . '/' . $file]);
    }
    $proof['lint_passed'] = array_filter($proof['lint'], static fn ($r): bool => $r['exit_code'] !== 0) === [];
    $proof['diff_clean'] = $run(['git', 'diff', '--check'])['exit_code'] === 0;
    $frozen = $read('var/reports/opportunity_calendar_20260910/protocol.json');
    foreach ($frozen['code_sha256'] as $file => $hash) {
        if (!hash_equals($hash, hash_file('sha256', $root . '/' . $file))) { throw new RuntimeException('Frozen research drift: ' . $file); }
    }
    $proof['frozen_research_unchanged'] = true;
    $protocol = $read('var/reports/candidate_execution_20260915/protocol.json');
    $inputPaths = ['raw' => 'var/reports/candidate_execution_data_20260914/raw.json', 'split' => 'var/reports/candidate_execution_data_20260914/split.json',
        'all' => 'var/reports/alpaca_stops_data_20260909/sip_all.json', 's5tw' => 'var/reports/breadth_volatility_data_20260909/s5tw.json',
        'vvix' => 'var/reports/vvix_data_20260909/cboe_vvix.json'];
    foreach ($inputPaths as $key => $path) {
        if (!hash_equals($protocol['input_sha256'][$key], hash_file('sha256', $root . '/' . $path))) { throw new RuntimeException('Replay input drift: ' . $key); }
    }
    $summary = $read('var/reports/candidate_execution_20260915/summary.json');
    if (count($summary['results']) !== 18 || $summary['protocol_sha256'] !== hash_file('sha256', $root . '/var/reports/candidate_execution_20260915/protocol.json')) {
        throw new RuntimeException('Incomplete execution comparisons.');
    }
    $proof['replay_inputs_verified'] = true;
    foreach (['continuous', 'fresh2023'] as $scenario) {
        $parity = $read('var/reports/candidate_execution_20260915/close_parity_' . $scenario . '.json');
        if ($parity['exact_frozen_metrics'] !== true || $parity['sleeves'] !== 12 || $parity['assertions'] < 37000 || $parity['orders_submitted'] !== 0) {
            throw new RuntimeException('Incomplete close parity: ' . $scenario);
        }
        foreach ($parity['code_sha256'] as $file => $hash) {
            if (!hash_equals($hash, hash_file('sha256', $root . '/' . $file))) { throw new RuntimeException('Close parity code drift: ' . $file); }
        }
        $proof['close_parity'][$scenario] = ['assertions' => $parity['assertions'], 'sessions' => $parity['sleeve_sessions']];
    }
    $proof['close_parity_verified'] = true;
    $minutes = $read('var/reports/candidate_execution_20260915/minutes/results.json');
    $minuteProtocol = $read('var/reports/candidate_execution_20260915/minutes/protocol.json');
    if ($minutes['events'] < 60 || $minutes['rth_touch'] !== $minutes['events'] || $minutes['missing_opening_minute'] !== 0
        || $minutes['orders_submitted'] !== 0 || $minuteProtocol['feed'] !== 'Alpaca SIP adjustment=split') { throw new RuntimeException('Minute execution sensitivity incomplete.'); }
    foreach ($minuteProtocol['input_sha256'] as $scenario => $hash) {
        if (!hash_equals($hash, hash_file('sha256', $root . '/var/reports/candidate_execution_20260915/close_parity_' . $scenario . '.json'))) { throw new RuntimeException('Minute audit belongs to another replay.'); }
    }
    foreach ($minutes['snapshots_sha256'] as $file => $hash) {
        if (!hash_equals($hash, hash_file('sha256', $root . '/var/reports/candidate_execution_20260915/minutes/' . $file))) { throw new RuntimeException('Minute data changed.'); }
    }
    $proof['minute_audit_verified'] = true;
    foreach (['continuous', 'fresh2023'] as $scenario) {
        foreach ([30, 60] as $cost) {
            $rows = [];
            foreach (['maximum', 'candidate'] as $variant) {
                $metrics = $summary['results']['split_' . $scenario . '_' . $cost . '_' . $variant . '_whole_previous_close'];
                $rows[$variant] = ['start' => $protocol['starts'][$scenario], 'end' => $protocol['end'], 'initial_equity' => 30000.,
                    'terminal_equity' => 30000 * (1 + $metrics['return']), 'cagr' => $metrics['cagr'], 'max_drawdown' => $metrics['max_drawdown'],
                    'data_contract' => 'Alpaca SIP split/raw whole previous close', 'cost_bps' => $cost, 'calendar_sha256' => $protocol['input_sha256']['split']];
                if ($variant === 'candidate' && ($metrics['max_gross_bound'] > 1.30 || $metrics['ex_top5_days_cagr'] <= 0 || $metrics['top5_positive_episode_share'] > .4)) {
                    throw new RuntimeException('Candidate gross/concentration sensitivity failed.');
                }
            }
            $benefit = Benefit::compare($rows['maximum'], $rows['candidate']);
            if (($cost === 30 && !$benefit['economically_interesting']) || ($cost === 60 && !$benefit['strictly_dominates'])) {
                throw new RuntimeException('Comparative economic benefit/stress criterion failed.');
            }
            $proof['economic_review'][$scenario . '_' . $cost] = $benefit;
        }
    }
    $proof['comparative_benefit_verified'] = true;
    $capitalDir = 'var/reports/candidate_capital_27567_66_20260915/';
    $capitalProtocol = $read($capitalDir . 'protocol.json'); $capitalSummary = $read($capitalDir . 'summary.json');
    if ($capitalProtocol['initial_equity'] !== 27567.66 || count($capitalSummary['results']) !== 8
        || $capitalProtocol['input_sha256'] !== $protocol['input_sha256']
        || $capitalSummary['protocol_sha256'] !== hash_file('sha256', $root . '/' . $capitalDir . 'protocol.json')) {
        throw new RuntimeException('Starting-capital sensitivity data drift.');
    }
    foreach ($capitalProtocol['code_sha256'] as $file => $sha) {
        if (!hash_equals($sha, hash_file('sha256', $root . '/' . $file))) { throw new RuntimeException('Capital sensitivity code drift: ' . $file); }
    }
    foreach (['continuous', 'fresh2023'] as $scenario) {
        foreach ([30, 60] as $cost) {
            $rows = [];
            foreach (['maximum', 'candidate'] as $variant) {
                $metrics = $capitalSummary['results']['split_' . $scenario . '_' . $cost . '_' . $variant . '_whole_previous_close'];
                $rows[$variant] = ['start' => $protocol['starts'][$scenario], 'end' => $protocol['end'], 'initial_equity' => 27567.66,
                    'terminal_equity' => 27567.66 * (1 + $metrics['return']), 'cagr' => $metrics['cagr'], 'max_drawdown' => $metrics['max_drawdown'],
                    'data_contract' => 'Alpaca SIP split/raw whole previous close', 'cost_bps' => $cost, 'calendar_sha256' => $protocol['input_sha256']['split']];
                if ($variant === 'candidate' && $metrics['max_gross_bound'] > 1.30) { throw new RuntimeException('Starting-capital gross failed.'); }
            }
            $benefit = Benefit::compare($rows['maximum'], $rows['candidate']);
            if (($cost === 30 && !$benefit['economically_interesting']) || ($cost === 60 && !$benefit['strictly_dominates'])) {
                throw new RuntimeException('Actual-capital benefit failed.');
            }
            $proof['capital_review'][$scenario . '_' . $cost] = $benefit;
        }
    }
    $proof['capital_sensitivity_verified'] = true; $proof['capital_reviewed'] = 27567.66;
    $snapshot = $run([PHP_BINARY, '-d', 'memory_limit=512M', $root . '/tools/prepare_candidate_snapshot.php', '2026-09-11', '2026-09-14']);
    if ($snapshot['exit_code'] !== 0) { throw new RuntimeException('Full-data snapshot failed: ' . $snapshot['output']); }
    $snapshotReport = $read('var/reports/candidate_execution_20260915/snapshot_20260911.json');
    if ($snapshotReport['orders_submitted'] !== 0 || $snapshotReport['operational_database_modified'] !== false || count($snapshotReport['plans']) !== 12
        || $snapshotReport['peak_memory_bytes'] > 512 * 1024 * 1024) { throw new RuntimeException('Invalid snapshot execution footprint.'); }
    $proof['snapshot_contract_verified'] = true; $proof['snapshot_peak_memory_bytes'] = $snapshotReport['peak_memory_bytes'];
} catch (Throwable $e) { $proof['failures'][] = $e->getMessage(); }
$proof['completed_at'] = gmdate(DATE_ATOM); $assessment = Release::assess($proof);
if ($proof['failures'] !== []) { $assessment['paper_admission'] = false; }
$proof['assessment'] = $assessment;
$proofPath = 'var/reports/candidate_release_20260915/verification.json'; Artifact::write($root . '/' . $proofPath, $proof);
$manifest = ['generated_at' => gmdate(DATE_ATOM), 'run_id' => $candidate['run_id'], 'profile' => Definition::PROFILE,
    'execution_contract' => Order::CONTRACT, 'paper_only' => true, 'live_approved' => false, 'paper_admission' => $assessment['paper_admission'],
    'policy' => $assessment['policy'], 'runtime_hash' => $proof['runtime_hash'], 'files' => $proof['files'],
    'proof_path' => $proofPath, 'proof_sha256' => hash_file('sha256', $root . '/' . $proofPath),
    'capital_reviewed' => $proof['capital_reviewed'] ?? null,
    'limits' => ['Adaptively selected history; no independent holdout.', 'Daily return curves omit live broker partial-fill/cancellation latency and stop-transition gaps.',
        'Paper admission is an experiment, not historical validation PASS or live approval.', 'Current-session source freshness and existing installer handoff remain mandatory.']];
Artifact::write($root . '/' . $candidate['release_manifest'], $manifest);
echo json_encode(['assessment' => $assessment, 'tests' => $proof['test_count'] ?? 0, 'failures' => $proof['failures'], 'runtime_hash' => $proof['runtime_hash']], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
exit($assessment['paper_admission'] ? 0 : 2);
