#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Data\HttpClient;
use FulltimeTrading\Storage\SqliteRepository;
use FulltimeTrading\Storage\TacticalPaperRepository;
use FulltimeTrading\Support\Config;
use FulltimeTrading\Support\PaperPlanStatusSummary;
use FulltimeTrading\Support\StatusExportGitPublisher;
use FulltimeTrading\Support\StatusSnapshotSafety;
use FulltimeTrading\Trading\AlpacaPaperAccountGuard;
use FulltimeTrading\Trading\AlpacaPaperClient;
use FulltimeTrading\Trading\TacticalNotificationHealthGuard;

require __DIR__ . '/../bootstrap.php';

$options = [
    'output-dir' => __DIR__ . '/../var/status',
    'limit' => '20',
    'git' => 'false',
    'push' => 'false',
    'remote' => 'origin',
    'branch' => 'main',
];

foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
        continue;
    }
    [$key, $value] = explode('=', substr($arg, 2), 2);
    $options[$key] = $value;
}

$config = Config::fromFile(__DIR__ . '/../config/config.php');
$repo = new SqliteRepository((string) $config->get('database_path'));
$repo->migrate();
$tacticalConfig = require __DIR__ . '/../config/tactical_paper.php';
$tacticalRepo = new TacticalPaperRepository((string) $config->get('database_path'));
$tacticalRepo->migrate();
$tacticalRunId = (string) ($tacticalConfig['run_id'] ?? '');

$now = new DateTimeImmutable();
$http = new HttpClient();
$client = new AlpacaPaperClient(
    $http,
    getenv('APCA_PAPER_BASE_URL') ?: (string) $config->get('trading.alpaca.paper_base_url', 'https://paper-api.alpaca.markets/v2'),
);

$account = null;
$positions = [];
$openOrders = [];
$clock = null;
$errors = [];
$accountGuard = [
    'account_reference_match' => false,
    'multiplier_match' => false,
    'shorting_match' => false,
    'active' => false,
    'unblocked' => false,
];

try {
    $candidateAccount = $client->account();
    $accountGuard = AlpacaPaperAccountGuard::validateConfigured($candidateAccount);
    $account = $candidateAccount;
    $clock = $client->clock();
    $positions = $client->positions();
    $openOrders = $client->openOrders();
} catch (Throwable $e) {
    $errors[] = StatusSnapshotSafety::errorCode($e);
}

$latestCycle = statusExportReadOptionalJson(__DIR__ . '/../var/reports/daily/latest_paper_cycle.json');
$latestMonitor = statusExportReadOptionalJson(__DIR__ . '/../var/reports/daily/latest_paper_monitor.json')
    ?: statusExportReadOptionalJson(__DIR__ . '/../var/reports/daily/latest_paper_monitor_cycle.json');
$latestPlan = statusExportReadOptionalJson(__DIR__ . '/../var/reports/daily/latest_paper_order_plan_cycle.json')
    ?: statusExportReadOptionalJson(__DIR__ . '/../var/reports/daily/latest_paper_order_plan_tuned_daily_margin_ready.json');
$tacticalCycle = statusExportReadOptionalJson(__DIR__ . '/../var/reports/daily/tactical_paper_cycle.json');
$tacticalHeartbeat = statusExportReadOptionalJson(__DIR__ . '/../var/run/tactical_paper_daemon_heartbeat.json');
$tacticalRun = $tacticalRunId !== '' ? $tacticalRepo->run($tacticalRunId) : null;
$tacticalNotificationHealth = TacticalNotificationHealthGuard::assess(
    (string) $config->get('database_path'),
    $tacticalRunId,
    $tacticalCycle,
);
$tacticalHealth = statusExportTacticalRuntimeHealth(
    $tacticalRun,
    $tacticalHeartbeat,
    $tacticalCycle,
    __DIR__ . '/../var/run/tactical_paper_daemon.lock',
    $tacticalRunId,
    $tacticalConfig,
    $tacticalNotificationHealth,
    $now,
);
$errors = array_values(array_unique(array_merge($errors, $tacticalHealth['errors'])));

