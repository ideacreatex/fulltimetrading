#!/usr/bin/env php
<?php
declare(strict_types=1);

// Advisory local snapshot only: no broker calls, orders, recovery or admission.
function paperDigestAge(mixed $value, int $now): ?int
{
    $timestamp = is_string($value) ? strtotime($value) : false;
    return $timestamp === false ? null : $now - $timestamp;
}

function paperDigestRead(string $path): array
{
    $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) { throw new RuntimeException('Expected JSON object: ' . basename($path)); }
    return $data;
}

function paperDigestBuild(array $status, array $heartbeats, array $audit, int $now): array
{
    $issues = [];
    $age = paperDigestAge($status['generated_at'] ?? null, $now);
    if ($age === null || $age < -60 || $age > 1200) { $issues[] = 'status_stale_or_invalid'; }
    foreach (['paper_only', 'paper_base_host_ok'] as $key) {
        if (($status['runtime'][$key] ?? null) !== true) { $issues[] = $key . '_not_verified'; }
    }
    $guard = $status['runtime']['paper_account_guard'] ?? [];
    foreach (['account_reference_match', 'multiplier_match', 'shorting_match', 'active', 'unblocked'] as $key) {
        if (($guard[$key] ?? null) !== true) { $issues[] = 'account_guard:' . $key; }
    }
    $broker = $status['alpaca'] ?? [];
    foreach (['positions', 'open_orders'] as $key) {
        if (!isset($broker[$key]) || !is_array($broker[$key])) { $issues[] = 'missing_' . $key; }
    }
    if (($broker['snapshot_complete'] ?? null) !== true) { $issues[] = 'broker_snapshot_incomplete'; }
    foreach (['equity', 'cash'] as $key) {
        if (!is_numeric($broker['account'][$key] ?? null)) { $issues[] = 'account_value_missing:' . $key; }
    }
    $run = $status['tactical']['run'] ?? [];
    if (empty($run['run_id'])) { $issues[] = 'run_missing'; }
    $services = [];
    foreach (['tactical_paper_daemon', 'paper_daemon'] as $name) {
        $h = $heartbeats[$name] ?? [];
        $hbAge = paperDigestAge($h['heartbeat_at'] ?? null, $now);
        $healthy = $hbAge !== null && $hbAge >= -60 && $hbAge <= 180
            && (int) ($h['pid'] ?? 0) > 1
            && (string) ($h['pid'] ?? '') === (string) ($h['lock_pid'] ?? '')
            && ($h['process_alive'] ?? false) === true && ($h['error'] ?? null) === null;
        $services[$name] = ['pid' => $h['pid'] ?? null, 'age_s' => $hbAge, 'healthy' => $healthy];
        if (!$healthy) { $issues[] = 'service_attention:' . $name; }
    }
    $auditAge = paperDigestAge($audit['completed_at'] ?? null, $now);
    $auditCurrent = $auditAge !== null && $auditAge >= -60 && $auditAge <= 86400
        && !empty($run['run_id']) && ($audit['run_id'] ?? null) === $run['run_id']
        && ($audit['audit']['ok'] ?? false) === true;
    if (!$auditCurrent) { $issues[] = 'audit_required'; }
    $positions = array_map(static fn (array $p): array => array_intersect_key($p,
        array_flip(['symbol', 'side', 'qty', 'avg_entry_price', 'unrealized_pl'])), $broker['positions'] ?? []);
    $orders = array_map(static fn (array $o): array => array_intersect_key($o,
        array_flip(['client_order_id', 'symbol', 'side', 'type', 'qty', 'filled_qty', 'stop_price', 'status'])), $broker['open_orders'] ?? []);
    return [
        'scope' => 'local_snapshot_not_entry_permission', 'generated_at' => $status['generated_at'] ?? null,
        'status_age_s' => $age, 'run' => array_intersect_key($run, array_flip(['run_id', 'status', 'last_error_code', 'runtime_hash_short'])),
        'inspection_issues' => $issues, 'runtime_errors' => $status['errors'] ?? ['status_errors_missing'],
        'cycle_errors' => $status['tactical']['cycle']['errors'] ?? [], 'services' => $services,
        'account' => array_intersect_key($broker['account'] ?? [], array_flip(['equity', 'cash'])),
        'positions' => $positions, 'orders' => $orders,
        'audit' => ['at' => $audit['completed_at'] ?? null, 'current' => $auditCurrent],
    ];
}

function paperDigestOutbox(string $path, string $table): array
{
    $real = realpath($path);
    if ($real === false) { throw new RuntimeException('Outbox missing: ' . $table); }
    if (!in_array($table, ['tactical_paper_notification', 'commentary_outbox'], true)) {
        throw new RuntimeException('Unknown outbox');
    }
    $db = new PDO('sqlite:file:' . $real . '?mode=ro', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA query_only=ON');
    $db->exec('PRAGMA busy_timeout=3000');
    return $db->query("SELECT status,COUNT(*) n,MAX(delivered_at) latest FROM $table GROUP BY status")
        ->fetchAll(PDO::FETCH_ASSOC);
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    set_error_handler(static function (int $level, string $message): never { throw new RuntimeException($message); });
    try {
        $root = dirname(__DIR__);
        require $root . '/bootstrap.php';
        $config = FulltimeTrading\Support\Config::fromFile($root . '/config/config.php');
        $heartbeats = [];
        foreach (['tactical_paper_daemon', 'paper_daemon'] as $name) {
            $h = paperDigestRead($root . '/var/run/' . $name . '_heartbeat.json');
            $h['lock_pid'] = trim((string) file_get_contents($root . '/var/run/' . $name . '.lock'));
            $h['process_alive'] = function_exists('posix_kill') && (int) ($h['pid'] ?? 0) > 1
                && posix_kill((int) $h['pid'], 0);
            $heartbeats[$name] = $h;
        }
        // This is the existing auditor's output, not a substitute audit.
        $auditPath = $root . '/var/reports/candidate_forward_20260915/latest.json';
        $audit = is_file($auditPath) ? paperDigestRead($auditPath) : [];
        $result = paperDigestBuild(paperDigestRead($root . '/var/status/latest_paper_status.json'), $heartbeats, $audit, time());
        $result['outboxes'] = [
            'trading' => paperDigestOutbox((string) $config->get('database_path'), 'tactical_paper_notification'),
            'commentary' => paperDigestOutbox($root . '/var/db/paper_market_commentary.sqlite', 'commentary_outbox'),
        ];
        foreach ($result['outboxes'] as $name => $rows) {
            foreach ($rows as $row) {
                if ($row['status'] !== 'delivered' && (int) $row['n'] > 0) { $result['inspection_issues'][] = 'outbox_attention:' . $name; }
            }
        }
        echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit($result['inspection_issues'] === [] ? 0 : 2);
    } catch (Throwable $e) {
        echo json_encode(['inspection_issues' => ['digest_unavailable'], 'error' => $e->getMessage()], JSON_INVALID_UTF8_SUBSTITUTE) . PHP_EOL;
        exit(2);
    }
}
