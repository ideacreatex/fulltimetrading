# FTT Paper Status

## Что Происходит

ALPACA PAPER | ЕСТЬ ОТКРЫТЫЕ ЗАЯВКИ У БРОКЕРА
Снимок: 2026-10-08 13:35:15 Нью-Йорк
У брокера: позиций 1, открытых заявок 1.
Капитал $27,630.10; деньги на счёте $26,583.66.
Лимит покупок брокера $54,213.76: может включать заёмные средства, это не бюджет новой сделки.
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

Расчёт модели: закрытие 2026-10-07; план на 2026-10-08.
Ручных действий по этому сообщению не требуется. Сообщение не отправляет заявки.

## Технические Подробности

- Generated: `2026-10-08T17:35:15+00:00`
- Market open: `yes`
- Orders enabled: `yes`
- Paper account guard: `verified`
- New production entries: `blocked`
- Entry block reason: `author_style_unqualified_tactical_rotation_shadow_only_2026-07-16`
- Equity: `$27,630.10`
- Cash: `$26,583.66`
- Buying power: `$54,213.76`
- Hybrid-v4 runtime: `paused`
- Hybrid-v4 health: `failed`
- Hybrid-v4 health errors: `tactical_heartbeat_failed, tactical_cycle_failed, tactical_run_failed, tactical_notification_signal_missing`
- Telegram outbox: `0 pending, 0 failed pending, 173 delivered`
- Hybrid reconciliation: `blocked_candidate_cycle`
- Hybrid entry/add now: `blocked`
- Hybrid entry/add reasons: `Отправка и исполнение проверяются отдельно от целей модели.`
- Telegram opening report key: `not_due`
- Telegram close report key: `not_due`
- Live review not before: `2026-10-16T13:41:18+00:00`

## Positions
- `MSFT` qty `2`, avg `$492.00`, price `$523.12`, value `$1046.25`, P/L `$62.25` (`+6.33%`), today `-1.25%`

## Open Orders
- `MSFT` sell stop qty `2`, limit `-`, status `new`

## Recent Actions
- `2026-10-08T17:34:48` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-10-08T17:33:46` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-10-08T17:32:41` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-10-08T17:31:38` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-10-08T17:30:36` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-10-08T17:29:33` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-10-08T17:28:31` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-10-08T17:27:29` `-` `monitor_heartbeat`: details_redacted_use_local_logs
