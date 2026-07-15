#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Data\HttpClient;
use FulltimeTrading\Notifications\TelegramNotifier;
use FulltimeTrading\Storage\SqliteRepository;
use FulltimeTrading\Support\Config;
use FulltimeTrading\Trading\AlpacaPaperAccountGuard;
use FulltimeTrading\Trading\AlpacaPaperClient;
use FulltimeTrading\Trading\PaperDailyReportFreshnessGuard;
use FulltimeTrading\Trading\PaperFamilyExposureGuard;

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
    'verified-report-cycle-started-at' => '',
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
$reportRisk = is_array($report['risk'] ?? null) ? $report['risk'] : [];
$configEntryEnabled = (bool) $config->get('strategy.entry_submission_enabled', false);
$reportEntryEnabled = ($reportRisk['entry_submission_enabled'] ?? false) === true;
$reportRisk['entry_submission_enabled'] = $configEntryEnabled && $reportEntryEnabled;
if (!$reportRisk['entry_submission_enabled']) {
    $reportRisk['entry_submission_block_reason'] = !$configEntryEnabled
        ? (string) $config->get('strategy.entry_submission_block_reason', 'production_validation_unavailable')
        : (string) ($reportRisk['entry_submission_block_reason'] ?? 'report_did_not_enable_production_entries');
}
$report['risk'] = $reportRisk;

if (!$dryRun) {
    if (!(bool) $config->get('trading.alpaca.paper_only', true)) {
        throw new RuntimeException('Refusing to submit orders while trading.alpaca.paper_only is false.');
    }
    if (!(bool) $config->get('trading.alpaca.orders_enabled', false)) {
        throw new RuntimeException('Refusing to submit orders while trading.alpaca.orders_enabled is false.');
    }
    $cycleStartedAtRaw = trim((string) $options['verified-report-cycle-started-at']);
    if ($cycleStartedAtRaw === '') {
        throw new RuntimeException('Refusing direct submit without current-cycle daily report provenance. Use paper-cycle.');
    }
    try {
        $cycleStartedAt = new DateTimeImmutable($cycleStartedAtRaw);
    } catch (Throwable $e) {
        throw new RuntimeException('Invalid verified-report-cycle-started-at.', 0, $e);
    }
    $freshness = PaperDailyReportFreshnessGuard::evaluate(
        (string) $options['report'],
        true,
        ['ok' => true, 'exit_code' => 0],
        $cycleStartedAt,
        '',
        true,
        $now,
    );
    if (!PaperDailyReportFreshnessGuard::allowsDownstream($freshness)) {
        throw new RuntimeException('Refusing submit: daily report freshness/provenance failed (' . (string) ($freshness['reason'] ?? 'unknown') . ').');
    }
}