$payload = [
    'generated_at' => $now->format(DateTimeInterface::ATOM),
    'host' => gethostname() ?: null,
    'repo' => statusExportGitSummary(),
    'runtime' => [
        'orders_enabled' => (bool) $config->get('trading.alpaca.orders_enabled', false),
        'production_entry_enabled' => (bool) $config->get('strategy.entry_submission_enabled', false),
        'production_entry_block_reason' => (string) $config->get('strategy.entry_submission_block_reason', ''),
        'paper_only' => (bool) $config->get('trading.alpaca.paper_only', true),
        'paper_base_host_ok' => parse_url(getenv('APCA_PAPER_BASE_URL') ?: (string) $config->get('trading.alpaca.paper_base_url', ''), PHP_URL_HOST) === 'paper-api.alpaca.markets',
        'data_key_set' => statusExportPresent('APCA_DATA_API_KEY_ID') || statusExportPresent('APCA_API_KEY_ID'),
        'paper_key_set' => statusExportPresent('APCA_PAPER_API_KEY_ID'),
        'paper_account_guard' => $accountGuard,
    ],
    'alpaca' => [
        'clock' => statusExportSanitizeClock($clock),
        'account' => statusExportSanitizeAccount($account),
        'positions' => array_map('statusExportSanitizePosition', $positions),
        'open_orders' => array_map('statusExportSanitizeOrder', $openOrders),
    ],
    'bot' => [
        'states' => statusExportSanitizeStates($repo->loadPaperPositionStates()),
        'recent_orders_scope' => 'local_order_history_not_current_open_orders',
        'recent_orders' => array_map('statusExportSanitizeStoredOrder', $repo->recentPaperOrders((int) $options['limit'])),
        'recent_actions' => array_map('statusExportSanitizeAction', $repo->recentPaperActions((int) $options['limit'])),
    ],
    'latest_cycle' => statusExportSummarizeCycle($latestCycle),
    'latest_monitor' => statusExportSummarizeMonitor($latestMonitor),
    'latest_plan' => statusExportSummarizePlan($latestPlan),
    'tactical' => [
        'run' => statusExportSanitizeTacticalRun($tacticalRun),
        'health' => $tacticalHealth,
        'notifications' => $tacticalNotificationHealth,
        'heartbeat' => statusExportSanitizeTacticalHeartbeat($tacticalHeartbeat),
        'cycle' => statusExportSanitizeTacticalCycle($tacticalCycle),
        'sleeves' => statusExportSanitizeTacticalSleeves($tacticalRunId !== '' ? $tacticalRepo->sleeves($tacticalRunId) : []),
        'positions' => $tacticalRunId !== '' ? $tacticalRepo->positions($tacticalRunId) : [],
        'active_intents' => array_map(
            'statusExportSanitizeTacticalIntent',
            $tacticalRunId !== '' ? $tacticalRepo->activeIntents($tacticalRunId) : [],
        ),
        'live_review_not_before' => $tacticalConfig['live_review_not_before'] ?? null,
    ],
    'errors' => $errors,
];

$outputDir = (string) $options['output-dir'];
statusExportEnsureDir($outputDir);
$jsonPath = $outputDir . '/latest_paper_status.json';
$mdPath = $outputDir . '/latest_paper_status.md';
statusExportWriteJson($jsonPath, $payload);
StatusSnapshotSafety::writeAtomic($mdPath, statusExportMarkdown($payload) . "\n");

echo "Paper status exported:\n";
echo "- {$jsonPath}\n";
echo "- {$mdPath}\n";

