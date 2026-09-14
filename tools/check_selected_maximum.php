#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Research\AlgorithmTrendResearch as A;
use FulltimeTrading\Trading\TacticalImplementationIdentity;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__);
$out = $root . '/var/reports/selected_maximum_20260909';
$read = static fn ($p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
$mode = $argv[1] ?? 'tests';
$path = $out . '/' . $mode . '.json';
if (file_exists($path)) { throw new RuntimeException('Refusing to overwrite verification.'); }
$run = static function (array $command) use ($root): array {
    $p = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $root);
    if (!is_resource($p)) { throw new RuntimeException('Cannot start verification.'); }
    fclose($pipes[0]); $text = stream_get_contents($pipes[1]); fclose($pipes[1]);
    return ['exit_code' => proc_close($p), 'output' => $text];
};
$report = ['started_at' => gmdate(DATE_ATOM), 'orders_submitted' => 0];
if ($mode === 'tests') {
    $tests = ['selected_maximum_research', 'research_trade_ledger', 'portfolio_stop_research', 'alpaca_stop_grid',
        'breadth_volatility_research', 'adaptive_rotation_research', 'algorithm_trend_research', 'daily_data_audit',
        'causal_tactical_rotation_backtester', 'causal_tactical_rotation_ensemble_backtester', 'hybrid_v4_research',
        'research_multiplicity_audit', 'market_data_cache_determinism', 'market_data_coverage', 'frozen_sip_iex_daily_bars_provider',
        'tactical_run_identity_gate', 'alpaca_paper_account_guard', 'alpaca_paper_client_guard', 'tactical_paper_runtime',
        'tactical_paper_signal_epoch', 'tactical_signal_artifact_guard', 'tactical_paper_month_gate', 'tactical_portfolio_status_message',
        'tactical_notification_policy', 'tactical_intent_status_message', 'tactical_portfolio_notification_schedule', 'tactical_portfolio_weekly_summary'];
    foreach ($tests as $test) {
        $r = $run([PHP_BINARY, $root . '/tests/' . $test . '.php']);
        $report['tests'][$test] = $r + ['sha256' => hash_file('sha256', $root . '/tests/' . $test . '.php')];
        printf("%s %s\n", $test, $r['exit_code'] === 0 ? 'PASS' : 'FAIL');
    }
    $report['passed'] = count(array_filter($report['tests'], static fn ($r): bool => $r['exit_code'] === 0));
    $report['total'] = count($tests);
    $report['diff_check'] = $run(['git', 'diff', '--check']);
} elseif ($mode === 'operational') {
    $dir = $out . '/operational';
    mkdir($dir, 0775, true);
    $report['status_export'] = $run([PHP_BINARY, 'bin/trade', 'paper-status-export', '--git=false', '--push=false', '--output-dir=' . $dir]);
    $report['month_report'] = $run([PHP_BINARY, 'bin/trade', 'tactical-paper-month-report', '--output=' . $dir . '/month_report.json']);
    $status = $read($dir . '/latest_paper_status.json');
    $report['runtime'] = $status['runtime'];
    $report['broker'] = ['equity' => $status['alpaca']['account']['equity'], 'cash' => $status['alpaca']['account']['cash'],
        'positions' => count($status['alpaca']['positions']), 'open_orders' => count($status['alpaca']['open_orders'])];
    foreach (['paper_daemon' => 'com.fulltimetrading.paper-daemon', 'tactical_paper_daemon' => 'com.fulltimetrading.hybrid-v4-paper'] as $name => $label) {
        $hb = $read($root . '/var/run/' . $name . '_heartbeat.json');
        $lock = (int) trim(file_get_contents($root . '/var/run/' . $name . '.lock'));
        $launch = $run(['launchctl', 'print', 'gui/' . posix_getuid() . '/' . $label]);
        preg_match('/\bpid = (\d+)/', $launch['output'], $match);
        $report['daemons'][$name] = ['heartbeat_pid' => $hb['pid'], 'lock_pid' => $lock, 'launchd_pid' => (int) ($match[1] ?? 0),
            'age_seconds' => time() - strtotime($hb['heartbeat_at']), 'registered' => $launch['exit_code'] === 0];
    }
    $r = $run(['launchctl', 'print', 'gui/' . posix_getuid() . '/com.fulltimetrading.paper-status-export']);
    $report['status_export_agent_registered'] = $r['exit_code'] === 0;
    $db = new PDO('sqlite:file:' . $root . '/var/db/trading.sqlite?mode=ro');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach (['tactical_paper_run', 'tactical_paper_intent', 'tactical_paper_fill_audit', 'tactical_paper_notification'] as $table) {
        $report['db_schema'][$table] = array_column($db->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC), 'name');
    }
    $report['run'] = $db->query('SELECT run_id,status,last_error_code,activated_at FROM tactical_paper_run ORDER BY created_at DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $report['intents'] = (int) $db->query('SELECT COUNT(*) FROM tactical_paper_intent')->fetchColumn();
    $report['fill_audits'] = (int) $db->query('SELECT COUNT(*) FROM tactical_paper_fill_audit')->fetchColumn();
    $report['telegram'] = $db->query('SELECT status,COUNT(*) AS n FROM tactical_paper_notification GROUP BY status')->fetchAll(PDO::FETCH_ASSOC);
} else { throw new RuntimeException('Unknown check mode.'); }
$p = $read($out . '/protocol.json');
$profile = require $root . '/config/tactical_rotation.php';
$report['operational_identity_unchanged'] = TacticalImplementationIdentity::current($root, $profile) === $p['operational_identity'];
$report['completed_at'] = gmdate(DATE_ATOM);
A::write($path, $report);
echo json_encode(array_diff_key($report, array_flip(['tests', 'runtime', 'status_export', 'month_report', 'db_schema'])), JSON_PRETTY_PRINT), "\n";
exit($report['operational_identity_unchanged'] && ($mode !== 'tests' || ($report['passed'] === $report['total'] && $report['diff_check']['exit_code'] === 0)) ? 0 : 1);
