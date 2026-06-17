#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Data\HttpClient;
use FulltimeTrading\Notifications\TelegramNotifier;
use FulltimeTrading\Storage\SqliteRepository;
use FulltimeTrading\Support\Config;
use FulltimeTrading\Trading\AlpacaPaperClient;

require __DIR__ . '/../bootstrap.php';

$options = [
    'report' => __DIR__ . '/../var/reports/daily/alpaca_selected_best_partial_live_signal_report.json',
    'output' => __DIR__ . '/../var/reports/daily/latest_paper_monitor.json',
    'submit' => 'false',
    'telegram' => 'true',
    'telegram-heartbeat' => 'false',
    'partial-pct' => '',
    'close-when-model-closed' => 'false',
    'time-in-force' => 'day',
    'review-cooldown-minutes' => '1440',
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
$http = new HttpClient();
$client = new AlpacaPaperClient(
    $http,
    getenv('APCA_PAPER_BASE_URL') ?: (string) $config->get('trading.alpaca.paper_base_url', 'https://paper-api.alpaca.markets/v2'),
);

$now = new DateTimeImmutable();
$dryRun = !boolOption((string) $options['submit']);
$submitAllowed = !$dryRun
    && (bool) $config->get('trading.alpaca.paper_only', true)
    && (bool) $config->get('trading.alpaca.orders_enabled', false);
$partialPct = (string) $options['partial-pct'] !== ''
    ? (float) $options['partial-pct']
    : (float) $config->get('strategy.partial_take_profit_pct', 0.25);
$partialPct = max(0.0, min(1.0, $partialPct));
$breakEvenPct = (float) $config->get('strategy.club_rules.break_even_profit_pct', 0.02);
$reviewCooldownMinutes = max(0, (int) $options['review-cooldown-minutes']);

$report = readJson((string) $options['report']);
$modelPositions = indexBySymbol($report['model']['open_positions'] ?? []);
$signals = indexBestSignalBySymbol(array_merge($report['signals_today'] ?? [], $report['recent_signals'] ?? []));
$states = $repo->loadPaperPositionStates();
$actions = [];
$suppressedActions = [];
$submittedOrders = [];

try {
    $account = $client->account();
    $clock = $client->clock();
    $positions = $client->positions();
    $openOrders = $client->openOrders();
} catch (Throwable $e) {
    $repo->logPaperAction([
        'created_at' => $now->format(DateTimeInterface::ATOM),
        'action' => 'monitor_error',
        'severity' => 'error',
        'dry_run' => $dryRun,
        'reason' => $e->getMessage(),
    ]);
    throw $e;
}

$isMarketOpen = (bool) ($clock['is_open'] ?? false);
$positionSymbols = [];
foreach ($positions as $position) {
    $symbol = strtoupper((string) ($position['symbol'] ?? ''));
    if ($symbol === '') {
        continue;
    }
    $positionSymbols[$symbol] = true;
    $state = stateFromPosition($position, $states[$symbol] ?? null, $modelPositions[$symbol] ?? null, $signals[$symbol] ?? null, $breakEvenPct, $now);
    $managedByReport = isset($modelPositions[$symbol]) || isset($signals[$symbol]);
    $decisionRows = decisionsForPosition(
        $state,
        $position,
        $managedByReport,
        $partialPct,
        boolOption((string) $options['close-when-model-closed']),
    );

    foreach ($decisionRows as $decision) {
        if (shouldSuppressDecision($state, $decision, $now, $reviewCooldownMinutes)) {
            $suppressedActions[] = [
                'symbol' => $symbol,
                'action' => $dryRun ? 'would_' . $decision['action'] : $decision['action'],
                'reason' => (string) $decision['reason'],
            ];
            continue;
        }

        $orderPayload = null;
        $submitted = false;
        $orderId = null;
        $clientOrderId = clientOrderId($decision['action'], $symbol, $now);
        if (in_array($decision['action'], ['close_stop', 'partial_take_profit', 'close_model_missing'], true)) {
            $orderPayload = sellOrder($symbol, (float) $decision['qty'], (string) $options['time-in-force'], $clientOrderId);
            $plannedStatus = $dryRun
                ? 'dry_run_planned'
                : (!$submitAllowed ? 'blocked_submit_not_allowed' : (!$isMarketOpen ? 'blocked_market_closed' : 'planned'));
            $repo->savePaperOrderState(orderStateFromMonitorOrder(
                $orderPayload,
                $plannedStatus,
                $dryRun,
                $now,
                false,
                null,
                null,
                ['decision' => $decision],
            ));
            if ($submitAllowed && $isMarketOpen) {
                $submittedOrder = $client->submitOrder($orderPayload);
                $submittedOrders[] = $submittedOrder;
                $submitted = true;
                $orderId = (string) ($submittedOrder['id'] ?? '');
                $repo->savePaperOrderState(orderStateFromMonitorOrder(
                    $orderPayload,
                    (string) ($submittedOrder['status'] ?? 'submitted'),
                    false,
                    $now,
                    true,
                    $orderId,
                    $now->format(DateTimeInterface::ATOM),
                    ['decision' => $decision, 'alpaca_order' => $submittedOrder],
                ));
            }
        }

        $repo->logPaperAction([
            'created_at' => $now->format(DateTimeInterface::ATOM),
            'symbol' => $symbol,
            'action' => $dryRun ? 'would_' . $decision['action'] : $decision['action'],
            'severity' => (string) $decision['severity'],
            'dry_run' => $dryRun,
            'submitted' => $submitted,
            'order_id' => $orderId,
            'client_order_id' => $orderPayload['client_order_id'] ?? $clientOrderId,
            'reason' => (string) $decision['reason'],
            'payload' => [
                'decision' => $decision,
                'order' => $orderPayload,
                'market_open' => $isMarketOpen,
                'submit_allowed' => $submitAllowed,
            ],
        ]);

        $actions[] = [
            'symbol' => $symbol,
            'action' => $dryRun ? 'would_' . $decision['action'] : $decision['action'],
            'reason' => (string) $decision['reason'],
            'submitted' => $submitted,
            'order' => $orderPayload,
        ];

        if (!$dryRun && ($submitted || in_array($decision['action'], ['arm_break_even', 'review_model_missing'], true))) {
            $state = applySubmittedDecision($state, $decision, $now, $orderPayload['client_order_id'] ?? null);
        }
    }

    $state['last_event_at'] = $now->format(DateTimeInterface::ATOM);
    $state['payload']['alpaca_position'] = $position;
    $repo->savePaperPositionState($state);
}

foreach ($states as $symbol => $state) {
    if (isset($positionSymbols[$symbol]) || ($state['status'] ?? '') === 'closed') {
        continue;
    }
    $state['status'] = 'closed';
    $state['qty'] = 0.0;
    $state['closed_at'] = $now->format(DateTimeInterface::ATOM);
    $state['last_event_at'] = $now->format(DateTimeInterface::ATOM);
    $state['last_action'] = 'sync_closed';
    $repo->savePaperPositionState($state);
    $repo->logPaperAction([
        'created_at' => $now->format(DateTimeInterface::ATOM),
        'symbol' => $symbol,
        'action' => 'sync_closed',
        'severity' => 'info',
        'dry_run' => false,
        'reason' => 'Paper position is no longer present at Alpaca.',
        'payload' => ['previous_state' => $state],
    ]);
    $actions[] = ['symbol' => $symbol, 'action' => 'sync_closed', 'reason' => 'Position absent at Alpaca.', 'submitted' => false];
}

if ($actions === []) {
    $repo->logPaperAction([
        'created_at' => $now->format(DateTimeInterface::ATOM),
        'action' => 'monitor_heartbeat',
        'severity' => 'info',
        'dry_run' => $dryRun,
        'reason' => 'No paper position actions required.',
        'payload' => [
            'positions' => count($positions),
            'open_orders' => count($openOrders),
            'market_open' => $isMarketOpen,
        ],
    ]);
}

$payload = [
    'generated_at' => $now->format(DateTimeInterface::ATOM),
    'dry_run' => $dryRun,
    'submit_allowed' => $submitAllowed,
    'market_open' => $isMarketOpen,
    'paper_account' => summarizeAccount($account),
    'positions_count' => count($positions),
    'open_orders_count' => count($openOrders),
    'partial_pct' => $partialPct,
    'break_even_pct' => $breakEvenPct,
    'actions' => $actions,
    'suppressed_actions' => $suppressedActions,
    'submitted_orders' => $submittedOrders,
    'state' => $repo->loadPaperPositionStates(),
    'recent_actions' => $repo->recentPaperActions(10),
];

writeJson((string) $options['output'], $payload);
$text = monitorText($payload);
echo $text . "\n";
echo "Monitor report: " . (string) $options['output'] . "\n";

if (boolOption((string) $options['telegram']) && ($actions !== [] || boolOption((string) $options['telegram-heartbeat']))) {
    $notifier = TelegramNotifier::fromEnv($http);
    if ($notifier === null) {
        echo "Telegram warning: missing TELEGRAM_BOT_TOKEN/TELEGRAM_CHAT_ID\n";
    } else {
        try {
            $notifier->sendMessage($text, $actions === []);
            echo "Telegram message sent\n";
        } catch (Throwable $e) {
            echo "Telegram warning: " . $e->getMessage() . "\n";
        }
    }
} elseif (boolOption((string) $options['telegram'])) {
    echo "Telegram heartbeat skipped\n";
}

/** @return array<string, mixed> */
function readJson(string $path): array
{
    $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new RuntimeException('Invalid JSON: ' . $path);
    }

    return $payload;
}

