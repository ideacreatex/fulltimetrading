#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Data\HttpClient;
use FulltimeTrading\Storage\SqliteRepository;
use FulltimeTrading\Support\Config;
use FulltimeTrading\Trading\AlpacaPaperClient;

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

try {
    $account = $client->account();
    $clock = $client->clock();
    $positions = $client->positions();
    $openOrders = $client->openOrders();
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}

$latestCycle = statusExportReadOptionalJson(__DIR__ . '/../var/reports/daily/latest_paper_cycle.json');
$latestMonitor = statusExportReadOptionalJson(__DIR__ . '/../var/reports/daily/latest_paper_monitor.json')
    ?: statusExportReadOptionalJson(__DIR__ . '/../var/reports/daily/latest_paper_monitor_cycle.json');
$latestPlan = statusExportReadOptionalJson(__DIR__ . '/../var/reports/daily/latest_paper_order_plan_cycle.json')
    ?: statusExportReadOptionalJson(__DIR__ . '/../var/reports/daily/latest_paper_order_plan_tuned_daily_margin_ready.json');

$payload = [
    'generated_at' => $now->format(DateTimeInterface::ATOM),
    'host' => gethostname() ?: null,
    'repo' => statusExportGitSummary(),
    'runtime' => [
        'orders_enabled' => (bool) $config->get('trading.alpaca.orders_enabled', false),
        'paper_only' => (bool) $config->get('trading.alpaca.paper_only', true),
        'paper_base_host_ok' => parse_url(getenv('APCA_PAPER_BASE_URL') ?: (string) $config->get('trading.alpaca.paper_base_url', ''), PHP_URL_HOST) === 'paper-api.alpaca.markets',
        'data_key_set' => statusExportPresent('APCA_DATA_API_KEY_ID') || statusExportPresent('APCA_API_KEY_ID'),
        'paper_key_set' => statusExportPresent('APCA_PAPER_API_KEY_ID'),
    ],
    'alpaca' => [
        'clock' => statusExportSanitizeClock($clock),
        'account' => statusExportSanitizeAccount($account),
        'positions' => array_map('statusExportSanitizePosition', $positions),
        'open_orders' => array_map('statusExportSanitizeOrder', $openOrders),
    ],
    'bot' => [
        'states' => statusExportSanitizeStates($repo->loadPaperPositionStates()),
        'recent_orders' => array_map('statusExportSanitizeStoredOrder', $repo->recentPaperOrders((int) $options['limit'])),
        'recent_actions' => array_map('statusExportSanitizeAction', $repo->recentPaperActions((int) $options['limit'])),
    ],
    'latest_cycle' => statusExportSummarizeCycle($latestCycle),
    'latest_monitor' => statusExportSummarizeMonitor($latestMonitor),
    'latest_plan' => statusExportSummarizePlan($latestPlan),
    'errors' => $errors,
];

$outputDir = (string) $options['output-dir'];
statusExportEnsureDir($outputDir);
$jsonPath = $outputDir . '/latest_paper_status.json';
$mdPath = $outputDir . '/latest_paper_status.md';
statusExportWriteJson($jsonPath, $payload);
file_put_contents($mdPath, statusExportMarkdown($payload) . "\n");

echo "Paper status exported:\n";
echo "- {$jsonPath}\n";
echo "- {$mdPath}\n";

if (statusExportBoolOption((string) $options['git'])) {
    $gitResult = statusExportCommitFiles($jsonPath, $mdPath, statusExportBoolOption((string) $options['push']), (string) $options['remote'], (string) $options['branch']);
    echo $gitResult . "\n";
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
    file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
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
        'pattern_day_trader' => (bool) ($account['pattern_day_trader'] ?? false),
        'daytrade_count' => isset($account['daytrade_count']) ? (int) $account['daytrade_count'] : null,
        'trading_blocked' => (bool) ($account['trading_blocked'] ?? false),
        'transfers_blocked' => (bool) ($account['transfers_blocked'] ?? false),
        'account_blocked' => (bool) ($account['account_blocked'] ?? false),
        'shorting_enabled' => (bool) ($account['shorting_enabled'] ?? false),
    ];
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
        'filled_qty' => statusExportNumericOrNull($order['filled_qty'] ?? null),
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
        'reason' => $action['reason'] ?? null,
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
        'actions' => $monitor['actions'] ?? [],
        'suppressed_actions_count' => is_array($monitor['suppressed_actions'] ?? null) ? count($monitor['suppressed_actions']) : 0,
    ];
}

/** @param ?array<string, mixed> $plan @return array<string, mixed>|null */
function statusExportSummarizePlan(?array $plan): ?array
{
    if ($plan === null) {
        return null;
    }

    return [
        'generated_at' => $plan['generated_at'] ?? null,
        'dry_run' => $plan['dry_run'] ?? null,
        'submit_allowed' => $plan['submit_allowed'] ?? null,
        'orders_count' => is_array($plan['orders'] ?? null) ? count($plan['orders']) : null,
        'skipped_count' => is_array($plan['skipped'] ?? null) ? count($plan['skipped']) : null,
        'orders' => array_map('statusExportSanitizeStoredOrder', is_array($plan['orders'] ?? null) ? $plan['orders'] : []),
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

    $lines = [];
    $lines[] = '# FTT Paper Status';
    $lines[] = '';
    $lines[] = '- Generated: `' . (string) $payload['generated_at'] . '`';
    $lines[] = '- Market open: `' . (!empty($clock['is_open']) ? 'yes' : 'no') . '`';
    $lines[] = '- Orders enabled: `' . (!empty($payload['runtime']['orders_enabled']) ? 'yes' : 'no') . '`';
    $lines[] = '- Equity: `$' . number_format((float) ($account['equity'] ?? 0.0), 2) . '`';
    $lines[] = '- Cash: `$' . number_format((float) ($account['cash'] ?? 0.0), 2) . '`';
    $lines[] = '- Buying power: `$' . number_format((float) ($account['buying_power'] ?? 0.0), 2) . '`';
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
    $add = statusExportRunCommand(['git', 'add', $jsonPath, $mdPath]);
    if ($add['exit_code'] !== 0) {
        return 'Git add failed: ' . trim($add['stderr']);
    }

    $diff = statusExportRunCommand(['git', 'diff', '--cached', '--quiet', '--', $jsonPath, $mdPath]);
    if ($diff['exit_code'] === 0) {
        return 'Git status export unchanged.';
    }

    $commit = statusExportRunCommand(['git', 'commit', '-m', 'Update paper status snapshot', '--', $jsonPath, $mdPath]);
    if ($commit['exit_code'] !== 0) {
        return 'Git commit failed: ' . trim($commit['stderr']);
    }

    if (!$push) {
        return 'Git status export committed locally.';
    }

    $pushResult = statusExportRunCommand(['git', 'push', $remote, 'HEAD:' . $branch]);
    if ($pushResult['exit_code'] !== 0) {
        return 'Git push failed: ' . trim($pushResult['stderr']);
    }

    return 'Git status export committed and pushed.';
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
