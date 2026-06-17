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
    'report' => __DIR__ . '/../var/reports/daily/latest_signal_report.json',
    'output' => __DIR__ . '/../var/reports/daily/latest_paper_order_plan.json',
    'submit' => 'false',
    'max-orders' => '',
    'min-score' => '0',
    'allow-layered' => 'false',
    'model-open-counts' => 'true',
    'ignore-model-open' => 'false',
    'paper-open-counts' => 'false',
    'paper-sync-required' => 'false',
    'paper-sizing-cash' => 'true',
    'maintenance-guard' => 'true',
    'maintenance-buffer-pct' => '0.70',
    'require-market-open' => 'true',
    'time-in-force' => 'day',
    'integer-qty-for-limit' => 'true',
    'dedupe' => 'true',
    'force' => 'false',
    'telegram' => 'false',
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
$now = new DateTimeImmutable();
$dryRun = !boolOption((string) $options['submit']);
$report = json_decode((string) file_get_contents((string) $options['report']), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($report)) {
    throw new RuntimeException('Invalid daily report JSON.');
}

if (!$dryRun) {
    if (!(bool) $config->get('trading.alpaca.paper_only', true)) {
        throw new RuntimeException('Refusing to submit orders while trading.alpaca.paper_only is false.');
    }
    if (!(bool) $config->get('trading.alpaca.orders_enabled', false)) {
        throw new RuntimeException('Refusing to submit orders while trading.alpaca.orders_enabled is false.');
    }
}

$paperContext = loadPaperContext($config, $repo, $http, $options, !$dryRun);
if (!$dryRun && boolOption((string) ($options['require-market-open'] ?? 'true')) && !($paperContext['clock']['is_open'] ?? false)) {
    throw new RuntimeException('Refusing to submit entry orders while Alpaca market clock is closed.');
}
$plan = buildOrderPlan($report, $options, $paperContext);
$plan = persistOrderPlan($repo, $plan, $options, $now, $dryRun);
$submitted = [];
$submitErrors = [];
if (!$dryRun) {

    $client = new AlpacaPaperClient(
        $http,
        getenv('APCA_PAPER_BASE_URL') ?: (string) $config->get('trading.alpaca.paper_base_url', 'https://paper-api.alpaca.markets/v2'),
    );
    foreach ($plan['orders'] as $order) {
        try {
            $submittedOrder = $client->submitOrder($order);
            $submitted[] = $submittedOrder;
            $repo->savePaperOrderState(orderStateFromPlan(
                $order,
                (string) ($submittedOrder['status'] ?? 'submitted'),
                false,
                (string) $options['report'],
                $now,
                true,
                (string) ($submittedOrder['id'] ?? ''),
                $now->format(DateTimeInterface::ATOM),
                ['alpaca_order' => $submittedOrder],
            ));
            $repo->logPaperAction([
                'created_at' => $now->format(DateTimeInterface::ATOM),
                'symbol' => (string) $order['symbol'],
                'action' => 'entry_order_submitted',
                'severity' => 'info',
                'dry_run' => false,
                'submitted' => true,
                'order_id' => (string) ($submittedOrder['id'] ?? ''),
                'client_order_id' => (string) ($order['client_order_id'] ?? ''),
                'reason' => 'Entry limit order sent to Alpaca paper.',
                'payload' => ['order' => $order, 'alpaca_order' => $submittedOrder],
            ]);
        } catch (Throwable $e) {
            $submitErrors[] = [
                'client_order_id' => (string) ($order['client_order_id'] ?? ''),
                'symbol' => (string) ($order['symbol'] ?? ''),
                'error' => $e->getMessage(),
            ];
            $repo->savePaperOrderState(orderStateFromPlan(
                $order,
                'submit_failed',
                false,
                (string) $options['report'],
                $now,
                false,
                null,
                null,
                ['error' => $e->getMessage()],
            ));
            $repo->logPaperAction([
                'created_at' => $now->format(DateTimeInterface::ATOM),
                'symbol' => (string) $order['symbol'],
                'action' => 'entry_order_submit_failed',
                'severity' => 'error',
                'dry_run' => false,
                'submitted' => false,
                'client_order_id' => (string) ($order['client_order_id'] ?? ''),
                'reason' => $e->getMessage(),
                'payload' => ['order' => $order],
            ]);
        }
    }
}

