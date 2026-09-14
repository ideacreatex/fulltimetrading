#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Data\HttpClient;
use FulltimeTrading\Paper\CandidateDataSnapshot;
use FulltimeTrading\Paper\CandidateRelease;
use FulltimeTrading\Paper\CandidateSession;
use FulltimeTrading\Paper\CandidateSignalArtifact;
use FulltimeTrading\Support\Config;
use FulltimeTrading\Support\ProcessLock;
use FulltimeTrading\Trading\AlpacaPaperAccountGuard;
use FulltimeTrading\Trading\AlpacaPaperClient;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__); $candidate = require $root . '/config/paper_candidate.php';
$options = ['submit' => 'false', 'telegram' => 'true', 'once' => 'false', 'interval-seconds' => '15',
    'max-runtime-seconds' => '0', 'artifact' => $root . '/var/reports/daily/candidate_signal.json'];
foreach (array_slice($argv, 1) as $arg) { if (str_starts_with($arg, '--') && str_contains($arg, '=')) { [$k, $v] = explode('=', substr($arg, 2), 2); $options[$k] = $v; } }
foreach (['submit', 'telegram', 'once'] as $key) { if (!in_array($options[$key], ['true', 'false'], true)) { throw new InvalidArgumentException('Invalid daemon boolean.'); } }
$submit = $options['submit'] === 'true';
$prefix = $root . '/var/run/' . ($submit ? 'tactical_paper_daemon' : 'candidate_daemon_preflight');
$options += ['lock' => $prefix . '.lock', 'heartbeat' => $prefix . '_heartbeat.json', 'state' => $prefix . '_state.json',
    'executor-output' => $root . '/var/reports/' . ($submit ? 'daily/tactical_paper_cycle.json' : 'candidate_daemon_preflight.json'),
    'log' => $root . '/var/log/' . ($submit ? 'tactical_paper_daemon.log' : 'candidate_daemon_preflight.log')];
