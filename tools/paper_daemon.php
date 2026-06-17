#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$options = [
    'profile' => 'tuned-daily',
    'submit' => 'false',
    'telegram' => 'true',
    'monitor-telegram' => 'true',
    'cycle-telegram' => 'true',
    'monitor-interval-seconds' => '60',
    'cycle-after' => '09:35',
    'cycle-before' => '15:45',
    'timezone' => 'America/New_York',
    'lock' => __DIR__ . '/../var/run/paper_daemon.lock',
    'heartbeat' => __DIR__ . '/../var/run/paper_daemon_heartbeat.json',
    'state' => __DIR__ . '/../var/run/paper_daemon_state.json',
    'log' => __DIR__ . '/../var/log/paper_daemon.log',
    'max-runtime-seconds' => '0',
    'once' => 'false',
];

foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
        continue;
    }
    [$key, $value] = explode('=', substr($arg, 2), 2);
    $options[$key] = $value;
}

$lockPath = (string) $options['lock'];
ensureDir(dirname($lockPath));
$lock = fopen($lockPath, 'c+');
if ($lock === false) {
    throw new RuntimeException('Unable to open lock file: ' . $lockPath);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    echo "FTT paper daemon already running\n";
    exit(0);
}
ftruncate($lock, 0);
fwrite($lock, (string) getmypid());
fflush($lock);

$startedAt = new DateTimeImmutable();
$maxRuntime = max(0, (int) $options['max-runtime-seconds']);
$monitorInterval = max(15, (int) $options['monitor-interval-seconds']);
$statePath = (string) $options['state'];
$heartbeatPath = (string) $options['heartbeat'];
$logPath = (string) $options['log'];
ensureDir(dirname($statePath));
ensureDir(dirname($heartbeatPath));
ensureDir(dirname($logPath));

logLine($logPath, ['event' => 'daemon_started', 'pid' => getmypid(), 'submit' => boolOption((string) $options['submit'])]);
echo "FTT paper daemon started pid " . getmypid() . "\n";

do {
    $now = new DateTimeImmutable();
    writeJson($heartbeatPath, [
        'pid' => getmypid(),
        'started_at' => $startedAt->format(DateTimeInterface::ATOM),
        'heartbeat_at' => $now->format(DateTimeInterface::ATOM),
        'submit' => boolOption((string) $options['submit']),
        'profile' => (string) $options['profile'],
    ]);

    $state = readJson($statePath);
    if (shouldRunCycle($state, $options)) {
        $cycle = runCommand(cycleCommand($options), dirname(__DIR__));
        logLine($logPath, ['event' => 'paper_cycle', 'result' => $cycle]);
        echo shortResult('paper-cycle', $cycle) . "\n";
        if ($cycle['exit_code'] === 0) {
            $key = boolOption((string) $options['submit']) ? 'last_submit_cycle_date' : 'last_dry_run_cycle_date';
            $state[$key] = usNow($options)->format('Y-m-d');
            $state[$key . '_at'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
            writeJson($statePath, $state);
        }
    }

    $monitor = runCommand(monitorCommand($options), dirname(__DIR__));
    logLine($logPath, ['event' => 'paper_monitor', 'result' => $monitor]);
    echo shortResult('paper-monitor', $monitor) . "\n";

    if (boolOption((string) $options['once'])) {
        break;
    }
    if ($maxRuntime > 0 && time() - $startedAt->getTimestamp() >= $maxRuntime) {
        break;
    }
    sleep($monitorInterval);
} while (true);

logLine($logPath, ['event' => 'daemon_stopped', 'pid' => getmypid()]);
echo "FTT paper daemon stopped\n";

/** @param array<string, string> $options @return list<string> */
function cycleCommand(array $options): array
{
    return [
        PHP_BINARY,
        __DIR__ . '/../bin/trade',
        'paper-cycle',
        '--profile=' . (string) $options['profile'],
        '--submit=' . (string) $options['submit'],
        '--telegram=' . (string) $options['cycle-telegram'],
    ];
}

/** @param array<string, string> $options @return list<string> */
function monitorCommand(array $options): array
{
    return [
        PHP_BINARY,
        __DIR__ . '/../bin/trade',
        'paper-monitor',
        '--submit=' . (string) $options['submit'],
        '--telegram=' . (string) $options['monitor-telegram'],
        '--telegram-heartbeat=false',
    ];
}

/** @param array<string, mixed> $state @param array<string, string> $options */
function shouldRunCycle(array $state, array $options): bool
{
    $now = usNow($options);
    $day = (int) $now->format('N');
    if ($day > 5) {
        return false;
    }
    $today = $now->format('Y-m-d');
    $key = boolOption((string) $options['submit']) ? 'last_submit_cycle_date' : 'last_dry_run_cycle_date';
    if (($state[$key] ?? '') === $today) {
        return false;
    }
    $hhmm = $now->format('H:i');

    return $hhmm >= (string) $options['cycle-after'] && $hhmm <= (string) $options['cycle-before'];
}

/** @param array<string, string> $options */
function usNow(array $options): DateTimeImmutable
{
    return new DateTimeImmutable('now', new DateTimeZone((string) $options['timezone']));
}

/** @param list<string> $command @return array<string, mixed> */
function runCommand(array $command, string $cwd): array
{
    $startedAt = new DateTimeImmutable();
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($process)) {
        return ['exit_code' => 127, 'stdout_tail' => '', 'stderr_tail' => 'Unable to start process.'];
    }
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'command' => implode(' ', array_map('escapeshellarg', $command)),
        'exit_code' => $exitCode,
        'started_at' => $startedAt->format(DateTimeInterface::ATOM),
        'finished_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
        'stdout_tail' => tailText((string) $stdout),
        'stderr_tail' => tailText((string) $stderr),
    ];
}

/** @param array<string, mixed> $result */
function shortResult(string $name, array $result): string
{
    $line = $name . ' exit ' . (int) ($result['exit_code'] ?? -1);
    $stderr = trim((string) ($result['stderr_tail'] ?? ''));
    if ($stderr !== '') {
        $line .= ' error=' . substr(preg_replace('/\s+/', ' ', $stderr) ?? $stderr, 0, 180);
    }

    return $line;
}

function tailText(string $text): string
{
    $text = trim($text);
    if (strlen($text) <= 2000) {
        return $text;
    }

    return substr($text, -2000);
}

/** @return array<string, mixed> */
function readJson(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $payload = json_decode((string) file_get_contents($path), true);

    return is_array($payload) ? $payload : [];
}

/** @param array<string, mixed> $payload */
function writeJson(string $path, array $payload): void
{
    ensureDir(dirname($path));
    file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}

/** @param array<string, mixed> $payload */
function logLine(string $path, array $payload): void
{
    ensureDir(dirname($path));
    $payload['created_at'] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
    file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
}

function ensureDir(string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create directory: ' . $dir);
    }
}

function boolOption(string $value): bool
{
    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'y', 'on'], true);
}
