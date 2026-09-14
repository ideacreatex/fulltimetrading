#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Data\HttpClient;
use FulltimeTrading\Notifications\TelegramNotifier;
use FulltimeTrading\Paper\AlpacaCandidateGateway;
use FulltimeTrading\Paper\CandidateAccountReconciliation;
use FulltimeTrading\Paper\CandidateCloseEngine;
use FulltimeTrading\Paper\CandidateDataSnapshot;
use FulltimeTrading\Paper\CandidateDefinition;
use FulltimeTrading\Paper\CandidateExecutionPlan;
use FulltimeTrading\Paper\CandidateLedger;
use FulltimeTrading\Paper\CandidateMessages;
use FulltimeTrading\Paper\CandidateOrder;
use FulltimeTrading\Paper\CandidateOrderReconciler;
use FulltimeTrading\Paper\CandidateRelease;
use FulltimeTrading\Paper\CandidateSession;
use FulltimeTrading\Paper\CandidateSignalArtifact;
use FulltimeTrading\Research\AlgorithmTrendResearch;
use FulltimeTrading\Support\Config;
use FulltimeTrading\Support\ProcessLock;
use FulltimeTrading\Trading\AlpacaPaperAccountGuard;
use FulltimeTrading\Trading\AlpacaPaperClient;
use FulltimeTrading\Trading\TacticalRotationExecutionWindow;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__); $options = ['submit' => 'false', 'telegram' => 'true', 'db' => '', 'output' => '',
    'artifact' => $root . '/var/reports/daily/candidate_signal.json', 'lock' => '',
    'mutation-lock' => $root . '/var/run/alpaca_paper_account_mutation.lock'];
foreach (array_slice($argv, 1) as $arg) { if (str_starts_with($arg, '--') && str_contains($arg, '=')) { [$k, $v] = explode('=', substr($arg, 2), 2); $options[$k] = $v; } }
foreach (['submit', 'telegram'] as $key) { if (!in_array($options[$key], ['true', 'false'], true)) { throw new InvalidArgumentException('Invalid boolean option.'); } }
$submit = $options['submit'] === 'true'; $telegram = $submit && $options['telegram'] === 'true';
$candidate = require $root . '/config/paper_candidate.php'; $config = Config::fromFile($root . '/config/config.php');
$output = $options['output'] !== '' ? $options['output'] : $root . '/var/reports/' . ($submit ? 'daily/tactical_paper_cycle.json' : 'candidate_preflight.json');
$lockPath = $options['lock'] !== '' ? $options['lock'] : $root . '/var/run/' . ($submit ? 'tactical_paper_executor.lock' : 'candidate_preflight.lock');
$lock = ProcessLock::tryAcquire($lockPath);
if ($lock === null) { exit(75); }
$lease = $submit ? ProcessLock::tryAcquire($options['mutation-lock']) : null;
if ($submit && $lease === null) { exit(75); }
$report = ['generated_at' => gmdate(DATE_ATOM), 'run_id' => $candidate['run_id'], 'profile' => CandidateDefinition::PROFILE,
    'dry_run' => !$submit, 'paper_only' => true, 'live_enabled' => false, 'entry_submission_enabled' => false,
    'errors' => [], 'events' => [], 'submitted' => [], 'notification_schedule' => [], 'signal' => [], 'account_guard' => []];