/** @param array<string, mixed> $payload */
function writeJson(string $path, array $payload): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create output dir: ' . $dir);
    }
    file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}

/** @param mixed $rows @return array<string, array<string, mixed>> */
function indexBySymbol(mixed $rows): array
{
    $result = [];
    if (!is_array($rows)) {
        return $result;
    }
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $symbol = strtoupper((string) ($row['symbol'] ?? ''));
        if ($symbol !== '') {
            $result[$symbol] = $row;
        }
    }

    return $result;
}

/** @param list<array<string, mixed>> $signals @return array<string, array<string, mixed>> */
function indexBestSignalBySymbol(array $signals): array
{
    $result = [];
    foreach ($signals as $signal) {
        if (!is_array($signal)) {
            continue;
        }
        $symbol = strtoupper((string) ($signal['symbol'] ?? ''));
        if ($symbol === '') {
            continue;
        }
        if (!isset($result[$symbol]) || (float) ($signal['score'] ?? 0.0) > (float) ($result[$symbol]['score'] ?? 0.0)) {
            $result[$symbol] = $signal;
        }
    }

    return $result;
}

/** @param array<string, mixed> $position @param ?array<string, mixed> $existing @param ?array<string, mixed> $model @param ?array<string, mixed> $signal @return array<string, mixed> */
function stateFromPosition(array $position, ?array $existing, ?array $model, ?array $signal, float $breakEvenPct, DateTimeImmutable $now): array
{
    $symbol = strtoupper((string) ($position['symbol'] ?? ''));
    $qty = abs((float) ($position['qty'] ?? 0.0));
    $avgEntry = (float) ($position['avg_entry_price'] ?? 0.0);
    $marketPrice = (float) ($position['current_price'] ?? $position['market_price'] ?? 0.0);
    $entry = (float) ($existing['entry_price'] ?? $model['entry'] ?? $signal['entry'] ?? $avgEntry);
    $initialStop = (float) ($existing['initial_stop_price'] ?? $model['initial_stop'] ?? $signal['stop'] ?? 0.0);
    $stop = (float) ($existing['stop_price'] ?? $model['stop'] ?? $initialStop);
    $target = (float) ($existing['target_price'] ?? $signal['target'] ?? 0.0);
    if ($target <= 0.0 && $entry > 0.0 && $initialStop > 0.0) {
        $target = $entry + max(0.0, ($entry - $initialStop) * 2.0);
    }
    $breakEvenTrigger = (float) ($existing['break_even_trigger_price'] ?? $signal['break_even_trigger'] ?? 0.0);
    if ($breakEvenTrigger <= 0.0 && $entry > 0.0) {
        $breakEvenTrigger = $entry * (1.0 + $breakEvenPct);
    }

    $payload = is_array($existing['payload'] ?? null) ? $existing['payload'] : [];
    $payload['model_position'] = $model;
    $payload['signal'] = $signal;

    return [
        'symbol' => $symbol,
        'status' => 'open',
        'qty' => $qty,
        'avg_entry_price' => $avgEntry,
        'market_price' => $marketPrice,
        'entry_price' => $entry,
        'stop_price' => $stop,
        'initial_stop_price' => $initialStop,
        'break_even_trigger_price' => $breakEvenTrigger,
        'target_price' => $target,
        'break_even_armed' => (bool) ($existing['break_even_armed'] ?? $model['break_even_armed'] ?? false),
        'partial_done' => (bool) ($existing['partial_done'] ?? $model['took_partial'] ?? false),
        'strategy' => (string) ($model['strategy'] ?? $signal['strategy'] ?? $existing['strategy'] ?? 'unknown'),
        'setup_key' => (string) ($model['key'] ?? $model['metadata']['setup_key'] ?? $existing['setup_key'] ?? ''),
        'opened_at' => $existing['opened_at'] ?? $model['entry_date'] ?? $now->format(DateTimeInterface::ATOM),
        'closed_at' => null,
        'last_event_at' => $now->format(DateTimeInterface::ATOM),
        'last_action' => $existing['last_action'] ?? 'sync_open',
        'client_order_id' => $existing['client_order_id'] ?? null,
        'payload' => $payload,
    ];
}

