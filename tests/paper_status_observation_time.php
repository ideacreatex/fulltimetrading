<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/tools/paper_status_export.php');
$assert = static function (bool $ok, string $why): void { if (!$ok) { throw new RuntimeException($why); } };
$clockPosition = strpos($source, '$now = new DateTimeImmutable();');
$assert($clockPosition !== false && substr_count($source, '$now = new DateTimeImmutable();') === 1
    && $clockPosition > strpos($source, '$tacticalNotificationHealth = TacticalNotificationHealthGuard::assess(')
    && $clockPosition < strpos($source, '$tacticalHealth = statusExportTacticalRuntimeHealth('),
    'The exported timestamp must be captured after broker/local reads and before health assessment.');

// Load only the pure assessment functions, never the exporter entry point or broker calls.
$tokens = token_get_all($source);
foreach (['statusExportTacticalRuntimeHealth', 'statusExportTimestamp'] as $name) {
    $found = false;
    for ($i = 0; $i < count($tokens); $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) { continue; }
        $j = $i + 1;
        while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) { $j++; }
        if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][1] !== $name) { continue; }
        $code = ''; $depth = 0; $opened = false;
        for ($k = $i; $k < count($tokens); $k++) {
            $token = $tokens[$k]; $code .= is_array($token) ? $token[1] : $token;
            if ($token === '{') { $opened = true; $depth++; }
            if ($token === '}') { $depth--; if ($opened && $depth === 0) { break; } }
        }
        eval($code); $found = true; break;
    }
    $assert($found, 'Assessment function unavailable: ' . $name);
}
function statusExportLockPid(string $path): ?int { return 42; }
function statusExportHybridLaunchdPid(): ?int { return 42; }
function statusExportLockIsHeld(string $path): bool { return true; }

$run = ['status' => 'active', 'last_error_code' => null];
$heartbeat = ['pid' => 42, 'heartbeat_at' => '2026-09-14T20:00:55Z', 'last_executor_finished_at' => '2026-09-14T20:00:55Z',
    'submit' => true, 'paper_only' => true, 'account_guard_verified' => true, 'error' => null, 'last_executor_exit_code' => 0,
    'last_executor_timed_out' => false];
$cycle = ['generated_at' => '2026-09-14T20:00:45Z', 'run_id' => 'fixture', 'profile' => 'fixture', 'run_status' => 'active',
    'dry_run' => false, 'paper_only' => true, 'report_snapshot_fresh' => true, 'errors' => [],
    'account_guard' => array_fill_keys(['account_reference_match', 'multiplier_match', 'shorting_match', 'active', 'unblocked'], true)];
$assess = static fn ($h, $now): array => statusExportTacticalRuntimeHealth($run, $h, $cycle, 'fixture', 'fixture', ['profile' => 'fixture'], ['errors' => []], new DateTimeImmutable($now));
$assert(in_array('tactical_heartbeat_stale', $assess($heartbeat, '2026-09-14T20:00:00Z')['errors'], true), 'Fixture reproduces the pre-fetch clock race.');
$assert($assess($heartbeat, '2026-09-14T20:01:00Z')['ok'], 'Observed-at time removes false future/stale errors.');
$assert(in_array('tactical_heartbeat_stale', $assess($heartbeat, '2026-09-14T20:05:00Z')['errors'], true), 'Genuinely stale heartbeat remains blocked.');
$future = $heartbeat; $future['heartbeat_at'] = '2026-09-14T20:01:10Z';
$assert(in_array('tactical_heartbeat_stale', $assess($future, '2026-09-14T20:01:00Z')['errors'], true), 'Real future timestamp remains blocked.');
$blocked = $heartbeat; $blocked['last_executor_exit_code'] = 2;
$assert(in_array('tactical_heartbeat_failed', $assess($blocked, '2026-09-14T20:01:00Z')['errors'], true), 'The clock fix must not hide failed/gated execution.');
echo "Export observation clock race reproduced and fixed; stale, future and failed executor checks preserved\n";
