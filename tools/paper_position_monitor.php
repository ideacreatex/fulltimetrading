#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Data\HttpClient;
use FulltimeTrading\Notifications\TelegramNotifier;
use FulltimeTrading\Storage\SqliteRepository;
use FulltimeTrading\Support\Config;
use FulltimeTrading\Support\ProcessLock;
use FulltimeTrading\Trading\AlpacaPaperClient;
use FulltimeTrading\Trading\PaperDailyReportFreshnessGuard;
use FulltimeTrading\Trading\PaperMonitorDecisionGuard;
use FulltimeTrading\Trading\PaperPositionLifecycle;
use FulltimeTrading\Trading\PaperReviewAlertGuard;

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
    'close-stop-reconcile-seconds' => '120',
    'close-stop-retry-seconds' => '300',
    'telegram-alert-retry-seconds' => '900',
    'lock' => __DIR__ . '/../var/run/paper_monitor.lock',
];

foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
        continue;
    }
    [$key, $value] = explode('=', substr($arg, 2), 2);
    $options[$key] = $value;
}

$monitorLock = ProcessLock::tryAcquire((string) $options['lock']);
if ($monitorLock === null) {
    fwrite(STDERR, "FTT paper monitor already running\n");
    // EX_TEMPFAIL: callers such as paper-daemon/install-launchd must be able to
    // distinguish a completed protection pass from one skipped on lock contention.
    exit(75);
}

$config = Config::fromFile(__DIR__ . '/../config/config.php');
$repo = new SqliteRepository((string) $config->get('database_path'));
$repo->migrate();
$http = new HttpClient();
$client = new AlpacaPaperClient(
    $http,
    getenv('APCA_PAPER_BASE_URL') ?: (string) $config->get('trading.alpaca.paper_base_url', 'https://paper-api.alpaca.markets/v2'),
);
$report = readJson((string) $options['report']);
$reportRisk = is_array($report['risk'] ?? null) ? $report['risk'] : [];

$now = new DateTimeImmutable();
$dryRun = !boolOption((string) $options['submit']);
$submitAllowed = !$dryRun
    && (bool) $config->get('trading.alpaca.paper_only', true)
    && (bool) $config->get('trading.alpaca.orders_enabled', false);
$partialPct = (string) $options['partial-pct'] !== ''
    ? (float) $options['partial-pct']
    : (float) ($reportRisk['partial_take_profit_pct'] ?? $config->get('strategy.partial_take_profit_pct', 0.25));
$partialPct = max(0.0, min(1.0, $partialPct));
$breakEvenPct = (float) ($reportRisk['break_even_profit_pct'] ?? $config->get('strategy.club_rules.break_even_profit_pct', 0.02));
$reviewCooldownMinutes = max(0, (int) $options['review-cooldown-minutes']);
$closeStopReconcileSeconds = max(15, (int) $options['close-stop-reconcile-seconds']);
$closeStopRetrySeconds = max(60, (int) $options['close-stop-retry-seconds']);
$telegramAlertRetrySeconds = max(60, (int) $options['telegram-alert-retry-seconds']);

