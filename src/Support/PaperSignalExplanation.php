<?php

declare(strict_types=1);

namespace FulltimeTrading\Support;

/** Read-only presentation of evidence, never an execution authorization. */
final class PaperSignalExplanation
{
    public static function build(array $snapshot, \DateTimeImmutable $observedAt): array
    {
        $runtime = (array) ($snapshot['runtime'] ?? []);
        $tactical = (array) ($snapshot['tactical'] ?? []);
        $run = (array) ($tactical['run'] ?? []);
        $cycle = (array) ($tactical['cycle'] ?? []);
        $signal = (array) ($cycle['signal'] ?? []);
        $eligibility = (array) ($cycle['entry_eligibility'] ?? []);
        $account = $snapshot['alpaca']['account'] ?? null;
        $guard = (array) ($runtime['paper_account_guard'] ?? []);
        $verified = is_array($account)
            && ($runtime['paper_only'] ?? null) === true
            && ($runtime['paper_base_host_ok'] ?? null) === true;
        foreach (['account_reference_match', 'multiplier_match', 'shorting_match', 'active', 'unblocked'] as $key) {
            $verified = $verified && ($guard[$key] ?? null) === true;
        }
        $fresh = self::fresh($snapshot['generated_at'] ?? null, $observedAt, 180);
        $cycleFresh = self::fresh($cycle['generated_at'] ?? null, $observedAt, 180);
        $sameRun = is_string($run['run_id'] ?? null) && $run['run_id'] !== ''
            && ($cycle['run_id'] ?? null) === $run['run_id'];
        $positions = $snapshot['alpaca']['positions'] ?? null;
        $orders = $snapshot['alpaca']['open_orders'] ?? null;
        $brokerKnown = $verified && $fresh && is_array($positions) && is_array($orders)
            && ($snapshot['alpaca']['snapshot_complete'] ?? null) === true;
        $codes = [];
        foreach (array_merge((array) ($snapshot['errors'] ?? []), (array) ($cycle['errors'] ?? []),
            (array) ($tactical['health']['errors'] ?? []), [$run['last_error_code'] ?? null]) as $code) {
            if (is_string($code) && $code !== '') { $codes[$code] = true; }
        }
        foreach ((array) ($eligibility['blocked_reasons'] ?? []) as $reason) {
            if (is_array($reason) && is_string($reason['code'] ?? null)) { $codes[$reason['code']] = true; }
        }
        if (!$brokerKnown) { $codes['broker_snapshot_unverified'] = true; }
        if (!$fresh || !$cycleFresh || !$sameRun) { $codes['snapshot_not_current'] = true; }
        $experimentalPaper = in_array($run['profile'] ?? null,
            ['maximum-stop12-costband2-whole-v1', 'maximum-stop12-costband2-whole-bull5-v1'], true)
            && ($cycle['profile'] ?? null) === $run['profile'] && ($signal['paper_admission'] ?? null) === true
            && ($cycle['paper_only'] ?? null) === true && $sameRun;
        if (($signal['validation_selected'] ?? null) !== true && !$experimentalPaper) {
            $codes[($signal['validation_selected'] ?? null) === false
                ? 'signal_validation_not_selected' : 'qualification_unknown'] = true;
        }
        if (($run['status'] ?? null) !== 'active') { $codes['run_not_active'] = true; }

        $legs = array_values(array_filter((array) ($eligibility['executable_buy_legs'] ?? []),
            static fn ($leg): bool => is_array($leg) && self::number($leg['qty'] ?? null) > 0.0
                && self::symbol($leg['symbol'] ?? null) !== null));
        $today = $observedAt->setTimezone(new \DateTimeZone('America/New_York'))->format('Y-m-d');
        $intended = $signal['intended_session'] ?? null;
        if ($legs !== [] && $intended !== $today) { $codes['not_current_session'] = true; }
        if (!empty($tactical['active_intents'])) { $codes['orders_in_progress'] = true; }
        // An old or contradictory allowed_now flag must not become a BUY headline.
        $planAllowed = ($eligibility['allowed_now'] ?? null) === true
            && ($tactical['health']['ok'] ?? null) === true && $codes === [] && $legs !== [];
        if (!$planAllowed && $codes === [] && $legs === []) { $codes['no_actionable_signal'] = true; }
        if (!$planAllowed && $codes === []) { $codes['execution_not_confirmed'] = true; }

        $reasons = [];
        foreach (array_keys($codes) as $code) {
            $explanation = self::reason($code);
            $reasons[$explanation['id']] ??= $explanation;
        }
        uasort($reasons, static fn ($a, $b): int => $a['priority'] <=> $b['priority']);
        $state = !$brokerKnown || !$cycleFresh || !$sameRun ? 'unverified'
            : ($orders !== [] ? 'broker_orders_open' : ($planAllowed ? 'plan_permitted_not_filled'
                : (isset($codes['signal_validation_not_selected']) || self::hasTechnicalBlock($codes)
                    ? 'entries_blocked' : 'observation')));
        $heading = match ($state) {
            'unverified' => 'СОСТОЯНИЕ НЕ ПОДТВЕРЖДЕНО',
            'broker_orders_open' => 'ЕСТЬ ОТКРЫТЫЕ ЗАЯВКИ У БРОКЕРА',
            'plan_permitted_not_filled' => 'ПЛАН ПОКУПКИ РАЗРЕШЁН, ИСПОЛНЕНИЕ НЕ ПОДТВЕРЖДЕНО',
            'entries_blocked' => 'НОВЫЕ ПОКУПКИ ЗАБЛОКИРОВАНЫ',
            default => 'НАБЛЮДЕНИЕ, НОВОЙ ПОКУПКИ НЕТ',
        };
        $lines = ['ALPACA PAPER | ' . $heading, 'Снимок: ' . self::dateLabel($snapshot['generated_at'] ?? null)];
        $lines[] = $brokerKnown
            ? sprintf('У брокера: позиций %d, открытых заявок %d.', count($positions), count($orders))
            : 'Позиции и заявки: достоверного текущего снимка нет. Пустой ответ не означает нулевой счёт.';
        if ($brokerKnown) {
            $lines[] = 'Капитал ' . self::money($account['equity'] ?? null)
                . '; деньги на счёте ' . self::money($account['cash'] ?? null) . '.';
            $lines[] = 'Лимит покупок брокера ' . self::money($account['buying_power'] ?? null)
                . ': может включать заёмные средства, это не бюджет новой сделки.';
            foreach (array_slice($orders, 0, 3) as $order) {
                if (!is_array($order)) { continue; }
                $lines[] = sprintf('Заявка: %s %s; количество %s, исполнено %s. Наличие заявки не означает полного исполнения.',
                    ($order['side'] ?? null) === 'buy' ? 'покупка' : (($order['side'] ?? null) === 'sell' ? 'продажа' : 'тип неизвестен'),
                    self::symbol($order['symbol'] ?? null) ?? 'тикер неизвестен',
                    self::number($order['qty'] ?? null) === null ? 'неизвестно' : self::quantity((float) $order['qty']),
                    self::number($order['filled_qty'] ?? null) === null ? 'неизвестно' : self::quantity((float) $order['filled_qty']));
            }
        }
        $lines[] = ($run['status'] ?? null) === 'active'
            ? 'Статус ACTIVE: учёт стратегии активен, но это не разрешение покупать.'
            : 'Стратегия не в состоянии ACTIVE; новые покупки не подтверждены.';
        if ($experimentalPaper) {
            $lines[] = 'Допуск нового выпуска: только экспериментальный paper. validation_selected=false: строгий исторический отбор не пройден; это не live-допуск.';
        }
        $lines[] = '';
        $lines[] = $planAllowed ? 'Бот: план разрешён на момент снимка; это ещё не покупка.'
            : 'Бот: новые покупки сейчас не разрешены или разрешение не подтверждено.';
        $displayReasons = array_values($reasons);
        $cycleErrors = (array) ($cycle['errors'] ?? []);
        $onlyQualificationFailure = $cycleErrors !== [] && array_filter($cycleErrors,
            static fn ($code): bool => !is_string($code) || !str_starts_with($code, 'signal_plan_blocked:')) === []
            && ($tactical['heartbeat']['last_executor_exit_code'] ?? null) === 2
            && empty($tactical['heartbeat']['error']) && empty($tactical['heartbeat']['last_executor_timed_out']);
        if ($onlyQualificationFailure && isset($reasons['qualification'])) {
            // The cycle's generic failure is the same qualification block, not a second outage.
            $displayReasons = array_values(array_filter($displayReasons, static fn ($r): bool => $r['id'] !== 'runtime'));
        }
        foreach (array_slice($displayReasons, 0, 4) as $reason) {
            $lines[] = 'Почему: ' . $reason['text'];
            $lines[] = 'Что дальше: ' . $reason['next'];
        }
        if (count($displayReasons) > 4) { $lines[] = 'Остальные причины сохранены в полном JSON-отчёте.'; }
        if ($planAllowed) {
            foreach (array_slice($legs, 0, 4) as $leg) {
                $lines[] = sprintf('План: купить %s, %s шт. Отправка и исполнение проверяются отдельно.',
                    self::symbol($leg['symbol']), self::quantity((float) $leg['qty']));
            }
        }
        $watch = [];
        $targets = [];
        foreach ((array) ($signal['targets'] ?? []) as $id => $target) {
            if (!is_array($target)) { continue; }
            $symbol = self::symbol($target['ranked_symbol'] ?? null);
            if ($symbol !== null) { $watch[$symbol] = true; }
            $pause = max(0, (int) ($target['cooldown_left'] ?? $target['circuit_cooldown_left'] ?? 0));
            $due = ($target['due'] ?? $target['rebalance_due_next_session'] ?? null) === true;
            $action = (string) ($target['action'] ?? 'unknown');
            $text = match ($action) {
                'hold', 'hold_cash' => 'модель не планирует новую покупку',
                'exit_to_cash' => 'модель планирует выход в деньги, не подтверждённую продажу',
                'rebalance', 'resize_or_hold' => $due ? 'модель предлагает пересмотр позиции; допуск проверяется отдельно'
                    : 'срок пересмотра позиции ещё не наступил',
                default => 'действие модели неизвестно',
            };
            if ($pause > 0) { $text .= '; защитная пауза: осталось ' . $pause . ' торговых сессий'; }
            if (($target['drawdown_rearm_pending'] ?? null) === true) { $text .= '; требуется условие возврата после просадки'; }
            $targets[] = ['sleeve_id' => (string) $id, 'text' => $text, 'watch_symbol' => $symbol,
                'model_position_not_broker_fact' => self::symbol($target['current_symbol'] ?? null)];
        }
        $lines[] = '';
        $lines[] = 'Расчёт модели: закрытие ' . self::canonicalDate($signal['as_of'] ?? null)
            . '; план на ' . self::canonicalDate($intended) . '.';
        if ($watch !== []) {
            $lines[] = 'На наблюдении: ' . implode(', ', array_slice(array_keys($watch), 0, 12))
                . '. Это лидеры рейтинга, НЕ заявка и НЕ факт покупки.';
        }
        $counts = array_count_values(array_column($targets, 'text'));
        foreach (array_slice($counts, 0, 3, true) as $text => $count) {
            $lines[] = 'Частей стратегии: ' . $count . ' | ' . $text . '.';
        }
        $lines[] = 'Ручных действий по этому сообщению не требуется. Сообщение не отправляет заявки.';
        return ['schema' => 'paper-signal-explanation-v1', 'execution_authority' => 'none', 'state' => $state,
            'fresh_snapshot' => $fresh, 'fresh_cycle' => $cycleFresh, 'same_run' => $sameRun,
            'broker_snapshot_verified' => $brokerKnown, 'reported_plan_permitted' => $planAllowed,
            'reasons' => array_values($reasons), 'targets' => $targets, 'watch_symbols' => array_keys($watch),
            'text' => self::bounded(implode("\n", $lines))];
    }

