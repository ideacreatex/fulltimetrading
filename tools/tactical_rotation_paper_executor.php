#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Data\HttpClient;
use FulltimeTrading\Notifications\TelegramNotifier;
use FulltimeTrading\Storage\SqliteRepository;
use FulltimeTrading\Storage\TacticalPaperRepository;
use FulltimeTrading\Support\Config;
use FulltimeTrading\Support\ProcessLock;
use FulltimeTrading\Trading\AlpacaPaperAccountGuard;
use FulltimeTrading\Trading\AlpacaPaperClient;
use FulltimeTrading\Trading\TacticalAmbiguousIntentReconciler;
use FulltimeTrading\Trading\TacticalExecutionStateGuard;
use FulltimeTrading\Trading\TacticalImplementationIdentity;
use FulltimeTrading\Trading\TacticalPortfolioNotificationSchedule;
use FulltimeTrading\Trading\TacticalPortfolioStatusMessage;
use FulltimeTrading\Trading\TacticalRotationExecutionWindow;
use FulltimeTrading\Trading\TacticalRotationPaperPlanner;
use FulltimeTrading\Trading\TacticalSignalArtifactGuard;
use FulltimeTrading\Trading\TacticalTransitionNotificationKey;

require __DIR__ . '/../bootstrap.php';

$options = [
    'submit' => 'false',
    'telegram' => 'true',
    'artifact' => __DIR__ . '/../var/reports/daily/tactical_rotation_shadow.json',
    'output' => __DIR__ . '/../var/reports/daily/tactical_paper_cycle.json',
    'db' => '',
    'lock' => __DIR__ . '/../var/run/tactical_paper_executor.lock',
    'mutation-lock' => __DIR__ . '/../var/run/alpaca_paper_account_mutation.lock',
];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--') && str_contains($arg, '=')) {
        [$key, $value] = explode('=', substr($arg, 2), 2);
        $options[$key] = $value;
    }
}

$root = dirname(__DIR__);
$config = Config::fromFile($root . '/config/config.php');
$profile = require $root . '/config/tactical_rotation.php';
$paper = require $root . '/config/tactical_paper.php';
$dryRun = !tacticalBool((string) $options['submit']);
$telegramEnabled = tacticalBool((string) $options['telegram']);

$lock = ProcessLock::tryAcquire((string) $options['lock']);
if ($lock === null) {
    fwrite(STDERR, "FTT tactical paper executor already running\n");
    exit(75);
}
$mutationLock = null;
if (!$dryRun) {
    $mutationLock = ProcessLock::tryAcquire((string) $options['mutation-lock']);
    if ($mutationLock === null) {
        fwrite(STDERR, "FTT Alpaca paper account mutation lease is busy\n");
        exit(75);
    }
}

if (($paper['enabled'] ?? false) !== true || ($paper['paper_only'] ?? false) !== true) {
    throw new RuntimeException('Tactical paper runtime is disabled or not paper-only.');
}
if ((string) ($paper['profile'] ?? '') !== (string) ($profile['profile'] ?? '')) {
    throw new RuntimeException('Tactical paper/profile identity mismatch.');
}
if (!$dryRun && (!(bool) $config->get('trading.alpaca.paper_only', true)
    || !(bool) $config->get('trading.alpaca.orders_enabled', false))) {
    throw new RuntimeException('Paper order submission gates are closed.');
}

$allocations = [];
foreach ((array) ($profile['sleeves'] ?? []) as $sleeveId => $definition) {
    if (is_string($sleeveId) && is_array($definition)) {
        $allocations[$sleeveId] = (float) ($definition['allocation'] ?? 0.0);
    }
}
$runtimeFiles = [
    $root . '/config/tactical_paper.php',
    $root . '/tools/tactical_rotation_paper_executor.php',
    $root . '/src/Storage/SqliteRepository.php',
    $root . '/src/Storage/TacticalPaperRepository.php',
    $root . '/src/Trading/TacticalRotationExecutionWindow.php',
    $root . '/src/Trading/TacticalRotationPaperPlanner.php',
    $root . '/src/Trading/TacticalAmbiguousIntentReconciler.php',
    $root . '/src/Trading/TacticalOrderGateway.php',
    $root . '/src/Trading/TacticalExecutionStateGuard.php',
    $root . '/src/Trading/TacticalImplementationIdentity.php',
    $root . '/src/Trading/TacticalSignalArtifactGuard.php',
    $root . '/src/Trading/TacticalNotificationHealthGuard.php',
    $root . '/src/Trading/TacticalPortfolioNotificationSchedule.php',
    $root . '/src/Trading/TacticalPortfolioStatusMessage.php',
    $root . '/src/Trading/PaperMonitorDecisionGuard.php',
    $root . '/src/Trading/TacticalTransitionNotificationKey.php',
    $root . '/src/Trading/TacticalLegacyOwnershipGuard.php',
    $root . '/src/Notifications/TelegramNotifier.php',
    $root . '/src/Trading/AlpacaPaperClient.php',
    $root . '/src/Data/HttpClient.php',
    $root . '/src/Data/FrozenSipIexDailyBarsProvider.php',
    $root . '/src/Data/VerifiedCacheSnapshotMarketDataProvider.php',
    $root . '/tools/run_tactical_rotation_backtest.php',
    $root . '/tools/paper_daemon.php',
    $root . '/tools/paper_position_monitor.php',
    $root . '/tools/tactical_rotation_paper_daemon.php',
];
$identity = [
    'run_id' => (string) $paper['run_id'],
    'profile' => (string) $paper['profile'],
    'strategy_hash' => hash_file('sha256', $root . '/config/tactical_rotation.php'),
    'runtime_hash' => tacticalFilesHash($runtimeFiles),
    'data_contract' => (array) $paper['data'],
    'live_review_not_before' => (string) $paper['live_review_not_before'],
];
$artifact = tacticalReadJson((string) $options['artifact']);
TacticalSignalArtifactGuard::validateArtifact(
    $artifact,
    $profile,
    $paper,
    TacticalImplementationIdentity::current($root, $profile),
);
$signalSummary = tacticalSignalSummary($artifact, array_keys($allocations));
if ($signalSummary === null) {
    throw new RuntimeException('Signal artifact summary is unavailable after validation.');
}