$temporary = null; $ledger = null; $notificationKeys = [];
try {
    $http = new HttpClient();
    $client = new AlpacaPaperClient($http, getenv('APCA_PAPER_BASE_URL') ?: (string) $config->get('trading.alpaca.paper_base_url'));
    $account = $client->account(); $report['account_guard'] = AlpacaPaperAccountGuard::validateConfigured($account);
    $now = new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
    $calendar = $client->calendar($now->modify('-15 days')->format('Y-m-d'), $now->modify('+15 days')->format('Y-m-d'));
    $clock = $client->clock(); $session = CandidateSession::resolve($calendar, $clock, $now);
    $positions = $client->positions(); $orders = $client->openOrders();
    $snapshot = ['captured_at' => gmdate(DATE_ATOM), 'equity' => $account['equity'], 'cash' => $account['cash'],
        'buying_power' => $account['buying_power'], 'positions' => $positions, 'open_orders' => $orders];
    $report['broker'] = ['equity' => (float) $account['equity'], 'cash' => (float) $account['cash'],
        'buying_power' => (float) $account['buying_power'], 'positions' => $positions, 'open_orders' => $orders];
    if (($submit && ($candidate['enabled'] ?? null) !== true) || ($candidate['paper_only'] ?? null) !== true || ($candidate['live_enabled'] ?? null) !== false) {
        throw new RuntimeException('candidate_release_not_enabled');
    }
    $release = CandidateRelease::verify($root, $candidate);
    $commissioned = CandidateRelease::commissioned($root, $candidate['run_id'], $release['runtime_hash']);
    $report['installation_commissioned'] = $commissioned;
    if ($submit && (!(bool) $config->get('trading.alpaca.paper_only', true) || !(bool) $config->get('trading.alpaca.orders_enabled', false))) {
        throw new RuntimeException('configured_paper_submission_gate_closed');
    }
    $artifact = $inputs = null; $fresh = false;
    try {
        $artifact = CandidateDataSnapshot::read($options['artifact']);
        $inputs = CandidateSignalArtifact::validate($artifact, $candidate, $release['runtime_hash'], require $root . '/config/tactical_rotation.php');
        CandidateDataSnapshot::verifyProvenance($root, $inputs['provenance']);
        $fresh = $artifact['as_of'] === $session['signal_date'] && $artifact['scheduled_session'] === $session['scheduled_session'];
    } catch (Throwable $e) { $report['errors'][] = 'candidate_signal_invalid:' . $e->getMessage(); }
    $report['signal'] = ['as_of' => $inputs['date'] ?? null, 'validation_selected' => false, 'paper_admission' => true,
        'fresh' => $fresh, 'next_session' => $artifact['scheduled_session'] ?? null];
    $report['report_snapshot_fresh'] = $fresh;
    $report['signal']['intended_session'] = $artifact['scheduled_session'] ?? null;
    $report['signal']['required_as_of'] = $session['signal_date'];
    $operationalDb = (string) $config->get('database_path');
    if (!$submit) {
        if ($positions !== [] || $orders !== []) { throw new RuntimeException('flat_only_isolated_preflight'); }
        if ($options['db'] === '' || realpath($options['db']) === realpath($operationalDb)) { $temporary = tempnam(sys_get_temp_dir(), 'candidate-preflight-'); }
    }
    $dbPath = $temporary ?? ($options['db'] !== '' ? $options['db'] : $operationalDb);
    if ($submit && realpath($dbPath) !== realpath($operationalDb)) { throw new RuntimeException('submission_requires_operational_database'); }
    $ledger = new CandidateLedger($dbPath);
    $identity = ['run_id' => $candidate['run_id'], 'profile' => CandidateDefinition::PROFILE,
        'strategy_hash' => hash_file('sha256', $root . '/config/paper_candidate.php'), 'runtime_hash' => $release['runtime_hash'],
        'data_contract' => ['execution_contract' => CandidateOrder::CONTRACT, 'paper_only' => true, 'data' => $candidate['data']]];
    $books = CandidateDefinition::books(require $root . '/config/tactical_rotation.php', [], [], []);
    $ledger->provision($identity, array_map(static fn ($b): float => $b['allocation'], $books));
    $runId = $candidate['run_id']; $run = $ledger->run($runId);
    $report['run_status'] = $run['status'];
    $recordFill = static function (array $before, array $after) use ($ledger, $runId, $telegram, &$notificationKeys): void {
        if (!$telegram || (float) $after['cumulative_filled_qty'] <= (float) $before['cumulative_filled_qty']) { return; }
        $key = 'candidate-fill:' . $runId . ':' . $after['decision_id'] . ':' . $after['cumulative_filled_qty'];
        $ledger->views->queueNotification($key, CandidateMessages::fill($after, (float) $before['cumulative_filled_qty']), ['run_id' => $runId]);
        $notificationKeys[] = $key;
    };
    if ($submit) {
        $reconciler = new CandidateOrderReconciler($ledger, new AlpacaCandidateGateway($client));
        foreach ($ledger->active($runId) as $i) {
            if ((int) $i['attempt_count'] === 0) { continue; }
            $observation = $reconciler->refresh($i['decision_id']);
            $recordFill($i, $observation['intent']);
            if ($observation['status'] === 'unresolved_lookup') { $report['errors'][] = 'unresolved_order_lookup'; }
        }
        $account = $client->account(); AlpacaPaperAccountGuard::validateConfigured($account);
        $positions = $client->positions(); $orders = $client->openOrders();
        $run = $ledger->run($runId);
    }
    $activated = false;
    if ($run['status'] === 'transition') {
        if (!$fresh) { throw new RuntimeException('activation_requires_latest_complete_signal'); }
        if ($positions !== [] || $orders !== []) { throw new RuntimeException('candidate_activation_requires_flat_account'); }
        if (abs((float) $account['equity'] - (float) $release['capital_reviewed']) > .01) { throw new RuntimeException('candidate_starting_capital_not_reviewed'); }
        $fingerprint = hash('sha256', CandidateOrder::json([(string) ($account['id'] ?? ''), $account['equity'], $account['cash']]));
        $stable = !$submit || $ledger->views->observeFlatHandoff($runId, $fingerprint, 120);
        if ($stable) {
            $proof = ['positions' => [], 'open_orders' => [], 'adoption' => 'flat_account_only', 'stable_for_seconds' => 120];
            if ($submit) { $proof['predecessor_run_id'] = $candidate['predecessor_run_id']; }
            $ledger->activate($runId, (float) $account['equity'], $proof); $activated = true; $run = $ledger->run($runId);
        }
    }
    $report['run_status'] = $run['status'];
    if ($run['status'] !== 'transition') {
        $ledgerCash = array_sum(array_column($ledger->views->sleeves($runId), 'cash'));
        $reconciliation = CandidateAccountReconciliation::inspect($ledger->views->expectedBrokerPositions($runId), $ledgerCash,
            $account, $positions, $orders, $ledger->active($runId));
        $report['reconciliation'] = $reconciliation;
        if (!$reconciliation['ok']) { $report['errors'] = [...$report['errors'], ...$reconciliation['errors']]; }
        $close = $ledger->checkpoint($runId, 'latest_close')['payload'] ?? null;
        if ($fresh && $reconciliation['ok']) {
            $engine = new CandidateCloseEngine($ledger);
            $prepared = $engine->prepare($runId, $inputs['date'], $artifact['scheduled_session'], $inputs['books'], $inputs['contexts'],
                $inputs['nominal_closes'], $inputs['confirmation'], $inputs['provenance']);
            $close = $engine->commit($prepared);
        } elseif (!$fresh) { $report['errors'][] = 'candidate_external_signal_stale'; }
        if (!$fresh && $reconciliation['ok']) {
            $updates = [];
            foreach ($ledger->views->positions($runId) as $name => $bookPositions) {
                foreach ($bookPositions as $symbol => $p) {
                    $last = $ledger->checkpoint($runId, 'protection:' . $name . ':' . $symbol)['payload']['last_close_date'] ?? '';
                    $observed = (new DateTimeImmutable($p['updated_at']))->setTimezone(new DateTimeZone('America/New_York'))->format('Y-m-d');
                    if ($last < $session['signal_date'] && $observed <= $session['signal_date']) { $updates[$name][$symbol] = true; }
                }
            }
            if ($updates !== []) {
                try {
                    $symbols = array_values(array_unique(array_merge(...array_map('array_keys', array_values($updates)))));
                    $rawCloses = CandidateDataSnapshot::rawCloses($root, $session['signal_date'], $symbols);
                    $protection = new \FulltimeTrading\Paper\CandidateProtection($ledger);
                    foreach ($updates as $name => $symbols) {
                        foreach ($symbols as $symbol => $_) { $protection->completedClose($runId, $name, $symbol, $session['signal_date'], $rawCloses[$symbol]); }
                    }
                } catch (Throwable) { $report['errors'][] = 'candidate_protective_close_unavailable'; }
            }
        }
        if ($close !== null) {
            $closeKey = 'portfolio-close:' . $runId . ':' . $close['date'];
            $report['notification_schedule']['close_status_key'] = $closeKey;
            if ($fresh) {
                $notificationSignal = ['as_of' => $close['date'], 'intended_session' => $close['scheduled_session'],
                    'decision_sha256' => hash('sha256', CandidateOrder::json($close))];
                $openSchedule = \FulltimeTrading\Trading\TacticalPortfolioNotificationSchedule::openStatus($clock, $account, $now, '09:35', $notificationSignal);
                if ($openSchedule !== null) {
                    $report['notification_schedule']['open_status_key'] = $openSchedule['key'];
                    $report['notification_schedule']['open_status_required_key'] = $openSchedule['required_key'];
                    if ($telegram) {
                        $ledger->views->queueNotification($openSchedule['key'], CandidateMessages::opening($run, $account, $positions, $orders,
                            $openSchedule['session_date'], $openSchedule['catch_up']), ['run_id' => $runId]);
                    }
                }
                $weeklySchedule = \FulltimeTrading\Trading\TacticalPortfolioNotificationSchedule::weeklyCloseStatus($clock, $account, $notificationSignal, $now);
                if ($weeklySchedule !== null) {
                    $report['notification_schedule']['weekly_status_key'] = $weeklySchedule['key'];
                    $report['notification_schedule']['weekly_status_session'] = $weeklySchedule['session_date'];
                    $report['notification_schedule']['weekly_status_catch_up'] = $weeklySchedule['catch_up'];
                    if ($telegram && !$ledger->views->notificationDelivered($weeklySchedule['key'])) {
                        $weekStart = new DateTimeImmutable($weeklySchedule['week_start'] . ' 00:00:00', new DateTimeZone('America/New_York'));
                        $observations = \FulltimeTrading\Paper\CandidateSnapshotReader::read(realpath($dbPath), $runId, $weekStart->format(DATE_ATOM), $now, (float) $run['initial_equity']);
                        $week = \FulltimeTrading\Trading\TacticalPortfolioWeeklySummary::fromSnapshots($observations['snapshots'], $weeklySchedule['session_date'], (float) $account['equity']);
                        if ($week !== null) { $ledger->views->queueNotification($weeklySchedule['key'], CandidateMessages::weekly($week, $close), ['run_id' => $runId]); }
                    }
                }
            }
            if ($telegram) {
                $ledger->views->queueNotification($closeKey, CandidateMessages::close($run, $close, $account, $positions, $orders), ['run_id' => $runId]);
                $notificationKeys[] = $closeKey;
                if (!empty($run['activated_at'])) {
                    $key = 'activated:' . $runId;
                    $ledger->views->queueNotification($key, 'Активирован новый PAPER-контур: максимум + стоп 12%, 12 частей. Старый запуск сохранён. Начальный капитал $'
                        . number_format((float) $run['initial_equity'], 2, '.', '') . '. Ручная оценка live возможна не ранее ' . $run['live_review_not_before']
                        . ' и только после остальных проверок. Live не включён.', ['run_id' => $runId]);
                    $notificationKeys[] = $key;
                }
            }
            $protected = true;
            foreach ($ledger->views->positions($runId) as $name => $bookPositions) {
                foreach ($bookPositions as $symbol => $p) {
                    $covered = 0;
                    foreach ($ledger->active($runId, $name) as $i) {
                        if ($i['symbol'] === $symbol && $i['leg'] === 'protective_stop' && !isset($i['payload']['cancel_request'])
                            && in_array($i['status'], ['new', 'accepted', 'done_for_day'], true)) {
                            $covered += (float) $i['requested_qty'] - (float) $i['cumulative_filled_qty'];
                        }
                    }
                    $protected = $protected && abs($covered - (float) $p['qty']) < 1e-8;
                }
            }
            $window = (new TacticalRotationExecutionWindow())->resolve($close['scheduled_session'], $now, $close['date'], $session['market_open']);
            $window['candidate_preopen_stop_transition_allowed'] = $now->format('Y-m-d') === $close['scheduled_session']
                && $now->format('H:i') >= '09:15' && $now->format('H:i') < '09:28' && !$session['market_open'];
            $outboxReady = $telegram && $commissioned && $ledger->views->notificationDelivered($closeKey);
            foreach ($ledger->views->pendingNotifications(100) as $pending) {
                if (($pending['payload']['run_id'] ?? '') === $runId) { $outboxReady = false; }
            }
            $gates = ['identity' => true, 'account' => true, 'reconciliation' => $reconciliation['ok'], 'paper_admission' => $outboxReady,
                'fresh_signal' => $fresh, 'risk_capacity' => (float) $account['buying_power'] > 0, 'all_positions_protected' => $protected];
            $report['entry_submission_enabled'] = $submit && $outboxReady && $run['status'] === 'active' && $fresh && $reconciliation['ok'] && $protected;
            $report['window'] = $window;
            if ($submit) {
                $action = (new CandidateExecutionPlan($ledger))->next($runId, $close, $window, $gates); $report['action'] = $action;
                if (in_array($action['action'], ['submit', 'cancel'], true)) {
                    $before = $ledger->intent($action['decision_id']);
                    if ($action['action'] === 'submit') {
                        $asset = $client->asset($before['symbol']);
                        if (($asset['tradable'] ?? null) !== true || ($asset['status'] ?? null) !== 'active') { throw new RuntimeException('candidate_asset_not_tradable'); }
                        if ($before['side'] === 'buy') {
                            $latestAccount = $client->account(); AlpacaPaperAccountGuard::validateConfigured($latestAccount);
                            $reference = $close['plans'][$before['sleeve_id']]['reference_prices'][$before['symbol']] ?? null;
                            if (!is_numeric($reference) || $reference <= 0) { throw new RuntimeException('candidate_reference_price_missing'); }
                            $notional = (float) $before['requested_qty'] * $reference;
                            $referenceNav = $referenceExposure = 0.;
                            foreach ($close['plans'] as $p) {
                                $referenceNav += $p['reference_nav'];
                                if ($p['target_quantities'] === null) { $referenceExposure += $p['target']['current_gross'] * $p['reference_nav']; }
                                else { foreach ($p['target_quantities'] as $s => $q) { $referenceExposure += $q * $p['reference_prices'][$s]; } }
                            }
                            $exposure = array_sum(array_map(static fn ($p): float => abs((float) $p['market_value']), $client->positions()));
                            if ($referenceNav <= 0 || $referenceExposure / $referenceNav > $candidate['entry_limits']['maximum_reference_gross']
                                || $notional > (float) $latestAccount['buying_power'] * (1 - $candidate['entry_limits']['buying_power_reserve_fraction'])
                                || ($exposure + $notional) / (float) $latestAccount['equity'] > $candidate['entry_limits']['maximum_projected_gross']) {
                                throw new RuntimeException('candidate_projected_risk_capacity_exceeded');
                            }
                        }
                        $submitNow = new DateTimeImmutable('now', new DateTimeZone('America/New_York'));
                        $submitSession = CandidateSession::resolve($calendar, $client->clock(), $submitNow);
                        $submitWindow = (new TacticalRotationExecutionWindow())->resolve($close['scheduled_session'], $submitNow, $close['date'], $submitSession['market_open']);
                        $tif = $before['payload']['body']['time_in_force'];
                        if (($tif === 'opg' && !$submitWindow['opg_submit_allowed'])
                            || ($tif === 'day' && !($before['side'] === 'buy' ? $submitWindow['rotation_reentry_allowed'] : $submitWindow['risk_exit_day_allowed']))) {
                            throw new RuntimeException('candidate_execution_window_closed_before_post');
                        }
                        $observation = $reconciler->submit($before['decision_id']);
                        $report['submitted'][] = ['decision_id' => $before['decision_id'], 'result' => $observation['status']];
                    } else { $observation = $reconciler->cancel($before['decision_id'], $action['reason']); }
                    $recordFill($before, $observation['intent']);
                    if (in_array($observation['status'], ['ambiguous_submission', 'unresolved_lookup', 'cancel_stuck'], true)) {
                        $report['errors'][] = 'candidate_' . $observation['status'];
                    }
                } elseif ($action['action'] === 'refresh') { $reconciler->refresh($action['decision_id']); }
            }
            $report['signal']['sleeves'] = array_map(static fn ($p): array => $p['target'], $close['plans']);
            $report['signal']['targets'] = $report['signal']['sleeves'];
        }
        if ($submit) {
            $account = $client->account(); AlpacaPaperAccountGuard::validateConfigured($account);
            $positions = $client->positions(); $orders = $client->openOrders(); $run = $ledger->run($runId);
            $finalReconciliation = CandidateAccountReconciliation::inspect($ledger->views->expectedBrokerPositions($runId),
                array_sum(array_column($ledger->views->sleeves($runId), 'cash')), $account, $positions, $orders, $ledger->active($runId));
            if (!$finalReconciliation['ok']) { $report['errors'] = [...$report['errors'], ...$finalReconciliation['errors']]; }
            $report['reconciliation'] = $finalReconciliation; $report['run_status'] = $run['status'];
        }
        if ($run['status'] === 'paused') { $report['errors'][] = 'candidate_run_paused'; }
        // A new fill must have acknowledged native coverage on a subsequent reconciled cycle.
        if ($report['errors'] !== [] || $report['submitted'] !== []) { $report['entry_submission_enabled'] = false; }
        $report['reconciliation_status'] = $report['errors'] === [] ? ($activated ? 'activated_flat_paper' : 'reconciled') : 'blocked_candidate_cycle';
        $snapshot = ['captured_at' => gmdate(DATE_ATOM), 'equity' => $account['equity'], 'cash' => $account['cash'],
            'buying_power' => $account['buying_power'], 'positions' => $positions, 'open_orders' => $orders];
    } else { $report['reconciliation_status'] = 'transition_flat_stability_wait'; }
    $report['broker'] = ['equity' => (float) $account['equity'], 'cash' => (float) $account['cash'],
        'buying_power' => (float) $account['buying_power'], 'positions' => $positions, 'open_orders' => $orders];
} catch (Throwable $e) {
    $report['errors'][] = $e->getMessage(); $report['reconciliation_status'] = 'blocked_candidate_cycle';
    $report['entry_submission_enabled'] = false;
    if ($ledger !== null && isset($runId)) {
        (new \FulltimeTrading\Paper\CandidateEntryBatch($ledger))->abort($runId, $e->getMessage());
    }
} finally {
    $report['generated_at'] = gmdate(DATE_ATOM); $report['errors'] = array_values(array_unique($report['errors']));
    if ($telegram && $ledger !== null && isset($runId)) {
        try {
            if ($report['errors'] !== []) {
                $codes = $report['errors']; sort($codes);
                $key = 'candidate-error:' . $runId . ':' . gmdate('Y-m-d') . ':' . substr(hash('sha256', CandidateOrder::json($codes)), 0, 16);
                $ledger->views->queueNotification($key, CandidateMessages::error($codes), ['run_id' => $runId]);
            }
            $notifier = TelegramNotifier::fromEnv($http) ?? throw new RuntimeException('candidate_telegram_unconfigured');
            foreach ($ledger->views->pendingNotifications(100) as $notification) {
                if (($notification['payload']['run_id'] ?? '') !== $runId) { continue; }
                $ledger->views->markNotificationAttempted($notification['notification_key']);
                $sent = $notifier->sendMessage($notification['message']);
                $ledger->views->markNotificationDelivered($notification['notification_key'], $sent['message_id'] ?? null);
            }
        } catch (Throwable) {
            $report['errors'][] = 'candidate_telegram_delivery_failed'; $report['entry_submission_enabled'] = false;
            $report['reconciliation_status'] = 'blocked_candidate_cycle';
        }
    }
    $report['entry_eligibility'] = ['allowed_now' => false, 'executable_buy_legs' => [],
        'blocked_reasons' => [['code' => $report['errors'][0] ?? (!empty($report['submitted']) ? 'orders_in_progress'
            : ((isset($window) && !$window['opg_submit_allowed'] && !$window['rotation_reentry_allowed']) ? 'entry_window_not_open' : 'no_actionable_signal')),
            'text' => 'Отправка и исполнение проверяются отдельно от целей модели.']]];
    if ($ledger !== null && isset($snapshot, $runId)) {
        $ledger->views->saveSnapshot($runId, $snapshot + ['reconciliation_status' => $report['reconciliation_status'],
            'payload' => ['errors' => $report['errors'], 'market_date' => $now->format('Y-m-d')]]);
        if (($ledger->run($runId)['status'] ?? null) === 'active') { $ledger->views->setRunError($runId, $report['errors'][0] ?? null); }
    }
    AlgorithmTrendResearch::write($output, $report);
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
    if ($temporary !== null) {
        unset($ledger);
        foreach ([$temporary, $temporary . '-wal', $temporary . '-shm'] as $file) { if (is_file($file)) { unlink($file); } }
    }
}
exit($report['errors'] === [] ? 0 : 2);