    /** The caller supplies the reconciled broker intent, not a model target. */
    public static function intent(array $intent): string
    {
        $requested = self::number($intent['requested_qty'] ?? null);
        $filled = self::number($intent['cumulative_filled_qty'] ?? null);
        $notional = self::number($intent['cumulative_fill_notional'] ?? null);
        $symbol = self::symbol($intent['symbol'] ?? null);
        $side = $intent['side'] ?? null;
        $status = (string) ($intent['status'] ?? 'unknown');
        if ($requested === null || $requested <= 0 || $filled === null || $filled < 0
            || $filled > $requested + 1.0e-6 || $symbol === null || !in_array($side, ['buy', 'sell'], true)) {
            return 'ALPACA PAPER | ДАННЫЕ ИСПОЛНЕНИЯ НЕ ПОДТВЕРЖДЕНЫ. Требуется сверка, это не сообщение о покупке или продаже.';
        }
        $terminal = in_array($status, ['rejected', 'canceled', 'expired'], true);
        $verb = $side === 'buy' ? 'куплено' : 'продано';
        $lines = ['ALPACA PAPER'];
        if ($filled > 1.0e-9) {
            $complete = $filled + 1.0e-6 >= $requested;
            $lines[] = $side === 'buy'
                ? ($complete ? 'АКЦИИ КУПЛЕНЫ: БРОКЕР ПОДТВЕРДИЛ ИСПОЛНЕНИЕ' : 'КУПЛЕНА ТОЛЬКО ЧАСТЬ ЗАЯВКИ')
                : ($complete ? 'ПРОДАЖА ПО ЗАЯВКЕ ИСПОЛНЕНА' : 'ПРОДАНА ТОЛЬКО ЧАСТЬ ЗАЯВКИ');
            $lines[] = sprintf('%s: %s %s из %s шт.', $symbol, $verb, self::quantity($filled), self::quantity($requested));
            $lines[] = $notional !== null && $notional > 0
                ? 'Средняя цена исполнения ' . self::money($notional / $filled) . '.'
                : 'Средняя цена исполнения пока не подтверждена.';
            if (!$complete) {
                $lines[] = $terminal ? 'Остаток заявки больше не активен; исполненная часть остаётся фактом сделки.'
                    : 'Остаток ещё не исполнен; дальнейшее исполнение не гарантировано.';
            }
            if ($side === 'sell') { $lines[] = 'Это исполнение одной заявки, не подтверждение закрытия всех позиций. Итоговый P/L требует сверки себестоимости.'; }
        } else {
            $lines[] = $terminal ? 'ЗАЯВКА ЗАВЕРШЕНА БЕЗ ИСПОЛНЕНИЯ' : 'ЗАЯВКА, НЕ СДЕЛКА';
            $lines[] = sprintf('%s: %s %s шт.; подтверждённо исполнено 0.', $symbol,
                $side === 'buy' ? 'на покупку' : 'на продажу', self::quantity($requested));
            if ($status === 'filled') { $lines[] = 'Противоречие: статус filled при нулевом исполнении. Нужна сверка.'; }
        }
        $lines[] = 'Статус: ' . match ($status) {
            'accepted', 'new' => 'брокер принял заявку', 'pending_new' => 'ожидается принятие брокером',
            'filled' => 'исполнена', 'partially_filled' => 'частичное исполнение',
            'rejected' => 'отклонена', 'canceled' => 'отменена', 'expired' => 'срок истёк',
            default => 'проверяется (' . self::safeCode($status) . ')',
        };
        $lines[] = 'Исполнено с начала этой заявки: повторное обновление статуса не означает новую сделку.';
        return implode("\n", $lines);
    }