$databasePath = trim((string) $options['db']) !== ''
    ? (string) $options['db']
    : (string) $config->get('database_path');
$repo = new TacticalPaperRepository($databasePath);
$repo->migrate();
$legacyRepo = new SqliteRepository($databasePath);
$legacyRepo->migrate();
$legacyStates = $legacyRepo->loadPaperPositionStates();
$run = $repo->ensureRun($identity, $allocations);
$http = new HttpClient();
$client = new AlpacaPaperClient(
    $http,
    getenv('APCA_PAPER_BASE_URL') ?: (string) $config->get('trading.alpaca.paper_base_url'),
);
$notifier = $telegramEnabled ? TelegramNotifier::fromEnv($http) : null;
$now = new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
$events = [];
$errors = [];
$submitted = [];

try {
    $account = $client->account();
    $guard = AlpacaPaperAccountGuard::validateConfigured($account);
    $clock = $client->clock();
    $brokerPositions = $client->positions();
    $openOrders = $client->openOrders();
} catch (Throwable $e) {
    $repo->setRunError((string) $paper['run_id'], 'broker_snapshot_failed');
    throw $e;
}

// Every broker order created by this runtime is recoverable solely from the
// deterministic client ID. Reconciliation always precedes new risk.
$intents = $repo->activeIntents((string) $paper['run_id']);
$ambiguousRecoveryObserved = false;
$ambiguousReconciler = new TacticalAmbiguousIntentReconciler();
foreach ($intents as $intent) {
    $status = strtolower((string) ($intent['status'] ?? ''));
    if (in_array($status, ['planned', 'filled', 'canceled', 'cancelled', 'expired', 'rejected'], true)) {
        continue;
    }
    try {
        if (in_array($status, ['submitting', 'ambiguous', 'ambiguous_missed'], true)) {
            $ambiguousRecoveryObserved = true;
            $recovery = $ambiguousReconciler->reconcile(
                $repo,
                $client,
                $intent,
                $now,
                tacticalExecutionWindowForIntent($intent, $paper, $clock, $now),
                (int) ($paper['execution']['ambiguous_max_attempts'] ?? 3),
                (int) ($paper['execution']['ambiguous_retry_delay_seconds'] ?? 120),
                (int) ($paper['execution']['ambiguous_missed_window_confirmations'] ?? 2),
            );
            if ($recovery['error_code'] !== null) {
                $errors[] = $recovery['error_code'];
            }
            if (!in_array($recovery['outcome'], ['retry_wait', 'retry_exhausted'], true)) {
                tacticalNotifyIntentStatus($repo, $notifier, $recovery['intent'], $events);
            }
            continue;
        }
        $order = $client->orderByClientOrderId((string) $intent['client_order_id']);
        if ($order !== null) {
            $updated = $repo->applyBrokerOrder((string) $intent['decision_id'], $order);
            tacticalNotifyIntentStatus($repo, $notifier, $updated, $events);
        }
    } catch (Throwable $e) {
        $errors[] = 'intent_reconcile_failed:' . substr(hash('sha256', $e->getMessage()), 0, 12);
    }
}

$knownClientIds = [];
foreach ($repo->activeIntents((string) $paper['run_id']) as $intent) {
    $knownClientIds[(string) $intent['client_order_id']] = true;
}
$foreignOpenOrders = array_values(array_filter(
    $openOrders,
    static fn (array $order): bool => !isset($knownClientIds[(string) ($order['client_order_id'] ?? '')]),
));
$run = $repo->run((string) $paper['run_id']) ?? $run;
$reconciliationStatus = 'transition';
$terminalIncomplete = $repo->terminalIncompleteIntents((string) $paper['run_id']);
$executionState = null;
$executionWindow = null;
$executableLegs = [];

