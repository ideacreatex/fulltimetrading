# FTT Paper Status

## Что Происходит

ALPACA PAPER | ЕСТЬ ОТКРЫТЫЕ ЗАЯВКИ У БРОКЕРА
Снимок: 2026-09-18 15:29:31 Нью-Йорк
У брокера: позиций 1, открытых заявок 1.
Капитал $27,574.20; деньги на счёте $26,583.66.
Лимит покупок брокера $54,157.86: может включать заёмные средства, это не бюджет новой сделки.
Заявка: продажа MSFT; количество 2, исполнено 0. Наличие заявки не означает полного исполнения.
Стратегия не в состоянии ACTIVE; новые покупки не подтверждены.
Допуск нового выпуска: только экспериментальный paper. validation_selected=false: строгий исторический отбор не пройден; это не live-допуск.

Бот: новые покупки сейчас не разрешены или разрешение не подтверждено.
Почему: Последний торговый цикл завершился с блокировкой или ошибкой. Это само по себе не доказывает, что сервис упал.
Что дальше: Проверить причину цикла и heartbeat; сначала диагностика, затем восстановление при подтверждённом сбое.
Почему: Есть дополнительная блокировка: candidate_run_paused.
Что дальше: Проверить локальную диагностику; разрешение не подтверждено.
Почему: Есть дополнительная блокировка: candidate_terminal_incomplete:79796b9b9712.
Что дальше: Проверить локальную диагностику; разрешение не подтверждено.
Почему: Торговый запуск ещё не активен или на паузе.
Что дальше: Проверить этап перехода и защитные условия.
Остальные причины сохранены в полном JSON-отчёте.

Расчёт модели: закрытие 2026-09-17; план на 2026-09-18.
На наблюдении: MSFT. Это лидеры рейтинга, НЕ заявка и НЕ факт покупки.
Частей стратегии: 4 | модель предлагает пересмотр позиции; допуск проверяется отдельно.
Частей стратегии: 8 | модель не планирует новую покупку.
Ручных действий по этому сообщению не требуется. Сообщение не отправляет заявки.

## Технические Подробности

- Generated: `2026-09-18T19:29:31+00:00`
- Market open: `yes`
- Orders enabled: `yes`
- Paper account guard: `verified`
- New production entries: `blocked`
- Entry block reason: `author_style_unqualified_tactical_rotation_shadow_only_2026-07-16`
- Equity: `$27,574.20`
- Cash: `$26,583.66`
- Buying power: `$54,157.86`
- Hybrid-v4 runtime: `paused`
- Hybrid-v4 health: `failed`
- Hybrid-v4 health errors: `tactical_heartbeat_failed, tactical_cycle_failed, tactical_run_failed`
- Telegram outbox: `0 pending, 0 failed pending, 115 delivered`
- Hybrid reconciliation: `blocked_candidate_cycle`
- Hybrid entry/add now: `blocked`
- Hybrid entry/add reasons: `Отправка и исполнение проверяются отдельно от целей модели.`
- Telegram opening report key: `portfolio-open:d1d8ddc9925b:2026-09-18:v3`
- Telegram close report key: `portfolio-close:hybrid-v4-bull5-2026-09-15:2026-09-17`
- Live review not before: `2026-10-16T13:41:18+00:00`

## Positions
- `MSFT` qty `2`, avg `$492.00`, price `$495.19`, value `$990.39`, P/L `$6.39` (`+0.65%`), today `-0.51%`

## Open Orders
- `MSFT` sell stop qty `2`, limit `-`, status `new`

## Recent Actions
- `2026-09-18T19:29:23` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-18T19:28:21` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-18T19:27:19` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-18T19:26:16` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-18T19:25:13` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-18T19:24:10` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-18T19:23:08` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-18T19:22:06` `-` `monitor_heartbeat`: details_redacted_use_local_logs
