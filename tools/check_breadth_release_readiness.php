#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Research\AlgorithmTrendResearch;
use FulltimeTrading\Trading\TacticalImplementationIdentity;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__);
$out = $root . '/var/reports/breadth_volatility_demo_readiness_20260909';
$read = static fn ($p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
$main = $read($root . '/var/reports/breadth_volatility_20260909/results.json');
$refinement = $read($root . '/var/reports/breadth_volatility_refinement_20260909/results.json');
$verification = $read($root . '/var/reports/breadth_volatility_verification_20260909/results.json');
$finalAudit = $read($root . '/var/reports/breadth_volatility_final_audit_20260909/results.json');
foreach ([$main, $refinement, $verification, $finalAudit] as $report) { if (!isset($report['completed_at'])) { throw new RuntimeException('Research is not finished.'); } }
$run = static function (array $command) use ($root): array {
    $p = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    if (!is_resource($p)) { throw new RuntimeException('Unable to start verification command.'); }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]); fclose($pipes[2]);
    return ['exit_code' => proc_close($p), 'stdout' => $stdout, 'stderr' => $stderr];
};
$report = ['checked_at' => gmdate(DATE_ATOM), 'tests' => [], 'checks' => [], 'live_enabled' => false,
    'orders_submitted_by_research' => 0, 'ready_for_order_enabled_demo' => false,
    'completed_replays' => $main['replays'] + $refinement['replays'] + $verification['replays'] + $finalAudit['replays']];
$tests = ['breadth_volatility_research', 'adaptive_rotation_research', 'algorithm_trend_research', 'daily_data_audit',
    'causal_tactical_rotation_backtester', 'causal_tactical_rotation_ensemble_backtester', 'hybrid_v4_research', 'research_multiplicity_audit',
    'market_data_cache_determinism', 'market_data_coverage', 'frozen_sip_iex_daily_bars_provider',
    'tactical_run_identity_gate', 'alpaca_paper_account_guard', 'alpaca_paper_client_guard', 'tactical_paper_runtime',
    'tactical_paper_signal_epoch', 'tactical_signal_artifact_guard', 'tactical_paper_month_gate',
    'tactical_portfolio_status_message', 'tactical_notification_policy', 'tactical_intent_status_message',
    'tactical_portfolio_notification_schedule', 'tactical_portfolio_weekly_summary'];
foreach ($tests as $test) {
    $result = $run([PHP_BINARY, $root . '/tests/' . $test . '.php']);
    $report['tests'][$test] = ['passed' => $result['exit_code'] === 0, 'exit_code' => $result['exit_code'],
        'output' => substr($result['stdout'] . $result['stderr'], 0, 3000), 'sha256' => hash_file('sha256', $root . '/tests/' . $test . '.php')];
    echo $test, ': ', $result['exit_code'] === 0 ? 'PASS' : 'FAIL', "\n";
}
$report['checks']['diff_check'] = $run(['git', 'diff', '--check']);
$report['checks']['status_export'] = $run([PHP_BINARY, 'bin/trade', 'paper-status-export', '--git=false', '--push=false', '--output-dir=' . $out]);
$report['checks']['month_report'] = $run([PHP_BINARY, 'bin/trade', 'tactical-paper-month-report', '--output=' . $out . '/month_report.json']);
$status = $read($out . '/latest_paper_status.json');
$report['broker'] = ['paper_only' => $status['runtime']['paper_only'], 'paper_base_host_ok' => $status['runtime']['paper_base_host_ok'],
    'account_guard' => $status['runtime']['paper_account_guard'], 'equity' => $status['alpaca']['account']['equity'],
    'cash' => $status['alpaca']['account']['cash'], 'positions' => count($status['alpaca']['positions']), 'open_orders' => count($status['alpaca']['open_orders'])];
foreach (['paper_daemon' => 'com.fulltimetrading.paper-daemon', 'tactical_paper_daemon' => 'com.fulltimetrading.hybrid-v4-paper'] as $name => $label) {
    $heartbeat = $read($root . '/var/run/' . $name . '_heartbeat.json');
    $lock = (int) trim(file_get_contents($root . '/var/run/' . $name . '.lock'));
    $launch = $run(['launchctl', 'print', 'gui/' . posix_getuid() . '/' . $label]);
    preg_match('/\bpid = (\d+)/', $launch['stdout'], $match);
    $report['daemons'][$name] = ['pid' => $heartbeat['pid'], 'lock_pid' => $lock, 'launchd_pid' => (int) ($match[1] ?? 0),
        'age_seconds' => time() - strtotime($heartbeat['heartbeat_at']),
        'pid_converged' => $heartbeat['pid'] === $lock && $lock === (int) ($match[1] ?? 0),
        'last_executor_exit_code' => $heartbeat['last_executor_exit_code'] ?? null];
}
$exporter = $run(['launchctl', 'print', 'gui/' . posix_getuid() . '/com.fulltimetrading.paper-status-export']);
preg_match('/last exit code = (\d+)/', $exporter['stdout'], $match);
$report['status_export_agent'] = ['registered' => $exporter['exit_code'] === 0, 'last_exit_code' => isset($match[1]) ? (int) $match[1] : null];
$db = new PDO('sqlite:file:' . $root . '/var/db/trading.sqlite?mode=ro');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$report['runtime_run'] = $db->query('SELECT run_id,status,last_error_code,activated_at FROM tactical_paper_run ORDER BY created_at DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$report['intents'] = (int) $db->query('SELECT COUNT(*) FROM tactical_paper_intent')->fetchColumn();
$report['fill_audits'] = (int) $db->query('SELECT COUNT(*) FROM tactical_paper_fill_audit')->fetchColumn();
$report['telegram'] = $db->query('SELECT status,count(*) AS n FROM tactical_paper_notification GROUP BY status')->fetchAll(PDO::FETCH_ASSOC);
$base = $read($root . '/var/reports/independent_data_20260908/protocol.json');
$report['operational_identity_unchanged'] = TacticalImplementationIdentity::current($root, $base['profile']) === $base['operational_identity'];
$passedCandidates = [];
foreach (['main' => $main, 'refinement' => $refinement] as $batch => $results) {
    foreach ($results['primary']['yahoo'][30] as $id => $row) { if ($row['qualification']['qualifies']) { $passedCandidates[] = $batch . '/' . $id; } }
}
$report['historically_qualified_primary_candidates'] = $passedCandidates;
$report['blockers'] = ['Current paper signal is blocked by frozen validation; research does not override it.',
    'Historical S5TW/VVIX downloads are research snapshots, not a monitored production signal feed.',
    'Expanded-universe transfer and multiple-testing robustness do not establish a deployable edge.',
    'Stock-universe survivorship and corporate-action accounting limitations remain unresolved.'];
if ($passedCandidates === []) { array_unshift($report['blockers'], 'No candidate passes the existing historical qualification gates.'); }
$report['month_gate_is_live_review_only_not_permission_to_bypass_demo_validation'] = true;
$report['tests_passed'] = count(array_filter($report['tests'], static fn ($r): bool => $r['passed']));
$report['tests_total'] = count($report['tests']);
$report['completed_at'] = gmdate(DATE_ATOM);
AlgorithmTrendResearch::write($out . '/readiness.json', $report);
printf("Verification: %d/%d tests, operational identity %s; order-enabled demo NOT APPROVED.\n", $report['tests_passed'], $report['tests_total'], $report['operational_identity_unchanged'] ? 'unchanged' : 'DRIFT');
exit($report['tests_passed'] === $report['tests_total'] && $report['operational_identity_unchanged'] ? 0 : 1);