if ((string) $run['status'] === 'transition') {
    if ($brokerPositions === [] && $openOrders === []) {
        $flatFingerprint = hash('sha256', json_encode([
            'account_reference_hash' => hash('sha256', (string) ($account['id'] ?? $account['account_number'] ?? '')),
            'equity' => (string) ($account['equity'] ?? ''),
            'cash' => (string) ($account['cash'] ?? ''),
            'last_equity' => (string) ($account['last_equity'] ?? ''),
            'positions' => [],
            'open_orders' => [],
        ], JSON_THROW_ON_ERROR));
        $handoffStabilitySeconds = max(120, (int) ($paper['execution']['flat_handoff_stability_seconds'] ?? 120));
        if ($repo->observeFlatHandoff((string) $paper['run_id'], $flatFingerprint, $handoffStabilitySeconds)) {
            $repo->activate((string) $paper['run_id'], (float) ($account['equity'] ?? 0.0), [
                'positions' => [],
                'open_orders' => [],
                'verified_at' => $now->format(DateTimeInterface::ATOM),
                'adoption' => 'flat_account_only',
                'stable_for_seconds' => $handoffStabilitySeconds,
            ]);
            $run = $repo->run((string) $paper['run_id']) ?? $run;
            $reconciliationStatus = 'activated_flat_wait_next_signal';
            tacticalNotify(
                $repo,
                $notifier,
                'activated:' . (string) $run['activated_at'],
                sprintf(
                    "✅ Hybrid-v4 paper активирован\nСтартовый equity: $%.2f\nСтарые позиции и заявки отсутствуют. Историческую PANW не догоняем: ждём следующий плановый сигнал D→D+1 open.",
                    (float) $run['initial_equity'],
                ),
                $events,
            );
        } else {
            $reconciliationStatus = 'flat_handoff_stability_check';
        }
    } else {
        $repo->clearFlatHandoffCandidate((string) $paper['run_id']);
        $reconciliationStatus = 'legacy_positions_in_control';
        $manifest = tacticalBrokerManifest($brokerPositions, $openOrders);
        tacticalNotify(
            $repo,
            $notifier,
            TacticalTransitionNotificationKey::fromManifest($manifest),
            tacticalTransitionMessage($account, $manifest),
            $events,
        );
    }
} elseif (in_array((string) $run['status'], ['active', 'paused'], true)) {
    $expected = $repo->expectedBrokerPositions((string) $paper['run_id']);
    $actual = tacticalPositionQuantities($brokerPositions);
    $executionState = TacticalExecutionStateGuard::assess(
        $repo->intents((string) $paper['run_id'], 10000),
        $expected,
        $actual,
        $foreignOpenOrders,
        $now,
        (float) ($paper['execution']['position_tolerance_shares'] ?? 0.000001),
        (string) ($paper['execution']['preopen_cutoff'] ?? '09:27'),
        (string) ($paper['execution']['postopen_rotation_cutoff'] ?? '09:32'),
    );
    $ambiguousIntents = array_values(array_filter(
        $repo->activeIntents((string) $paper['run_id']),
        static fn (array $intent): bool => in_array(
            strtolower((string) ($intent['status'] ?? '')),
            ['submitting', 'ambiguous', 'ambiguous_missed'],
            true,
        ),
    ));
    if ($errors !== []) {
        $reconciliationStatus = 'blocked_intent_reconciliation';
    } elseif ($foreignOpenOrders !== []) {
        $errors[] = 'foreign_open_order';
        $reconciliationStatus = 'blocked_foreign_open_order';
    } elseif ($ambiguousIntents !== []) {
        $errors[] = 'ambiguous_order_intent';
        $reconciliationStatus = 'blocked_ambiguous_order_intent';
    } elseif ($ambiguousRecoveryObserved) {
        $reconciliationStatus = 'ambiguous_recovery_reconcile_only';
    } elseif ($openOrders !== []) {
        $reconciliationStatus = 'known_orders_inflight_reconcile_only';
    } else {
        $reconciliationStatus = 'reconciled';
        try {
            $planResult = tacticalPlanCurrentSignal(
                $artifact,
                $profile,
                $paper,
                $repo,
                $client,
                $account,
                $brokerPositions,
                $clock,
                $now,
            );
            $reconciliationStatus = (string) $planResult['status'];
            $executionWindow = (array) ($planResult['window'] ?? []);
            foreach ($planResult['checkpoints'] as $checkpoint) {
                $repo->recordSleeveCheckpoint(
                    (string) $paper['run_id'],
                    (string) $checkpoint['sleeve_id'],
                    (string) $checkpoint['signal_date'],
                    (string) $checkpoint['session_date'],
                    (array) $checkpoint['payload'],
                );
            }
            $executableLegs = tacticalPrepareExecutableLegs(
                $planResult['legs'],
                $repo,
                (string) $paper['run_id'],
                $planResult['window'],
                $actual,
                (float) ($paper['execution']['position_tolerance_shares'] ?? 0.000001),
            );
            $entriesAllowed = (string) $run['status'] === 'active'
                && ($executionState['entries_allowed'] ?? false) === true;
            $riskOnlyDenied = false;
            $sellSymbols = [];
            foreach ($executableLegs as $candidateLeg) {
                if (strtolower((string) ($candidateLeg['side'] ?? '')) === 'sell') {
                    $sellSymbols[strtoupper((string) ($candidateLeg['symbol'] ?? ''))] = true;
                }
            }
            foreach ($executableLegs as $leg) {
                if (strtolower((string) ($leg['side'] ?? '')) === 'buy'
                    && isset($sellSymbols[strtoupper((string) ($leg['symbol'] ?? ''))])) {
                    $riskOnlyDenied = true;
                    continue;
                }
                $riskReducingSell = TacticalExecutionStateGuard::riskReducingSellAllowed(
                    $leg,
                    $actual,
                    $repo->positions((string) $paper['run_id']),
                    $openOrders,
                    $executionState,
                    (float) ($paper['execution']['position_tolerance_shares'] ?? 0.000001),
                );
                if (!$entriesAllowed && !$riskReducingSell) {
                    $riskOnlyDenied = true;
                    continue;
                }
                if (!tacticalIntentAllowedInWindow($leg, $planResult['window'], $repo)) {
                    continue;
                }
                $asset = $client->asset((string) $leg['symbol']);
                if (($asset['status'] ?? '') !== 'active' || ($asset['tradable'] ?? false) !== true) {
                    throw new RuntimeException('Executable target asset is not active/tradable.');
                }
                // Never leave a pristine OPG intent stranded merely because a
                // dry-run or an unavailable window observed the plan.
                if ($dryRun) {
                    continue;
                }
                $intent = $repo->createIntent($leg);
                if (!tacticalIntentAllowedInWindow($intent, $planResult['window'], $repo)) {
                    continue;
                }
                $submittedIntent = tacticalSubmitIntent($repo, $client, $intent);
                $submitted[] = $submittedIntent;
                if (TacticalPaperRepository::isTerminalIncompleteIntent($submittedIntent)) {
                    $errors[] = 'terminal_incomplete_order:' . substr((string) $submittedIntent['decision_id'], 0, 12);
                    $reconciliationStatus = 'paused_terminal_incomplete_order';
                    break;
                }
                if (in_array(strtolower((string) ($submittedIntent['status'] ?? '')), ['submitting', 'ambiguous'], true)) {
                    $errors[] = 'ambiguous_order_intent';
                    $reconciliationStatus = 'blocked_ambiguous_order_intent';
                    break;
                }
            }
            if (!$entriesAllowed) {
                foreach ((array) ($executionState['reason_codes'] ?? []) as $reasonCode) {
                    $errors[] = (string) $reasonCode;
                }
                if ((string) $run['status'] === 'paused' && ($executionState['reason_codes'] ?? []) === []) {
                    $errors[] = (string) ($run['last_error_code'] ?: 'run_paused');
                }
                if ($reconciliationStatus !== 'paused_terminal_incomplete_order') {
                    $reconciliationStatus = $submitted !== []
                        ? 'risk_reduction_submitted_entries_blocked'
                        : ($riskOnlyDenied ? 'risk_reduction_only_entries_blocked' : 'entries_blocked_by_execution_state');
                }
            }
        } catch (Throwable $e) {
            $errors[] = 'signal_plan_blocked:' . substr(hash('sha256', $e->getMessage()), 0, 12);
            $reconciliationStatus = 'blocked_signal_or_plan';
        }
    }
}