    private static function reason(string $code): array
    {
        [$id, $priority, $text, $next] = match (true) {
            str_starts_with($code, 'runtime_identity_drift') => ['identity', 0,
                'Код сервиса отличается от зафиксированной версии. Защита остановила исполнение, а не брокер отклонил покупку.',
                'Нужна проверка и штатный выпуск согласованной версии. Перезапуск сам по себе не устраняет расхождение.'],
            in_array($code, ['snapshot_not_current', 'broker_snapshot_unverified'], true) => ['snapshot', 1,
                'Свежесть данных, принадлежность запуска или paper-счёт не подтверждены.',
                'Получить свежий проверенный снимок. Не делать вывод о нулевых позициях по отсутствующим данным.'],
            $code === 'candidate_external_signal_stale' || str_starts_with($code, 'candidate_signal_invalid:') => ['source', 1,
                'Нет полного свежего сигнала: цены Alpaca, S5TW и VVIX должны относиться к одному закрытию рынка.',
                'Дождаться публикации и проверки источников. Старые значения не подставляются; новые покупки запрещены.'],
            $code === 'signal_validation_not_selected' || str_starts_with($code, 'signal_plan_blocked:') => ['qualification', 2,
                'Версия стратегии не допущена к новым покупкам по текущей проверке истории. Это не прогноз падения рынка и не отказ Alpaca.',
                'Проверить новый paper-релиз. Наличие денег или наступление следующего дня эту блокировку не снимает.'],
            $code === 'qualification_unknown' => ['qualification_unknown', 2,
                'Результат проверки версии стратегии отсутствует.', 'Проверить артефакт сигнала; отсутствие результата не означает допуск.'],
            in_array($code, ['tactical_heartbeat_failed', 'tactical_cycle_failed', 'tactical_run_failed', 'runtime_error'], true) => ['runtime', 3,
                'Последний торговый цикл завершился с блокировкой или ошибкой. Это само по себе не доказывает, что сервис упал.',
                'Проверить причину цикла и heartbeat; сначала диагностика, затем восстановление при подтверждённом сбое.'],
            $code === 'orders_in_progress' => ['orders', 4,
                'Есть заявка в исполнении или сверке; новый дополнительный риск заблокирован.', 'Дождаться ответа брокера и сверки фактического количества.'],
            $code === 'run_not_active' => ['run', 4, 'Торговый запуск ещё не активен или на паузе.', 'Проверить этап перехода и защитные условия.'],
            $code === 'rotation_exit_first' => ['rotation', 5, 'Сначала должна исполниться продажа прежней позиции.',
                'Новая покупка возможна только после подтверждённой продажи и повторной проверки допуска.'],
            in_array($code, ['not_current_session', 'entry_window_not_open', 'entry_window_locked', 'entry_window_closed', 'signal_expired'], true) => ['window', 5,
                'План не находится в разрешённом окне исполнения.', 'Дождаться подходящего свежего сигнала; прошедшую цену не догонять.'],
            $code === 'no_actionable_signal' => ['hold', 6,
                'По расписанию и условиям модели новой покупки нет (HOLD). Лидер рейтинга может быть при этом указан.',
                'Дождаться следующего расчёта и разрешённого действия, а не покупать лидера вручную.'],
            default => ['other:' . self::safeCode($code), 3,
                'Есть дополнительная блокировка: ' . self::safeCode($code) . '.', 'Проверить локальную диагностику; разрешение не подтверждено.'],
        };
        return ['id' => $id, 'priority' => $priority, 'code' => self::safeCode($code), 'text' => $text, 'next' => $next];
    }

