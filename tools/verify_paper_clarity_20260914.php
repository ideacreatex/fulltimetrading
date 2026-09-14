#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Research\AlgorithmTrendResearch as A;
use FulltimeTrading\Trading\TacticalImplementationIdentity;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__); $out = $root . '/var/reports/paper_clarity_20260914';
$read = static fn ($p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
$run = static function (array $command) use ($root): array {
    $p = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $root);
    if (!is_resource($p)) { throw new RuntimeException('Unable to start verification.'); }
    fclose($pipes[0]); $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    return ['exit_code' => proc_close($p), 'output' => $output];
};
$previous = $read($root . '/var/reports/paper_candidate_20260914/tests_verification.json');
$tests = array_unique(array_merge(array_keys($previous['tests']), ['paper_signal_explanation', 'economic_benefit_review', 'paper_explanation_preview']));
$report = ['started_at' => gmdate(DATE_ATOM), 'manual_orders_submitted' => 0];
foreach ($tests as $test) {
    $result = $run([PHP_BINARY, $root . '/tests/' . $test . '.php']);
    $report['tests'][$test] = $result + ['sha256' => hash_file('sha256', $root . '/tests/' . $test . '.php')];
    printf("%s %s\n", $test, $result['exit_code'] === 0 ? 'PASS' : 'FAIL');
}
$report['passed'] = count(array_filter($report['tests'], static fn ($r) => $r['exit_code'] === 0));
$report['total'] = count($tests);
$report['diff_check'] = $run(['git', 'diff', '--check']);
$parent = $read($root . '/var/reports/opportunity_calendar_20260910/protocol.json');
$report['frozen_code_hashes_verified'] = 0;
foreach ($parent['code_sha256'] as $file => $sha) {
    if (hash_file('sha256', $root . '/' . $file) !== $sha) { throw new RuntimeException('Frozen research changed: ' . $file); }
    $report['frozen_code_hashes_verified']++;
}
$report['implementation_identity_unchanged'] = TacticalImplementationIdentity::current($root, require $root . '/config/tactical_rotation.php') === $parent['operational_identity'];
$source = file_get_contents($root . '/tools/tactical_rotation_paper_executor.php');
if (!preg_match('/\$runtimeFiles = \[(.*?)\];/s', $source, $block)
    || !preg_match_all('/\$root \. \'(\/[^\']+)\'/D', $block[1], $matches) || count($matches[1]) !== 31) {
    throw new RuntimeException('Cannot independently parse frozen runtime manifest.');
}
$files = array_map(static fn ($s) => $root . $s, $matches[1]); sort($files, SORT_STRING);
$context = hash_init('sha256');
foreach ($files as $file) { hash_update($context, basename($file) . "\0"); hash_update_file($context, $file); }
$currentHash = hash_final($context);
$db = new PDO('sqlite:file:' . $root . '/var/db/trading.sqlite?mode=ro');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); $db->exec('PRAGMA busy_timeout=5000');
foreach (['tactical_paper_run', 'tactical_paper_intent', 'tactical_paper_fill_audit', 'tactical_paper_notification'] as $table) {
    $report['schema'][$table] = array_column($db->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC), 'name');
}
$config = require $root . '/config/tactical_paper.php';
$q = $db->prepare('SELECT run_id,status,activated_at,strategy_hash,runtime_hash,last_error_code FROM tactical_paper_run WHERE run_id=:run');
$q->execute(['run' => $config['run_id']]); $runRow = $q->fetch(PDO::FETCH_ASSOC);
$report['active_run'] = $runRow;
$report['runtime_hash_matches'] = $runRow !== false && hash_equals($runRow['runtime_hash'], $currentHash);
$report['strategy_hash_matches'] = $runRow !== false && hash_equals($runRow['strategy_hash'], hash_file('sha256', $root . '/config/tactical_rotation.php'));
foreach (['intents' => 'SELECT COUNT(*) FROM tactical_paper_intent WHERE run_id=:run',
    'fill_audits' => 'SELECT COUNT(*) FROM tactical_paper_fill_audit f JOIN tactical_paper_intent i ON i.decision_id=f.decision_id WHERE i.run_id=:run'] as $key => $sql) {
    $q = $db->prepare($sql); $q->execute(['run' => $config['run_id']]); $report[$key] = (int) $q->fetchColumn();
}
$report['telegram_outbox_all_runs'] = $db->query('SELECT status,COUNT(*) AS n FROM tactical_paper_notification GROUP BY status')->fetchAll(PDO::FETCH_ASSOC);
foreach (['paper_daemon' => 'com.fulltimetrading.paper-daemon', 'tactical_paper_daemon' => 'com.fulltimetrading.hybrid-v4-paper'] as $name => $label) {
    $launch = $run(['launchctl', 'print', 'gui/' . posix_getuid() . '/' . $label]);
    preg_match('/\bpid = (\d+)/', $launch['output'], $match);
    $hb = $read($root . '/var/run/' . $name . '_heartbeat.json');
    $pid = (int) ($match[1] ?? 0); $lock = (int) trim(file_get_contents($root . '/var/run/' . $name . '.lock'));
    $report['daemons'][$name] = ['registered' => $launch['exit_code'] === 0, 'launchd_pid' => $pid, 'lock_pid' => $lock,
        'heartbeat_pid' => $hb['pid'], 'pids_match' => $pid > 0 && $pid === $lock && $pid === (int) $hb['pid'],
        'heartbeat_age_seconds' => time() - strtotime($hb['heartbeat_at'])];
}
$report['status_export_agent_registered'] = $run(['launchctl', 'print', 'gui/' . posix_getuid() . '/com.fulltimetrading.paper-status-export'])['exit_code'] === 0;
$report['completed_at'] = gmdate(DATE_ATOM);
$report['verification_passed'] = $report['passed'] === $report['total'] && $report['diff_check']['exit_code'] === 0
    && $report['implementation_identity_unchanged'] && $report['runtime_hash_matches'] && $report['strategy_hash_matches'];
$report['verification_scope'] = 'Code regressions and unchanged operational identity, NOT a healthy trading-cycle or deployment PASS.';
A::write($out . '/verification.json', $report);
echo json_encode(array_diff_key($report, array_flip(['tests', 'schema'])), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
exit($report['verification_passed'] ? 0 : 1);
