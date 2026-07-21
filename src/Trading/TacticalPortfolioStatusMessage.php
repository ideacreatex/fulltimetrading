<?php

declare(strict_types=1);

namespace FulltimeTrading\Trading;

/** Builds a compact Russian portfolio report that stays below Telegram limits. */
final class TacticalPortfolioStatusMessage
{
    private const TELEGRAM_SAFE_BYTES = 3800;

    private const SLEEVE_LABELS = [
        'dynamic_loo10' => 'Основной 60%',
        'qqq200_full' => 'QQQ200 13.3%',
        'spy200_full' => 'SPY200 13.3%',
        'qqq150_ex_crypto' => 'QQQ150 без crypto 13.3%',
    ];

    /**
     * @param array<string,mixed> $run
     * @param array<string,mixed> $signal
     * @param list<array<string,mixed>> $activeIntents
     * @param list<string> $errors
     * @param list<array<string,mixed>> $brokerPositions
     * @param list<array<string,mixed>> $tacticalSleeves
     * @return array{
     *   allowed_now:bool,
     *   actions:list<array<string,mixed>>,
     *   executable_buy_legs:list<array<string,mixed>>,
     *   blocked_reasons:list<array{code:string,text:string}>,
     *   candidates:list<array<string,mixed>>,
     *   execution_window_status:?string
     * }
     */
    public static function entryEligibility(
        array $run,
        string $reconciliationStatus,
        array $signal,
        array $activeIntents,
        array $errors,
        array $brokerPositions,
        \DateTimeImmutable $nowNewYork,
        ?array $executionWindow = null,
        array $executableLegs = [],
        array $tacticalSleeves = [],
    ): array {
        $status = strtolower((string) ($run['status'] ?? 'unknown'));
        $reasons = [];
        if ($status === 'transition') {
            $symbols = array_values(array_filter(array_map(
                static fn (array $position): string => strtoupper(trim((string) ($position['symbol'] ?? ''))),
                $brokerPositions,
            )));
            $suffix = $symbols === [] ? '' : ' (' . implode(', ', $symbols) . ')';
            $reasons[] = [
                'code' => 'run_transition',
                'text' => 'переход: текущие legacy-позиции ещё под прежними правилами' . $suffix,
            ];
        } elseif ($status === 'paused') {
            $reasons[] = ['code' => 'run_paused', 'text' => 'hybrid поставлен на паузу защитным правилом'];
        } elseif ($status !== 'active') {
            $reasons[] = ['code' => 'run_not_active', 'text' => 'hybrid ещё не активирован'];
        }
        if ($activeIntents !== []) {
            $reasons[] = [
                'code' => 'orders_in_progress',
                'text' => 'есть исполняемая или сверяемая заявка; новый дополнительный риск запрещён',
            ];
        }
        if ($errors !== []) {
            $reasons[] = [
                'code' => 'runtime_error',
                'text' => 'защитная блокировка: ' . implode(', ', array_slice(array_values(array_unique($errors)), 0, 3)),
            ];
        }

        $tacticalOwnedBySleeve = [];
        foreach ($tacticalSleeves as $sleeve) {
            if (!is_array($sleeve)) {
                continue;
            }
            $sleeveId = trim((string) ($sleeve['sleeve_id'] ?? ''));
            if ($sleeveId === '') {
                continue;
            }
            foreach ((array) ($sleeve['positions'] ?? []) as $position) {
                if (!is_array($position) || (float) ($position['qty'] ?? 0.0) <= 1.0e-9) {
                    continue;
                }
                $ownedSymbol = strtoupper(trim((string) ($position['symbol'] ?? '')));
                if ($ownedSymbol !== '') {
                    $tacticalOwnedBySleeve[$sleeveId][$ownedSymbol] = (float) $position['qty'];
                }
            }
        }

        $executableBuyTargets = [];
        $rotationReentryBuys = [];
        foreach ($executableLegs as $leg) {
            if (!is_array($leg)
                || strtolower((string) ($leg['side'] ?? '')) !== 'buy'
                || (float) ($leg['requested_qty'] ?? 0.0) <= 0.0) {
                continue;
            }
            $legSleeve = trim((string) ($leg['sleeve_id'] ?? ''));
            $legSymbol = strtoupper(trim((string) ($leg['symbol'] ?? '')));
            if ($legSleeve !== '' && $legSymbol !== '') {
                $targetKey = $legSleeve . '|' . $legSymbol;
                $executableBuyTargets[$targetKey] = true;
                if ((array) ($leg['payload']['required_exit_decision_ids'] ?? []) !== []) {
                    $rotationReentryBuys[$targetKey] = true;
                }
            }
        }

        $actions = [];
        $candidates = [];
        foreach ((array) ($signal['targets'] ?? []) as $sleeveId => $target) {
            if (!is_array($target)) {
                continue;
            }
            $candidate = strtoupper(trim((string) ($target['ranked_symbol'] ?? '')));
            if ($candidate !== '') {
                $candidates[] = [
                    'sleeve_id' => (string) $sleeveId,
                    'symbol' => $candidate,
                    'ranked_gross' => (float) ($target['ranked_gross'] ?? 0.0),
                    'cooldown_left' => (int) ($target['cooldown_left'] ?? 0),
                    'watch_only' => true,
                ];
            }

            if (($target['due'] ?? false) !== true) {
                continue;
            }
            $action = strtolower((string) ($target['action'] ?? 'hold'));
            $symbol = strtoupper(trim((string) ($target['symbol'] ?? '')));
            $currentSymbol = strtoupper(trim((string) ($target['current_symbol'] ?? '')));
            $gross = (float) ($target['gross'] ?? 0.0);
            $currentGross = (float) ($target['current_gross'] ?? 0.0);
            $ownedSymbols = array_keys($tacticalOwnedBySleeve[(string) $sleeveId] ?? []);
            $ownedTarget = in_array($symbol, $ownedSymbols, true);
            $isRotationReentry = isset($rotationReentryBuys[(string) $sleeveId . '|' . $symbol]);
            if ($isRotationReentry
                && in_array($action, ['rebalance', 'resize_or_hold'], true)
                && $symbol !== ''
                && $gross > 0.0) {
                $actions[] = [
                    'type' => 'rotation',
                    'sleeve_id' => (string) $sleeveId,
                    'symbol' => $symbol,
                    'target_gross' => $gross,
                    // The sell fill has already removed the ledger row. The
                    // model shadow may name a different stale symbol, so do
                    // not present it as broker fact without the exit intent.
                    'from_symbol' => 'закрытая позиция',
                ];
            } elseif (in_array($action, ['rebalance', 'resize_or_hold'], true)
                && $symbol !== ''
                && $gross > 0.0
                && $ownedSymbols !== []
                && !$ownedTarget) {
                $actions[] = [
                    'type' => 'rotation',
                    'sleeve_id' => (string) $sleeveId,
                    'symbol' => $symbol,
                    'target_gross' => $gross,
                    'from_symbol' => implode('+', $ownedSymbols),
                ];
            } elseif (in_array($action, ['rebalance', 'resize_or_hold'], true)
                && $symbol !== ''
                && $gross > 0.0
                && (!$ownedTarget
                    || $gross > $currentGross + 1.0e-9
                    || isset($executableBuyTargets[(string) $sleeveId . '|' . $symbol]))) {
                $actions[] = [
                    'type' => $ownedTarget ? 'add' : 'new_entry',
                    'sleeve_id' => (string) $sleeveId,
                    'symbol' => $symbol,
                    'target_gross' => $gross,
                    'current_gross' => $currentGross,
                ];
            }
        }

        $executableBuyLegs = [];
        foreach ($executableLegs as $leg) {
            if (!is_array($leg)
                || strtolower((string) ($leg['side'] ?? '')) !== 'buy'
                || (float) ($leg['requested_qty'] ?? 0.0) <= 0.0) {
                continue;
            }
            $executableBuyLegs[] = [
                'sleeve_id' => (string) ($leg['sleeve_id'] ?? ''),
                'symbol' => strtoupper((string) ($leg['symbol'] ?? '')),
                'qty' => (float) ($leg['requested_qty'] ?? 0.0),
                'time_in_force' => strtolower((string) ($leg['payload']['time_in_force'] ?? '')),
                'has_exit_dependency' => (array) ($leg['payload']['required_exit_decision_ids'] ?? []) !== [],
            ];
        }

        $intendedSession = trim((string) ($signal['intended_session'] ?? ''));
        $today = $nowNewYork->format('Y-m-d');
        if ($actions !== [] && $intendedSession === '') {
            $reasons[] = ['code' => 'session_unknown', 'text' => 'торговая сессия сигнала не подтверждена'];
        } elseif ($actions !== [] && $intendedSession < $today) {
            $reasons[] = ['code' => 'signal_expired', 'text' => 'окно сигнала прошло; догонять цену запрещено'];
        }
        if ($actions === []) {
            $reasons[] = ['code' => 'no_actionable_signal', 'text' => 'сигнал HOLD: нового входа или докупки нет'];
        } elseif ($status === 'active' && $activeIntents === [] && $errors === []) {
            $windowStatus = strtolower((string) ($executionWindow['status'] ?? ''));
            $buyWindowAllowed = ($executionWindow['opg_submit_allowed'] ?? false) === true
                || ($executionWindow['rotation_reentry_allowed'] ?? false) === true;
            $hasRotation = array_filter(
                $actions,
                static fn (array $action): bool => ($action['type'] ?? '') === 'rotation',
            ) !== [];
            if ($executionWindow === null) {
                $reasons[] = [
                    'code' => 'reconciliation_not_ready',
                    'text' => 'исполняемый план брокера не подтверждён: '
                        . ($reconciliationStatus !== '' ? $reconciliationStatus : 'unknown'),
                ];
            } elseif ($executableBuyLegs === [] && $hasRotation) {
                $reasons[] = [
                    'code' => 'rotation_exit_first',
                    'text' => 'ротация: сначала должен исполниться выход; replacement-buy появится только после fill',
                ];
            } elseif ($executableBuyLegs === []) {
                $reasons[] = self::windowReason($windowStatus, $intendedSession);
            } elseif (!$buyWindowAllowed) {
                $reasons[] = self::windowReason($windowStatus, $intendedSession);
            }
        }

        $windowAllowsBuy = ($executionWindow['opg_submit_allowed'] ?? false) === true
            || ($executionWindow['rotation_reentry_allowed'] ?? false) === true;

        return [
            'allowed_now' => $executableBuyLegs !== [] && $windowAllowsBuy && $reasons === [],
            'actions' => $actions,
            'executable_buy_legs' => $executableBuyLegs,
            'blocked_reasons' => self::uniqueReasons($reasons),
            'candidates' => $candidates,
            'execution_window_status' => $executionWindow['status'] ?? null,
        ];
    }

