# FTT Paper Status

## Что Происходит

ALPACA PAPER | СОСТОЯНИЕ НЕ ПОДТВЕРЖДЕНО
Снимок: 2026-09-26 09:06:54 Нью-Йорк
Позиции и заявки: достоверного текущего снимка нет. Пустой ответ не означает нулевой счёт.
Стратегия не в состоянии ACTIVE; новые покупки не подтверждены.
Допуск нового выпуска: только экспериментальный paper. validation_selected=false: строгий исторический отбор не пройден; это не live-допуск.

Бот: новые покупки сейчас не разрешены или разрешение не подтверждено.
Почему: Брокер сообщил окончательный статус заявки с неисполненным остатком. Это остановило новые покупки, но не означает закрытие имеющихся позиций.
Что дальше: Сверить конкретную заявку, исполнения и стопы. Перезапуск не снимает эту паузу; повторять остаток вручную нельзя.
Почему: Свежесть данных, принадлежность запуска или paper-счёт не подтверждены.
Что дальше: Получить свежий проверенный снимок. Не делать вывод о нулевых позициях по отсутствующим данным.
Почему: Есть дополнительная блокировка: alpaca_sync_or_account_validation_failed.
Что дальше: Проверить локальную диагностику; разрешение не подтверждено.
Почему: Последний торговый цикл завершился с блокировкой или ошибкой. Это само по себе не доказывает, что сервис упал.
Что дальше: Проверить причину цикла и heartbeat; сначала диагностика, затем восстановление при подтверждённом сбое.
Остальные причины сохранены в полном JSON-отчёте.

Расчёт модели: закрытие 2026-09-25; план на 2026-09-28.
Ручных действий по этому сообщению не требуется. Сообщение не отправляет заявки.

## Технические Подробности

- Generated: `2026-09-26T13:06:54+00:00`
- Market open: `no`
- Orders enabled: `yes`
- Paper account guard: `failed`
- New production entries: `blocked`
- Entry block reason: `author_style_unqualified_tactical_rotation_shadow_only_2026-07-16`
- Equity: `$0.00`
- Cash: `$0.00`
- Buying power: `$0.00`
- Hybrid-v4 runtime: `paused`
- Hybrid-v4 health: `failed`
- Hybrid-v4 health errors: `tactical_heartbeat_failed, tactical_cycle_stale, tactical_cycle_mismatch, tactical_cycle_failed, tactical_run_failed, tactical_notification_signal_missing`
- Telegram outbox: `0 pending, 0 failed pending, 143 delivered`
- Hybrid reconciliation: `blocked_candidate_cycle`
- Hybrid entry/add now: `blocked`
- Hybrid entry/add reasons: `Отправка и исполнение проверяются отдельно от целей модели.`
- Telegram opening report key: `not_due`
- Telegram close report key: `not_due`
- Live review not before: `2026-10-16T13:41:18+00:00`

## Positions
- none

## Open Orders
- none

## Recent Actions
- `2026-09-26T13:02:07` `-` `monitor_error`: details_redacted_use_local_logs
- `2026-09-26T12:58:06` `-` `monitor_error`: details_redacted_use_local_logs
- `2026-09-26T12:54:04` `-` `monitor_error`: details_redacted_use_local_logs
- `2026-09-26T12:53:02` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-26T12:51:47` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-26T12:50:45` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-26T12:49:43` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-26T12:48:40` `-` `monitor_heartbeat`: details_redacted_use_local_logs
