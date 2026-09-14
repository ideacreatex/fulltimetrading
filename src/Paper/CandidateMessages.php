<?php

declare(strict_types=1);

namespace FulltimeTrading\Paper;

final class CandidateMessages
{
    public static function close(array $run, array $close, array $account, array $positions, array $orders): string
    {
        $initial = (float) $run['initial_equity']; $equity = (float) $account['equity'];
        $lines = ['PAPER: максимум + стоп 12%, 12 частей', 'План на ' . $close['scheduled_session'] . ' по закрытию ' . $close['date'] . '.',
            'Это цели стратегии, а не подтверждённые покупки.'];
        $due = 0;
        foreach ($close['plans'] as $name => $plan) {
            if ($plan['target_quantities'] === null) { continue; }
            ++$due; $q = $plan['target_quantities'];
            $lines[] = self::sleeveLabel($name) . ': ' . ($q === [] ? 'цель: деньги' : 'цель: ' . reset($q) . ' шт. ' . array_key_first($q));
        }
        if ($due === 0) { $lines[] = 'На следующую сессию плановых изменений нет.'; }
        $lines[] = sprintf('Счёт: $%.2f; деньги: $%.2f; P/L с активации: $%+.2f (%+.2f%%).',
            $equity, $account['cash'], $equity - $initial, $initial > 0 ? 100 * ($equity / $initial - 1) : 0);
        $lines[] = sprintf('У брокера: %d позиций, %d открытых заявок.', count($positions), count($orders));
        $lines[] = 'Допуск: только экспериментальный paper. Live запрещён; строгий исторический validation_selected остаётся false.';
        $lines[] = 'Buying power показывает лимит брокера. Покупка требует срока сигнала, допуска paper, сверки и разрешения риска.';
        return implode("\n", $lines);
    }

    public static function fill(array $intent, float $previousQuantity): string
    {
        $quantity = (float) $intent['cumulative_filled_qty'];
        if ($quantity <= $previousQuantity) { throw new \InvalidArgumentException('No new confirmed fill.'); }
        return sprintf("Брокер подтвердил %s %s.\nИсполнено ещё %.0f шт.; всего по заявке %.0f из %.0f. Средняя цена $%.4f.\nЧасть: %s. Тип: %s. PAPER, не live.",
            $intent['side'] === 'buy' ? 'покупку' : 'продажу', $intent['symbol'], $quantity - $previousQuantity,
            $quantity, $intent['requested_qty'], $intent['cumulative_fill_notional'] / $quantity,
            self::sleeveLabel($intent['sleeve_id']), $intent['leg'] === 'protective_stop' ? 'защитный стоп' : 'плановая заявка');
    }

    private static function sleeveLabel(string $name): string
    {
        $label = match (true) {
            str_starts_with($name, 'dynamic_') => 'Динамическая часть',
            str_starts_with($name, 'qqq200_') => 'Фильтр QQQ/200',
            str_starts_with($name, 'spy200_') => 'Фильтр SPY/200',
            str_starts_with($name, 'qqq150_') => 'Фильтр QQQ/150 без криптоакций',
            default => $name,
        };
        return preg_match('/_phase([0-2])$/D', $name, $match) ? $label . ', очередь ' . ((int) $match[1] + 1) . '/3' : $label;
    }

    public static function error(array $codes): string
    {
        $reason = 'Торговый цикл не прошёл проверку; детали сохранены в локальном журнале.';
        foreach ($codes as $code) {
            if ($code === 'candidate_external_signal_stale' || str_starts_with($code, 'candidate_signal_invalid:')) {
                $reason = 'Нет проверенного сигнала последнего закрытия: нужны цены Alpaca, S5TW и VVIX за одну дату. Старые значения не подставляются.'; break;
            }
            if (str_contains($code, 'ambiguous') || str_contains($code, 'unresolved')) {
                $reason = 'Ответ брокера или сверка заявки не подтверждены. Повторная покупка не отправляется; выполняется проверка существующей заявки.';
            }
        }
        return 'PAPER: новые покупки заблокированы. ' . $reason
            . ' Это не прогноз падения рынка. Ручные заявки и переключение в live не выполняются.';
    }
}