foreach ($submitted as $intent) {
    tacticalNotifyIntentStatus($repo, $notifier, $intent, $events);
}
$reportSnapshotFresh = true;
if ($submitted !== [] || $ambiguousRecoveryObserved) {
    try {
        $account = $client->account();
        $guard = AlpacaPaperAccountGuard::validateConfigured($account);
        $clock = $client->clock();
        $brokerPositions = $client->positions();
        $openOrders = $client->openOrders();
        $events[] = ['type' => 'post_execution_broker_snapshot_refreshed'];
    } catch (Throwable $e) {
        $reportSnapshotFresh = false;
        $errors[] = 'post_execution_broker_snapshot_failed:' . substr(hash('sha256', $e->getMessage()), 0, 12);
    }
}
if ($errors !== []) {
    $repo->setRunError((string) $paper['run_id'], $errors[0]);
    tacticalNotify(
        $repo,
        $notifier,
        'runtime-error:' . $now->format('Y-m-d-H') . ':' . hash('sha256', implode('|', $errors)),
        "⚠️ Hybrid-v4 paper: входы заблокированы\n" . implode("\n", array_unique($errors)) . "\nСверка продолжится автоматически.",
        $events,
    );
} else {
    $repo->setRunError((string) $paper['run_id'], null);
}

$currentRun = $repo->run((string) $paper['run_id']) ?? $run;
$activeIntents = $repo->activeIntents((string) $paper['run_id']);
$sleeveSummary = tacticalSanitizeSleeves($repo, (string) $paper['run_id'], $brokerPositions);
$recentIntentSummary = array_map(
    'tacticalSanitizeIntent',
    array_slice($repo->intents((string) $paper['run_id']), 0, 20),
);
$entryEligibility = TacticalPortfolioStatusMessage::entryEligibility(
    $currentRun,
    $reconciliationStatus,
    $signalSummary,
    array_merge($activeIntents, $submitted),
    array_values(array_unique($errors)),
    $brokerPositions,
    $now,
    $executionWindow,
    $executableLegs,
    $sleeveSummary,
);
$stopPolicy = tacticalStopPolicy($config, $root);
$closeStatusSchedule = $reportSnapshotFresh
    ? TacticalPortfolioNotificationSchedule::closeStatus($clock, $account, $signalSummary, $now)
    : null;
if ($closeStatusSchedule !== null) {
    tacticalNotify(
        $repo,
        $notifier,
        $closeStatusSchedule['key'],
        TacticalPortfolioStatusMessage::build(
            'close',
            $now,
            $account,
            $brokerPositions,
            $openOrders,
            $legacyStates,
            $currentRun,
            $reconciliationStatus,
            $signalSummary,
            $sleeveSummary,
            $entryEligibility,
            array_values(array_unique($errors)),
            $clock,
            $stopPolicy,
            $closeStatusSchedule,
        ),
        $events,
    );
}
$openStatusSchedule = $reportSnapshotFresh
    ? TacticalPortfolioNotificationSchedule::openStatus(
        $clock,
        $account,
        $now,
        TacticalPortfolioNotificationSchedule::OPEN_REPORT_AFTER,
        $signalSummary,
    )
    : null;
$requiredOpenStatusKey = $reportSnapshotFresh
    ? TacticalPortfolioNotificationSchedule::requiredOpenKey($clock, $account, $signalSummary, $now)
    : null;
if ($openStatusSchedule !== null) {
    tacticalNotify(
        $repo,
        $notifier,
        $openStatusSchedule['key'],
        TacticalPortfolioStatusMessage::build(
            'open',
            $now,
            $account,
            $brokerPositions,
            $openOrders,
            $legacyStates,
            $currentRun,
            $reconciliationStatus,
            $signalSummary,
            $sleeveSummary,
            $entryEligibility,
            array_values(array_unique($errors)),
            $clock,
            $stopPolicy,
            $openStatusSchedule,
        ),
        $events,
    );
}
tacticalFlushNotifications($repo, $notifier, $events);

$repo->saveSnapshot((string) $paper['run_id'], [
    'captured_at' => $now->format(DateTimeInterface::ATOM),
    'equity' => (float) ($account['equity'] ?? 0.0),
    'cash' => (float) ($account['cash'] ?? 0.0),
    'buying_power' => (float) ($account['buying_power'] ?? 0.0),
    'positions' => tacticalBrokerManifest($brokerPositions, [])['positions'],
    'open_orders' => tacticalBrokerManifest([], $openOrders)['open_orders'],
    'reconciliation_status' => $reconciliationStatus,
    'payload' => [
        'dry_run' => $dryRun,
        'errors' => $errors,
        'report_snapshot_fresh' => $reportSnapshotFresh,
        'execution_state' => $executionState,
        'terminal_incomplete_count' => count($terminalIncomplete),
    ],
]);