    /**
     * @param array<string,mixed> $account
     * @param list<array<string,mixed>> $brokerPositions
     * @param list<array<string,mixed>> $openOrders
     * @param array<string,array<string,mixed>> $legacyStates
     * @param array<string,mixed> $run
     * @param array<string,mixed> $signal
     * @param list<array<string,mixed>> $sleeves
     * @param array<string,mixed> $eligibility
     * @param list<string> $errors
     * @param array<string,mixed> $clock
     * @param array<string,mixed> $stopPolicy
     * @param array<string,mixed> $reportMeta
     */
    public static function build(
        string $phase,
        \DateTimeImmutable $nowNewYork,
        array $account,
        array $brokerPositions,
        array $openOrders,
        array $legacyStates,
        array $run,
        string $reconciliationStatus,
        array $signal,
        array $sleeves,
        array $eligibility,
        array $errors,
        array $clock,
        array $stopPolicy = [],
        array $reportMeta = [],
    ): string {
        $title = match ($phase) {
            'open' => '🌅 ОТКРЫТИЕ • ALPACA PAPER',
            'close' => '🌙 ЗАКРЫТИЕ / ПЛАН • ALPACA PAPER',
            default => '📌 СТАТУС • ALPACA PAPER',
        };
        $snapshotTime = $nowNewYork;
        try {
            if (trim((string) ($clock['timestamp'] ?? '')) !== '') {
                $snapshotTime = (new \DateTimeImmutable((string) $clock['timestamp']))->setTimezone(
                    new \DateTimeZone('America/New_York'),
                );
            }
        } catch (\Throwable) {
            $snapshotTime = $nowNewYork;
        }
        $lines = [$title . ' • ' . $snapshotTime->format('Y-m-d H:i') . ' NY'];
        if (($reportMeta['catch_up'] ?? false) === true) {
            $lines[] = '⚠️ CATCH-UP: сообщение восстановлено после штатного времени; цифры — текущий снимок Alpaca.';
        }
        $lines[] = 'Alpaca: PAPER / ' . strtoupper((string) ($account['status'] ?? 'unknown'));

        $runStatus = strtolower((string) ($run['status'] ?? 'unknown'));
        $lines[] = 'Режим: ' . match ($runStatus) {
            'transition' => 'ПЕРЕХОД — новые hybrid-входы заблокированы',
            'active' => 'ACTIVE — исполнение только по правилам hybrid-v4',
            'paused' => 'PAUSED — новые входы заблокированы',
            default => strtoupper($runStatus),
        };
        $lines[] = 'Сверка: ' . ($reconciliationStatus !== '' ? $reconciliationStatus : 'unknown')
            . ' | рынок: ' . (($clock['is_open'] ?? false) ? 'ОТКРЫТ' : 'ЗАКРЫТ');
        if ($runStatus === 'transition' && empty($run['activated_at'])) {
            $tacticalCash = array_sum(array_map(
                static fn (array $sleeve): float => (float) ($sleeve['cash'] ?? 0.0),
                $sleeves,
            ));
            $tacticalNav = array_sum(array_map(
                static fn (array $sleeve): float => (float) ($sleeve['nav'] ?? 0.0),
                $sleeves,
            ));
            $lines[] = sprintf(
                'Hybrid-капитал ещё не активирован: tactical cash %s / nav %s — техническое состояние, не потеря денег.',
                self::money($tacticalCash),
                self::money($tacticalNav),
            );
        }

        $equity = (float) ($account['equity'] ?? 0.0);
        $cash = (float) ($account['cash'] ?? 0.0);
        $buyingPower = (float) ($account['buying_power'] ?? 0.0);
        $longMarketValue = abs((float) ($account['long_market_value'] ?? 0.0));
        $shortMarketValue = abs((float) ($account['short_market_value'] ?? 0.0));
        $grossValue = $longMarketValue + $shortMarketValue;
        if ($grossValue <= 0.0 && $brokerPositions !== []) {
            $grossValue = array_sum(array_map(
                static fn (array $position): float => abs((float) ($position['market_value'] ?? 0.0)),
                $brokerPositions,
            ));
        }
        $lines[] = '';
        $lines[] = 'СЧЁТ';
        $lines[] = sprintf(
            'Equity %s | cash %s | buying power %s',
            self::money($equity),
            self::money($cash),
            self::money($buyingPower),
        );
        $lines[] = sprintf(
            'Нагрузка %.1f%% equity | свободный cash %.1f%%',
            $equity > 0.0 ? 100.0 * $grossValue / $equity : 0.0,
            $equity > 0.0 ? 100.0 * $cash / $equity : 0.0,
        );
        $openProfit = 0.0;
        $openCostBasis = 0.0;
        foreach ($brokerPositions as $position) {
            $positionProfit = (float) ($position['unrealized_pl'] ?? 0.0);
            $openProfit += $positionProfit;
            $positionCostBasis = abs((float) ($position['cost_basis'] ?? 0.0));
            if ($positionCostBasis <= 0.0) {
                $positionCostBasis = abs((float) ($position['market_value'] ?? 0.0) - $positionProfit);
            }
            $openCostBasis += $positionCostBasis;
        }
        if ($brokerPositions !== []) {
            $lines[] = sprintf(
                'Открытый P/L %s (%+.2f%%)',
                self::signedMoney($openProfit),
                $openCostBasis > 0.0 ? 100.0 * $openProfit / $openCostBasis : 0.0,
            );
        }
        $lines[] = 'Buying power — лимит брокера, а не разрешение стратегии на новую покупку.';
        $lastEquity = (float) ($account['last_equity'] ?? 0.0);
        if ($lastEquity > 0.0) {
            $daily = $equity - $lastEquity;
            $lines[] = sprintf('День: %s (%+.2f%%)', self::signedMoney($daily), 100.0 * $daily / $lastEquity);
        }

        $lines[] = '';
        $lines[] = 'МОЖНО ОТКРЫТЬ / ДОКУПИТЬ СЕЙЧАС: '
            . (!empty($eligibility['allowed_now']) ? 'ДА, только боту в штатное окно' : 'НЕТ');
        foreach ((array) ($eligibility['actions'] ?? []) as $action) {
            if (!is_array($action)) {
                continue;
            }
            $type = (string) ($action['type'] ?? '');
            if ($type === 'rotation') {
                $lines[] = sprintf(
                    '• РОТАЦИЯ %s → %s, цель %.1f%% gross (%s)',
                    (string) ($action['from_symbol'] ?? ''),
                    (string) ($action['symbol'] ?? ''),
                    100.0 * (float) ($action['target_gross'] ?? 0.0),
                    self::SLEEVE_LABELS[(string) ($action['sleeve_id'] ?? '')] ?? (string) ($action['sleeve_id'] ?? ''),
                );
            } else {
                $lines[] = sprintf(
                    '• %s %s → цель %.1f%% gross (%s)',
                    $type === 'add' ? 'ДОКУПКА' : 'НОВЫЙ ВХОД',
                    (string) ($action['symbol'] ?? ''),
                    100.0 * (float) ($action['target_gross'] ?? 0.0),
                    self::SLEEVE_LABELS[(string) ($action['sleeve_id'] ?? '')] ?? (string) ($action['sleeve_id'] ?? ''),
                );
            }
        }
        if (trim((string) ($eligibility['execution_window_status'] ?? '')) !== '') {
            $lines[] = 'Execution window: ' . (string) $eligibility['execution_window_status'];
        }
        foreach ((array) ($eligibility['blocked_reasons'] ?? []) as $reason) {
            if (is_array($reason) && trim((string) ($reason['text'] ?? '')) !== '') {
                $lines[] = '• ' . (string) $reason['text'];
            }
        }
        $lines[] = 'Кандидат/лидер — это наблюдение, не команда на покупку.';
        if ($errors !== []) {
            $lines[] = '⚠️ Ошибки: ' . implode(', ', array_slice(array_values(array_unique($errors)), 0, 3));
        }

        $lines[] = '';
        $lines[] = 'ОТКРЫТЫЕ ПОЗИЦИИ';
        if ($brokerPositions === []) {
            $lines[] = 'Нет.';
        } else {
            usort($brokerPositions, static fn (array $a, array $b): int => strcmp(
                (string) ($a['symbol'] ?? ''),
                (string) ($b['symbol'] ?? ''),
            ));
            $visiblePositions = array_slice($brokerPositions, 0, 8);
            $mentalStopShown = false;
            $hardStopShown = false;
            foreach ($visiblePositions as $position) {
                $symbol = strtoupper((string) ($position['symbol'] ?? ''));
                $qty = (float) ($position['qty'] ?? 0.0);
                $average = (float) ($position['avg_entry_price'] ?? 0.0);
                $current = (float) ($position['current_price'] ?? 0.0);
                $unrealized = (float) ($position['unrealized_pl'] ?? 0.0);
                $unrealizedPct = 100.0 * (float) ($position['unrealized_plpc'] ?? 0.0);
                $todayPct = 100.0 * (float) ($position['change_today'] ?? 0.0);
                $legacy = self::activeLegacyState($legacyStates, $symbol, $qty);
                $owner = $runStatus === 'transition' || $legacy !== null ? 'legacy' : 'hybrid';
                $lines[] = sprintf(
                    '%s • %.4g шт. • %s',
                    $symbol,
                    $qty,
                    $owner,
                );
                $lines[] = sprintf(
                    '  вход %s → сейчас %s | P/L %s (%+.2f%%) | сегодня %+.2f%%',
                    self::money($average),
                    self::money($current),
                    self::signedMoney($unrealized),
                    $unrealizedPct,
                    $todayPct,
                );
                $stop = is_array($legacy) ? (float) ($legacy['stop_price'] ?? 0.0) : 0.0;
                if ($stop > 0.0) {
                    $distance = $current > 0.0 ? 100.0 * ($current / $stop - 1.0) : 0.0;
                    $modelPosition = is_array($legacy['payload']['model_position'] ?? null)
                        ? $legacy['payload']['model_position']
                        : null;
                    $breakEvenArmed = !empty($legacy['break_even_armed'])
                        || !empty($modelPosition['break_even_armed']);
                    $stopMode = PaperMonitorDecisionGuard::evaluateStop(
                        $legacy,
                        $stopPolicy,
                        $current,
                        null,
                        $modelPosition,
                    )['mode'];
                    if ($stopMode === 'hard_intraday') {
                        $hardStopShown = true;
                        $lines[] = sprintf(
                            '  hard intraday monitor-stop %s | запас %+.2f%% | БУ %s | частичная фиксация %s',
                            self::money($stop),
                            $distance,
                            $breakEvenArmed ? 'ДА' : 'нет',
                            !empty($legacy['partial_done']) ? 'ДА' : 'нет',
                        );
                    } else {
                        $mentalStopShown = true;
                        $lines[] = sprintf(
                            '  ментальный close-stop %s | запас %+.2f%% | БУ %s | частичная фиксация %s',
                            self::money($stop),
                            $distance,
                            $breakEvenArmed ? 'ДА' : 'нет',
                            !empty($legacy['partial_done']) ? 'ДА' : 'нет',
                        );
                    }
                    $breakEvenTrigger = (float) ($legacy['break_even_trigger_price'] ?? 0.0);
                    if ($breakEvenTrigger > 0.0 && !$breakEvenArmed) {
                        $lines[] = '  триггер БУ ' . self::money($breakEvenTrigger);
                    }
                }
            }
            if (count($brokerPositions) > count($visiblePositions)) {
                $lines[] = '… ещё позиций: ' . (count($brokerPositions) - count($visiblePositions));
            }
            if ($mentalStopShown) {
                $lines[] = 'Close-stop не стоит у брокера: выход только после подтверждённого дневного закрытия ниже стопа.';
            }
            if ($hardStopShown) {
                $lines[] = 'Hard monitor-stop тоже не является standing stop-order: монитор реагирует intraday и отправляет market-exit.';
            }
        }

        $lines[] = '';
        $lines[] = 'ОТКРЫТЫЕ ЗАЯВКИ';
        if ($openOrders === []) {
            $lines[] = 'Нет.';
        } else {
            $visibleOrders = array_slice($openOrders, 0, 8);
            foreach ($visibleOrders as $order) {
                $lines[] = sprintf(
                    '%s %s %.4g (исполнено %.4g) • %s/%s',
                    strtoupper((string) ($order['side'] ?? '')),
                    strtoupper((string) ($order['symbol'] ?? '')),
                    (float) ($order['qty'] ?? 0.0),
                    (float) ($order['filled_qty'] ?? 0.0),
                    (string) ($order['status'] ?? 'unknown'),
                    (string) ($order['time_in_force'] ?? 'unknown'),
                );
            }
            if (count($openOrders) > count($visibleOrders)) {
                $lines[] = '… ещё заявок: ' . (count($openOrders) - count($visibleOrders));
            }
        }

        $lines[] = '';
        $lines[] = sprintf(
            'СИГНАЛ ПО РУКАВАМ • close %s → план на %s',
            (string) ($signal['as_of'] ?? 'unknown'),
            (string) ($signal['intended_session'] ?? 'следующую сессию'),
        );
        foreach ((array) ($signal['targets'] ?? []) as $sleeveId => $target) {
            if (!is_array($target)) {
                continue;
            }
            $parts = [self::SLEEVE_LABELS[(string) $sleeveId] ?? (string) $sleeveId];
            $parts[] = self::actionLabel((string) ($target['action'] ?? 'unknown'));
            $currentSymbol = strtoupper(trim((string) ($target['current_symbol'] ?? '')));
            if ($currentSymbol !== '') {
                $parts[] = sprintf('модель %s %.1f%%', $currentSymbol, 100.0 * (float) ($target['current_gross'] ?? 0.0));
            } else {
                $parts[] = 'модель в кэше';
            }
            $candidate = strtoupper(trim((string) ($target['ranked_symbol'] ?? '')));
            if ($candidate !== '') {
                $parts[] = sprintf('кандидат %s %.1f%%', $candidate, 100.0 * (float) ($target['ranked_gross'] ?? 0.0));
            }
            $cooldown = (int) ($target['cooldown_left'] ?? 0);
            if ($cooldown > 0) {
                $parts[] = 'cooldown ' . $cooldown;
            }
            if (!empty($target['drawdown_rearm_pending'])) {
                $parts[] = 'ожидает re-arm после просадки';
            }
            if (!empty($target['risk_exit_pending'])) {
                $parts[] = 'risk-exit ожидается';
            }
            $executionStatus = trim((string) ($target['execution_status'] ?? ''));
            if ($executionStatus !== '') {
                $parts[] = $executionStatus;
            }
            if (!empty($target['no_chase'])) {
                $parts[] = 'без погони за ценой';
            }
            $parts[] = !empty($target['due']) ? 'действие запланировано' : 'действий нет';
            $lines[] = '• ' . implode(' | ', $parts);
        }

        $message = implode("\n", $lines);
        return self::fitTelegramLimit($message);
    }

