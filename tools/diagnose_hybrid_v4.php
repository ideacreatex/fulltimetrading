#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

$root = dirname(__DIR__);
$paper = require $root . '/config/tactical_paper.php';
$config = FulltimeTrading\Support\Config::fromFile($root . '/config/config.php');
$pdo = new PDO('sqlite:' . $config->get('database_path'), null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY,
]);
$pdo->exec('PRAGMA query_only=ON');
$pdo->exec('PRAGMA busy_timeout=5000');
$statement = $pdo->prepare('SELECT run_id, profile, status, strategy_hash, runtime_hash, activated_at, initial_equity, last_error_code FROM tactical_paper_run WHERE run_id = ?');
$statement->execute([$paper['run_id']]);
$run = $statement->fetch(PDO::FETCH_ASSOC);
if ($run === false) {
    throw new RuntimeException('Paper run has not been created.');
}
$executor = file_get_contents($root . '/tools/tactical_rotation_paper_executor.php');
if (preg_match('/\$runtimeFiles = \[(.*?)\];/s', $executor, $block) !== 1
    || preg_match_all('/\$root\s*\.\s*\x27([^\x27]+)\x27/', $block[1], $matches) < 1) {
    throw new RuntimeException('Executor runtime manifest cannot be inspected.');
}
$paths = array_map(static fn (string $path): string => $root . $path, $matches[1]);
sort($paths, SORT_STRING);
$hash = hash_init('sha256');
$files = [];
foreach ($paths as $path) {
    if (!is_file($path)) {
        throw new RuntimeException('Runtime identity file is missing: ' . basename($path));
    }
    hash_update($hash, basename($path) . "\0");
    hash_update_file($hash, $path);
    $files[substr($path, strlen($root) + 1)] = hash_file('sha256', $path);
}
$current = hash_final($hash);
$read = static function (string $path): array {
    return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
};
$cycle = $read($root . '/var/reports/daily/tactical_paper_cycle.json');
$signal = $read($root . '/var/reports/daily/tactical_rotation_shadow.json');
$validation = $read($root . '/var/reports/tactical_rotation/latest.json');
$counts = [];
foreach (['tactical_paper_intent', 'tactical_paper_fill_audit'] as $table) {
    $counts[$table] = (int) $pdo->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn();
}
$snapshots = $pdo->prepare('SELECT COUNT(*) AS count, MIN(datetime(captured_at)) AS first_at_utc, MAX(datetime(captured_at)) AS latest_at_utc, COUNT(DISTINCT substr(captured_at, 1, 10)) AS stored_dates FROM tactical_paper_snapshot WHERE run_id = ? AND datetime(captured_at) >= datetime(?)');
$snapshots->execute([$run['run_id'], $run['activated_at'] ?? '1970-01-01T00:00:00Z']);
$notification = $pdo->query('SELECT status, COUNT(*) AS count, MAX(delivered_at) AS last_delivered_at FROM tactical_paper_notification GROUP BY status')->fetchAll(PDO::FETCH_ASSOC);
$activation = is_string($run['activated_at']) ? new DateTimeImmutable($run['activated_at']) : null;
$report = [
    'generated_at' => gmdate(DATE_ATOM),
    'read_only' => true,
    'order_submission_enabled' => false,
    'run' => $run,
    'runtime_identity' => [
        'matches' => hash_equals($run['runtime_hash'], $current),
        'expected' => $run['runtime_hash'], 'current' => $current,
        'strategy_matches' => hash_equals($run['strategy_hash'], hash_file('sha256', $root . '/config/tactical_rotation.php')),
        'files_sha256' => $files,
        'explanation' => 'An active run pins its code. Changed executor or notification files block execution; an alive daemon does not mean trading works.',
        'repair_constraint' => 'Do not overwrite the stored hash. A reviewed runtime transition or a separately identified new paper experiment is required.',
    ],
    'heartbeat' => $read($root . '/var/run/tactical_paper_daemon_heartbeat.json'),
    'cycle' => array_intersect_key($cycle, array_flip(['generated_at', 'errors', 'reconciliation_status', 'mode', 'execution_attempted', 'orders_attempted', 'orders_submitted', 'execution_identity_verified'])),
    'signal' => array_intersect_key($signal, array_flip(['generated_at', 'as_of', 'intended_session', 'validation_selected', 'targets', 'signal_epoch'])),
    'validation' => array_map(static fn (array $row): array => [
        'qualifies' => $row['qualifies'], 'failed_gates' => $row['failed_gates'],
        'holdout_ex_top5_days_cagr' => $row['holdout_2026_ytd']['ex_top5_days_cagr'],
    ], $validation['cost_stress']),
    'counts_all_runs' => $counts,
    'observation' => $snapshots->fetch(PDO::FETCH_ASSOC),
    'calendar_threshold_from_activation' => $activation?->modify('+31 days')->format(DATE_ATOM),
    'notifications' => $notification,
    'explanations' => [
        'buying_power' => 'Broker capacity is separate from strategy qualification, executable signals, and runtime identity.',
        'cooldown' => isset($signal['signal_epoch'])
            ? 'Targets use a separately identified fresh paper model epoch. Historical qualification is unchanged; model returns are not broker fills.'
            : 'Targets inherit risk/cooldown from the continuous historical model, not from the empty broker account.',
        'author' => 'Hybrid-v4 is a separate stock-rotation model, not an exact implementation of the author POOS entry rules.',
        'observation' => 'Calendar age is not healthy trading observation. Snapshot coverage and unresolved errors remain separate gates.',
    ],
];
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
