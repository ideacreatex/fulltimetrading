<?php
declare(strict_types=1);
require dirname(__DIR__) . '/tools/paper_monitor_digest.php';
$n = 0;
$check = static function (bool $ok, string $why) use (&$n): void {
    ++$n;
    if (!$ok) { throw new RuntimeException($why); }
};
$now = strtotime('2026-09-29T18:00:00Z');
$s = ['generated_at' => gmdate(DATE_ATOM, $now), 'runtime' => [
    'paper_only' => true, 'paper_base_host_ok' => true,
    'paper_account_guard' => array_fill_keys(['account_reference_match', 'multiplier_match', 'shorting_match', 'active', 'unblocked'], true),
], 'alpaca' => ['snapshot_complete' => true, 'positions' => [], 'open_orders' => [], 'account' => ['equity' => '100', 'cash' => '100']],
    'tactical' => ['run' => ['run_id' => 'test', 'status' => 'paused']], 'errors' => ['blocked']];
$h = array_fill_keys(['tactical_paper_daemon', 'paper_daemon'], [
    'heartbeat_at' => gmdate(DATE_ATOM, $now), 'pid' => 123, 'lock_pid' => '123', 'process_alive' => true,
]);
$a = ['completed_at' => gmdate(DATE_ATOM, $now), 'run_id' => 'test', 'audit' => ['ok' => true]];
$r = paperDigestBuild($s, $h, $a, $now);
$check($r['inspection_issues'] === [], 'Fresh local inputs');
$check($r['runtime_errors'] === ['blocked'] && $r['run']['status'] === 'paused', 'Never hide runtime pause/errors');
$check(paperDigestBuild($s, $h, $a, $now + 1201)['inspection_issues'] !== [], 'Staleness');
$check(paperDigestBuild($s, $h, [], $now)['audit']['current'] === false, 'Missing audit');
$b = $a; $b['run_id'] = 'other';
$check(!paperDigestBuild($s, $h, $b, $now)['audit']['current'], 'Wrong run audit');
$b = $a; $b['completed_at'] = gmdate(DATE_ATOM, $now - 86401);
$check(!paperDigestBuild($s, $h, $b, $now)['audit']['current'], 'Expired audit');
$b = $s; unset($b['alpaca']['positions']);
$check(in_array('missing_positions', paperDigestBuild($b, $h, $a, $now)['inspection_issues']), 'Missing is not flat');
$b = $s; $b['runtime']['paper_account_guard']['active'] = false;
$check(in_array('account_guard:active', paperDigestBuild($b, $h, $a, $now)['inspection_issues']), 'Guard failure');
$b = $s; $b['runtime']['paper_only'] = false;
$check(in_array('paper_only_not_verified', paperDigestBuild($b, $h, $a, $now)['inspection_issues']), 'Not paper');
foreach (['lock_pid' => '456', 'process_alive' => false, 'heartbeat_at' => 'bad', 'error' => 'failure'] as $key => $value) {
    $b = $h; $b['paper_daemon'][$key] = $value;
    $check(!paperDigestBuild($s, $b, $a, $now)['services']['paper_daemon']['healthy'], 'Heartbeat ' . $key);
}
$check(paperDigestBuild([], [], [], $now)['inspection_issues'] !== [], 'Empty input fails closed');
$b = $s; $b['generated_at'] = gmdate(DATE_ATOM, $now + 61);
$check(in_array('status_stale_or_invalid', paperDigestBuild($b, $h, $a, $now)['inspection_issues']), 'Future timestamp');
$b = $s; unset($b['alpaca']['account']['cash']);
$check(in_array('account_value_missing:cash', paperDigestBuild($b, $h, $a, $now)['inspection_issues']), 'Missing cash');
$b = $s; $b['alpaca']['snapshot_complete'] = false;
$check(in_array('broker_snapshot_incomplete', paperDigestBuild($b, $h, $a, $now)['inspection_issues']), 'Incomplete snapshot');
$path = tempnam(sys_get_temp_dir(), 'paper-digest-test-');
try {
    $db = new PDO('sqlite:' . $path);
    $db->exec('CREATE TABLE commentary_outbox(status TEXT, delivered_at TEXT)');
    $db->exec("INSERT INTO commentary_outbox VALUES ('delivered','2026-09-29T18:00:00Z')");
    $db = null;
    $hash = hash_file('sha256', $path);
    $rows = paperDigestOutbox($path, 'commentary_outbox');
    $check(count($rows) === 1 && (int) $rows[0]['n'] === 1, 'Outbox count');
    $check(hash_file('sha256', $path) === $hash, 'Outbox remains byte-identical');
    try { paperDigestOutbox($path . '.missing', 'commentary_outbox'); $missing = false; }
    catch (RuntimeException) { $missing = true; }
    $check($missing && !is_file($path . '.missing'), 'Missing database not created');
} finally { unlink($path); }
echo "paper_monitor_digest: $n checks passed\n";