    /** @param list<array{code:string,text:string}> $reasons @return list<array{code:string,text:string}> */
    private static function uniqueReasons(array $reasons): array
    {
        $seen = [];
        $result = [];
        foreach ($reasons as $reason) {
            $code = (string) ($reason['code'] ?? '');
            if ($code === '' || isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $result[] = $reason;
        }

        return $result;
    }

    private static function actionLabel(string $action): string
    {
        return match (strtolower($action)) {
            'hold' => 'HOLD',
            'hold_cash' => 'HOLD CASH',
            'rebalance' => 'ПЕРЕХОД',
            'resize_or_hold' => 'ИЗМЕНИТЬ / ДЕРЖАТЬ',
            'exit_to_cash' => 'ВЫХОД В КЭШ',
            default => strtoupper($action),
        };
    }

    /**
     * @param array<string,array<string,mixed>> $legacyStates
     * @return array<string,mixed>|null
     */
    private static function activeLegacyState(array $legacyStates, string $symbol, float $brokerQty): ?array
    {
        $state = $legacyStates[$symbol] ?? null;
        if (!is_array($state)
            || strtolower((string) ($state['status'] ?? '')) !== 'open'
            || (float) ($state['qty'] ?? 0.0) <= 0.0
            || abs((float) ($state['qty'] ?? 0.0) - $brokerQty) > 0.000001) {
            return null;
        }

        return $state;
    }

    /** @return array{code:string,text:string} */
    private static function windowReason(string $status, string $intendedSession): array
    {
        return match ($status) {
            'waiting_for_opg_window' => [
                'code' => 'entry_window_not_open',
                'text' => 'план на ' . ($intendedSession !== '' ? $intendedSession : 'следующую сессию')
                    . ' есть, вечерняя очередь ещё не открыта',
            ],
            'locked_for_open' => [
                'code' => 'entry_window_locked',
                'text' => 'OPG уже зафиксирован перед открытием; новые заявки не добавляются',
            ],
            'missed_no_chase', 'risk_exit_recovery_window', 'late_risk_exit_recovery_window' => [
                'code' => 'entry_window_closed',
                'text' => 'окно новых входов закрыто; разрешено только снижение риска, цену не догоняем',
            ],
            'awaiting_broker_open_confirmation' => [
                'code' => 'broker_open_unconfirmed',
                'text' => 'Alpaca ещё не подтвердил открытый рынок; новый риск заблокирован',
            ],
            default => [
                'code' => 'no_executable_buy_leg',
                'text' => 'planner не создал разрешённую buy-заявку (ledger/округление/последовательность исполнения)',
            ],
        };
    }

    private static function money(float $value): string
    {
        return '$' . number_format($value, 2, '.', ',');
    }

    private static function signedMoney(float $value): string
    {
        return ($value >= 0.0 ? '+' : '-') . self::money(abs($value));
    }

    private static function fitTelegramLimit(string $message): string
    {
        if (strlen($message) <= self::TELEGRAM_SAFE_BYTES) {
            return $message;
        }

        $suffix = "\n… отчёт сокращён; полный снимок сохранён в paper-status-export.";
        $message = substr($message, 0, self::TELEGRAM_SAFE_BYTES - strlen($suffix));
        while ($message !== '' && preg_match('//u', $message) !== 1) {
            $message = substr($message, 0, -1);
        }

        return rtrim($message) . $suffix;
    }
}
