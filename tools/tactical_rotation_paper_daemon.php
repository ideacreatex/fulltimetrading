#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Support\ProcessLock;
use FulltimeTrading\Trading\PaperDailyReportFreshnessGuard;

require __DIR__ . '/../bootstrap.php';

$paperRuntime = require __DIR__ . '/../config/tactical_paper.php';
$executionConfig = (array) ($paperRuntime['execution'] ?? []);

$options = [
    'submit' => 'false',
    'telegram' => 'true',
    'interval-seconds' => (string) ($executionConfig['monitor_interval_seconds'] ?? 60),
    'once' => 'false',
    'max-runtime-seconds' => '0',
    'lock' => __DIR__ . '/../var/run/tactical_paper_daemon.lock',
    'heartbeat' => __DIR__ . '/../var/run/tactical_paper_daemon_heartbeat.json',
    'state' => __DIR__ . '/../var/run/tactical_paper_daemon_state.json',
    'log' => __DIR__ . '/../var/log/tactical_paper_daemon.log',
    'artifact' => __DIR__ . '/../var/reports/daily/tactical_rotation_shadow.json',
    'full-artifact' => __DIR__ . '/../var/reports/tactical_rotation/latest.json',
    'executor-output' => __DIR__ . '/../var/reports/daily/tactical_paper_cycle.json',
    'child-timeout-seconds' => '300',
];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
        [$key, $value] = explode('=', substr($arg, 2), 2);
        $options[$key] = $value;
    }
}

$lock = ProcessLock::tryAcquire((string) $options['lock']);
if ($lock === null) {
    echo "FTT tactical paper daemon already running\n";
    exit(0);
}
$root = dirname(__DIR__);
$startedAt = new DateTimeImmutable();
$interval = max(15, (int) $options['interval-seconds']);
$childTimeout = max(60, (int) $options['child-timeout-seconds']);
$maximumRuntime = max(0, (int) $options['max-runtime-seconds']);
$state = tacticalDaemonReadJson((string) $options['state']);
$lastSignal = null;
$lastExecutor = null;
tacticalDaemonLog((string) $options['log'], ['event' => 'started', 'pid' => getmypid()]);

if (tacticalDaemonBool((string) $options['submit'])) {
    $guard = tacticalDaemonRun(
        [PHP_BINARY, $root . '/bin/trade', 'alpaca-account'],
        $root,
        $childTimeout,
    );
    if ((int) $guard['exit_code'] !== 0) {
        tacticalDaemonHeartbeat($options, $startedAt, null, null, false, 'account_guard_failed');
        fwrite(STDERR, "Tactical paper account guard failed.\n");
        exit(78);
    }
}