$paperContext = loadPaperContext($config, $repo, $http, $options, !$dryRun);
$plan = buildOrderPlan($report, $options, $paperContext);
if (
    !$dryRun
    && ($plan['orders'] ?? []) !== []
    && boolOption((string) ($options['require-market-open'] ?? 'true'))
    && !($paperContext['clock']['is_open'] ?? false)
) {
    throw new RuntimeException('Refusing to submit entry orders while Alpaca market clock is closed.');
}
$plan = persistOrderPlan($repo, $plan, $options, $now, $dryRun);
$submitted = [];
$submitErrors = [];
if (!$dryRun) {

    $client = new AlpacaPaperClient(
        $http,
        getenv('APCA_PAPER_BASE_URL') ?: (string) $config->get('trading.alpaca.paper_base_url', 'https://paper-api.alpaca.markets/v2'),
    );
    foreach ($plan['orders'] as $order) {
        $clientOrderId = (string) ($order['client_order_id'] ?? '');
        try {
            $existingOrder = $clientOrderId !== '' ? $client->orderByClientOrderId($clientOrderId) : null;
            if ($existingOrder !== null) {
                $submitted[] = $existingOrder;
                recordAcceptedEntry(
                    $repo,
                    $order,
                    $existingOrder,
                    (string) $options['report'],
                    $now,
                    true,
                    'Existing Alpaca order reconciled before retrying submission.',
                );
                continue;
            }
            $submittedOrder = $client->submitOrder($order);
            $submitted[] = $submittedOrder;
            recordAcceptedEntry(
                $repo,
                $order,
                $submittedOrder,
                (string) $options['report'],
                $now,
                false,
                'Entry limit order sent to Alpaca paper.',
            );
        } catch (Throwable $e) {
            $reconciledOrder = null;
            $lookupError = null;
            if ($clientOrderId !== '') {
                try {
                    $reconciledOrder = $client->orderByClientOrderId($clientOrderId);
                } catch (Throwable $lookupException) {
                    $lookupError = $lookupException->getMessage();
                }
            }
            if ($reconciledOrder !== null) {
                $submitted[] = $reconciledOrder;
                recordAcceptedEntry(
                    $repo,
                    $order,
                    $reconciledOrder,
                    (string) $options['report'],
                    $now,
                    true,
                    'Submission response was ambiguous; Alpaca order reconciled by client_order_id.',
                );
                continue;
            }
            $errorMessage = $e->getMessage();
            if ($lookupError !== null) {
                $errorMessage .= ' Reconciliation lookup also failed: ' . $lookupError;
            }
            $submitErrors[] = [
                'client_order_id' => $clientOrderId,
                'symbol' => (string) ($order['symbol'] ?? ''),
                'error' => $errorMessage,
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
                ['error' => $errorMessage],
            ));
            $repo->logPaperAction([
                'created_at' => $now->format(DateTimeInterface::ATOM),
                'symbol' => (string) $order['symbol'],
                'action' => 'entry_order_submit_failed',
                'severity' => 'error',
                'dry_run' => false,
                'submitted' => false,
                'client_order_id' => $clientOrderId,
                'reason' => $errorMessage,
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

if ($submitErrors !== []) {
    fwrite(STDERR, 'One or more Alpaca entry orders could not be submitted or reconciled.' . "\n");
    exit(1);
}

/**
 * @param array<string, mixed> $order
 * @param array<string, mixed> $alpacaOrder
 */
function recordAcceptedEntry(
    SqliteRepository $repo,
    array $order,
    array $alpacaOrder,
    string $sourceReport,
    DateTimeImmutable $now,
    bool $reconciled,
    string $reason,
): void {
    $repo->savePaperOrderState(orderStateFromPlan(
        $order,
        (string) ($alpacaOrder['status'] ?? 'submitted'),
        false,
        $sourceReport,
        $now,
        true,
        (string) ($alpacaOrder['id'] ?? ''),
        $now->format(DateTimeInterface::ATOM),
        [
            'alpaca_order' => $alpacaOrder,
            'reconciled' => $reconciled,
        ],
    ));
    $repo->logPaperAction([
        'created_at' => $now->format(DateTimeInterface::ATOM),
        'symbol' => (string) ($order['symbol'] ?? ''),
        'action' => $reconciled ? 'entry_order_reconciled' : 'entry_order_submitted',
        'severity' => 'info',
        'dry_run' => false,
        'submitted' => true,
        'order_id' => (string) ($alpacaOrder['id'] ?? ''),
        'client_order_id' => (string) ($order['client_order_id'] ?? ''),
        'reason' => $reason,
        'payload' => [
            'order' => $order,
            'alpaca_order' => $alpacaOrder,
            'reconciled' => $reconciled,
        ],
    ]);
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
    $entrySubmissionEnabled = ($risk['entry_submission_enabled'] ?? false) === true;
    $entrySubmissionBlockReason = trim((string) ($risk['entry_submission_block_reason'] ?? 'production_validation_unavailable'));
    $model = is_array($report['model'] ?? null) ? $report['model'] : [];
    $market = is_array($report['market'] ?? null) ? $report['market'] : [];
    $marketAllowsLongRisk = array_key_exists('allows_long_risk', $market)
        ? (bool) $market['allows_long_risk']
        : null;
    $openPositions = is_array($model['open_positions'] ?? null) ? $model['open_positions'] : [];

    $maxOpen = max(1, (int) ($risk['max_open_positions'] ?? 1));
    $reportInitialCash = max(0.0, (float) ($risk['initial_cash'] ?? 0.0));
    $paperEquity = isset($paperContext['account']['equity']) ? (float) $paperContext['account']['equity'] : 0.0;
    $initialCash = boolOption((string) ($options['paper-sizing-cash'] ?? 'true')) && $paperEquity > 0.0
        ? $paperEquity
        : $reportInitialCash;
    $maxGross = max(0.0, (float) ($risk['max_gross_exposure_pct'] ?? 1.0));
    $familyCap = isset($risk['family_exposure_cap_pct'])
        ? max(0.0, (float) $risk['family_exposure_cap_pct'])
        : null;
    $familyGuard = new PaperFamilyExposureGuard();
    $familyExposure = $familyGuard->exposureByFamily(
        is_array($paperContext['positions'] ?? null) ? $paperContext['positions'] : [],
        is_array($paperContext['open_orders'] ?? null) ? $paperContext['open_orders'] : [],
    );
    $grossExposure = $familyGuard->grossExposure(
        is_array($paperContext['positions'] ?? null) ? $paperContext['positions'] : [],
        is_array($paperContext['open_orders'] ?? null) ? $paperContext['open_orders'] : [],
    );
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
        if (!$entrySubmissionEnabled) {
            $skipped[] = skipRow($signal, 'production_validation_blocks_entries');
            continue;
        }
        if ($marketAllowsLongRisk !== true) {
            $skipped[] = skipRow(
                $signal,
                $marketAllowsLongRisk === false ? 'market_regime_blocks_long_risk' : 'market_regime_unavailable',
            );
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

        $recommendedPositionPct = isset($signal['recommended_position_pct'])
            ? max(0.0, (float) $signal['recommended_position_pct'])
            : null;
        $orderBudget = $recommendedPositionPct === null
            ? $slotBudget
            : $initialCash * $recommendedPositionPct;
        if ($orderBudget <= 0.0) {
            $skipped[] = skipRow($signal, 'zero_recommended_position_budget');
            continue;
        }
        $grossAvailable = max(0.0, ($initialCash * $maxGross) - $grossExposure);
        if ($grossAvailable <= 0.0) {
            $skipped[] = skipRow($signal, 'gross_exposure_cap_exhausted');
            continue;
        }
        $orderBudget = min($orderBudget, $grossAvailable);
        $family = $familyGuard->familyForSymbol($symbol);
        $familyAvailable = $familyCap === null
            ? INF
            : $familyGuard->availableNotional($symbol, $initialCash, $familyCap, $familyExposure);
        if ($familyAvailable <= 0.0) {
            $skipped[] = skipRow($signal, 'family_exposure_cap_exhausted');
            continue;
        }
        $orderBudget = min($orderBudget, $familyAvailable);
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
                'recommended_position_pct' => $recommendedPositionPct,
                'gross_available_before_order' => round($grossAvailable, 2),
                'family' => $family,
                'family_exposure_cap_pct' => $familyCap,
                'family_available_before_order' => is_finite($familyAvailable) ? round($familyAvailable, 2) : null,
                'estimated_maintenance_rate' => $maintenanceRate,
                'estimated_maintenance_requirement' => round($plannedMaintenance, 2),
            ],
        ];
        $familyGuard->reserve($symbol, $plannedNotional, $familyExposure);
        $grossExposure += $plannedNotional;
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
        'signal_sizing_mode' => 'recommended_position_pct_with_legacy_slot_fallback',
        'market_allows_long_risk' => $marketAllowsLongRisk,
        'entry_submission_enabled' => $entrySubmissionEnabled,
        'entry_submission_block_reason' => $entrySubmissionEnabled ? null : $entrySubmissionBlockReason,
        'estimated_gross_exposure' => round($grossExposure, 2),
        'estimated_gross_exposure_pct_of_equity' => $initialCash > 0.0
            ? round($grossExposure / $initialCash, 4)
            : null,
        'family_exposure_cap_pct' => $familyCap,
        'estimated_family_exposure' => array_map(static fn (float $value): float => round($value, 2), $familyExposure),
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
        $remainingNotional = remainingBuyOrderNotional($order);
        if ($remainingNotional <= 0.0) {
            continue;
        }
        $requirement += $remainingNotional * maintenanceRateForSymbol($symbol);
    }

    return $requirement;
}

/** @param array<string, mixed> $order */
function remainingBuyOrderNotional(array $order): float
{
    $remainingQty = max(0.0, (float) ($order['qty'] ?? 0.0) - (float) ($order['filled_qty'] ?? 0.0));
    $price = (float) ($order['limit_price'] ?? $order['stop_price'] ?? 0.0);
    $remaining = $remainingQty > 0.0 && $price > 0.0 ? $remainingQty * $price : 0.0;
    $notional = max(0.0, (float) ($order['notional'] ?? 0.0));
    if ($notional > 0.0) {
        $filled = max(0.0, (float) ($order['filled_qty'] ?? 0.0))
            * max(0.0, (float) ($order['filled_avg_price'] ?? 0.0));
        $remaining = max(0.0, $notional - $filled);
    }

    return $remaining;
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
    if (!$submitRequested && !boolOption((string) ($options['paper-open-counts'] ?? 'false'))) {
        return $context;
    }

    try {
        $client = new AlpacaPaperClient(
            $http,
            getenv('APCA_PAPER_BASE_URL') ?: (string) $config->get('trading.alpaca.paper_base_url', 'https://paper-api.alpaca.markets/v2'),
        );
        $context['account'] = $client->account();
        if ($submitRequested) {
            AlpacaPaperAccountGuard::validateConfigured($context['account']);
        }
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
    // `planned` may mean the process crashed after Alpaca accepted the request
    // but before local persistence. Let the stable client_order_id reconciliation
    // run before deciding whether another HTTP submit is necessary.
    if (in_array($status, ['planned', 'dry_run_planned', 'skipped', 'submit_failed', 'canceled', 'cancelled', 'expired', 'rejected'], true)) {
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