$lock = ProcessLock::tryAcquire($options['lock']);
if ($lock === null) { exit(75); }
$started = time(); $lastExecutor = $lastSignal = null; $jobs = []; $state = []; $error = null; $accountGuard = false;
$log = static function (array $row) use ($options): void {
    if (!is_dir(dirname($options['log']))) { mkdir(dirname($options['log']), 0775, true); }
    file_put_contents($options['log'], json_encode(['at' => gmdate(DATE_ATOM)] + $row, JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
};
$heartbeat = static function () use ($options, $started, &$lastExecutor, &$lastSignal, &$error, &$accountGuard, &$jobs): void {
    CandidateSignalArtifact::write($options['heartbeat'], ['pid' => getmypid(), 'started_at' => gmdate(DATE_ATOM, $started),
        'heartbeat_at' => gmdate(DATE_ATOM), 'submit' => $options['submit'] === 'true', 'telegram' => $options['telegram'] === 'true',
        'paper_only' => true, 'account_guard_verified' => $accountGuard, 'mode' => 'candidate-v1', 'error' => $error,
        'last_executor_exit_code' => $lastExecutor['exit_code'] ?? null, 'last_executor_finished_at' => $lastExecutor['finished_at'] ?? null,
        'last_executor_timed_out' => $lastExecutor['timed_out'] ?? null, 'last_signal_exit_code' => $lastSignal['exit_code'] ?? null,
        'last_signal_finished_at' => $lastSignal['finished_at'] ?? null, 'children' => array_keys($jobs)]);
};
$spawn = static function (array $command, int $timeout) use ($root): array {
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
    if (!is_resource($process)) { throw new RuntimeException('Candidate child start failed.'); }
    stream_set_blocking($pipes[1], false); stream_set_blocking($pipes[2], false);
    return ['process' => $process, 'pipes' => $pipes, 'started' => microtime(true), 'timeout' => $timeout, 'stdout' => '', 'stderr' => ''];
};
$stop = false;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT] as $signal) { pcntl_signal($signal, static function () use (&$stop): void { $stop = true; }); }
}
try {
    if (($candidate['enabled'] ?? null) !== true) { throw new RuntimeException('candidate_release_not_enabled'); }
    CandidateRelease::verify($root, $candidate);
    $config = Config::fromFile($root . '/config/config.php');
    $client = new AlpacaPaperClient(new HttpClient(), getenv('APCA_PAPER_BASE_URL') ?: (string) $config->get('trading.alpaca.paper_base_url'));
    AlpacaPaperAccountGuard::validateConfigured($client->account()); $accountGuard = true;
    $state = is_file($options['state']) ? CandidateDataSnapshot::read($options['state']) : [];
    $nextCycle = $nextSession = 0; $session = null; $completedCycles = 0;
    $log(['event' => 'candidate_started', 'pid' => getmypid()]);
    do {
        foreach ($jobs as $kind => &$job) {
            foreach ([1 => 'stdout', 2 => 'stderr'] as $pipe => $field) {
                $job[$field] = substr($job[$field] . stream_get_contents($job['pipes'][$pipe]), -12000);
            }
            $status = proc_get_status($job['process']);
            $timedOut = microtime(true) - $job['started'] > $job['timeout'];
            if (($status['running'] ?? false) && !$timedOut) { continue; }
            if ($timedOut && ($status['running'] ?? false)) {
                proc_terminate($job['process'], 15); usleep(250000);
                if ((proc_get_status($job['process'])['running'] ?? false)) { proc_terminate($job['process'], 9); }
            }
            foreach ([1, 2] as $pipe) { fclose($job['pipes'][$pipe]); }
            $closed = proc_close($job['process']);
            $result = ['exit_code' => $timedOut ? 124 : (($status['exitcode'] ?? -1) >= 0 ? $status['exitcode'] : $closed),
                'timed_out' => $timedOut, 'finished_at' => gmdate(DATE_ATOM),
                'stdout_tail' => $job['stdout'], 'stderr_tail' => $job['stderr']];
            if ($kind === 'executor') { $lastExecutor = $result; ++$completedCycles; $nextCycle = time() + max(15, (int) $options['interval-seconds']); }
            else { $lastSignal = $result; $state['next_signal_refresh_at'] = time() + ($result['exit_code'] === 0 ? 60 : 300); }
            $log(['event' => $kind, 'result' => $result]); unset($jobs[$kind]);
        }
        unset($job);
        $heartbeat();
        if ($stop || ($options['once'] === 'true' && $completedCycles > 0)
            || ((int) $options['max-runtime-seconds'] > 0 && time() - $started >= (int) $options['max-runtime-seconds'])) { break; }
        if (time() >= $nextSession && !isset($jobs['executor'])) {
            try {
                $now = new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
                $session = CandidateSession::resolve($client->calendar($now->modify('-15 days')->format('Y-m-d'), $now->modify('+15 days')->format('Y-m-d')), $client->clock(), $now);
                $error = null;
            } catch (Throwable $e) { $error = $e->getMessage(); $log(['event' => 'calendar_error', 'error' => $error]); }
            $nextSession = time() + 60;
        }
        // Signal computation is read-only and independent: never delay fill protection while a provider is slow.
        $artifact = is_file($options['artifact']) ? CandidateDataSnapshot::read($options['artifact']) : [];
        if ($session !== null && ($artifact['as_of'] ?? '') !== $session['signal_date'] && !isset($jobs['signal'])
            && time() >= ($state['next_signal_refresh_at'] ?? 0)) {
            $jobs['signal'] = $spawn([PHP_BINARY, '-d', 'memory_limit=512M', $root . '/tools/prepare_candidate_signal.php',
                '--output=' . $options['artifact']], 900);
        }
        if (!isset($jobs['executor']) && time() >= $nextCycle) {
            $jobs['executor'] = $spawn([PHP_BINARY, '-d', 'memory_limit=512M', $root . '/bin/trade', 'tactical-paper-executor',
                '--candidate=true', '--submit=' . $options['submit'], '--telegram=' . $options['telegram'],
                '--artifact=' . $options['artifact'], '--output=' . $options['executor-output']], 90);
        }
        CandidateSignalArtifact::write($options['state'], $state + ['mode' => 'candidate-v1']);
        sleep(1);
    } while (true);
} catch (Throwable $e) { $error = $e->getMessage(); $log(['event' => 'candidate_failure', 'error' => $error]); }
finally {
    // The shared mutation lock keeps a terminating child from overlapping a replacement daemon.
    foreach ($jobs as $job) {
        proc_terminate($job['process'], 15); usleep(250000);
        if ((proc_get_status($job['process'])['running'] ?? false)) { proc_terminate($job['process'], 9); }
        foreach ([1, 2] as $pipe) { fclose($job['pipes'][$pipe]); }
        proc_close($job['process']);
    }
    $jobs = []; $heartbeat(); $log(['event' => 'candidate_stopped', 'pid' => getmypid()]);
}
exit($error === null ? 0 : 78);
