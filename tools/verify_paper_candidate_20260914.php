#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Backtest\TacticalRotationQualification;
use FulltimeTrading\Research\AlgorithmTrendResearch as A;
use FulltimeTrading\Trading\TacticalImplementationIdentity;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__); $out = $root . '/var/reports/paper_candidate_20260914';
$read = static fn ($p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
$run = static function (array $command) use ($root): array {
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $root);
    if (!is_resource($process)) { throw new RuntimeException('Cannot run verification.'); }
    fclose($pipes[0]); $text = stream_get_contents($pipes[1]); fclose($pipes[1]);
    return ['exit_code' => proc_close($process), 'output' => $text];
};
$mode = $argv[1] ?? 'tests'; $report = ['started_at' => gmdate(DATE_ATOM), 'orders_submitted' => 0];
if ($mode === 'tests') {
    $tests = ['standard_etf_research', 'opportunity_research', 'opportunity_cost_stress', 'selected_maximum_research',
        'research_trade_ledger', 'portfolio_stop_research', 'alpaca_stop_grid', 'breadth_volatility_research',
        'adaptive_rotation_research', 'algorithm_trend_research', 'daily_data_audit', 'causal_tactical_rotation_backtester',
        'causal_tactical_rotation_ensemble_backtester', 'hybrid_v4_research', 'research_multiplicity_audit',
        'market_data_cache_determinism', 'market_data_coverage', 'frozen_sip_iex_daily_bars_provider',
        'tactical_run_identity_gate', 'alpaca_paper_account_guard', 'alpaca_paper_client_guard', 'tactical_paper_runtime',
        'tactical_paper_signal_epoch', 'tactical_signal_artifact_guard', 'tactical_paper_month_gate',
        'tactical_portfolio_status_message', 'tactical_notification_policy', 'tactical_intent_status_message',
        'tactical_portfolio_notification_schedule', 'tactical_portfolio_weekly_summary', 'status_snapshot_safety', 'tactical_rotation_shadow_context',
        'hybrid_php_memory_profile', 'install_hybrid_launchd_rollback', 'paper_status_observation_time'];
    foreach ($tests as $test) {
        $r = $run([PHP_BINARY, $root . '/tests/' . $test . '.php']);
        $report['tests'][$test] = $r + ['sha256' => hash_file('sha256', $root . '/tests/' . $test . '.php')];
        printf("%s %s\n", $test, $r['exit_code'] === 0 ? 'PASS' : 'FAIL');
    }
    $report['passed'] = count(array_filter($report['tests'], static fn ($r): bool => $r['exit_code'] === 0)); $report['total'] = count($tests);
    $report['diff_check'] = $run(['git', 'diff', '--check']);
    $report['frozen_code_hashes_verified'] = 0;
    foreach ($read($root . '/var/reports/opportunity_calendar_20260910/protocol.json')['code_sha256'] as $file => $sha) {
        if (hash_file('sha256', $root . '/' . $file) !== $sha) { throw new RuntimeException('Prior research code drift: ' . $file); }
        $report['frozen_code_hashes_verified']++;
    }
} elseif ($mode === 'operational') {
    $snapshotDir = $argv[2] ?? 'operational';
    if (!in_array($snapshotDir, ['operational', 'operational_after_recovery', 'operational_final'], true)) { throw new InvalidArgumentException('Unknown snapshot directory.'); }
    $dir = $out . '/' . $snapshotDir;
    if (!file_exists($dir . '/latest_paper_status.json')) { throw new RuntimeException('Run isolated status export first.'); }
    $status = $read($dir . '/latest_paper_status.json');
    $report['status_snapshot_sha256'] = hash_file('sha256', $dir . '/latest_paper_status.json');
    $report['status_generated_at'] = $status['generated_at'];
    $report['export_health'] = $status['tactical']['health'] ?? null;
    $report['month_report'] = $run([PHP_BINARY, 'bin/trade', 'tactical-paper-month-report', '--output=' . $dir . '/month_report.json']);
    $report['runtime'] = $status['runtime'];
    $report['broker'] = ['equity' => $status['alpaca']['account']['equity'] ?? null, 'cash' => $status['alpaca']['account']['cash'] ?? null,
        'positions' => isset($status['alpaca']['positions']) ? count($status['alpaca']['positions']) : null,
        'open_orders' => isset($status['alpaca']['open_orders']) ? count($status['alpaca']['open_orders']) : null];
    foreach (['paper_daemon' => 'com.fulltimetrading.paper-daemon', 'tactical_paper_daemon' => 'com.fulltimetrading.hybrid-v4-paper'] as $name => $label) {
        $hb = $read($root . '/var/run/' . $name . '_heartbeat.json'); $lock = (int) trim(file_get_contents($root . '/var/run/' . $name . '.lock'));
        $launch = $run(['launchctl', 'print', 'gui/' . posix_getuid() . '/' . $label]); preg_match('/\bpid = (\d+)/', $launch['output'], $match);
        $report['daemons'][$name] = ['heartbeat_pid' => $hb['pid'], 'lock_pid' => $lock, 'launchd_pid' => (int) ($match[1] ?? 0),
            'age_seconds' => time() - strtotime($hb['heartbeat_at']), 'registered' => $launch['exit_code'] === 0];
    }
    $export = $run(['launchctl', 'print', 'gui/' . posix_getuid() . '/com.fulltimetrading.paper-status-export']);
    $report['status_export_agent_registered'] = $export['exit_code'] === 0;
    $db = new PDO('sqlite:file:' . $root . '/var/db/trading.sqlite?mode=ro'); $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA busy_timeout=5000');
    foreach (['tactical_paper_run', 'tactical_paper_intent', 'tactical_paper_fill_audit', 'tactical_paper_notification'] as $table) {
        $report['schema'][$table] = array_column($db->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC), 'name');
    }
    $report['run'] = $db->query('SELECT run_id,status,last_error_code,activated_at FROM tactical_paper_run ORDER BY created_at DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    foreach (['intents' => 'SELECT COUNT(*) FROM tactical_paper_intent WHERE run_id = :run',
        'fill_audits' => 'SELECT COUNT(*) FROM tactical_paper_fill_audit f JOIN tactical_paper_intent i ON i.decision_id = f.decision_id WHERE i.run_id = :run'] as $key => $sql) {
        $query = $db->prepare($sql); $query->execute(['run' => $report['run']['run_id']]); $report[$key] = (int) $query->fetchColumn();
    }
    $report['telegram_all_runs'] = $db->query('SELECT status,COUNT(*) AS n FROM tactical_paper_notification GROUP BY status')->fetchAll(PDO::FETCH_ASSOC);
} else { throw new InvalidArgumentException('Use tests or operational.'); }
$profile = require $root . '/config/tactical_rotation.php';
$parent = $read($root . '/var/reports/opportunity_calendar_20260910/protocol.json');
$report['operational_identity_unchanged'] = TacticalImplementationIdentity::current($root, $profile) === $parent['operational_identity'];
$candidate = $read($out . '/continuous_baseline.json'); $m = $candidate['metrics'];
$report['historical_qualification_recomputed'] = (new TacticalRotationQualification($profile['validation']))->evaluate(
    $m['train'], $m['validation'], $m['holdout'], $m['full'], $candidate['annual']);
if ($report['historical_qualification_recomputed'] != $candidate['qualification']) { throw new RuntimeException('Qualification mismatch.'); }
$report['sleeve_counts'] = ['candidate' => count($candidate['next_targets_research_only']), 'operational' => count($profile['sleeves'])];
$report['deployment_performed'] = false;
$report['completed_at'] = gmdate(DATE_ATOM);
A::write($out . '/' . ($mode === 'operational' ? $snapshotDir : $mode) . '_verification.json', $report);
echo json_encode(array_diff_key($report, array_flip(['tests', 'runtime', 'month_report', 'schema'])), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
exit($report['operational_identity_unchanged'] && ($mode !== 'tests' || ($report['passed'] === $report['total'] && $report['diff_check']['exit_code'] === 0)) ? 0 : 1);