$payload = [
    'schema' => 1,
    'generated_at' => $now->format(DateTimeInterface::ATOM),
    'run_id' => $paper['run_id'],
    'profile' => $paper['profile'],
    'dry_run' => $dryRun,
    'paper_only' => true,
    'account_guard' => $guard,
    'run_status' => $currentRun['status'] ?? null,
    'reconciliation_status' => $reconciliationStatus,
    'execution_state' => $executionState,
    'report_snapshot_fresh' => $reportSnapshotFresh,
    'signal' => $signalSummary,
    'broker' => [
        'equity' => (float) ($account['equity'] ?? 0.0),
        'last_equity' => (float) ($account['last_equity'] ?? 0.0),
        'cash' => (float) ($account['cash'] ?? 0.0),
        'buying_power' => (float) ($account['buying_power'] ?? 0.0),
        'long_market_value' => (float) ($account['long_market_value'] ?? 0.0),
        'short_market_value' => (float) ($account['short_market_value'] ?? 0.0),
        'positions' => tacticalBrokerManifest($brokerPositions, [])['positions'],
        'open_orders' => tacticalBrokerManifest([], $openOrders)['open_orders'],
        'clock' => [
            'timestamp' => $clock['timestamp'] ?? null,
            'is_open' => (bool) ($clock['is_open'] ?? false),
            'next_open' => $clock['next_open'] ?? null,
            'next_close' => $clock['next_close'] ?? null,
        ],
    ],
    'sleeves' => $sleeveSummary,
    'recent_intents' => $recentIntentSummary,
    'entry_eligibility' => $entryEligibility,
    'notification_schedule' => [
        'close_status_key' => $closeStatusSchedule['key'] ?? null,
        'close_status_session' => $closeStatusSchedule['session_date'] ?? null,
        'close_status_catch_up' => $closeStatusSchedule['catch_up'] ?? null,
        'open_status_key' => $openStatusSchedule['key'] ?? null,
        'open_status_required_key' => $requiredOpenStatusKey,
        'open_status_session' => $openStatusSchedule['session_date'] ?? null,
        'open_status_catch_up' => $openStatusSchedule['catch_up'] ?? null,
    ],
    'events' => $events,
    'errors' => array_values(array_unique($errors)),
    'live_review_not_before' => $paper['live_review_not_before'],
];
tacticalWriteJson((string) $options['output'], $payload);
echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
exit($errors === [] ? 0 : 2);

/** @return array<string,mixed> */
function tacticalPlanCurrentSignal(
    array $artifact,
    array $profile,
    array $paper,
    TacticalPaperRepository $repo,
    AlpacaPaperClient $client,
    array $account,
    array $brokerPositions,
    array $brokerClock,
    DateTimeImmutable $now,
): array {
    if (($artifact['validation_selected'] ?? false) !== true
        || (string) ($artifact['profile'] ?? '') !== (string) $paper['profile']) {
        throw new RuntimeException('Signal artifact did not pass the frozen validation identity.');
    }
    $targets = $artifact['targets'] ?? null;
    if (!is_array($targets) || array_keys($targets) !== array_keys((array) $profile['sleeves'])) {
        throw new RuntimeException('Signal artifact sleeve set mismatch.');
    }
    $dates = array_values(array_unique(array_map(
        static fn (array $target): string => (string) ($target['signal_date'] ?? ''),
        $targets,
    )));
    if (count($dates) !== 1 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dates[0])) {
        throw new RuntimeException('Signal artifact has inconsistent dates.');
    }
    $signalDate = $dates[0];
    TacticalSignalArtifactGuard::validateDataProvenance(
        (array) ($artifact['data_provenance'] ?? []),
        (array) $paper['data'],
        $signalDate,
        tacticalProfileSymbols($profile),
    );
    $calendar = $client->calendar(
        (new DateTimeImmutable($signalDate))->modify('+1 day')->format('Y-m-d'),
        (new DateTimeImmutable($signalDate))->modify('+14 days')->format('Y-m-d'),
    );
    $sessionDate = '';
    foreach ($calendar as $row) {
        if ((string) ($row['date'] ?? '') > $signalDate) {
            $sessionDate = (string) $row['date'];
            break;
        }
    }
    if ($sessionDate === '') {
        throw new RuntimeException('Next Alpaca trading session is unavailable.');
    }
    if (isset($artifact['intended_session']) && $artifact['intended_session'] !== null
        && !hash_equals($sessionDate, (string) $artifact['intended_session'])) {
        throw new RuntimeException('Artifact/broker intended session mismatch.');
    }
    $execution = (array) $paper['execution'];
    $window = (new TacticalRotationExecutionWindow(
        (string) $execution['preopen_start'],
        (string) $execution['preopen_cutoff'],
        (string) $execution['evening_queue_start'],
        (string) $execution['postopen_rotation_cutoff'],
        (string) ($execution['risk_exit_day_cutoff'] ?? '15:45'),
    ))->resolve(
        $sessionDate,
        $now,
        $signalDate,
        tacticalBrokerClockConfirmsOpen($brokerClock, $now),
    );
    $sleeves = $repo->sleeves((string) $paper['run_id']);
    $owned = $repo->positions((string) $paper['run_id']);
    $marketPrices = [];
    foreach ($brokerPositions as $position) {
        $marketPrices[strtoupper((string) ($position['symbol'] ?? ''))] = (float) ($position['current_price'] ?? 0.0);
    }
    $planner = new TacticalRotationPaperPlanner();
    $legs = [];
    $checkpoints = [];
    foreach ($targets as $sleeveId => $target) {
        if (!is_array($target) || ($target['shadow_only'] ?? false) !== true) {
            throw new RuntimeException('Invalid tactical target row.');
        }
        $virtualEquity = (float) ($sleeves[$sleeveId]['cash'] ?? 0.0);
        foreach (($owned[$sleeveId] ?? []) as $symbol => $position) {
            $price = $marketPrices[$symbol] ?? 0.0;
            if ($price <= 0.0) {
                throw new RuntimeException('Missing current price for sleeve-owned position.');
            }
            $virtualEquity += (float) $position['qty'] * $price;
        }
        $referencePrice = (float) ($target['sizing_reference_close'] ?? 0.0);
        if (($target['rebalance_due_next_session'] ?? false) !== true) {
            $heldModelSymbol = strtoupper(trim((string) ($target['current_symbol'] ?? '')));
            if ($heldModelSymbol !== '' && isset($marketPrices[$heldModelSymbol])) {
                $referencePrice = (float) $marketPrices[$heldModelSymbol];
            }
        }
        if (($target['rebalance_due_next_session'] ?? false) === true
            && (string) ($target['symbol'] ?? '') !== ''
            && $referencePrice <= 0.0) {
            throw new RuntimeException('Executable target is missing its completed-bar sizing close.');
        }
        $sleeveLegs = $planner->plan(
            (string) $paper['run_id'],
            (string) $sleeveId,
            $sessionDate,
            $target,
            $virtualEquity,
            $referencePrice,
            $owned[$sleeveId] ?? [],
            (float) $execution['maximum_target_gross'],
        );
        $legs = array_merge($legs, $sleeveLegs);
        $checkpoints[] = [
            'sleeve_id' => $sleeveId,
            'signal_date' => $signalDate,
            'session_date' => $sessionDate,
            'payload' => [
                'target' => $target,
                'virtual_equity' => $virtualEquity,
                'planned_legs' => count($sleeveLegs),
                'window' => $window,
            ],
        ];
    }
    return [
        'status' => $legs === [] ? 'reconciled_hold' : (string) $window['status'],
        'window' => $window,
        'legs' => $legs,
        'checkpoints' => $checkpoints,
    ];
}