if (statusExportBoolOption((string) $options['git'])) {
    try {
        $gitResult = statusExportCommitFiles($jsonPath, $mdPath, statusExportBoolOption((string) $options['push']), (string) $options['remote'], (string) $options['branch']);
        echo $gitResult . "\n";
    } catch (RuntimeException $e) {
        fwrite(STDERR, 'Paper status Git update failed: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

if ($errors !== []) {
    fwrite(STDERR, 'Paper status export failed runtime health/sync: ' . implode(' | ', $errors) . "\n");
    exit(2);
}

/** @return array<string, mixed>|null */
function statusExportReadOptionalJson(string $path): ?array
{
    if (!is_file($path)) {
        return null;
    }
    try {
        $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return null;
    }

    return is_array($payload) ? $payload : null;
}

/** @param array<string, mixed> $payload */
function statusExportWriteJson(string $path, array $payload): void
{
    StatusSnapshotSafety::writeAtomic($path, StatusSnapshotSafety::encodeJson($payload));
}

function statusExportEnsureDir(string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create directory: ' . $dir);
    }
}

/** @return array<string, mixed> */
function statusExportGitSummary(): array
{
    return [
        'branch' => trim(statusExportRunCommand(['git', 'rev-parse', '--abbrev-ref', 'HEAD'])['stdout'] ?? ''),
        'commit' => trim(statusExportRunCommand(['git', 'rev-parse', '--short', 'HEAD'])['stdout'] ?? ''),
        'dirty' => trim(statusExportRunCommand(['git', 'status', '--porcelain'])['stdout'] ?? '') !== '',
    ];
}

/** @param ?array<string, mixed> $account @return array<string, mixed>|null */
function statusExportSanitizeAccount(?array $account): ?array
{
    if ($account === null) {
        return null;
    }

    return [
        'id_statusExportLast4' => statusExportLast4((string) ($account['id'] ?? '')),
        'account_number_statusExportLast4' => statusExportLast4((string) ($account['account_number'] ?? '')),
        'status' => $account['status'] ?? null,
        'currency' => $account['currency'] ?? null,
        'cash' => statusExportNumericOrNull($account['cash'] ?? null),
        'equity' => statusExportNumericOrNull($account['equity'] ?? null),
        'buying_power' => statusExportNumericOrNull($account['buying_power'] ?? null),
        'multiplier' => $account['multiplier'] ?? null,
        'pattern_day_trader' => statusExportStrictBoolOrNull($account, 'pattern_day_trader'),
        'daytrade_count' => isset($account['daytrade_count']) ? (int) $account['daytrade_count'] : null,
        'trading_blocked' => statusExportStrictBoolOrNull($account, 'trading_blocked'),
        'transfers_blocked' => statusExportStrictBoolOrNull($account, 'transfers_blocked'),
        'account_blocked' => statusExportStrictBoolOrNull($account, 'account_blocked'),
        'shorting_enabled' => statusExportStrictBoolOrNull($account, 'shorting_enabled'),
    ];
}

/** @param array<string, mixed> $payload */
function statusExportStrictBoolOrNull(array $payload, string $key): ?bool
{
    return array_key_exists($key, $payload) && is_bool($payload[$key]) ? $payload[$key] : null;
}

/** @param ?array<string, mixed> $clock @return array<string, mixed>|null */
function statusExportSanitizeClock(?array $clock): ?array
{
    if ($clock === null) {
        return null;
    }

    return [
        'timestamp' => $clock['timestamp'] ?? null,
        'is_open' => (bool) ($clock['is_open'] ?? false),
        'next_open' => $clock['next_open'] ?? null,
        'next_close' => $clock['next_close'] ?? null,
    ];
}

/** @param array<string, mixed> $position @return array<string, mixed> */
function statusExportSanitizePosition(array $position): array
{
    return [
        'symbol' => strtoupper((string) ($position['symbol'] ?? '')),
        'side' => $position['side'] ?? null,
        'qty' => statusExportNumericOrNull($position['qty'] ?? null),
        'avg_entry_price' => statusExportNumericOrNull($position['avg_entry_price'] ?? null),
        'current_price' => statusExportNumericOrNull($position['current_price'] ?? null),
        'market_value' => statusExportNumericOrNull($position['market_value'] ?? null),
        'cost_basis' => statusExportNumericOrNull($position['cost_basis'] ?? null),
        'unrealized_pl' => statusExportNumericOrNull($position['unrealized_pl'] ?? null),
        'unrealized_plpc' => statusExportNumericOrNull($position['unrealized_plpc'] ?? null),
        'change_today' => statusExportNumericOrNull($position['change_today'] ?? null),
    ];
}

/** @param array<string, mixed> $order @return array<string, mixed> */
function statusExportSanitizeOrder(array $order): array
{
    return [
        'id_statusExportLast4' => statusExportLast4((string) ($order['id'] ?? '')),
        'client_order_id' => $order['client_order_id'] ?? null,
        'symbol' => strtoupper((string) ($order['symbol'] ?? '')),
        'side' => $order['side'] ?? null,
        'type' => $order['type'] ?? null,
        'qty' => statusExportNumericOrNull($order['qty'] ?? null),
        'notional' => statusExportNumericOrNull($order['notional'] ?? null),
        'filled_qty' => statusExportNumericOrNull($order['filled_qty'] ?? null),
        'filled_avg_price' => statusExportNumericOrNull($order['filled_avg_price'] ?? null),
        'limit_price' => statusExportNumericOrNull($order['limit_price'] ?? null),
        'stop_price' => statusExportNumericOrNull($order['stop_price'] ?? null),
        'status' => $order['status'] ?? null,
        'time_in_force' => $order['time_in_force'] ?? null,
        'created_at' => $order['created_at'] ?? null,
        'updated_at' => $order['updated_at'] ?? null,
        'submitted_at' => $order['submitted_at'] ?? null,
        'filled_at' => $order['filled_at'] ?? null,
    ];
}

/** @param array<string, array<string, mixed>> $states @return list<array<string, mixed>> */
function statusExportSanitizeStates(array $states): array
{
    $rows = [];
    foreach ($states as $state) {
        if (!is_array($state)) {
            continue;
        }
        $rows[] = [
            'symbol' => strtoupper((string) ($state['symbol'] ?? '')),
            'status' => $state['status'] ?? null,
            'qty' => statusExportNumericOrNull($state['qty'] ?? null),
            'avg_entry_price' => statusExportNumericOrNull($state['avg_entry_price'] ?? null),
            'market_price' => statusExportNumericOrNull($state['market_price'] ?? null),
            'entry_price' => statusExportNumericOrNull($state['entry_price'] ?? null),
            'stop_price' => statusExportNumericOrNull($state['stop_price'] ?? null),
            'break_even_trigger_price' => statusExportNumericOrNull($state['break_even_trigger_price'] ?? null),
            'target_price' => statusExportNumericOrNull($state['target_price'] ?? null),
            'break_even_armed' => (bool) ($state['break_even_armed'] ?? false),
            'partial_done' => (bool) ($state['partial_done'] ?? false),
            'last_action' => $state['last_action'] ?? null,
            'last_event_at' => $state['last_event_at'] ?? null,
        ];
    }

    return $rows;
}

/** @param array<string, mixed> $order @return array<string, mixed> */
function statusExportSanitizeStoredOrder(array $order): array
{
    return [
        'client_order_id' => $order['client_order_id'] ?? null,
        'symbol' => strtoupper((string) ($order['symbol'] ?? '')),
        'side' => $order['side'] ?? null,
        'type' => $order['type'] ?? null,
        'qty' => statusExportNumericOrNull($order['qty'] ?? null),
        'limit_price' => statusExportNumericOrNull($order['limit_price'] ?? null),
        'status' => $order['status'] ?? null,
        'submitted' => (bool) ($order['submitted'] ?? false),
        'order_id_last4' => statusExportLast4((string) ($order['order_id'] ?? '')),
        'planned_at' => $order['planned_at'] ?? null,
        'submitted_at' => $order['submitted_at'] ?? null,
        'updated_at' => $order['updated_at'] ?? null,
    ];
}

/** @param array<string, mixed> $action @return array<string, mixed> */
function statusExportSanitizeAction(array $action): array
{
    return [
        'id' => isset($action['id']) ? (int) $action['id'] : null,
        'created_at' => $action['created_at'] ?? null,
        'symbol' => isset($action['symbol']) ? strtoupper((string) $action['symbol']) : null,
        'action' => $action['action'] ?? null,
        'severity' => $action['severity'] ?? null,
        'dry_run' => (bool) ($action['dry_run'] ?? false),
        'submitted' => (bool) ($action['submitted'] ?? false),
        'order_id_last4' => statusExportLast4((string) ($action['order_id'] ?? '')),
        'client_order_id' => $action['client_order_id'] ?? null,
        'reason' => StatusSnapshotSafety::redactedDetail($action['reason'] ?? null),
    ];
}

/** @param ?array<string, mixed> $cycle @return array<string, mixed>|null */
function statusExportSummarizeCycle(?array $cycle): ?array
{
    if ($cycle === null) {
        return null;
    }

    return [
        'generated_at' => $cycle['generated_at'] ?? null,
        'ok' => $cycle['ok'] ?? null,
        'submit_requested' => $cycle['submit_requested'] ?? null,
        'profile' => $cycle['profile'] ?? null,
        'daily_summary' => $cycle['daily_summary'] ?? null,
        'order_plan_summary' => $cycle['order_plan_summary'] ?? null,
        'monitor_summary' => $cycle['monitor_summary'] ?? null,
    ];
}

/** @param ?array<string, mixed> $monitor @return array<string, mixed>|null */
function statusExportSummarizeMonitor(?array $monitor): ?array
{
    if ($monitor === null) {
        return null;
    }

    return [
        'generated_at' => $monitor['generated_at'] ?? null,
        'dry_run' => $monitor['dry_run'] ?? null,
        'submit_allowed' => $monitor['submit_allowed'] ?? null,
        'market_open' => $monitor['market_open'] ?? null,
        'positions_count' => $monitor['positions_count'] ?? null,
        'open_orders_count' => $monitor['open_orders_count'] ?? null,
        'actions' => array_map(
            'statusExportSanitizeMonitorAction',
            is_array($monitor['actions'] ?? null) ? $monitor['actions'] : [],
        ),
        'suppressed_actions_count' => is_array($monitor['suppressed_actions'] ?? null) ? count($monitor['suppressed_actions']) : 0,
    ];
}

/** @return array<string, mixed> */
function statusExportSanitizeMonitorAction(mixed $action): array
{
    if (!is_array($action)) {
        return [
            'symbol' => null,
            'action' => 'invalid_action_redacted',
            'reason' => StatusSnapshotSafety::REDACTED_DETAIL,
            'submitted' => false,
        ];
    }

    return [
        'symbol' => isset($action['symbol']) ? strtoupper((string) $action['symbol']) : null,
        'action' => isset($action['action']) ? (string) $action['action'] : null,
        'reason' => StatusSnapshotSafety::redactedDetail($action['reason'] ?? null),
        'submitted' => ($action['submitted'] ?? null) === true,
    ];
}

/** @param ?array<string, mixed> $plan @return array<string, mixed>|null */
function statusExportSummarizePlan(?array $plan): ?array
{
    $summary = PaperPlanStatusSummary::fromPayload($plan);
    if ($summary === null) {
        return null;
    }

    $summary['orders'] = array_map(
        'statusExportSanitizeStoredOrder',
        is_array($summary['orders'] ?? null) ? $summary['orders'] : [],
    );

    return $summary;
}

/** @param ?array<string,mixed> $run @return array<string,mixed>|null */
function statusExportSanitizeTacticalRun(?array $run): ?array
{
    if ($run === null) {
        return null;
    }

    return [
        'run_id' => $run['run_id'] ?? null,
        'profile' => $run['profile'] ?? null,
        'status' => $run['status'] ?? null,
        'initial_equity' => statusExportNumericOrNull($run['initial_equity'] ?? null),
        'activated_at' => $run['activated_at'] ?? null,
        'last_error_code' => $run['last_error_code'] ?? null,
        'live_review_not_before' => $run['live_review_not_before'] ?? null,
        'strategy_hash_short' => substr((string) ($run['strategy_hash'] ?? ''), 0, 12),
        'runtime_hash_short' => substr((string) ($run['runtime_hash'] ?? ''), 0, 12),
    ];
}

/** @param ?array<string,mixed> $heartbeat @return array<string,mixed>|null */
function statusExportSanitizeTacticalHeartbeat(?array $heartbeat): ?array
{
    if ($heartbeat === null) {
        return null;
    }

    return array_intersect_key($heartbeat, array_fill_keys([
        'pid', 'started_at', 'heartbeat_at', 'submit', 'telegram', 'paper_only',
        'account_guard_verified', 'last_signal_exit_code', 'last_signal_finished_at',
        'last_executor_exit_code', 'last_executor_finished_at', 'last_executor_timed_out', 'error',
    ], true));
}

/** @param ?array<string,mixed> $cycle @return array<string,mixed>|null */
function statusExportSanitizeTacticalCycle(?array $cycle): ?array
{
    if ($cycle === null) {
        return null;
    }

    return [
        'generated_at' => $cycle['generated_at'] ?? null,
        'run_id' => $cycle['run_id'] ?? null,
        'profile' => $cycle['profile'] ?? null,
        'dry_run' => $cycle['dry_run'] ?? null,
        'paper_only' => $cycle['paper_only'] ?? null,
        'account_guard' => is_array($cycle['account_guard'] ?? null) ? $cycle['account_guard'] : null,
        'run_status' => $cycle['run_status'] ?? null,
        'reconciliation_status' => $cycle['reconciliation_status'] ?? null,
        'signal' => $cycle['signal'] ?? null,
        'errors' => is_array($cycle['errors'] ?? null) ? $cycle['errors'] : [],
    ];
}

/**
 * A persisted tactical run means the submit daemon is an operational
 * dependency, not an optional status decoration. Fail the status export when
 * its lock/heartbeat/cycle can no longer prove one fresh successful pass.
 *
 * @param ?array<string,mixed> $run
 * @param ?array<string,mixed> $heartbeat
 * @param ?array<string,mixed> $cycle
 * @param array<string,mixed> $tacticalConfig
 * @param array<string,mixed> $notificationHealth
 * @return array{expected:bool,ok:bool,max_age_seconds:int,checked_at:string,errors:list<string>}
 */
function statusExportTacticalRuntimeHealth(
    ?array $run,
    ?array $heartbeat,
    ?array $cycle,
    string $lockPath,
    string $expectedRunId,
    array $tacticalConfig,
    array $notificationHealth,
    DateTimeImmutable $now,
): array {
    $configuredInterval = max(1, (int) ($tacticalConfig['execution']['monitor_interval_seconds'] ?? 60));
    $maxAge = max(180, $configuredInterval * 3);
    $errors = [];
    if ($run === null) {
        return [
            'expected' => false,
            'ok' => true,
            'max_age_seconds' => $maxAge,
            'checked_at' => $now->format(DateTimeInterface::ATOM),
            'errors' => [],
        ];
    }

    $nowTimestamp = $now->getTimestamp();
    $heartbeatPid = is_int($heartbeat['pid'] ?? null)
        ? $heartbeat['pid']
        : (is_string($heartbeat['pid'] ?? null) && ctype_digit($heartbeat['pid']) ? (int) $heartbeat['pid'] : 0);
    $heartbeatAt = statusExportTimestamp($heartbeat['heartbeat_at'] ?? null);
    $executorAt = statusExportTimestamp($heartbeat['last_executor_finished_at'] ?? null);

    if ($heartbeat === null) {
        $errors[] = 'tactical_heartbeat_missing';
    } elseif ($heartbeatPid <= 0 || $heartbeatAt === null || $executorAt === null) {
        $errors[] = 'tactical_heartbeat_invalid';
    } else {
        if ($heartbeatAt < $nowTimestamp - $maxAge || $heartbeatAt > $nowTimestamp + 5
            || $executorAt < $nowTimestamp - $maxAge || $executorAt > $nowTimestamp + 5) {
            $errors[] = 'tactical_heartbeat_stale';
        }

        $lockPid = statusExportLockPid($lockPath);
        $launchdPid = statusExportHybridLaunchdPid();
        if ($lockPid !== $heartbeatPid || $launchdPid !== $heartbeatPid || !statusExportLockIsHeld($lockPath)) {
            $errors[] = 'tactical_heartbeat_pid_mismatch';
        }

        if (($heartbeat['submit'] ?? null) !== true
            || ($heartbeat['paper_only'] ?? null) !== true
            || ($heartbeat['account_guard_verified'] ?? null) !== true
            || ($heartbeat['error'] ?? null) !== null
            || (int) ($heartbeat['last_executor_exit_code'] ?? -1) !== 0
            || ($heartbeat['last_executor_timed_out'] ?? null) !== false) {
            $errors[] = 'tactical_heartbeat_failed';
        }
        if (array_key_exists('last_signal_exit_code', $heartbeat)
            && $heartbeat['last_signal_exit_code'] !== null
            && (int) $heartbeat['last_signal_exit_code'] !== 0) {
            $errors[] = 'tactical_signal_refresh_failed';
        }
    }

    $cycleAt = statusExportTimestamp($cycle['generated_at'] ?? null);
    if ($cycle === null) {
        $errors[] = 'tactical_cycle_missing';
    } elseif ($cycleAt === null) {
        $errors[] = 'tactical_cycle_invalid';
    } else {
        if ($cycleAt < $nowTimestamp - $maxAge || $cycleAt > $nowTimestamp + 5) {
            $errors[] = 'tactical_cycle_stale';
        }
        if (!hash_equals($expectedRunId, (string) ($cycle['run_id'] ?? ''))
            || !hash_equals((string) ($tacticalConfig['profile'] ?? ''), (string) ($cycle['profile'] ?? ''))
            || !hash_equals((string) ($run['status'] ?? ''), (string) ($cycle['run_status'] ?? ''))
            || ($executorAt !== null && ($cycleAt > $executorAt + 5 || $cycleAt < $executorAt - 300))) {
            $errors[] = 'tactical_cycle_mismatch';
        }

        $guard = is_array($cycle['account_guard'] ?? null) ? $cycle['account_guard'] : [];
        if (($cycle['dry_run'] ?? null) !== false
            || ($cycle['paper_only'] ?? null) !== true
            || !is_array($cycle['errors'] ?? null)
            || $cycle['errors'] !== []
            || ($guard['account_reference_match'] ?? false) !== true
            || ($guard['multiplier_match'] ?? false) !== true
            || ($guard['shorting_match'] ?? false) !== true
            || ($guard['active'] ?? false) !== true
            || ($guard['unblocked'] ?? false) !== true) {
            $errors[] = 'tactical_cycle_failed';
        }
    }

    if (($run['last_error_code'] ?? null) !== null && trim((string) $run['last_error_code']) !== '') {
        $errors[] = 'tactical_run_failed';
    }
    foreach (($notificationHealth['errors'] ?? []) as $notificationError) {
        if (is_string($notificationError) && $notificationError !== '') {
            $errors[] = $notificationError;
        }
    }
    $errors = array_values(array_unique($errors));

    return [
        'expected' => true,
        'ok' => $errors === [],
        'max_age_seconds' => $maxAge,
        'checked_at' => $now->format(DateTimeInterface::ATOM),
        'errors' => $errors,
    ];
}

function statusExportTimestamp(mixed $value): ?int
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }
    $timestamp = strtotime($value);

    return $timestamp === false ? null : $timestamp;
}