    private static function hasTechnicalBlock(array $codes): bool
    {
        foreach (array_keys($codes) as $code) {
            if (str_starts_with($code, 'runtime') || str_starts_with($code, 'tactical_')
                || str_starts_with($code, 'signal_plan_blocked:') || $code === 'qualification_unknown') { return true; }
        }
        return false;
    }

    private static function safeCode(string $code): string
    {
        return preg_match('/^[a-z][a-z0-9_:.-]{0,95}$/D', $code) === 1 ? $code : 'details_in_local_logs';
    }

    private static function fresh(mixed $timestamp, \DateTimeImmutable $now, int $maximumAge): bool
    {
        if (!is_string($timestamp) || preg_match('/^\d{4}-\d{2}-\d{2}T.*(?:Z|[+-]\d{2}:\d{2})$/D', $timestamp) !== 1) { return false; }
        try { $age = $now->getTimestamp() - (new \DateTimeImmutable($timestamp))->getTimestamp(); }
        catch (\Throwable) { return false; }
        return $age >= -5 && $age <= $maximumAge;
    }

    private static function dateLabel(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T/', $value)) { return 'время неизвестно'; }
        try { return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('America/New_York'))->format('Y-m-d H:i:s') . ' Нью-Йорк'; }
        catch (\Throwable) { return 'время неизвестно'; }
    }

    private static function canonicalDate(mixed $value): string
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) ? $value : 'дата неизвестна';
    }

    private static function symbol(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Z][A-Z0-9.\-]{0,14}$/D', $value) ? $value : null;
    }

    private static function number(mixed $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : null;
    }

    private static function money(mixed $value): string
    {
        $n = self::number($value);
        return $n === null ? 'неизвестно' : '$' . number_format($n, 2, '.', ',');
    }

    private static function quantity(float $value): string
    {
        return rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');
    }

    private static function bounded(string $text): string
    {
        if (strlen($text) <= 3800) { return $text; }
        $suffix = "\nПодробности в полном status-export. Это не команда на покупку.";
        $text = substr($text, 0, 3800 - strlen($suffix));
        while (preg_match('//u', $text) !== 1) { $text = substr($text, 0, -1); }
        return rtrim($text) . $suffix;
    }
}