/**
 * Rotation exits go to the opening auction first. Their replacement buy is
 * not even persisted until the exit fill is reconciled; then a bounded DAY
 * re-entry is allowed only through 09:32. Cash entries remain true OPG.
 *
 * @param list<array<string,mixed>> $legs
 * @return list<array<string,mixed>>
 */
function tacticalPrepareExecutableLegs(
    array $legs,
    TacticalPaperRepository $repo,
    string $runId,
    array $window,
    array $actualBrokerPositions,
    float $positionTolerance,
): array {
    $planner = new TacticalRotationPaperPlanner();
    $sequential = $planner->prepareSequentialExecution(
        $legs,
        $repo->intents($runId, 10000),
        $window,
    );

    return $planner->capAggregateSellReservations(
        $sequential,
        $actualBrokerPositions,
        $repo->positions($runId),
        $positionTolerance,
    );
}

function tacticalIntentAllowedInWindow(
    array $intent,
    array $window,
    TacticalPaperRepository $repo,
): bool {
    $dependencies = [];
    foreach ((array) ($intent['payload']['required_exit_decision_ids'] ?? []) as $decisionId) {
        $dependency = $repo->intent((string) $decisionId);
        if ($dependency === null) {
            return false;
        }
        $dependencies[] = $dependency;
    }

    return (new TacticalRotationPaperPlanner())->isAllowedInWindow($intent, $window, $dependencies);
}

function tacticalBrokerClockConfirmsOpen(array $clock, DateTimeImmutable $now): bool
{
    if (($clock['is_open'] ?? false) !== true || trim((string) ($clock['timestamp'] ?? '')) === '') {
        return false;
    }
    try {
        $timezone = new DateTimeZone('America/New_York');
        $now = $now->setTimezone($timezone);
        $brokerTimestamp = (new DateTimeImmutable((string) $clock['timestamp']))->setTimezone($timezone);

        return $brokerTimestamp->format('Y-m-d') === $now->format('Y-m-d')
            && abs($brokerTimestamp->getTimestamp() - $now->getTimestamp()) <= 300;
    } catch (Throwable) {
        return false;
    }
}

/** @param array<string,mixed> $intent @param array<string,mixed> $paper */
function tacticalExecutionWindowForIntent(
    array $intent,
    array $paper,
    array $brokerClock,
    DateTimeImmutable $now,
): array {
    $execution = (array) ($paper['execution'] ?? []);

    return (new TacticalRotationExecutionWindow(
        (string) ($execution['preopen_start'] ?? '09:15'),
        (string) ($execution['preopen_cutoff'] ?? '09:27'),
        (string) ($execution['evening_queue_start'] ?? '19:05'),
        (string) ($execution['postopen_rotation_cutoff'] ?? '09:32'),
        (string) ($execution['risk_exit_day_cutoff'] ?? '15:45'),
    ))->resolve(
        (string) ($intent['scheduled_session'] ?? ''),
        $now,
        (string) ($intent['signal_date'] ?? ''),
        tacticalBrokerClockConfirmsOpen($brokerClock, $now),
    );
}

/** @return list<string> */
function tacticalProfileSymbols(array $profile): array
{
    $symbols = [];
    foreach ((array) ($profile['sleeves'] ?? []) as $definition) {
        $config = array_replace($profile, (array) ($definition['config'] ?? []));
        $symbols = array_merge(
            $symbols,
            [(string) ($config['benchmark'] ?? '')],
            [(string) ($config['market_context']['symbol'] ?? '')],
            [(string) ($config['signal_market_filter']['symbol'] ?? '')],
            (array) ($config['universe'] ?? []),
        );
    }
    $symbols = array_values(array_unique(array_filter(array_map(
        static fn (mixed $symbol): string => strtoupper(trim((string) $symbol)),
        $symbols,
    ))));
    sort($symbols, SORT_STRING);

    return $symbols;
}

/** @return array<string,mixed> */
function tacticalSubmitIntent(
    TacticalPaperRepository $repo,
    AlpacaPaperClient $client,
    array $intent,
): array {
    TacticalRotationPaperPlanner::assertExecutionIdentity($intent);
    $status = strtolower((string) ($intent['status'] ?? ''));
    if ($status !== 'planned') {
        return $intent;
    }
    if (!$repo->markSubmitting((string) $intent['decision_id'])) {
        return $repo->intent((string) $intent['decision_id']) ?? $intent;
    }
    $timeInForce = strtolower((string) ($intent['payload']['time_in_force'] ?? 'opg'));
    $body = [
        'symbol' => (string) $intent['symbol'],
        'qty' => (string) (int) $intent['requested_qty'],
        'side' => (string) $intent['side'],
        'type' => 'market',
        'time_in_force' => $timeInForce,
        'client_order_id' => (string) $intent['client_order_id'],
    ];
    try {
        return $repo->applyBrokerOrder((string) $intent['decision_id'], $client->submitOrder($body));
    } catch (Throwable $e) {
        try {
            $existing = $client->orderByClientOrderId((string) $intent['client_order_id']);
            if ($existing !== null) {
                return $repo->applyBrokerOrder((string) $intent['decision_id'], $existing);
            }
        } catch (Throwable) {
            // Preserve the ambiguous state; the next cycle reconciles before risk.
        }
        $repo->markAmbiguous(
            (string) $intent['decision_id'],
            'submit_unknown_' . substr(hash('sha256', $e->getMessage()), 0, 12),
        );

        return $repo->intent((string) $intent['decision_id']) ?? $intent;
    }
}