$payload = [
    'generated_at' => $now->format(DateTimeInterface::ATOM),
    'source_report' => (string) $options['report'],
    'submit_requested' => !$dryRun,
    'submitted_count' => count($submitted),
    'submit_error_count' => count($submitErrors),
    'plan' => $plan,
    'submitted' => $submitted,
    'submit_errors' => $submitErrors,
    'recent_orders' => $repo->recentPaperOrders(10),
];

$output = (string) $options['output'];
$dir = dirname($output);
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    throw new RuntimeException('Unable to create output directory: ' . $dir);
}
file_put_contents($output, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

echo "Order plan: {$output}\n";
echo "Orders: " . count($plan['orders']) . ", skipped: " . count($plan['skipped']) . ", submitted: " . count($submitted) . "\n";
foreach ($plan['orders'] as $order) {
    printf(
        "- %s %s qty %s limit %s tif %s\n",
        strtoupper((string) $order['side']),
        (string) $order['symbol'],
        (string) $order['qty'],
        (string) $order['limit_price'],
        (string) $order['time_in_force'],
    );
}
if ($submitErrors !== []) {
    echo "Submit errors: " . count($submitErrors) . "\n";
}

if (boolOption((string) $options['telegram'])) {
    $notifier = TelegramNotifier::fromEnv($http);
    if ($notifier === null) {
        echo "Telegram warning: missing TELEGRAM_BOT_TOKEN/TELEGRAM_CHAT_ID\n";
    } else {
        try {
            $notifier->sendMessage(orderPlanText($payload), count($plan['orders']) === 0 && count($submitErrors) === 0);
            echo "Telegram message sent\n";
        } catch (Throwable $e) {
            echo "Telegram warning: " . $e->getMessage() . "\n";
        }
    }
}

/**
 * @param array<string, mixed> $report
 * @param array<string, string> $options
 * @param array<string, mixed> $paperContext
 * @return array<string, mixed>
 */
function buildOrderPlan(array $report, array $options, array $paperContext): array
{
    $signals = is_array($report['signals_today'] ?? null) ? $report['signals_today'] : [];
    $risk = is_array($report['risk'] ?? null) ? $report['risk'] : [];
    $model = is_array($report['model'] ?? null) ? $report['model'] : [];
    $openPositions = is_array($model['open_positions'] ?? null) ? $model['open_positions'] : [];

    $maxOpen = max(1, (int) ($risk['max_open_positions'] ?? 1));
    $reportInitialCash = max(0.0, (float) ($risk['initial_cash'] ?? 0.0));
    $paperEquity = isset($paperContext['account']['equity']) ? (float) $paperContext['account']['equity'] : 0.0;
    $initialCash = boolOption((string) ($options['paper-sizing-cash'] ?? 'true')) && $paperEquity > 0.0
        ? $paperEquity
        : $reportInitialCash;
    $maxGross = max(0.0, (float) ($risk['max_gross_exposure_pct'] ?? 1.0));
    $slotBudget = $initialCash > 0.0 ? ($initialCash * $maxGross / $maxOpen) : 0.0;
    $modelOpenCounts = boolOption((string) ($options['model-open-counts'] ?? 'true'));
    $ignoreModelOpen = boolOption((string) ($options['ignore-model-open'] ?? 'false'));
    $paperOpenCounts = boolOption((string) ($options['paper-open-counts'] ?? 'false'));
    $modelSymbols = $ignoreModelOpen ? [] : openSymbols($openPositions);
    $paperSymbols = $paperOpenCounts ? paperOpenSymbols($paperContext) : [];
    $openSymbols = array_merge($modelSymbols, $paperSymbols);
    $slotSymbols = [];
    if ($modelOpenCounts && !$ignoreModelOpen) {
        $slotSymbols = array_merge($slotSymbols, $modelSymbols);
    }
    if ($paperOpenCounts) {
        $slotSymbols = array_merge($slotSymbols, $paperSymbols);
    }
    $openCount = count($slotSymbols);
    $availableSlots = max(0, $maxOpen - $openCount);
    $maxOrders = (string) ($options['max-orders'] ?? '') !== ''
        ? max(0, (int) $options['max-orders'])
        : $availableSlots;
    $maxOrders = min($availableSlots, $maxOrders);
    $minScore = (float) ($options['min-score'] ?? 0.0);
    $allowLayered = boolOption((string) ($options['allow-layered'] ?? 'false'));
    $maintenanceGuard = boolOption((string) ($options['maintenance-guard'] ?? 'true'));
    $maintenanceBufferPct = max(0.0, min(1.0, (float) ($options['maintenance-buffer-pct'] ?? 0.90)));
    $maintenanceLimit = $maintenanceGuard && $paperEquity > 0.0 ? $paperEquity * $maintenanceBufferPct : INF;
    $maintenanceUsed = $maintenanceGuard ? existingMaintenanceRequirement($paperContext) : 0.0;

    usort($signals, static fn (array $a, array $b): int => ((float) ($b['score'] ?? 0.0)) <=> ((float) ($a['score'] ?? 0.0)));

    $orders = [];
    $skipped = [];
    $plannedSymbols = [];
    foreach ($signals as $signal) {
        if (count($orders) >= $maxOrders) {
            $skipped[] = skipRow($signal, 'no_available_slot');
            continue;
        }

        $symbol = strtoupper((string) ($signal['symbol'] ?? ''));
        $direction = strtolower((string) ($signal['direction'] ?? ''));
        $entry = (float) ($signal['entry'] ?? 0.0);
        $score = (float) ($signal['score'] ?? 0.0);
        if ($symbol === '' || $direction !== 'long' || $entry <= 0.0) {
            $skipped[] = skipRow($signal, 'not_a_long_limit_signal');
            continue;
        }
        if ($score < $minScore) {
            $skipped[] = skipRow($signal, 'score_below_minimum');
            continue;
        }
        if (isset($openSymbols[$symbol]) && (!$allowLayered || !$openSymbols[$symbol]['break_even_armed'])) {
            $skipped[] = skipRow($signal, 'symbol_already_open_without_green_garden');
            continue;
        }
        if (isset($plannedSymbols[$symbol])) {
            $skipped[] = skipRow($signal, 'symbol_already_planned');
            continue;
        }
        if ($slotBudget <= 0.0) {
            $skipped[] = skipRow($signal, 'zero_slot_budget');
            continue;
        }

        $orderBudget = $slotBudget;
        $maintenanceRate = maintenanceRateForSymbol($symbol);
        $maintenanceRemaining = $maintenanceLimit - $maintenanceUsed;
        if ($maintenanceGuard && $maintenanceRate > 0.0) {
            if ($maintenanceRemaining <= 0.0) {
                $skipped[] = skipRow($signal, 'maintenance_budget_exhausted');
                continue;
            }
            $orderBudget = min($orderBudget, $maintenanceRemaining / $maintenanceRate);
        }

        $qty = boolOption((string) ($options['integer-qty-for-limit'] ?? 'true'))
            ? floor($orderBudget / $entry)
            : floor(($orderBudget / $entry) * 1000000.0) / 1000000.0;
        if ($qty <= 0.0) {
            $skipped[] = skipRow($signal, $maintenanceGuard ? 'quantity_too_small_after_maintenance_guard' : 'quantity_too_small');
            continue;
        }
        $plannedNotional = $qty * $entry;
        $plannedMaintenance = $plannedNotional * $maintenanceRate;

        $orders[] = [
            'symbol' => $symbol,
            'side' => 'buy',
            'type' => 'limit',
            'qty' => number_format($qty, 6, '.', ''),
            'limit_price' => alpacaPrice($entry),
            'time_in_force' => (string) ($options['time-in-force'] ?? 'day'),
            'extended_hours' => false,
            'client_order_id' => clientOrderId($signal),
            'metadata' => [
                'score' => $score,
                'stop' => (float) ($signal['stop'] ?? 0.0),
                'break_even_trigger' => (float) ($signal['break_even_trigger'] ?? 0.0),
                'target' => (float) ($signal['target'] ?? 0.0),
                'planned_notional' => round($plannedNotional, 2),
                'estimated_maintenance_rate' => $maintenanceRate,
                'estimated_maintenance_requirement' => round($plannedMaintenance, 2),
            ],
        ];
        $maintenanceUsed += $plannedMaintenance;
        $plannedSymbols[$symbol] = true;
    }

    return [
        'as_of' => (string) ($report['as_of'] ?? ''),
        'action' => (string) ($report['action'] ?? ''),
        'model_open_counts' => $modelOpenCounts,
        'ignore_model_open' => $ignoreModelOpen,
        'paper_open_counts' => $paperOpenCounts,
        'paper_sizing_cash' => boolOption((string) ($options['paper-sizing-cash'] ?? 'true')),
        'sizing_cash' => round($initialCash, 2),
        'report_initial_cash' => round($reportInitialCash, 2),
        'paper_equity' => $paperEquity > 0.0 ? round($paperEquity, 2) : null,
        'market_open' => (bool) ($paperContext['clock']['is_open'] ?? false),
        'maintenance_guard' => $maintenanceGuard,
        'maintenance_buffer_pct' => $maintenanceBufferPct,
        'maintenance_limit' => is_finite($maintenanceLimit) ? round($maintenanceLimit, 2) : null,
        'estimated_maintenance_used' => round($maintenanceUsed, 2),
        'estimated_maintenance_pct_of_equity' => $paperEquity > 0.0 ? round($maintenanceUsed / $paperEquity, 4) : null,
        'paper_positions_count' => count($paperContext['positions'] ?? []),
        'paper_open_orders_count' => count($paperContext['open_orders'] ?? []),
        'paper_sync_error' => $paperContext['sync_error'] ?? null,
        'available_slots' => $availableSlots,
        'open_slot_symbols' => array_keys($slotSymbols),
        'slot_budget' => round($slotBudget, 2),
        'orders' => $orders,
        'skipped' => $skipped,
    ];
}

/** @param array<string, mixed> $paperContext */
function existingMaintenanceRequirement(array $paperContext): float
{
    $requirement = 0.0;
    foreach (($paperContext['positions'] ?? []) as $position) {
        if (!is_array($position)) {
            continue;
        }
        $symbol = strtoupper((string) ($position['symbol'] ?? ''));
        $marketValue = abs((float) ($position['market_value'] ?? 0.0));
        if ($symbol === '' || $marketValue <= 0.0) {
            continue;
        }
        $requirement += $marketValue * maintenanceRateForSymbol($symbol);
    }
    foreach (($paperContext['open_orders'] ?? []) as $order) {
        if (!is_array($order)) {
            continue;
        }
        $symbol = strtoupper((string) ($order['symbol'] ?? ''));
        $side = strtolower((string) ($order['side'] ?? ''));
        $status = strtolower((string) ($order['status'] ?? ''));
        if ($symbol === '' || $side !== 'buy' || in_array($status, ['filled', 'canceled', 'cancelled', 'expired', 'rejected'], true)) {
            continue;
        }
        $qty = (float) ($order['qty'] ?? 0.0);
        $price = (float) ($order['limit_price'] ?? $order['stop_price'] ?? 0.0);
        if ($qty <= 0.0 || $price <= 0.0) {
            continue;
        }
        $requirement += ($qty * $price) * maintenanceRateForSymbol($symbol);
    }

    return $requirement;
}

function maintenanceRateForSymbol(string $symbol): float
{
    $symbol = strtoupper($symbol);
    $tripleLeveraged = ['UPRO', 'SPXL', 'TQQQ', 'SOXL', 'TECL', 'FAS', 'TNA', 'UDOW', 'FNGU', 'BULZ'];
    $doubleLeveraged = ['USD', 'SSO', 'SPUU', 'QLD', 'ROM', 'MSFU', 'MSFX'];
    if (in_array($symbol, $tripleLeveraged, true)) {
        return 0.75;
    }
    if (in_array($symbol, $doubleLeveraged, true)) {
        return 0.50;
    }

    return 0.30;
}

/**
 * @param array<string, string> $options
 * @return array{account:array<string, mixed>, clock:array<string, mixed>, positions:list<array<string, mixed>>, open_orders:list<array<string, mixed>>, db_states:array<string, array<string, mixed>>, sync_error:?string}
 */
function loadPaperContext(Config $config, SqliteRepository $repo, HttpClient $http, array $options, bool $submitRequested): array
{
    $context = [
        'account' => [],
        'clock' => [],
        'positions' => [],
        'open_orders' => [],
        'db_states' => $repo->loadPaperPositionStates(),
        'sync_error' => null,
    ];
    if (!boolOption((string) ($options['paper-open-counts'] ?? 'false'))) {
        return $context;
    }

    try {
        $client = new AlpacaPaperClient(
            $http,
            getenv('APCA_PAPER_BASE_URL') ?: (string) $config->get('trading.alpaca.paper_base_url', 'https://paper-api.alpaca.markets/v2'),
        );
        $context['account'] = $client->account();
        $context['clock'] = $client->clock();
        $context['positions'] = $client->positions();
        $context['open_orders'] = $client->openOrders();
    } catch (Throwable $e) {
        $context['sync_error'] = $e->getMessage();
        if ($submitRequested || boolOption((string) ($options['paper-sync-required'] ?? 'false'))) {
            throw new RuntimeException('Unable to sync Alpaca paper positions/orders: ' . $e->getMessage(), 0, $e);
        }
    }

    return $context;
}

/** @param array<string, mixed> $paperContext @return array<string, array<string, mixed>> */
function paperOpenSymbols(array $paperContext): array
{
    $symbols = [];
    $states = is_array($paperContext['db_states'] ?? null) ? $paperContext['db_states'] : [];
    foreach (($paperContext['positions'] ?? []) as $position) {
        if (!is_array($position)) {
            continue;
        }
        $symbol = strtoupper((string) ($position['symbol'] ?? ''));
        if ($symbol === '') {
            continue;
        }
        $state = is_array($states[$symbol] ?? null) ? $states[$symbol] : [];
        $symbols[$symbol] = [
            'break_even_armed' => (bool) ($state['break_even_armed'] ?? false),
            'source' => 'alpaca_position',
        ];
    }
    foreach (($paperContext['open_orders'] ?? []) as $order) {
        if (!is_array($order)) {
            continue;
        }
        $symbol = strtoupper((string) ($order['symbol'] ?? ''));
        $side = strtolower((string) ($order['side'] ?? ''));
        $status = strtolower((string) ($order['status'] ?? ''));
        if ($symbol === '' || $side !== 'buy' || in_array($status, ['filled', 'canceled', 'cancelled', 'expired', 'rejected'], true)) {
            continue;
        }
        $symbols[$symbol] = [
            'break_even_armed' => false,
            'source' => 'alpaca_open_buy_order',
        ];
    }

    return $symbols;
}

/**
 * @param array<string, mixed> $plan
 * @param array<string, string> $options
 * @return array<string, mixed>
 */
function persistOrderPlan(SqliteRepository $repo, array $plan, array $options, DateTimeImmutable $now, bool $dryRun): array
{
    $existingOrders = $repo->loadPaperOrderStates();
    $dedupe = boolOption((string) ($options['dedupe'] ?? 'true'));
    $force = boolOption((string) ($options['force'] ?? 'false'));
    $orders = [];
    $skipped = is_array($plan['skipped'] ?? null) ? $plan['skipped'] : [];
    $deduped = 0;
    $logged = 0;

    foreach (($plan['orders'] ?? []) as $order) {
        if (!is_array($order)) {
            continue;
        }
        $clientOrderId = (string) ($order['client_order_id'] ?? '');
        $existing = $clientOrderId !== '' ? ($existingOrders[$clientOrderId] ?? null) : null;
        if ($dedupe && !$force && is_array($existing) && paperOrderBlocksReplay($existing)) {
            $deduped++;
            $skipped[] = [
                'symbol' => strtoupper((string) ($order['symbol'] ?? '')),
                'entry' => (float) ($order['limit_price'] ?? 0.0),
                'score' => (float) ($order['metadata']['score'] ?? 0.0),
                'reason' => 'duplicate_client_order_id',
                'client_order_id' => $clientOrderId,
                'existing_status' => (string) ($existing['status'] ?? ''),
                'existing_updated_at' => (string) ($existing['updated_at'] ?? ''),
            ];
            $repo->logPaperAction([
                'created_at' => $now->format(DateTimeInterface::ATOM),
                'symbol' => (string) ($order['symbol'] ?? ''),
                'action' => 'entry_order_duplicate_skipped',
                'severity' => 'warning',
                'dry_run' => $dryRun,
                'submitted' => false,
                'client_order_id' => $clientOrderId,
                'reason' => 'Order with the same client_order_id already exists in DB.',
                'payload' => ['order' => $order, 'existing' => $existing],
            ]);
            continue;
        }

        $status = $dryRun ? 'dry_run_planned' : 'planned';
        $repo->savePaperOrderState(orderStateFromPlan(
            $order,
            $status,
            $dryRun,
            (string) $options['report'],
            $now,
        ));
        $repo->logPaperAction([
            'created_at' => $now->format(DateTimeInterface::ATOM),
            'symbol' => (string) ($order['symbol'] ?? ''),
            'action' => $dryRun ? 'entry_order_dry_run_planned' : 'entry_order_planned',
            'severity' => 'info',
            'dry_run' => $dryRun,
            'submitted' => false,
            'client_order_id' => $clientOrderId,
            'reason' => $dryRun ? 'Entry limit order planned in dry-run mode.' : 'Entry limit order prepared for Alpaca paper submit.',
            'payload' => ['order' => $order],
        ]);
        $logged++;
        $orders[] = $order;
    }

    $plan['orders'] = $orders;
    $plan['skipped'] = $skipped;
    $plan['deduped_count'] = $deduped;
    $plan['logged_count'] = $logged;
    $plan['dedupe_enabled'] = $dedupe;
    $plan['force'] = $force;

    return $plan;
}

/** @param array<string, mixed> $state */
function paperOrderBlocksReplay(array $state): bool
{
    $status = strtolower((string) ($state['status'] ?? ''));
    if (in_array($status, ['dry_run_planned', 'skipped', 'submit_failed', 'canceled', 'cancelled', 'expired', 'rejected'], true)) {
        return false;
    }

    return true;
}

/**
 * @param array<string, mixed> $order
 * @param array<string, mixed> $extraPayload
 * @return array<string, mixed>
 */
function orderStateFromPlan(
    array $order,
    string $status,
    bool $dryRun,
    string $sourceReport,
    DateTimeImmutable $now,
    bool $submitted = false,
    ?string $orderId = null,
    ?string $submittedAt = null,
    array $extraPayload = [],
): array {
    $metadata = is_array($order['metadata'] ?? null) ? $order['metadata'] : [];

    return [
        'client_order_id' => (string) ($order['client_order_id'] ?? ''),
        'symbol' => (string) ($order['symbol'] ?? ''),
        'side' => (string) ($order['side'] ?? 'buy'),
        'type' => (string) ($order['type'] ?? 'limit'),
        'qty' => (float) ($order['qty'] ?? 0.0),
        'limit_price' => isset($order['limit_price']) ? (float) $order['limit_price'] : null,
        'stop_price' => isset($metadata['stop']) ? (float) $metadata['stop'] : null,
        'time_in_force' => (string) ($order['time_in_force'] ?? 'day'),
        'status' => $status,
        'submitted' => $submitted,
        'order_id' => $orderId,
        'source_report' => $sourceReport,
        'planned_at' => $now->format(DateTimeInterface::ATOM),
        'submitted_at' => $submittedAt,
        'updated_at' => $now->format(DateTimeInterface::ATOM),
        'payload' => array_merge([
            'dry_run' => $dryRun,
            'order' => $order,
        ], $extraPayload),
    ];
}

/** @param array<string, mixed> $payload */
function orderPlanText(array $payload): string
{
    $plan = is_array($payload['plan'] ?? null) ? $payload['plan'] : [];
    $orders = is_array($plan['orders'] ?? null) ? $plan['orders'] : [];
    $lines = [
        'FTT paper order plan',
        'Mode: ' . (!empty($payload['submit_requested']) ? 'submit' : 'dry-run'),
        'Orders: ' . count($orders) . ', skipped: ' . count($plan['skipped'] ?? []) . ', deduped: ' . (int) ($plan['deduped_count'] ?? 0) . ', submitted: ' . (int) ($payload['submitted_count'] ?? 0) . ', errors: ' . (int) ($payload['submit_error_count'] ?? 0),
    ];
    foreach (array_slice($orders, 0, 8) as $order) {
        if (!is_array($order)) {
            continue;
        }
        $lines[] = sprintf(
            '%s %s qty %s limit %s score %.2f',
            strtoupper((string) ($order['side'] ?? '')),
            (string) ($order['symbol'] ?? ''),
            (string) ($order['qty'] ?? ''),
            (string) ($order['limit_price'] ?? ''),
            (float) ($order['metadata']['score'] ?? 0.0),
        );
    }

    return implode("\n", $lines);
}

function alpacaPrice(float $price): string
{
    $decimals = $price >= 1.0 ? 2 : 4;

    return number_format(round($price, $decimals), $decimals, '.', '');
}

/** @param list<array<string, mixed>> $positions @return array<string, array<string, mixed>> */
function openSymbols(array $positions): array
{
    $symbols = [];
    foreach ($positions as $position) {
        $symbol = strtoupper((string) ($position['symbol'] ?? ''));
        if ($symbol === '') {
            continue;
        }
        $symbols[$symbol] = [
            'break_even_armed' => (bool) ($position['break_even_armed'] ?? false),
        ];
    }

    return $symbols;
}

/** @param array<string, mixed> $signal @return array<string, mixed> */
function skipRow(array $signal, string $reason): array
{
    return [
        'symbol' => strtoupper((string) ($signal['symbol'] ?? '')),
        'entry' => (float) ($signal['entry'] ?? 0.0),
        'score' => (float) ($signal['score'] ?? 0.0),
        'reason' => $reason,
    ];
}

/** @param array<string, mixed> $signal */
function clientOrderId(array $signal): string
{
    $parts = [
        'ftt',
        preg_replace('/[^A-Z0-9]+/', '', strtoupper((string) ($signal['symbol'] ?? ''))),
        preg_replace('/[^0-9]+/', '', (string) ($signal['date'] ?? date('Ymd'))),
        strtolower((string) ($signal['ma_type'] ?? 'ma')) . (string) ($signal['ma_period'] ?? ''),
    ];

    return substr(implode('_', array_filter($parts)), 0, 48);
}

function boolOption(string $value): bool
{
    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'y', 'on'], true);
}
