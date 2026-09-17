# FTT Paper Status

## Что Происходит

ALPACA PAPER | ЕСТЬ ОТКРЫТЫЕ ЗАЯВКИ У БРОКЕРА
Снимок: 2026-09-17 10:45:17 Нью-Йорк
У брокера: позиций 1, открытых заявок 1.
Капитал $27,578.63; деньги на счёте $26,583.66.
Лимит покупок брокера $54,162.29: может включать заёмные средства, это не бюджет новой сделки.
Заявка: продажа MSFT; количество 2, исполнено 0. Наличие заявки не означает полного исполнения.
Статус ACTIVE: учёт стратегии активен, но это не разрешение покупать.
Допуск нового выпуска: только экспериментальный paper. validation_selected=false: строгий исторический отбор не пройден; это не live-допуск.

Бот: новые покупки сейчас не разрешены или разрешение не подтверждено.
Почему: Нет полного свежего сигнала: цены Alpaca, S5TW и VVIX должны относиться к одному закрытию рынка.
Что дальше: Дождаться публикации и проверки источников. Старые значения не подставляются; новые покупки запрещены.
Почему: Последний торговый цикл завершился с блокировкой или ошибкой. Это само по себе не доказывает, что сервис упал.
Что дальше: Проверить причину цикла и heartbeat; сначала диагностика, затем восстановление при подтверждённом сбое.
Почему: Есть дополнительная блокировка: tactical_signal_refresh_failed.
Что дальше: Проверить локальную диагностику; разрешение не подтверждено.
Почему: Есть заявка в исполнении или сверке; новый дополнительный риск заблокирован.
Что дальше: Дождаться ответа брокера и сверки фактического количества.

Расчёт модели: закрытие 2026-09-15; план на 2026-09-16.
На наблюдении: MSFT. Это лидеры рейтинга, НЕ заявка и НЕ факт покупки.
Частей стратегии: 8 | модель не планирует новую покупку.
Частей стратегии: 4 | модель предлагает пересмотр позиции; допуск проверяется отдельно.
Ручных действий по этому сообщению не требуется. Сообщение не отправляет заявки.

## Технические Подробности

- Generated: `2026-09-17T14:45:17+00:00`
- Market open: `yes`
- Orders enabled: `yes`
- Paper account guard: `verified`
- New production entries: `blocked`
- Entry block reason: `author_style_unqualified_tactical_rotation_shadow_only_2026-07-16`
- Equity: `$27,578.63`
- Cash: `$26,583.66`
- Buying power: `$54,162.29`
- Hybrid-v4 runtime: `active`
- Hybrid-v4 health: `failed`
- Hybrid-v4 health errors: `tactical_heartbeat_failed, tactical_signal_refresh_failed, tactical_cycle_failed, tactical_run_failed`
- Telegram outbox: `0 pending, 0 failed pending, 107 delivered`
- Hybrid reconciliation: `blocked_candidate_cycle`
- Hybrid entry/add now: `blocked`
- Hybrid entry/add reasons: `Отправка и исполнение проверяются отдельно от целей модели.`
- Telegram opening report key: `not_due`
- Telegram close report key: `portfolio-close:hybrid-v4-bull5-2026-09-15:2026-09-15`
- Live review not before: `2026-10-16T13:41:18+00:00`

## Positions
- `MSFT` qty `2`, avg `$492.00`, price `$497.56`, value `$995.12`, P/L `$11.12` (`+1.13%`), today `+1.48%`

## Open Orders
- `MSFT` sell stop qty `2`, limit `-`, status `new`

## Recent Actions
- `2026-09-17T14:45:01` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-17T14:43:30` `-` `monitor_error`: details_redacted_use_local_logs
- `2026-09-17T14:41:59` `-` `monitor_error`: details_redacted_use_local_logs
- `2026-09-17T14:40:28` `-` `monitor_error`: details_redacted_use_local_logs
- `2026-09-17T14:38:56` `-` `monitor_error`: details_redacted_use_local_logs
- `2026-09-17T14:37:55` `-` `monitor_error`: details_redacted_use_local_logs
- `2026-09-17T14:36:34` `-` `monitor_error`: details_redacted_use_local_logs
- `2026-09-17T14:35:03` `-` `monitor_error`: details_redacted_use_local_logs