/** @return array<string,mixed>|null */
function tacticalSignalSummary(array $artifact, array $sleeveIds): ?array
{
    $targets = $artifact['targets'] ?? null;
    if (!is_array($targets)) {
        return null;
    }
    $rows = [];
    $contexts = is_array($artifact['execution_contexts'] ?? null)
        ? $artifact['execution_contexts']
        : [];
    foreach ($sleeveIds as $id) {
        $target = $targets[$id] ?? null;
        if (!is_array($target)) {
            return null;
        }
        $context = is_array($contexts[$id] ?? null) ? $contexts[$id] : [];
        $rows[$id] = [
            'action' => $target['action'] ?? null,
            'symbol' => $target['symbol'] ?? null,
            'gross' => isset($target['gross']) ? (float) $target['gross'] : null,
            'current_symbol' => $target['current_symbol'] ?? null,
            'current_gross' => isset($target['current_gross']) ? (float) $target['current_gross'] : null,
            'ranked_symbol' => $target['ranked_symbol'] ?? null,
            'ranked_gross' => isset($target['ranked_gross']) ? (float) $target['ranked_gross'] : null,
            'cooldown_left' => (int) ($target['circuit_cooldown_left'] ?? 0),
            'cooldown_after_next_open_tick' => (int) ($target['cooldown_after_next_open_tick'] ?? 0),
            'drawdown_rearm_pending' => (bool) ($target['drawdown_rearm_pending'] ?? false),
            'risk_exit_pending' => (bool) ($target['risk_exit_pending'] ?? false),
            'allocation' => isset($target['allocation']) ? (float) $target['allocation'] : null,
            'due' => (bool) ($target['rebalance_due_next_session'] ?? false),
            'execution_status' => $context['status'] ?? null,
            'no_chase' => (bool) ($context['no_chase'] ?? true),
            'shadow_order_eligible' => (bool) ($context['order_eligible'] ?? false),
        ];
    }

    return [
        'as_of' => (string) ($artifact['as_of'] ?? reset($targets)['signal_date'] ?? ''),
        'generated_at' => $artifact['generated_at'] ?? null,
        'intended_session' => $artifact['intended_session'] ?? null,
        'validation_selected' => (bool) ($artifact['validation_selected'] ?? false),
        'decision_sha256' => $artifact['decision_sha256'] ?? null,
        'targets' => $rows,
        'data_provenance' => $artifact['data_provenance'] ?? null,
    ];
}

function tacticalTransitionMessage(array $account, array $manifest): string
{
    $lines = [
        '🛡 Hybrid-v4 готов, входы пока заблокированы',
        sprintf('Paper equity: $%.2f, cash: $%.2f', (float) ($account['equity'] ?? 0.0), (float) ($account['cash'] ?? 0.0)),
        'Текущий legacy-портфель остаётся под прежними стопами:',
    ];
    foreach ($manifest['positions'] as $position) {
        $lines[] = sprintf('%s — %.4g акций', $position['symbol'], $position['qty']);
    }
    $lines[] = 'После полной сверки и закрытия legacy-позиций стартовый капитал будет зафиксирован автоматически. Старую PANW не догоняем.';

    return implode("\n", $lines);
}

function tacticalNotify(
    TacticalPaperRepository $repo,
    ?TelegramNotifier $notifier,
    string $key,
    string $message,
    array &$events,
): void {
    // A diagnostic `--telegram=false` run must not freeze a snapshot in the
    // operational outbox for a later production daemon to send.
    if ($notifier === null) {
        return;
    }
    if ($repo->notificationDelivered($key)) {
        return;
    }
    $repo->queueNotification($key, $message, ['message_sha256' => hash('sha256', $message)]);
    $events[] = ['type' => 'telegram_queued', 'key' => $key];
}

function tacticalFlushNotifications(
    TacticalPaperRepository $repo,
    ?TelegramNotifier $notifier,
    array &$events,
): void {
    if ($notifier === null) {
        return;
    }
    foreach ($repo->pendingNotifications(50) as $notification) {
        $key = (string) $notification['notification_key'];
        $repo->markNotificationAttempted($key, 300);
        try {
            $response = $notifier->sendMessage((string) $notification['message']);
            $messageId = (int) ($response['result']['message_id'] ?? 0);
            $repo->markNotificationDelivered($key, $messageId > 0 ? $messageId : null);
            $events[] = ['type' => 'telegram_delivered', 'key' => $key, 'message_id' => $messageId];
        } catch (Throwable $e) {
            $events[] = [
                'type' => 'telegram_retry_pending',
                'key' => $key,
                'error_code' => substr(hash('sha256', $e->getMessage()), 0, 12),
            ];
        }
    }
}

function tacticalNotifyIntentStatus(TacticalPaperRepository $repo, ?TelegramNotifier $notifier, array $intent, array &$events): void
{
    $status = (string) ($intent['status'] ?? 'unknown');
    tacticalNotify(
        $repo,
        $notifier,
        'intent:' . (string) $intent['decision_id'] . ':' . $status . ':' . (string) ($intent['cumulative_filled_qty'] ?? 0),
        sprintf(
            "🧾 Hybrid-v4 %s\n%s %s %s, sleeve %s\nFilled: %.4g / %.4g",
            $status,
            strtoupper((string) $intent['side']),
            (string) $intent['symbol'],
            (string) $intent['scheduled_session'],
            (string) $intent['sleeve_id'],
            (float) ($intent['cumulative_filled_qty'] ?? 0.0),
            (float) ($intent['requested_qty'] ?? 0.0),
        ),
        $events,
    );
}

