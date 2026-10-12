# FTT Paper Status

## Что Происходит

ALPACA PAPER | ЕСТЬ ОТКРЫТЫЕ ЗАЯВКИ У БРОКЕРА
Снимок: 2026-10-11 23:00:13 Нью-Йорк
У брокера: позиций 1, открытых заявок 1.
Капитал $27,658.58; деньги на счёте $26,583.66.
Лимит покупок брокера $54,242.24: может включать заёмные средства, это не бюджет новой сделки.
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

Расчёт модели: закрытие 2026-10-09; план на 2026-10-12.
Ручных действий по этому сообщению не требуется. Сообщение не отправляет заявки.

## Технические Подробности

- Generated: `2026-10-12T03:00:13+00:00`
- Market open: `no`
- Orders enabled: `yes`
- Paper account guard: `verified`
- New production entries: `blocked`
- Entry block reason: `author_style_unqualified_tactical_rotation_shadow_only_2026-07-16`
- Equity: `$27,658.58`
- Cash: `$26,583.66`
- Buying power: `$54,242.24`
- Hybrid-v4 runtime: `paused`
- Hybrid-v4 health: `failed`
- Hybrid-v4 health errors: `tactical_heartbeat_failed, tactical_cycle_failed, tactical_run_failed, tactical_notification_signal_missing`
- Telegram outbox: `0 pending, 0 failed pending, 181 delivered`
- Hybrid reconciliation: `blocked_candidate_cycle`
- Hybrid entry/add now: `blocked`
- Hybrid entry/add reasons: `Отправка и исполнение проверяются отдельно от целей модели.`
- Telegram opening report key: `not_due`
- Telegram close report key: `not_due`
- Live review not before: `2026-10-16T13:41:18+00:00`

## Positions
- `MSFT` qty `2`, avg `$492.00`, price `$537.46`, value `$1074.92`, P/L `$90.92` (`+9.24%`), today `+0.45%`

## Open Orders
- `MSFT` sell stop qty `2`, limit `-`, status `accepted`

## Recent Actions
- `2026-10-12T02:59:46` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-10-12T02:58:43` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-10-12T02:57:41` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-10-12T02:56:39` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-10-12T02:55:37` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-10-12T02:54:35` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-10-12T02:53:32` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-10-12T02:52:30` `-` `monitor_heartbeat`: details_redacted_use_local_logs