/** @param array<string, mixed> $state @param array<string, mixed> $position @return list<array<string, mixed>> */
function decisionsForPosition(array $state, array $position, bool $managedOpen, float $partialPct, bool $closeWhenModelClosed): array
{
    $rows = [];
    $qty = (float) $state['qty'];
    $price = (float) $state['market_price'];
    $entry = (float) $state['entry_price'];
    $stop = (float) $state['stop_price'];
    $beTrigger = (float) $state['break_even_trigger_price'];
    $target = (float) $state['target_price'];
    $symbol = (string) $state['symbol'];
    if ($qty <= 0.0 || $price <= 0.0) {
        return $rows;
    }

    if (!$managedOpen) {
        $rows[] = [
            'action' => $closeWhenModelClosed ? 'close_model_missing' : 'review_model_missing',
            'severity' => $closeWhenModelClosed ? 'warning' : 'info',
            'reason' => $symbol . ' exists at Alpaca but is absent from model/report-managed positions.',
            'qty' => $qty,
        ];
        if (!$closeWhenModelClosed) {
            return $rows;
        }
    }

    if (!($state['break_even_armed'] ?? false) && $beTrigger > 0.0 && $price >= $beTrigger) {
        $rows[] = [
            'action' => 'arm_break_even',
            'severity' => 'info',
            'reason' => sprintf('%s reached BE trigger %.2f at %.2f.', $symbol, $beTrigger, $price),
            'qty' => $qty,
            'new_stop' => $entry,
        ];
    }

    if ($stop > 0.0 && $price <= $stop) {
        $rows[] = [
            'action' => 'close_stop',
            'severity' => 'warning',
            'reason' => sprintf('%s price %.2f is at/below stop %.2f.', $symbol, $price, $stop),
            'qty' => $qty,
        ];

        return $rows;
    }

    if (!($state['partial_done'] ?? false) && $target > 0.0 && $price >= $target) {
        $rows[] = [
            'action' => 'partial_take_profit',
            'severity' => 'info',
            'reason' => sprintf('%s reached target %.2f at %.2f.', $symbol, $target, $price),
            'qty' => max(0.0, floor($qty * $partialPct * 1000000.0) / 1000000.0),
        ];
    }

    return $rows;
}