do {
    tacticalDaemonHeartbeat($options, $startedAt, $lastSignal, $lastExecutor, true, null);
    $nowNewYork = new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
    $expectedClosed = PaperDailyReportFreshnessGuard::latestExpectedClosedBarDate($nowNewYork)->format('Y-m-d');
    $artifact = tacticalDaemonReadJson((string) $options['artifact']);
    $artifactAsOf = (string) ($artifact['as_of'] ?? '');
    $refreshComplete = (string) ($state['last_signal_refresh_success_date'] ?? '') === $expectedClosed;
    $nextRefreshAt = isset($state['next_signal_refresh_at'])
        ? strtotime((string) $state['next_signal_refresh_at'])
        : false;
    $refreshDue = $nextRefreshAt === false || $nextRefreshAt <= time();
    $todayNewYork = $nowNewYork->format('Y-m-d');
    $hhmm = $nowNewYork->format('H:i');
    $insideSameDayRefreshWindow = $hhmm >= (string) ($executionConfig['signal_refresh_after'] ?? '16:20')
        && $hhmm <= (string) ($executionConfig['signal_refresh_before'] ?? '23:30');
    $refreshWindowOpen = $expectedClosed < $todayNewYork || $insideSameDayRefreshWindow;
    if ($artifactAsOf < $expectedClosed && !$refreshComplete && $refreshDue && $refreshWindowOpen) {
        $lastSignal = tacticalDaemonRun([
            PHP_BINARY,
            $root . '/tools/run_tactical_rotation_backtest.php',
            '--end=' . $expectedClosed,
            '--cost-bps=20,30',
            '--output=' . (string) $options['full-artifact'],
            '--shadow-output=' . (string) $options['artifact'],
        ], $root, $childTimeout);
        $state['last_signal_refresh_expected_date'] = $expectedClosed;
        $state['last_signal_refresh_at'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
        $state['last_signal_refresh_exit_code'] = (int) $lastSignal['exit_code'];
        if ((int) $lastSignal['exit_code'] === 0) {
            $state['last_signal_refresh_success_date'] = $expectedClosed;
            $state['next_signal_refresh_at'] = null;
        } else {
            $state['next_signal_refresh_at'] = (new DateTimeImmutable('+5 minutes'))->format(DateTimeInterface::ATOM);
        }
        tacticalDaemonWriteJson((string) $options['state'], $state);
        tacticalDaemonLog((string) $options['log'], ['event' => 'signal_refresh', 'result' => $lastSignal]);
    }

    $lastExecutor = tacticalDaemonRun([
        PHP_BINARY,
        $root . '/bin/trade',
        'tactical-paper-executor',
        '--submit=' . (string) $options['submit'],
        '--telegram=' . (string) $options['telegram'],
        '--artifact=' . (string) $options['artifact'],
        '--output=' . (string) $options['executor-output'],
    ], $root, $childTimeout);
    tacticalDaemonLog((string) $options['log'], ['event' => 'executor', 'result' => $lastExecutor]);
    tacticalDaemonHeartbeat($options, $startedAt, $lastSignal, $lastExecutor, true, null);

    if (tacticalDaemonBool((string) $options['once'])) {
        break;
    }
    if ($maximumRuntime > 0 && time() - $startedAt->getTimestamp() >= $maximumRuntime) {
        break;
    }
    sleep($interval);
} while (true);

tacticalDaemonLog((string) $options['log'], ['event' => 'stopped', 'pid' => getmypid()]);

/** @param list<string> $command @return array<string,mixed> */
function tacticalDaemonRun(array $command, string $cwd, int $timeoutSeconds): array
{
    $started = microtime(true);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($process)) {
        return ['exit_code' => 127, 'duration_seconds' => 0.0, 'stdout_tail' => '', 'stderr_tail' => 'proc_open failed'];
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $timedOut = false;
    $lastStatus = null;
    while (true) {
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        $lastStatus = proc_get_status($process);
        if (!is_array($lastStatus) || ($lastStatus['running'] ?? false) !== true) {
            break;
        }
        if (microtime(true) - $started > $timeoutSeconds) {
            $timedOut = true;
            proc_terminate($process, 15);
            usleep(250000);
            $afterTerm = proc_get_status($process);
            if (is_array($afterTerm) && ($afterTerm['running'] ?? false) === true) {
                proc_terminate($process, 9);
            }
            break;
        }
        usleep(100000);
    }
    $stdout .= (string) stream_get_contents($pipes[1]);
    $stderr .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $closeCode = proc_close($process);
    $exitCode = $timedOut ? 124 : (is_array($lastStatus) && (int) ($lastStatus['exitcode'] ?? -1) >= 0
        ? (int) $lastStatus['exitcode']
        : $closeCode);

    return [
        'command' => implode(' ', array_map('escapeshellarg', $command)),
        'exit_code' => $exitCode,
        'timed_out' => $timedOut,
        'duration_seconds' => round(microtime(true) - $started, 3),
        'finished_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        'stdout_tail' => tacticalDaemonTail($stdout),
        'stderr_tail' => tacticalDaemonTail($stderr),
    ];
}

/** @param array<string,string> $options @param ?array<string,mixed> $signal @param ?array<string,mixed> $executor */
function tacticalDaemonHeartbeat(
    array $options,
    DateTimeImmutable $startedAt,
    ?array $signal,
    ?array $executor,
    bool $accountGuardVerified,
    ?string $error,
): void {
    tacticalDaemonWriteJson((string) $options['heartbeat'], [
        'pid' => getmypid(),
        'started_at' => $startedAt->format(DateTimeInterface::ATOM),
        'heartbeat_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        'submit' => tacticalDaemonBool((string) $options['submit']),
        'telegram' => tacticalDaemonBool((string) $options['telegram']),
        'paper_only' => true,
        'account_guard_verified' => $accountGuardVerified,
        'last_signal_exit_code' => $signal['exit_code'] ?? null,
        'last_signal_finished_at' => $signal['finished_at'] ?? null,
        'last_executor_exit_code' => $executor['exit_code'] ?? null,
        'last_executor_finished_at' => $executor['finished_at'] ?? null,
        'last_executor_timed_out' => $executor['timed_out'] ?? null,
        'error' => $error,
    ]);
}

/** @return array<string,mixed> */
function tacticalDaemonReadJson(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $value = json_decode((string) file_get_contents($path), true);

    return is_array($value) ? $value : [];
}

/** @param array<string,mixed> $payload */
function tacticalDaemonWriteJson(string $path, array $payload): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create tactical daemon state directory.');
    }
    $temp = $path . '.tmp-' . bin2hex(random_bytes(5));
    if (file_put_contents($temp, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n") === false
        || !rename($temp, $path)) {
        @unlink($temp);
        throw new RuntimeException('Unable to atomically write tactical daemon state.');
    }
}

/** @param array<string,mixed> $payload */
function tacticalDaemonLog(string $path, array $payload): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        return;
    }
    $payload['created_at'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
    @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
}

function tacticalDaemonTail(string $text): string
{
    $text = trim($text);

    return strlen($text) <= 4000 ? $text : substr($text, -4000);
}

function tacticalDaemonBool(string $value): bool
{
    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
}