function statusExportLockPid(string $path): ?int
{
    if (!is_file($path)) {
        return null;
    }
    $value = trim((string) @file_get_contents($path));

    return $value !== '' && ctype_digit($value) && (int) $value > 0 ? (int) $value : null;
}

function statusExportLockIsHeld(string $path): bool
{
    if (!is_file($path)) {
        return false;
    }
    $handle = @fopen($path, 'r+');
    if ($handle === false) {
        return false;
    }
    $acquired = flock($handle, LOCK_EX | LOCK_NB);
    if ($acquired) {
        flock($handle, LOCK_UN);
    }
    fclose($handle);

    return !$acquired;
}

function statusExportHybridLaunchdPid(): ?int
{
    $uid = function_exists('posix_getuid') ? posix_getuid() : (int) trim(statusExportRunCommand(['id', '-u'])['stdout']);
    $result = statusExportRunCommand([
        'launchctl',
        'print',
        'gui/' . $uid . '/com.fulltimetrading.hybrid-v4-paper',
    ]);
    if ($result['exit_code'] !== 0
        || preg_match('/^\s*pid\s*=\s*([0-9]+)\s*$/m', $result['stdout'], $matches) !== 1) {
        return null;
    }

    return (int) $matches[1] > 0 ? (int) $matches[1] : null;
}