/** @param array<string, mixed> $state @param array<string, mixed> $decision */
function applySubmittedDecision(array $state, array $decision, DateTimeImmutable $now, ?string $clientOrderId): array
{
    $action = (string) $decision['action'];
    if ($action === 'arm_break_even') {
        $state['break_even_armed'] = true;
        $state['stop_price'] = (float) ($decision['new_stop'] ?? $state['entry_price']);
    }
    if ($action === 'partial_take_profit') {
        $state['partial_done'] = true;
    }
    if (in_array($action, ['close_stop', 'close_model_missing'], true)) {
        $state['status'] = 'closing';
    }
    if ($action === 'review_model_missing') {
        $payload = is_array($state['payload'] ?? null) ? $state['payload'] : [];
        $payload['last_review_model_missing_at'] = $now->format(DateTimeInterface::ATOM);
        $state['payload'] = $payload;
    }
    $state['last_action'] = $action;
    $state['last_event_at'] = $now->format(DateTimeInterface::ATOM);
    $state['client_order_id'] = $clientOrderId;

    return $state;
}

/** @param array<string, mixed> $state @param array<string, mixed> $decision */
function shouldSuppressDecision(array $state, array $decision, DateTimeImmutable $now, int $cooldownMinutes): bool
{
    if ((string) ($decision['action'] ?? '') !== 'review_model_missing' || $cooldownMinutes <= 0) {
        return false;
    }

    $payload = is_array($state['payload'] ?? null) ? $state['payload'] : [];
    $lastAt = (string) ($payload['last_review_model_missing_at'] ?? '');
    if ($lastAt === '') {
        return false;
    }

    try {
        $last = new DateTimeImmutable($lastAt);
    } catch (Throwable) {
        return false;
    }

    return ($now->getTimestamp() - $last->getTimestamp()) < ($cooldownMinutes * 60);
}

