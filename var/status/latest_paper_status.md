# FTT Paper Status

## Что Происходит

ALPACA PAPER | ЕСТЬ ОТКРЫТЫЕ ЗАЯВКИ У БРОКЕРА
Снимок: 2026-09-23 20:33:30 Нью-Йорк
У брокера: позиций 1, открытых заявок 1.
Капитал $27,583.70; деньги на счёте $26,583.66.
Лимит покупок брокера $54,167.36: может включать заёмные средства, это не бюджет новой сделки.
Заявка: продажа MSFT; количество 2, исполнено 0. Наличие заявки не означает полного исполнения.
Стратегия не в состоянии ACTIVE; новые покупки не подтверждены.
Допуск нового выпуска: только экспериментальный paper. validation_selected=false: строгий исторический отбор не пройден; это не live-допуск.

Бот: новые покупки сейчас не разрешены или разрешение не подтверждено.
Почему: Нет полного свежего сигнала: цены Alpaca, S5TW и VVIX должны относиться к одному закрытию рынка.
Что дальше: Дождаться публикации и проверки источников. Старые значения не подставляются; новые покупки запрещены.
Почему: Брокер сообщил окончательный статус заявки с неисполненным остатком. Это остановило новые покупки, но не означает закрытие имеющихся позиций.
Что дальше: Сверить конкретную заявку, исполнения и стопы. Перезапуск не снимает эту паузу; повторять остаток вручную нельзя.
Почему: Запуск находится на защитной паузе. Даже свежий сигнал не разрешает новые покупки.
Что дальше: Проверить сохранённую причину паузы и защиту фактических акций. Не сбрасывать историю и не снимать блокировку автоматически.
Почему: Последний торговый цикл завершился с блокировкой или ошибкой. Это само по себе не доказывает, что сервис упал.
Что дальше: Проверить причину цикла и heartbeat; сначала диагностика, затем восстановление при подтверждённом сбое.
Остальные причины сохранены в полном JSON-отчёте.

Расчёт модели: закрытие 2026-09-22; план на 2026-09-23.
На наблюдении: PLTR, MSFT. Это лидеры рейтинга, НЕ заявка и НЕ факт покупки.
Частей стратегии: 4 | модель предлагает пересмотр позиции; допуск проверяется отдельно.
Частей стратегии: 8 | модель не планирует новую покупку.
Ручных действий по этому сообщению не требуется. Сообщение не отправляет заявки.

## Технические Подробности

- Generated: `2026-09-24T00:33:30+00:00`
- Market open: `no`
- Orders enabled: `yes`
- Paper account guard: `verified`
- New production entries: `blocked`
- Entry block reason: `author_style_unqualified_tactical_rotation_shadow_only_2026-07-16`
- Equity: `$27,583.70`
- Cash: `$26,583.66`
- Buying power: `$54,167.36`
- Hybrid-v4 runtime: `paused`
- Hybrid-v4 health: `failed`
- Hybrid-v4 health errors: `tactical_heartbeat_failed, tactical_signal_refresh_failed, tactical_cycle_failed, tactical_run_failed`
- Telegram outbox: `0 pending, 0 failed pending, 137 delivered`
- Hybrid reconciliation: `blocked_candidate_cycle`
- Hybrid entry/add now: `blocked`
- Hybrid entry/add reasons: `Отправка и исполнение проверяются отдельно от целей модели.`
- Telegram opening report key: `not_due`
- Telegram close report key: `portfolio-close:hybrid-v4-bull5-2026-09-15:2026-09-22`
- Live review not before: `2026-10-16T13:41:18+00:00`

## Positions
- `MSFT` qty `2`, avg `$492.00`, price `$500.02`, value `$1000.04`, P/L `$16.04` (`+1.63%`), today `+0.41%`

## Open Orders
- `MSFT` sell stop qty `2`, limit `-`, status `new`

## Recent Actions
- `2026-09-24T00:33:18` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-24T00:32:15` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-24T00:31:13` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-24T00:30:11` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-24T00:29:09` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-24T00:28:07` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-24T00:27:05` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-24T00:26:03` `-` `monitor_heartbeat`: details_redacted_use_local_logs