/** @param array<string,array<string,mixed>> $sleeves @return list<array<string,mixed>> */
function statusExportSanitizeTacticalSleeves(array $sleeves): array
{
    $rows = [];
    foreach ($sleeves as $id => $row) {
        $rows[] = [
            'sleeve_id' => $id,
            'allocation' => statusExportNumericOrNull($row['allocation'] ?? null),
            'cash' => statusExportNumericOrNull($row['cash'] ?? null),
            'initial_equity' => statusExportNumericOrNull($row['initial_equity'] ?? null),
            'last_signal_date' => $row['last_signal_date'] ?? null,
            'last_session' => $row['last_session'] ?? null,
        ];
    }

    return $rows;
}

/** @param array<string,mixed> $intent @return array<string,mixed> */
function statusExportSanitizeTacticalIntent(array $intent): array
{
    return [
        'decision_id_short' => substr((string) ($intent['decision_id'] ?? ''), 0, 12),
        'sleeve_id' => $intent['sleeve_id'] ?? null,
        'signal_date' => $intent['signal_date'] ?? null,
        'scheduled_session' => $intent['scheduled_session'] ?? null,
        'symbol' => $intent['symbol'] ?? null,
        'side' => $intent['side'] ?? null,
        'requested_qty' => statusExportNumericOrNull($intent['requested_qty'] ?? null),
        'filled_qty' => statusExportNumericOrNull($intent['cumulative_filled_qty'] ?? null),
        'status' => $intent['status'] ?? null,
        'client_order_id' => $intent['client_order_id'] ?? null,
        'updated_at' => $intent['updated_at'] ?? null,
    ];
}