/** @return array{positions:list<array<string,mixed>>,open_orders:list<array<string,mixed>>} */
function tacticalBrokerManifest(array $positions, array $orders): array
{
    return [
        'positions' => array_map(static fn (array $row): array => [
            'symbol' => strtoupper((string) ($row['symbol'] ?? '')),
            'side' => $row['side'] ?? null,
            'qty' => (float) ($row['qty'] ?? 0.0),
            'avg_entry_price' => (float) ($row['avg_entry_price'] ?? 0.0),
            'current_price' => (float) ($row['current_price'] ?? 0.0),
            'market_value' => (float) ($row['market_value'] ?? 0.0),
            'unrealized_pl' => (float) ($row['unrealized_pl'] ?? 0.0),
            'unrealized_plpc' => (float) ($row['unrealized_plpc'] ?? 0.0),
            'change_today' => (float) ($row['change_today'] ?? 0.0),
        ], $positions),
        'open_orders' => array_map(static fn (array $row): array => [
            'client_order_id' => $row['client_order_id'] ?? null,
            'symbol' => strtoupper((string) ($row['symbol'] ?? '')),
            'side' => $row['side'] ?? null,
            'qty' => (float) ($row['qty'] ?? 0.0),
            'filled_qty' => (float) ($row['filled_qty'] ?? 0.0),
            'status' => $row['status'] ?? null,
            'time_in_force' => $row['time_in_force'] ?? null,
        ], $orders),
    ];
}

/** @return array<string,float> */
function tacticalPositionQuantities(array $positions): array
{
    $result = [];
    foreach ($positions as $position) {
        $symbol = strtoupper((string) ($position['symbol'] ?? ''));
        $qty = (float) ($position['qty'] ?? 0.0);
        if ($symbol === '' || (string) ($position['side'] ?? 'long') !== 'long' || $qty < 0.0) {
            throw new RuntimeException('Unexpected broker position in tactical account.');
        }
        $result[$symbol] = $qty;
    }
    ksort($result);

    return $result;
}

/** @return list<array<string,mixed>> */
function tacticalSanitizeSleeves(TacticalPaperRepository $repo, string $runId, array $brokerPositions): array
{
    $prices = [];
    foreach ($brokerPositions as $position) {
        $prices[(string) $position['symbol']] = (float) ($position['current_price'] ?? 0.0);
    }
    $positions = $repo->positions($runId);
    $rows = [];
    foreach ($repo->sleeves($runId) as $id => $sleeve) {
        $nav = (float) $sleeve['cash'];
        $owned = [];
        foreach (($positions[$id] ?? []) as $symbol => $position) {
            $nav += (float) $position['qty'] * (float) ($prices[$symbol] ?? 0.0);
            $owned[] = ['symbol' => $symbol, 'qty' => (float) $position['qty']];
        }
        $rows[] = [
            'sleeve_id' => $id,
            'allocation' => (float) $sleeve['allocation'],
            'cash' => (float) $sleeve['cash'],
            'nav' => $nav,
            'positions' => $owned,
            'last_signal_date' => $sleeve['last_signal_date'],
            'last_session' => $sleeve['last_session'],
        ];
    }

    return $rows;
}

function tacticalSanitizeIntent(array $intent): array
{
    return [
        'decision_id_short' => substr((string) $intent['decision_id'], 0, 12),
        'sleeve_id' => $intent['sleeve_id'],
        'signal_date' => $intent['signal_date'],
        'scheduled_session' => $intent['scheduled_session'],
        'symbol' => $intent['symbol'],
        'side' => $intent['side'],
        'requested_qty' => (float) $intent['requested_qty'],
        'client_order_id' => $intent['client_order_id'],
        'status' => $intent['status'],
        'filled_qty' => (float) $intent['cumulative_filled_qty'],
        'updated_at' => $intent['updated_at'],
    ];
}

function tacticalFilesHash(array $paths): string
{
    sort($paths, SORT_STRING);
    $context = hash_init('sha256');
    foreach ($paths as $path) {
        if (!is_file($path)) {
            throw new RuntimeException('Tactical runtime identity file is missing.');
        }
        hash_update($context, basename($path) . "\0");
        hash_update_file($context, $path);
    }

    return hash_final($context);
}

function tacticalReadJson(string $path): array
{
    if (!is_file($path)) {
        return [];
    }
    $payload = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

    return is_array($payload) ? $payload : [];
}

function tacticalWriteJson(string $path, array $payload): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create tactical report directory.');
    }
    $temp = $path . '.tmp-' . bin2hex(random_bytes(6));
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    if (file_put_contents($temp, $json) === false || !rename($temp, $path)) {
        @unlink($temp);
        throw new RuntimeException('Unable to atomically write tactical report.');
    }
}

function tacticalBool(string $value): bool
{
    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
}

/** @return array<string,mixed> */
function tacticalStopPolicy(Config $config, string $root): array
{
    $fallback = [
        'swing_stop_mode' => (string) $config->get('strategy.club_rules.default_swing_stop_mode', 'mental'),
        'break_even_stop_mode' => (string) $config->get('strategy.club_rules.break_even_stop_mode', 'hard'),
        'mental_stop_exit_on_close' => (bool) $config->get('strategy.club_rules.mental_stop_exit_on_close', true),
        'hybrid_hard_stop_symbols' => array_values((array) $config->get('strategy.club_rules.hybrid_hard_stop_symbols', [])),
    ];
    $monitor = tacticalReadJson(rtrim($root, '/') . '/var/reports/daily/latest_paper_monitor.json');
    $policy = is_array($monitor['stop_policy'] ?? null) ? $monitor['stop_policy'] : [];
    $swingMode = strtolower((string) ($policy['swing_stop_mode'] ?? ''));
    $breakEvenMode = strtolower((string) ($policy['break_even_stop_mode'] ?? ''));
    if (!in_array($swingMode, ['hard', 'mental', 'hybrid'], true)
        || !in_array($breakEvenMode, ['hard', 'close'], true)
        || !array_key_exists('mental_stop_exit_on_close', $policy)
        || !is_bool($policy['mental_stop_exit_on_close'])
        || !is_array($policy['hybrid_hard_stop_symbols'] ?? null)) {
        return $fallback;
    }

    return [
        'swing_stop_mode' => $swingMode,
        'break_even_stop_mode' => $breakEvenMode,
        'mental_stop_exit_on_close' => $policy['mental_stop_exit_on_close'],
        'hybrid_hard_stop_symbols' => array_values($policy['hybrid_hard_stop_symbols']),
    ];
}