$modelPositions = indexBySymbol($report['model']['open_positions'] ?? []);
$signals = PaperPositionLifecycle::indexLatestSignals(array_merge($report['signals_today'] ?? [], $report['recent_signals'] ?? []));
$stopPolicy = stopPolicyFromReport($report, $config);
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
$canSubmit = $isMarketOpen && $submitAllowed;
$positionSymbols = [];
foreach ($positions as $position) {
    $symbol = strtoupper((string) ($position['symbol'] ?? ''));
    if ($symbol === '') {
        continue;
    }
    $positionSymbols[$symbol] = true;
    $existingState = $states[$symbol] ?? null;
    $newLifecycle = PaperPositionLifecycle::isNew($existingState);
    $entryOrder = null;
    if ($newLifecycle) {
        $entryOrder = $repo->latestSubmittedBuyOrder($symbol, PaperPositionLifecycle::reentrySearchAfter($existingState));
    } elseif ($existingState !== null) {
        $reentrySearchAfter = PaperPositionLifecycle::reentrySearchAfter($existingState);
        if ($reentrySearchAfter !== null) {
            $candidateEntryOrder = $repo->latestSubmittedBuyOrder($symbol, $reentrySearchAfter);
            if (PaperPositionLifecycle::shouldResetForReentry($existingState, $position, $candidateEntryOrder)) {
                $newLifecycle = true;
                $entryOrder = $candidateEntryOrder;
            }
        }
    }
    $state = PaperPositionLifecycle::stateFromPosition(
        $position,
        $existingState,
        $modelPositions[$symbol] ?? null,
        $signals[$symbol] ?? null,
        $entryOrder,
        $breakEvenPct,
        $now,
        $newLifecycle,
    );
    $state = reconcilePartialTakeProfitState(
        $state,
        $symbol,
        (float) $state['qty'],
        $openOrders,
        $client,
        $now,
        $closeStopRetrySeconds,
    );
    $managedByReport = isset($modelPositions[$symbol]) || isset($signals[$symbol]);
    if (PaperPositionLifecycle::requiresRiskSourceReview($state, $managedByReport)) {
        $rejectedSources = is_array($state['payload']['rejected_risk_sources'] ?? null)
            ? implode(', ', $state['payload']['rejected_risk_sources'])
            : '';
        $decisionRows = [[
            'action' => 'review_risk_source_invalid',
            'severity' => 'warning',
            'reason' => $symbol . ' is present in the model/report, but this Alpaca lifecycle has no proven-current stop source; manual review is required.'
                . ($rejectedSources !== '' ? ' Rejected stale sources: ' . $rejectedSources . '.' : ''),
            'qty' => (float) $state['qty'],
        ]];
    } else {
        $closedBarObservation = confirmedClosedBarObservation(
            $report,
            $modelPositions[$symbol] ?? null,
            $position,
            $clock,
        );
        $decisionRows = decisionsForPosition(
            $state,
            $position,
            $managedByReport,
            $partialPct,
            boolOption((string) $options['close-when-model-closed']),
            $stopPolicy,
            $modelPositions[$symbol] ?? null,
            $closedBarObservation,
        );
    }
    $fullExitActive = false;
    foreach ($decisionRows as $decision) {
        if (in_array((string) ($decision['action'] ?? ''), ['close_stop', 'close_model_missing'], true)) {
            $fullExitActive = true;
            break;
        }
    }
    if (!$fullExitActive) {
        $state = PaperMonitorDecisionGuard::clearRecoveredCloseStop($state);
    }

    foreach ($decisionRows as $decision) {
        if (in_array((string) ($decision['action'] ?? ''), ['close_stop', 'close_model_missing'], true)) {
            $state = PaperMonitorDecisionGuard::ensureCloseStopEvent($state, $decision, $now);
            $eventClientOrderId = PaperMonitorDecisionGuard::clientOrderId($state);
            $activeSellOrder = PaperMonitorDecisionGuard::activeSellOrder($openOrders, $symbol, $eventClientOrderId);
            $blockedByOtherSell = false;
            if ($activeSellOrder !== null) {
                $activeClientOrderId = (string) ($activeSellOrder['client_order_id'] ?? '');
                if ($eventClientOrderId !== '' && hash_equals($eventClientOrderId, $activeClientOrderId)) {
                    $state = PaperMonitorDecisionGuard::clearActiveSellBlock($state);
                    $state = PaperMonitorDecisionGuard::applyOrder($state, $activeSellOrder, $now, $closeStopRetrySeconds);
                } else {
                    $previousBlock = PaperMonitorDecisionGuard::activeSellBlock($state);
                    if ($previousBlock !== null && orderReferencesMatch($previousBlock, $activeSellOrder)) {
                        $state = PaperMonitorDecisionGuard::reconcileActiveSellBlock($state, $activeSellOrder, (float) $state['qty'], $now);
                        if (PaperMonitorDecisionGuard::activeSellBlock($state) === null) {
                            // A partial fill changed position qty: rotate to the current remaining-exit fingerprint.
                            $state = PaperMonitorDecisionGuard::ensureCloseStopEvent($state, $decision, $now);
                        }
                    }
                    $blockedByOtherSell = true;
                    $state = PaperMonitorDecisionGuard::markBlockedByActiveSell($state, $activeSellOrder, (float) $state['qty'], $now);
                }
            } else {
                $activeSellBlock = PaperMonitorDecisionGuard::activeSellBlock($state);
                if ($activeSellBlock !== null) {
                    try {
                        $blockingOrder = lookupOrderReference(
                            $client,
                            (string) ($activeSellBlock['order_id'] ?? ''),
                            (string) ($activeSellBlock['client_order_id'] ?? ''),
                        );
                        if ($blockingOrder !== null) {
                            $state = PaperMonitorDecisionGuard::reconcileActiveSellBlock($state, $blockingOrder, (float) $state['qty'], $now);
                            if (PaperMonitorDecisionGuard::activeSellBlock($state) === null) {
                                // Re-evaluate stop/action/remaining qty only after the old quantity reservation is resolved.
                                $state = PaperMonitorDecisionGuard::ensureCloseStopEvent($state, $decision, $now);
                            }
                        }
                    } catch (Throwable) {
                        // Unknown blocker outcome remains guarded until it can be reconciled or position qty changes.
                    }
                    $blockedByOtherSell = PaperMonitorDecisionGuard::activeSellBlock($state) !== null;
                }
            }
            if (($activeSellOrder === null || $blockedByOtherSell) && PaperMonitorDecisionGuard::shouldReconcile($state)) {
                try {
                    $reconciledOrder = lookupCloseStopOrder($client, $state);
                    if ($reconciledOrder !== null) {
                        $state = PaperMonitorDecisionGuard::applyOrder($state, $reconciledOrder, $now, $closeStopRetrySeconds);
                    } else {
                        $state = PaperMonitorDecisionGuard::releaseAmbiguousAttemptIfDue($state, $now);
                    }
                } catch (Throwable) {
                    // Keep submitting/inflight guarded when reconciliation itself is unavailable.
                }
            }
            $state = PaperMonitorDecisionGuard::prepareTerminalRetryIfDue($state, $now);

            $clientOrderId = PaperMonitorDecisionGuard::clientOrderId($state);
            $safePositionQty = PaperMonitorDecisionGuard::positionQtyAfterKnownPartialFills($state, (float) $state['qty']);
            $remainingExitQty = PaperMonitorDecisionGuard::remainingExitQty($state, $safePositionQty);
            $orderPayload = sellOrder($symbol, $remainingExitQty, (string) $options['time-in-force'], $clientOrderId);
            $submitted = false;
            $orderId = null;
            $submitError = null;
            if (!$blockedByOtherSell && PaperMonitorDecisionGuard::maySubmit($state, $canSubmit, $remainingExitQty)) {
                $state = PaperMonitorDecisionGuard::markSubmitting($state, $now, $closeStopReconcileSeconds);
                $state['last_event_at'] = $now->format(DateTimeInterface::ATOM);
                $state['payload']['alpaca_position'] = $position;
                // Persist the stable attempt before the external request, so a crash cannot create a new sell id.
                $repo->savePaperPositionState($state);
                $repo->savePaperOrderState(orderStateFromMonitorOrder(
                    $orderPayload,
                    'submitting',
                    false,
                    $now,
                    false,
                    null,
                    null,
                    ['decision' => $decision, 'phase' => 'submitting'],
                ));
                try {
                    $submittedOrder = $client->submitOrder($orderPayload);
                    $submittedOrders[] = $submittedOrder;
                    $submitted = true;
                    $orderId = (string) ($submittedOrder['id'] ?? '');
                    $state = PaperMonitorDecisionGuard::applyOrder($state, $submittedOrder, $now, $closeStopRetrySeconds);
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
                } catch (Throwable $e) {
                    $submitError = $e->getMessage();
                    try {
                        $reconciledOrder = lookupCloseStopOrder($client, $state);
                    } catch (Throwable) {
                        $reconciledOrder = null;
                    }
                    if ($reconciledOrder !== null) {
                        $orderId = (string) ($reconciledOrder['id'] ?? '');
                        $state = PaperMonitorDecisionGuard::applyOrder($state, $reconciledOrder, $now, $closeStopRetrySeconds);
                        $repo->savePaperOrderState(orderStateFromMonitorOrder(
                            $orderPayload,
                            (string) ($reconciledOrder['status'] ?? 'submitted'),
                            false,
                            $now,
                            true,
                            $orderId,
                            $now->format(DateTimeInterface::ATOM),
                            ['decision' => $decision, 'alpaca_order' => $reconciledOrder, 'submit_error' => $submitError],
                        ));
                    } else {
                        $state = PaperMonitorDecisionGuard::markAmbiguousSubmit($state, $submitError, $now, $closeStopReconcileSeconds);
                        $repo->savePaperOrderState(orderStateFromMonitorOrder(
                            $orderPayload,
                            'submit_unknown',
                            false,
                            $now,
                            false,
                            null,
                            null,
                            ['decision' => $decision, 'error' => $submitError],
                        ));
                    }
                }
            } elseif (PaperMonitorDecisionGuard::phase($state) === 'pending' && $remainingExitQty > 0.0000001) {
                $plannedStatus = $dryRun
                    ? 'dry_run_planned'
                    : (!$submitAllowed ? 'blocked_submit_not_allowed' : 'blocked_market_closed');
                if (PaperMonitorDecisionGuard::needsPlannedOrderPersistence($state, $plannedStatus)) {
                    $repo->savePaperOrderState(orderStateFromMonitorOrder(
                        $orderPayload,
                        $plannedStatus,
                        $dryRun,
                        $now,
                        false,
                        null,
                        null,
                        ['decision' => $decision, 'phase' => 'pending'],
                    ));
                    $state = PaperMonitorDecisionGuard::markPlannedOrderPersisted($state, $plannedStatus, $now);
                }
            }

            [$alertKind, $alertReason] = closeStopAlert($state, $decision, $isMarketOpen, $canSubmit, $submitted, $submitError);
            $alertKey = PaperMonitorDecisionGuard::alertKey($state, $alertKind);
            $decisionAction = (string) $decision['action'];
            $actionName = $dryRun ? 'would_' . $decisionAction : $decisionAction;
            if (PaperMonitorDecisionGuard::shouldLogAction($state, $alertKey)) {
                $repo->logPaperAction([
                    'created_at' => $now->format(DateTimeInterface::ATOM),
                    'symbol' => $symbol,
                    'action' => $actionName,
                    'severity' => (string) $decision['severity'],
                    'dry_run' => $dryRun,
                    'submitted' => $submitted,
                    'order_id' => $orderId,
                    'client_order_id' => $clientOrderId,
                    'reason' => $alertReason,
                    'payload' => [
                        'decision' => $decision,
                        'order' => $orderPayload,
                        'market_open' => $isMarketOpen,
                        'submit_allowed' => $submitAllowed,
                        'can_submit' => $canSubmit,
                        'paper_exit_event' => PaperMonitorDecisionGuard::event($state),
                    ],
                ]);
                $state = PaperMonitorDecisionGuard::markActionLogged(
                    $state,
                    PaperMonitorDecisionGuard::eventId($state),
                    $alertKey,
                    $now,
                );
            }

            if (!PaperMonitorDecisionGuard::shouldEmitAlert($state, $alertKey, $now, $telegramAlertRetrySeconds)) {
                $suppressedActions[] = ['symbol' => $symbol, 'action' => $actionName, 'reason' => $alertReason];
                continue;
            }

            $actions[] = [
                'symbol' => $symbol,
                'action' => $actionName,
                'reason' => $alertReason,
                'submitted' => $submitted,
                'inflight' => in_array(PaperMonitorDecisionGuard::phase($state), ['submitting', 'inflight', 'filled'], true),
                'order' => $orderPayload,
                'paper_exit_event_id' => PaperMonitorDecisionGuard::eventId($state),
                'telegram_alert_key' => $alertKey,
            ];
            continue;
        }

        if ((string) ($decision['action'] ?? '') === 'partial_take_profit') {
            $state = PaperMonitorDecisionGuard::ensurePartialEvent($state, $decision, $now);
            $state = reconcilePartialTakeProfitState(
                $state,
                $symbol,
                (float) $state['qty'],
                $openOrders,
                $client,
                $now,
                $closeStopRetrySeconds,
            );
            $state = PaperMonitorDecisionGuard::preparePartialRetryIfDue($state, $now);
            $clientOrderId = PaperMonitorDecisionGuard::partialClientOrderId($state);
            $remainingPartialQty = PaperMonitorDecisionGuard::partialRemainingQty($state, (float) $state['qty']);
            $orderPayload = sellOrder($symbol, $remainingPartialQty, (string) $options['time-in-force'], $clientOrderId);
            $submitted = false;
            $orderId = null;
            $submitError = null;
            $blockedByOtherSell = PaperMonitorDecisionGuard::partialActiveSellBlock($state) !== null;
            if (!$blockedByOtherSell && PaperMonitorDecisionGuard::partialMaySubmit($state, $canSubmit, $remainingPartialQty)) {
                $state = PaperMonitorDecisionGuard::markPartialSubmitting($state, $now, $closeStopReconcileSeconds);
                $state['last_event_at'] = $now->format(DateTimeInterface::ATOM);
                $state['payload']['alpaca_position'] = $position;
                // Persist the stable partial attempt before HTTP, exactly as for a full exit.
                $repo->savePaperPositionState($state);
                $repo->savePaperOrderState(orderStateFromMonitorOrder(
                    $orderPayload,
                    'submitting',
                    false,
                    $now,
                    false,
                    null,
                    null,
                    ['decision' => $decision, 'phase' => 'submitting', 'paper_partial_event' => PaperMonitorDecisionGuard::partialEvent($state)],
                ));
                try {
                    $submittedOrder = $client->submitOrder($orderPayload);
                    $submittedOrders[] = $submittedOrder;
                    $submitted = true;
                    $orderId = (string) ($submittedOrder['id'] ?? '');
                    $state = PaperMonitorDecisionGuard::applyPartialOrder($state, $submittedOrder, $now, $closeStopRetrySeconds);
                    $state = PaperMonitorDecisionGuard::syncPartialPosition($state, (float) $state['qty'], $now);
                    $repo->savePaperOrderState(orderStateFromMonitorOrder(
                        $orderPayload,
                        (string) ($submittedOrder['status'] ?? 'submitted'),
                        false,
                        $now,
                        true,
                        $orderId,
                        $now->format(DateTimeInterface::ATOM),
                        ['decision' => $decision, 'alpaca_order' => $submittedOrder, 'paper_partial_event' => PaperMonitorDecisionGuard::partialEvent($state)],
                    ));
                } catch (Throwable $e) {
                    $submitError = $e->getMessage();
                    try {
                        $reconciledOrder = lookupPartialOrder($client, $state);
                    } catch (Throwable) {
                        $reconciledOrder = null;
                    }
                    if ($reconciledOrder !== null) {
                        $orderId = (string) ($reconciledOrder['id'] ?? '');
                        $state = PaperMonitorDecisionGuard::applyPartialOrder($state, $reconciledOrder, $now, $closeStopRetrySeconds);
                        $state = PaperMonitorDecisionGuard::syncPartialPosition($state, (float) $state['qty'], $now);
                        $repo->savePaperOrderState(orderStateFromMonitorOrder(
                            $orderPayload,
                            (string) ($reconciledOrder['status'] ?? 'submitted'),
                            false,
                            $now,
                            true,
                            $orderId,
                            $now->format(DateTimeInterface::ATOM),
                            ['decision' => $decision, 'alpaca_order' => $reconciledOrder, 'submit_error' => $submitError],
                        ));
                    } else {
                        $state = PaperMonitorDecisionGuard::markPartialAmbiguousSubmit($state, $submitError, $now, $closeStopReconcileSeconds);
                        $repo->savePaperOrderState(orderStateFromMonitorOrder(
                            $orderPayload,
                            'submit_unknown',
                            false,
                            $now,
                            false,
                            null,
                            null,
                            ['decision' => $decision, 'error' => $submitError, 'paper_partial_event' => PaperMonitorDecisionGuard::partialEvent($state)],
                        ));
                    }
                }
            } elseif (PaperMonitorDecisionGuard::partialPhase($state) === 'pending' && $remainingPartialQty > 0.0000001) {
                $plannedStatus = $dryRun
                    ? 'dry_run_planned'
                    : (!$submitAllowed ? 'blocked_submit_not_allowed' : 'blocked_market_closed');
                if (PaperMonitorDecisionGuard::partialNeedsPlannedOrderPersistence($state, $plannedStatus)) {
                    $repo->savePaperOrderState(orderStateFromMonitorOrder(
                        $orderPayload,
                        $plannedStatus,
                        $dryRun,
                        $now,
                        false,
                        null,
                        null,
                        ['decision' => $decision, 'phase' => 'pending', 'paper_partial_event' => PaperMonitorDecisionGuard::partialEvent($state)],
                    ));
                    $state = PaperMonitorDecisionGuard::markPartialPlannedOrderPersisted($state, $plannedStatus, $now);
                }
            }

            [$partialAlertKind, $partialReason] = partialTakeProfitAlert($state, $decision, $isMarketOpen, $canSubmit, $submitted, $submitError);
            $actionName = $dryRun ? 'would_partial_take_profit' : 'partial_take_profit';
            $partialEventId = (string) (PaperMonitorDecisionGuard::partialEvent($state)['event_id'] ?? '');
            $partialAlertKey = PaperMonitorDecisionGuard::partialAlertKey($state, $partialAlertKind);
            if (PaperMonitorDecisionGuard::shouldLogPartialAction($state, $partialAlertKey)) {
                $repo->logPaperAction([
                    'created_at' => $now->format(DateTimeInterface::ATOM),
                    'symbol' => $symbol,
                    'action' => $actionName,
                    'severity' => (string) $decision['severity'],
                    'dry_run' => $dryRun,
                    'submitted' => $submitted,
                    'order_id' => $orderId,
                    'client_order_id' => $clientOrderId,
                    'reason' => $partialReason,
                    'payload' => [
                        'decision' => $decision,
                        'order' => $orderPayload,
                        'market_open' => $isMarketOpen,
                        'submit_allowed' => $submitAllowed,
                        'can_submit' => $canSubmit,
                        'paper_partial_event' => PaperMonitorDecisionGuard::partialEvent($state),
                    ],
                ]);
                $state = PaperMonitorDecisionGuard::markPartialActionLogged(
                    $state,
                    $partialEventId,
                    $partialAlertKey,
                    $now,
                );
            }

            if (!PaperMonitorDecisionGuard::shouldEmitPartialAlert($state, $partialAlertKey, $now, $telegramAlertRetrySeconds)) {
                $suppressedActions[] = ['symbol' => $symbol, 'action' => $actionName, 'reason' => $partialReason];
                continue;
            }

            $actions[] = [
                'symbol' => $symbol,
                'action' => $actionName,
                'reason' => $partialReason,
                'submitted' => $submitted,
                'inflight' => in_array(PaperMonitorDecisionGuard::partialPhase($state), ['submitting', 'inflight'], true),
                'order' => $orderPayload,
                'paper_partial_event_id' => $partialEventId,
                'telegram_alert_scope' => 'partial',
                'telegram_alert_key' => $partialAlertKey,
            ];
            continue;
        }

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

        $reviewAction = PaperReviewAlertGuard::isReviewAction((string) $decision['action']);
        $actions[array_key_last($actions)]['review_action'] = $reviewAction ? (string) $decision['action'] : null;
        if (!$reviewAction && !$dryRun && ($submitted || $decision['action'] === 'arm_break_even')) {
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
    $state = PaperMonitorDecisionGuard::clearCloseStopEvent($state);
    $state = PaperMonitorDecisionGuard::clearPartialEvent($state);
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
    'stop_policy' => $stopPolicy,
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
        markPaperExitAlertsAttempted($repo, $actions, $now);
        try {
            $notifier->sendMessage($text, $actions === []);
            markPaperExitAlertsDelivered($repo, $actions, $now);
            markReviewAlertsDelivered($repo, $actions, $now);
            echo "Telegram message sent\n";
        } catch (Throwable $e) {
            echo "Telegram warning: " . $e->getMessage() . "\n";
        }
    }
} elseif (boolOption((string) $options['telegram'])) {
    echo "Telegram heartbeat skipped\n";
}

/** @param array<string, mixed> $state @return array<string, mixed>|null */
function lookupCloseStopOrder(AlpacaPaperClient $client, array $state): ?array
{
    $event = PaperMonitorDecisionGuard::event($state);
    if (!is_array($event)) {
        return null;
    }

    return lookupOrderReference(
        $client,
        (string) ($event['order_id'] ?? ''),
        (string) ($event['client_order_id'] ?? ''),
    );
}

/** @param array<string, mixed> $state @return array<string, mixed>|null */
function lookupPartialOrder(AlpacaPaperClient $client, array $state): ?array
{
    $event = PaperMonitorDecisionGuard::partialEvent($state);
    if (!is_array($event)) {
        return null;
    }

    return lookupOrderReference(
        $client,
        (string) ($event['order_id'] ?? ''),
        (string) ($event['client_order_id'] ?? ''),
    );
}

/**
 * Reconcile an already-created target-partial event on every poll, including polls
 * where price has left the target or a stop now has priority.
 *
 * @param array<string, mixed> $state
 * @param list<array<string, mixed>> $openOrders
 * @return array<string, mixed>
 */
function reconcilePartialTakeProfitState(
    array $state,
    string $symbol,
    float $positionQty,
    array $openOrders,
    AlpacaPaperClient $client,
    DateTimeImmutable $now,
    int $retryDelaySeconds,
): array {
    $state = PaperMonitorDecisionGuard::syncPartialPosition($state, $positionQty, $now);
    if (PaperMonitorDecisionGuard::partialEvent($state) === null) {
        return $state;
    }

    $clientOrderId = PaperMonitorDecisionGuard::partialClientOrderId($state);
    $activeSell = PaperMonitorDecisionGuard::activeSellOrder($openOrders, $symbol, $clientOrderId);
    if ($activeSell !== null) {
        $activeClientOrderId = (string) ($activeSell['client_order_id'] ?? '');
        if ($clientOrderId !== '' && hash_equals($clientOrderId, $activeClientOrderId)) {
            $state = PaperMonitorDecisionGuard::clearPartialActiveSellBlock($state);
            $state = PaperMonitorDecisionGuard::applyPartialOrder($state, $activeSell, $now, $retryDelaySeconds);
        } else {
            $state = PaperMonitorDecisionGuard::markPartialBlockedByActiveSell($state, $activeSell, $positionQty, $now);
        }

        return PaperMonitorDecisionGuard::syncPartialPosition($state, $positionQty, $now);
    }

    $activeBlock = PaperMonitorDecisionGuard::partialActiveSellBlock($state);
    if ($activeBlock !== null) {
        try {
            $blockingOrder = lookupOrderReference(
                $client,
                (string) ($activeBlock['order_id'] ?? ''),
                (string) ($activeBlock['client_order_id'] ?? ''),
            );
        } catch (Throwable) {
            return $state;
        }
        if ($blockingOrder === null) {
            // Unknown foreign-sell outcome keeps the target quantity reserved.
            return $state;
        }
        $state = PaperMonitorDecisionGuard::reconcilePartialActiveSellBlock($state, $blockingOrder, $positionQty, $now);
        if (PaperMonitorDecisionGuard::partialActiveSellBlock($state) !== null) {
            return PaperMonitorDecisionGuard::syncPartialPosition($state, $positionQty, $now);
        }
    }

    if (PaperMonitorDecisionGuard::shouldReconcilePartial($state)) {
        try {
            $order = lookupPartialOrder($client, $state);
            if ($order !== null) {
                $state = PaperMonitorDecisionGuard::applyPartialOrder($state, $order, $now, $retryDelaySeconds);
            } else {
                $state = PaperMonitorDecisionGuard::releasePartialAmbiguousAttemptIfDue($state, $now);
            }
        } catch (Throwable) {
            // Keep the stable attempt guarded until Alpaca can confirm its outcome.
        }
    }

    return PaperMonitorDecisionGuard::syncPartialPosition($state, $positionQty, $now);
}

/** @return array<string, mixed>|null */
function lookupOrderReference(AlpacaPaperClient $client, string $orderId, string $clientOrderId): ?array
{
    $orderId = trim($orderId);
    $clientOrderId = trim($clientOrderId);
    $order = $orderId !== '' ? $client->order($orderId) : null;
    if ($order === null && $clientOrderId !== '') {
        $order = $client->orderByClientOrderId($clientOrderId);
    }

    for ($depth = 0; $depth < 3 && is_array($order); $depth++) {
        $replacedBy = trim((string) ($order['replaced_by'] ?? ''));
        if (strtolower((string) ($order['status'] ?? '')) !== 'replaced' || $replacedBy === '') {
            break;
        }
        $replacement = $client->order($replacedBy);
        if ($replacement === null) {
            break;
        }
        $order = $replacement;
    }

    return $order;
}

/** @param array<string, mixed> $left @param array<string, mixed> $right */
function orderReferencesMatch(array $left, array $right): bool
{
    foreach (['id' => 'order_id', 'client_order_id' => 'client_order_id'] as $rightKey => $leftKey) {
        $leftValue = trim((string) ($left[$leftKey] ?? ''));
        $rightValue = trim((string) ($right[$rightKey] ?? ''));
        if ($leftValue !== '' && $rightValue !== '' && hash_equals($leftValue, $rightValue)) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<string, mixed> $state
 * @param array<string, mixed> $decision
 * @return array{0:string, 1:string}
 */
function closeStopAlert(
    array $state,
    array $decision,
    bool $isMarketOpen,
    bool $canSubmit,
    bool $submitted,
    ?string $submitError,
): array {
    $event = PaperMonitorDecisionGuard::event($state) ?? [];
    $phase = PaperMonitorDecisionGuard::phase($state);
    $clientOrderId = (string) ($event['client_order_id'] ?? '');
    $baseReason = rtrim((string) ($decision['reason'] ?? 'Stop threshold reached.'));

    $activeSellBlock = is_array($event['blocked_by_active_sell'] ?? null) ? $event['blocked_by_active_sell'] : null;
    if ($activeSellBlock !== null) {
        $blockingClientOrderId = (string) ($activeSellBlock['client_order_id'] ?? 'unknown');

        return ['active_sell', $baseReason . ' Another active sell (' . $blockingClientOrderId . ') already reserves position quantity; this exit attempt is blocked without adopting that order.'];
    }
    if ($phase === 'filled') {
        return ['submitted', $baseReason . ' Exit order is filled; waiting for the position snapshot to clear.'];
    }
    if ($phase === 'inflight') {
        $suffix = $submitted
            ? ' Exit order accepted by Alpaca paper.'
            : ' Exit order is already inflight; duplicate sell suppressed.';

        return ['submitted', $baseReason . $suffix];
    }
    if ($phase === 'suspended') {
        return ['suspended', $baseReason . ' Exit order is suspended at Alpaca; duplicate sell remains blocked while reconciliation continues.'];
    }
    if ($phase === 'submitting') {
        $error = trim((string) ($submitError ?? $event['last_error'] ?? ''));
        $suffix = ' Submission outcome is being reconciled with stable client id ' . $clientOrderId . '; duplicate sell suppressed.';
        if ($error !== '') {
            $suffix .= ' Last error: ' . substr(preg_replace('/\s+/', ' ', $error) ?? $error, 0, 240);
        }

        return ['submitting', $baseReason . $suffix];
    }
    if ($phase === 'retry_wait') {
        $retryAfter = (string) ($event['retry_after'] ?? 'later');
        $status = (string) ($event['order_status'] ?? 'terminal');

        return ['retry_wait', $baseReason . ' Previous close order is ' . $status . '; controlled retry after ' . $retryAfter . '.'];
    }
    if (!$isMarketOpen) {
        return ['pending', $baseReason . ' Market is closed; stop pending until market open.'];
    }
    if (!$canSubmit) {
        return ['blocked', $baseReason . ' Market is open, but guarded paper submit is disabled; no order sent.'];
    }

    return ['submitting', $baseReason . ' Close order attempt is pending reconciliation.'];
}

/**
 * @param array<string, mixed> $state
 * @param array<string, mixed> $decision
 * @return array{0:string,1:string}
 */
function partialTakeProfitAlert(
    array $state,
    array $decision,
    bool $isMarketOpen,
    bool $canSubmit,
    bool $submitted,
    ?string $submitError,
): array {
    $event = PaperMonitorDecisionGuard::partialEvent($state) ?? [];
    $phase = PaperMonitorDecisionGuard::partialPhase($state);
    $base = rtrim((string) ($decision['reason'] ?? 'Partial target reached.'));
    $remaining = (float) ($event['remaining_qty'] ?? 0.0);
    $knownFilled = (float) ($event['known_filled_qty'] ?? 0.0);
    if (PaperMonitorDecisionGuard::partialActiveSellBlock($state) !== null) {
        return ['active_sell', $base . sprintf(' Another active sell reserves quantity; partial is blocked. Known filled %.6f, remaining %.6f.', $knownFilled, $remaining)];
    }
    if ($phase === 'filled') {
        return ['filled', $base . sprintf(' Target partial is fully filled (%.6f); partial_done is now armed.', $knownFilled)];
    }
    if ($phase === 'inflight') {
        return ['submitted', $base . ($submitted ? ' Partial order accepted by Alpaca paper.' : ' Partial order is already inflight; duplicate sell suppressed.')
            . sprintf(' Remaining target qty %.6f.', $remaining)];
    }
    if ($phase === 'suspended') {
        return ['suspended', $base . ' Partial order is suspended; duplicate sell remains blocked.'];
    }
    if ($phase === 'submitting') {
        $error = trim((string) ($submitError ?? $event['last_error'] ?? ''));
        $suffix = ' Partial submission is being reconciled with stable client id ' . (string) ($event['client_order_id'] ?? '') . '.';
        if ($error !== '') {
            $suffix .= ' Last error: ' . substr(preg_replace('/\s+/', ' ', $error) ?? $error, 0, 240);
        }

        return ['submitting', $base . $suffix];
    }
    if ($phase === 'retry_wait') {
        return ['retry_wait', $base . sprintf(
            ' Previous partial attempt is %s; retry of at most %.6f after %s.',
            (string) ($event['order_status'] ?? 'terminal'),
            $remaining,
            (string) ($event['retry_after'] ?? 'later'),
        )];
    }
    if (!$isMarketOpen) {
        return ['pending', $base . sprintf(' Market is closed; partial %.6f remains pending.', $remaining)];
    }
    if (!$canSubmit) {
        return ['blocked', $base . sprintf(' Guarded paper submit is disabled; partial %.6f was not sent.', $remaining)];
    }

    return ['submitting', $base . sprintf(' Partial attempt remains pending reconciliation; remaining %.6f.', $remaining)];
}

/** @param list<array<string, mixed>> $actions */
function markPaperExitAlertsAttempted(SqliteRepository $repo, array $actions, DateTimeImmutable $now): void
{
    updatePaperExitAlerts($repo, $actions, $now, false);
}

/** @param list<array<string, mixed>> $actions */
function markPaperExitAlertsDelivered(SqliteRepository $repo, array $actions, DateTimeImmutable $now): void
{
    updatePaperExitAlerts($repo, $actions, $now, true);
}

/** @param list<array<string, mixed>> $actions */
function updatePaperExitAlerts(SqliteRepository $repo, array $actions, DateTimeImmutable $now, bool $delivered): void
{
    $deliveries = [];
    foreach ($actions as $action) {
        if (!is_array($action)) {
            continue;
        }
        $symbol = strtoupper((string) ($action['symbol'] ?? ''));
        $scope = (string) ($action['telegram_alert_scope'] ?? 'exit');
        $eventId = $scope === 'partial'
            ? (string) ($action['paper_partial_event_id'] ?? '')
            : (string) ($action['paper_exit_event_id'] ?? '');
        $alertKey = (string) ($action['telegram_alert_key'] ?? '');
        if ($symbol !== '' && $eventId !== '' && $alertKey !== '') {
            $deliveries[$symbol][] = [$scope, $eventId, $alertKey];
        }
    }
    if ($deliveries === []) {
        return;
    }

    $states = $repo->loadPaperPositionStates();
    foreach ($deliveries as $symbol => $rows) {
        $state = $states[$symbol] ?? null;
        if (!is_array($state)) {
            continue;
        }
        foreach ($rows as [$scope, $eventId, $alertKey]) {
            if ($scope === 'partial') {
                $state = $delivered
                    ? PaperMonitorDecisionGuard::markPartialAlertDelivered($state, $eventId, $alertKey, $now)
                    : PaperMonitorDecisionGuard::markPartialAlertAttempted($state, $eventId, $alertKey, $now);
            } else {
                $state = $delivered
                    ? PaperMonitorDecisionGuard::markAlertDelivered($state, $eventId, $alertKey, $now)
                    : PaperMonitorDecisionGuard::markAlertAttempted($state, $eventId, $alertKey, $now);
            }
        }
        $repo->savePaperPositionState($state);
    }
}

/** @param list<array<string, mixed>> $actions */
function markReviewAlertsDelivered(SqliteRepository $repo, array $actions, DateTimeImmutable $now): void
{
    $reviewActions = [];
    foreach ($actions as $action) {
        if (!is_array($action)) {
            continue;
        }
        $symbol = strtoupper((string) ($action['symbol'] ?? ''));
        $reviewAction = (string) ($action['review_action'] ?? '');
        if ($symbol !== '' && PaperReviewAlertGuard::isReviewAction($reviewAction)) {
            $reviewActions[$symbol][$reviewAction] = true;
        }
    }
    if ($reviewActions === []) {
        return;
    }

    $states = $repo->loadPaperPositionStates();
    foreach ($reviewActions as $symbol => $symbolActions) {
        $state = $states[$symbol] ?? null;
        if (!is_array($state)) {
            continue;
        }
        foreach (array_keys($symbolActions) as $reviewAction) {
            $state = PaperReviewAlertGuard::markDelivered($state, $reviewAction, $now);
        }
        $repo->savePaperPositionState($state);
    }
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

/**
 * @param array<string, mixed> $state
 * @param array<string, mixed> $position
 * @param array<string, mixed> $stopPolicy
 * @param array<string, mixed>|null $modelPosition
 * @param array{date:string,price:float,source:string}|null $closedBarObservation
 * @return list<array<string, mixed>>
 */
function decisionsForPosition(
    array $state,
    array $position,
    bool $managedOpen,
    float $partialPct,
    bool $closeWhenModelClosed,
    array $stopPolicy,
    ?array $modelPosition,
    ?array $closedBarObservation,
): array
{
    $rows = [];
    $qty = (float) $state['qty'];
    $safePositionQty = PaperMonitorDecisionGuard::positionQtyAfterKnownPartialFills($state, $qty);
    $price = (float) $state['market_price'];
    $entry = (float) $state['entry_price'];
    $stop = (float) $state['stop_price'];
    $beTrigger = (float) $state['break_even_trigger_price'];
    $target = (float) $state['target_price'];
    $symbol = (string) $state['symbol'];
    if ($qty <= 0.0 || $price <= 0.0) {
        return $rows;
    }

    if (PaperMonitorDecisionGuard::isLatchedMentalStop($state)) {
        $event = PaperMonitorDecisionGuard::event($state) ?? [];
        $triggerPrice = (float) ($event['trigger_price'] ?? $stop);
        $triggerDate = trim((string) ($event['trigger_bar_date'] ?? ''));
        $rows[] = [
            'action' => 'close_stop',
            'severity' => 'warning',
            'reason' => sprintf(
                '%s mental stop remains latched after daily close%s %.2f at/below stop %.2f.',
                $symbol,
                $triggerDate !== '' ? ' ' . $triggerDate : '',
                $triggerPrice,
                $stop,
            ),
            // Keep the stop latched, but re-evaluate the full reconciled long qty:
            // an overnight add-on or split must not be left behind at the open.
            'qty' => $safePositionQty,
            'stop_trigger_mode' => 'mental_close',
            'stop_trigger_price' => $triggerPrice,
            'stop_trigger_bar_date' => $triggerDate,
        ];

        return $rows;
    }

    $stopEvaluation = PaperMonitorDecisionGuard::evaluateStop(
        $state,
        $stopPolicy,
        $price,
        $closedBarObservation['price'] ?? null,
        $modelPosition,
    );
    if ($stopEvaluation['triggered']) {
        $triggerPrice = (float) ($stopEvaluation['observed_price'] ?? $price);
        $triggerMode = (string) $stopEvaluation['mode'];
        $triggerDate = $triggerMode === 'mental_close' ? (string) ($closedBarObservation['date'] ?? '') : '';
        $rows[] = [
            'action' => 'close_stop',
            'severity' => 'warning',
            'reason' => $triggerMode === 'mental_close'
                ? sprintf('%s daily close %s%.2f is at/below mental stop %.2f.', $symbol, $triggerDate !== '' ? $triggerDate . ' ' : '', $triggerPrice, $stop)
                : sprintf('%s hard-stop quote %.2f is at/below stop %.2f.', $symbol, $triggerPrice, $stop),
            'qty' => $safePositionQty,
            'stop_trigger_mode' => $triggerMode,
            'stop_trigger_price' => $triggerPrice,
            'stop_trigger_bar_date' => $triggerDate,
        ];

        return $rows;
    }

    if (!$managedOpen) {
        $rows[] = [
            'action' => $closeWhenModelClosed ? 'close_model_missing' : 'review_model_missing',
            'severity' => $closeWhenModelClosed ? 'warning' : 'info',
            'reason' => $symbol . ' exists at Alpaca but is absent from model/report-managed positions.',
            'qty' => $safePositionQty,
        ];

        // close_model_missing is already a full-position exit; never add a second sell decision.
        return $rows;
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

    if (!($state['partial_done'] ?? false) && $target > 0.0 && $price >= $target) {
        $rows[] = [
            'action' => 'partial_take_profit',
            'severity' => 'info',
            'reason' => sprintf('%s reached target %.2f at %.2f.', $symbol, $target, $price),
            'qty' => max(0.0, floor($safePositionQty * $partialPct * 1000000.0) / 1000000.0),
        ];
    }

    return $rows;
}

/** @param array<string, mixed> $report @return array<string, mixed> */
function stopPolicyFromReport(array $report, Config $config): array
{
    $risk = is_array($report['risk'] ?? null) ? $report['risk'] : [];
    $swingMode = strtolower((string) ($risk['swing_stop_mode'] ?? $config->get('strategy.club_rules.default_swing_stop_mode', 'mental')));
    if (!in_array($swingMode, ['hard', 'mental', 'hybrid'], true)) {
        $swingMode = 'mental';
    }
    $breakEvenMode = strtolower((string) ($risk['break_even_stop_mode'] ?? $config->get('strategy.club_rules.break_even_stop_mode', 'hard')));
    if (!in_array($breakEvenMode, ['hard', 'close'], true)) {
        $breakEvenMode = 'hard';
    }

    return [
        'swing_stop_mode' => $swingMode,
        'break_even_stop_mode' => $breakEvenMode,
        'mental_stop_exit_on_close' => (bool) ($risk['mental_stop_exit_on_close'] ?? $config->get('strategy.club_rules.mental_stop_exit_on_close', true)),
        'hybrid_hard_stop_symbols' => is_array($risk['hybrid_hard_stop_symbols'] ?? null)
            ? array_values($risk['hybrid_hard_stop_symbols'])
            : array_values((array) $config->get('strategy.club_rules.hybrid_hard_stop_symbols', [])),
    ];
}

/**
 * A mental stop may consume only a confirmed daily close. Model mark_price is accepted
 * only for a closed report bar; the Alpaca quote is accepted only just after that session.
 *
 * @param array<string, mixed> $report
 * @param array<string, mixed>|null $modelPosition
 * @param array<string, mixed> $position
 * @param array<string, mixed> $clock
 * @return array{date:string,price:float,source:string}|null
 */
function confirmedClosedBarObservation(array $report, ?array $modelPosition, array $position, array $clock): ?array
{
    $asOf = substr(trim((string) ($report['as_of'] ?? '')), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) {
        return null;
    }
    $symbol = strtoupper((string) ($position['symbol'] ?? ''));
    $reportedBars = is_array($report['data']['latest_closed_bars'] ?? null)
        ? $report['data']['latest_closed_bars']
        : [];
    $reportedBar = is_array($reportedBars[$symbol] ?? null) ? $reportedBars[$symbol] : null;
    if ($reportedBar !== null) {
        $reportedDate = substr(trim((string) ($reportedBar['date'] ?? '')), 0, 10);
        $reportedClose = (float) ($reportedBar['close'] ?? 0.0);
        if (
            $reportedDate === $asOf
            && $reportedClose > 0.0
            && PaperDailyReportFreshnessGuard::barWasClosedWhenReportGenerated($report, $reportedDate)
            && reportBarIsClosed($reportedDate, $clock)
        ) {
            return ['date' => $reportedDate, 'price' => $reportedClose, 'source' => 'report_closed_bar'];
        }
    }
    $modelDate = substr(trim((string) ($modelPosition['date'] ?? $asOf)), 0, 10);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $modelDate)) {
        $modelDate = $asOf;
    }
    if (
        $modelDate === $asOf
        && PaperDailyReportFreshnessGuard::barWasClosedWhenReportGenerated($report, $modelDate)
        && reportBarIsClosed($modelDate, $clock)
    ) {
        $modelClose = (float) ($modelPosition['mark_price'] ?? 0.0);
        if ($modelClose > 0.0) {
            return ['date' => $modelDate, 'price' => $modelClose, 'source' => 'model_mark_price'];
        }
    }
    $marketDate = marketClockDate($clock);
    if (
        $asOf === $marketDate
        && PaperDailyReportFreshnessGuard::barWasClosedWhenReportGenerated($report, $asOf)
        && reportBarIsClosed($asOf, $clock)
    ) {
        $close = (float) ($position['current_price'] ?? $position['market_price'] ?? 0.0);
        if ($close > 0.0) {
            return ['date' => $asOf, 'price' => $close, 'source' => 'alpaca_post_close'];
        }
    }

    return null;
}

/** @param array<string, mixed> $clock */
function reportBarIsClosed(string $barDate, array $clock): bool
{
    $marketDate = marketClockDate($clock);
    if ($marketDate === '' || $barDate === '') {
        return false;
    }
    if ($barDate < $marketDate) {
        return true;
    }
    if ($barDate > $marketDate || (bool) ($clock['is_open'] ?? false)) {
        return false;
    }

    $nextOpen = marketDateFromTimestamp((string) ($clock['next_open'] ?? ''));
    if ($nextOpen !== '' && $nextOpen > $marketDate) {
        return true;
    }

    try {
        $timestamp = new DateTimeImmutable((string) ($clock['timestamp'] ?? 'now'));
        $marketTime = $timestamp->setTimezone(new DateTimeZone('America/New_York'))->format('H:i:s');

        return $marketTime >= '16:00:00';
    } catch (Throwable) {
        return false;
    }
}

/** @param array<string, mixed> $clock */
function marketClockDate(array $clock): string
{
    return marketDateFromTimestamp((string) ($clock['timestamp'] ?? ''));
}

function marketDateFromTimestamp(string $value): string
{
    if (trim($value) === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('America/New_York'))->format('Y-m-d');
    } catch (Throwable) {
        return '';
    }
}

/** @param array<string, mixed> $state @param array<string, mixed> $decision */
function applySubmittedDecision(array $state, array $decision, DateTimeImmutable $now, ?string $clientOrderId): array
{
    $action = (string) $decision['action'];
    if ($action === 'arm_break_even') {
        $state['break_even_armed'] = true;
        $state['stop_price'] = (float) ($decision['new_stop'] ?? $state['entry_price']);
    }
    $state['last_action'] = $action;
    $state['last_event_at'] = $now->format(DateTimeInterface::ATOM);
    if ($clientOrderId !== null && trim($clientOrderId) !== '') {
        $state['client_order_id'] = $clientOrderId;
    }

    return $state;
}

/** @param array<string, mixed> $state @param array<string, mixed> $decision */
function shouldSuppressDecision(array $state, array $decision, DateTimeImmutable $now, int $cooldownMinutes): bool
{
    return PaperReviewAlertGuard::shouldSuppress(
        $state,
        (string) ($decision['action'] ?? ''),
        $now,
        $cooldownMinutes,
    );
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
                !empty($action['submitted']) ? ' [submitted]' : (!empty($action['inflight']) ? ' [inflight]' : ''),
            );
        }
    }
    if ($suppressedActions !== []) {
        $lines[] = 'Suppressed duplicate actions: ' . count($suppressedActions);
    }

    return implode("\n", $lines);
}

function boolOption(string $value): bool
{
    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'y', 'on'], true);
}