/** @return array<string, mixed> */
function sellOrder(string $symbol, float $qty, string $timeInForce, string $clientOrderId): array
{
    return [
        'symbol' => $symbol,
        'side' => 'sell',
        'type' => 'market',
        'qty' => number_format(max(0.0, $qty), 6, '.', ''),
        'time_in_force' => $timeInForce,
        'extended_hours' => false,
        'client_order_id' => $clientOrderId,
    ];
}

/**
 * @param array<string, mixed> $order
 * @param array<string, mixed> $extraPayload
 * @return array<string, mixed>
 */
function orderStateFromMonitorOrder(
    array $order,
    string $status,
    bool $dryRun,
    DateTimeImmutable $now,
    bool $submitted = false,
    ?string $orderId = null,
    ?string $submittedAt = null,
    array $extraPayload = [],
): array {
    return [
        'client_order_id' => (string) ($order['client_order_id'] ?? ''),
        'symbol' => (string) ($order['symbol'] ?? ''),
        'side' => (string) ($order['side'] ?? 'sell'),
        'type' => (string) ($order['type'] ?? 'market'),
        'qty' => (float) ($order['qty'] ?? 0.0),
        'limit_price' => isset($order['limit_price']) ? (float) $order['limit_price'] : null,
        'stop_price' => null,
        'time_in_force' => (string) ($order['time_in_force'] ?? 'day'),
        'status' => $status,
        'submitted' => $submitted,
        'order_id' => $orderId,
        'source_report' => 'paper_position_monitor',
        'planned_at' => $now->format(DateTimeInterface::ATOM),
        'submitted_at' => $submittedAt,
        'updated_at' => $now->format(DateTimeInterface::ATOM),
        'payload' => array_merge([
            'dry_run' => $dryRun,
            'order' => $order,
        ], $extraPayload),
    ];
}

function clientOrderId(string $action, string $symbol, DateTimeImmutable $now): string
{
    $raw = implode('_', ['fttmon', preg_replace('/[^A-Z0-9]+/', '', strtoupper($symbol)), preg_replace('/[^a-z0-9]+/', '', strtolower($action)), $now->format('YmdHis')]);

    return substr($raw, 0, 48);
}

/** @param array<string, mixed> $account @return array<string, mixed> */
function summarizeAccount(array $account): array
{
    return [
        'status' => $account['status'] ?? null,
        'currency' => $account['currency'] ?? null,
        'cash' => isset($account['cash']) ? (float) $account['cash'] : null,
        'equity' => isset($account['equity']) ? (float) $account['equity'] : null,
        'buying_power' => isset($account['buying_power']) ? (float) $account['buying_power'] : null,
        'multiplier' => $account['multiplier'] ?? null,
        'trading_blocked' => (bool) ($account['trading_blocked'] ?? false),
    ];
}

/** @param array<string, mixed> $payload */
function monitorText(array $payload): string
{
    $lines = [];
    $lines[] = 'FTT paper monitor ' . substr((string) $payload['generated_at'], 0, 19);
    $lines[] = 'Mode: ' . ($payload['dry_run'] ? 'DRY-RUN' : 'SUBMIT') . ', market ' . ($payload['market_open'] ? 'open' : 'closed');
    $account = is_array($payload['paper_account'] ?? null) ? $payload['paper_account'] : [];
    $lines[] = sprintf(
        'Paper: equity $%.2f, cash $%.2f, BP $%.2f, mult %s',
        (float) ($account['equity'] ?? 0.0),
        (float) ($account['cash'] ?? 0.0),
        (float) ($account['buying_power'] ?? 0.0),
        (string) ($account['multiplier'] ?? 'n/a'),
    );
    $lines[] = 'Positions: ' . (int) $payload['positions_count'] . ', open orders: ' . (int) $payload['open_orders_count'];
    $actions = is_array($payload['actions'] ?? null) ? $payload['actions'] : [];
    $suppressedActions = is_array($payload['suppressed_actions'] ?? null) ? $payload['suppressed_actions'] : [];
    if ($actions === []) {
        $lines[] = 'Actions: none';
    } else {
        $lines[] = 'Actions:';
        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }
            $lines[] = sprintf(
                '- %s %s: %s%s',
                (string) ($action['symbol'] ?? ''),
                (string) ($action['action'] ?? ''),
                (string) ($action['reason'] ?? ''),
                !empty($action['submitted']) ? ' [submitted]' : '',
            );
        }
    }
    if ($suppressedActions !== []) {
        $lines[] = 'Suppressed duplicate info actions: ' . count($suppressedActions);
    }

    return implode("\n", $lines);
}

function boolOption(string $value): bool
{
    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'y', 'on'], true);
}
