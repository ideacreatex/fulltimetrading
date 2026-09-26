# FTT Paper Status

## Что Происходит

ALPACA PAPER | ЕСТЬ ОТКРЫТЫЕ ЗАЯВКИ У БРОКЕРА
Снимок: 2026-09-26 09:37:09 Нью-Йорк
У брокера: позиций 1, открытых заявок 1.
Капитал $27,616.00; деньги на счёте $26,583.66.
Лимит покупок брокера $54,199.66: может включать заёмные средства, это не бюджет новой сделки.
Заявка: продажа MSFT; количество 2, исполнено 0. Наличие заявки не означает полного исполнения.
Стратегия не в состоянии ACTIVE; новые покупки не подтверждены.
Допуск нового выпуска: только экспериментальный paper. validation_selected=false: строгий исторический отбор не пройден; это не live-допуск.

Бот: новые покупки сейчас не разрешены или разрешение не подтверждено.
Почему: Брокер сообщил окончательный статус заявки с неисполненным остатком. Это остановило новые покупки, но не означает закрытие имеющихся позиций.
Что дальше: Сверить конкретную заявку, исполнения и стопы. Перезапуск не снимает эту паузу; повторять остаток вручную нельзя.
Почему: Последний торговый цикл завершился с блокировкой или ошибкой. Это само по себе не доказывает, что сервис упал.
Что дальше: Проверить причину цикла и heartbeat; сначала диагностика, затем восстановление при подтверждённом сбое.
Почему: Есть дополнительная блокировка: tactical_notification_signal_missing.
Что дальше: Проверить локальную диагностику; разрешение не подтверждено.
Почему: Есть дополнительная блокировка: details_in_local_logs.
Что дальше: Проверить локальную диагностику; разрешение не подтверждено.
Остальные причины сохранены в полном JSON-отчёте.

Расчёт модели: закрытие 2026-09-25; план на 2026-09-28.
Ручных действий по этому сообщению не требуется. Сообщение не отправляет заявки.

## Технические Подробности

- Generated: `2026-09-26T13:37:09+00:00`
- Market open: `no`
- Orders enabled: `yes`
- Paper account guard: `verified`
- New production entries: `blocked`
- Entry block reason: `author_style_unqualified_tactical_rotation_shadow_only_2026-07-16`
- Equity: `$27,616.00`
- Cash: `$26,583.66`
- Buying power: `$54,199.66`
- Hybrid-v4 runtime: `paused`
- Hybrid-v4 health: `failed`
- Hybrid-v4 health errors: `tactical_heartbeat_failed, tactical_cycle_failed, tactical_run_failed, tactical_notification_signal_missing`
- Telegram outbox: `0 pending, 0 failed pending, 143 delivered`
- Hybrid reconciliation: `blocked_candidate_cycle`
- Hybrid entry/add now: `blocked`
- Hybrid entry/add reasons: `Отправка и исполнение проверяются отдельно от целей модели.`
- Telegram opening report key: `not_due`
- Telegram close report key: `not_due`
- Live review not before: `2026-10-16T13:41:18+00:00`

## Positions
- `MSFT` qty `2`, avg `$492.00`, price `$516.17`, value `$1032.34`, P/L `$48.34` (`+4.91%`), today `+0.00%`

## Open Orders
- `MSFT` sell stop qty `2`, limit `-`, status `accepted`

## Recent Actions
- `2026-09-26T13:37:05` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-26T13:36:03` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-26T13:35:00` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-26T13:33:58` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-26T13:32:56` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-26T13:31:54` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-26T13:30:52` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-26T13:29:49` `-` `monitor_heartbeat`: details_redacted_use_local_logs