/** @param array<string, mixed> $payload */
function statusExportMarkdown(array $payload): string
{
    $account = is_array($payload['alpaca']['account'] ?? null) ? $payload['alpaca']['account'] : [];
    $clock = is_array($payload['alpaca']['clock'] ?? null) ? $payload['alpaca']['clock'] : [];
    $positions = is_array($payload['alpaca']['positions'] ?? null) ? $payload['alpaca']['positions'] : [];
    $orders = is_array($payload['alpaca']['open_orders'] ?? null) ? $payload['alpaca']['open_orders'] : [];
    $actions = is_array($payload['bot']['recent_actions'] ?? null) ? array_slice($payload['bot']['recent_actions'], 0, 8) : [];
    $tactical = is_array($payload['tactical'] ?? null) ? $payload['tactical'] : [];

    $lines = [];
    $lines[] = '# FTT Paper Status';
    $lines[] = '';
    $lines[] = '- Generated: `' . (string) $payload['generated_at'] . '`';
    $lines[] = '- Market open: `' . (!empty($clock['is_open']) ? 'yes' : 'no') . '`';
    $lines[] = '- Orders enabled: `' . (!empty($payload['runtime']['orders_enabled']) ? 'yes' : 'no') . '`';
    $guard = is_array($payload['runtime']['paper_account_guard'] ?? null) ? $payload['runtime']['paper_account_guard'] : [];
    $lines[] = '- Paper account guard: `' . (
        ($guard['account_reference_match'] ?? false) === true
        && ($guard['multiplier_match'] ?? false) === true
        && ($guard['shorting_match'] ?? false) === true
        && ($guard['active'] ?? false) === true
        && ($guard['unblocked'] ?? false) === true
            ? 'verified'
            : 'failed'
    ) . '`';
    $lines[] = '- New production entries: `' . (!empty($payload['runtime']['production_entry_enabled']) ? 'enabled' : 'blocked') . '`';
    if (empty($payload['runtime']['production_entry_enabled'])) {
        $lines[] = '- Entry block reason: `' . (string) ($payload['runtime']['production_entry_block_reason'] ?? 'unknown') . '`';
    }
    $lines[] = '- Equity: `$' . number_format((float) ($account['equity'] ?? 0.0), 2) . '`';
    $lines[] = '- Cash: `$' . number_format((float) ($account['cash'] ?? 0.0), 2) . '`';
    $lines[] = '- Buying power: `$' . number_format((float) ($account['buying_power'] ?? 0.0), 2) . '`';
    $lines[] = '- Hybrid-v4 runtime: `' . (string) ($tactical['run']['status'] ?? 'not_initialized') . '`';
    $hybridHealth = ($tactical['health']['expected'] ?? false) !== true
        ? 'not_initialized'
        : (($tactical['health']['ok'] ?? false) === true ? 'healthy' : 'failed');
    $lines[] = '- Hybrid-v4 health: `' . $hybridHealth . '`';
    $hybridErrors = is_array($tactical['health']['errors'] ?? null) ? $tactical['health']['errors'] : [];
    if ($hybridErrors !== []) {
        $lines[] = '- Hybrid-v4 health errors: `' . implode(', ', array_map('strval', $hybridErrors)) . '`';
    }
    $notificationHealth = is_array($tactical['notifications'] ?? null) ? $tactical['notifications'] : [];
    $lines[] = sprintf(
        '- Telegram outbox: `%d pending, %d failed pending, %d delivered`',
        (int) ($notificationHealth['pending_count'] ?? 0),
        (int) ($notificationHealth['failed_pending_count'] ?? 0),
        (int) ($notificationHealth['delivered_count'] ?? 0),
    );
    $lines[] = '- Hybrid reconciliation: `' . (string) ($tactical['cycle']['reconciliation_status'] ?? 'not_started') . '`';
    $lines[] = '- Live review not before: `' . (string) ($tactical['live_review_not_before'] ?? 'unknown') . '`';
    $lines[] = '';
    $lines[] = '## Positions';
    if ($positions === []) {
        $lines[] = '- none';
    } else {
        foreach ($positions as $position) {
            if (!is_array($position)) {
                continue;
            }
            $lines[] = sprintf(
                '- `%s` qty `%s`, price `$%.2f`, value `$%.2f`, P/L `$%.2f`',
                (string) ($position['symbol'] ?? ''),
                (string) ($position['qty'] ?? '0'),
                (float) ($position['current_price'] ?? 0.0),
                (float) ($position['market_value'] ?? 0.0),
                (float) ($position['unrealized_pl'] ?? 0.0),
            );
        }
    }
    $lines[] = '';
    $lines[] = '## Open Orders';
    if ($orders === []) {
        $lines[] = '- none';
    } else {
        foreach ($orders as $order) {
            if (!is_array($order)) {
                continue;
            }
            $lines[] = sprintf(
                '- `%s` %s %s qty `%s`, limit `%s`, status `%s`',
                (string) ($order['symbol'] ?? ''),
                (string) ($order['side'] ?? ''),
                (string) ($order['type'] ?? ''),
                (string) ($order['qty'] ?? '0'),
                $order['limit_price'] !== null ? '$' . number_format((float) $order['limit_price'], 2) : '-',
                (string) ($order['status'] ?? ''),
            );
        }
    }
    $lines[] = '';
    $lines[] = '## Recent Actions';
    if ($actions === []) {
        $lines[] = '- none';
    } else {
        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }
            $lines[] = sprintf(
                '- `%s` `%s` `%s`: %s',
                substr((string) ($action['created_at'] ?? ''), 0, 19),
                (string) ($action['symbol'] ?? '-'),
                (string) ($action['action'] ?? ''),
                (string) ($action['reason'] ?? ''),
            );
        }
    }

    return implode("\n", $lines);
}

function statusExportCommitFiles(string $jsonPath, string $mdPath, bool $push, string $remote, string $branch): string
{
    $publisher = new StatusExportGitPublisher(dirname(__DIR__));

    return $publisher->commitFiles($jsonPath, $mdPath, $push, $remote, $branch);
}

/** @param list<string> $command @return array{exit_code:int, stdout:string, stderr:string} */
function statusExportRunCommand(array $command): array
{
    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        return ['exit_code' => 127, 'stdout' => '', 'stderr' => 'Unable to start process'];
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return ['exit_code' => $exitCode, 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
}

function statusExportNumericOrNull(mixed $value): ?float
{
    if ($value === null || $value === '') {
        return null;
    }

    return is_numeric($value) ? (float) $value : null;
}

function statusExportLast4(string $value): ?string
{
    return $value !== '' ? substr($value, -4) : null;
}

function statusExportPresent(string $key): bool
{
    $value = getenv($key);

    return is_string($value) && trim($value) !== '';
}

function statusExportBoolOption(string $value): bool
{
    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'y', 'on'], true);
}
