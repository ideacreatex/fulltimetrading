# FTT Paper Status

## Что Происходит

ALPACA PAPER | ЕСТЬ ОТКРЫТЫЕ ЗАЯВКИ У БРОКЕРА
Снимок: 2026-09-25 21:28:16 Нью-Йорк
У брокера: позиций 1, открытых заявок 1.
Капитал $27,619.48; деньги на счёте $26,583.66.
Лимит покупок брокера $54,203.14: может включать заёмные средства, это не бюджет новой сделки.
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

Расчёт модели: закрытие 2026-09-24; план на 2026-09-25.
На наблюдении: PLTR, MSFT. Это лидеры рейтинга, НЕ заявка и НЕ факт покупки.
Частей стратегии: 4 | модель предлагает пересмотр позиции; допуск проверяется отдельно.
Частей стратегии: 8 | модель не планирует новую покупку.
Ручных действий по этому сообщению не требуется. Сообщение не отправляет заявки.

## Технические Подробности

- Generated: `2026-09-26T01:28:16+00:00`
- Market open: `no`
- Orders enabled: `yes`
- Paper account guard: `verified`
- New production entries: `blocked`
- Entry block reason: `author_style_unqualified_tactical_rotation_shadow_only_2026-07-16`
- Equity: `$27,619.48`
- Cash: `$26,583.66`
- Buying power: `$54,203.14`
- Hybrid-v4 runtime: `paused`
- Hybrid-v4 health: `failed`
- Hybrid-v4 health errors: `tactical_heartbeat_failed, tactical_signal_refresh_failed, tactical_cycle_failed, tactical_run_failed`
- Telegram outbox: `0 pending, 0 failed pending, 142 delivered`
- Hybrid reconciliation: `blocked_candidate_cycle`
- Hybrid entry/add now: `blocked`
- Hybrid entry/add reasons: `Отправка и исполнение проверяются отдельно от целей модели.`
- Telegram opening report key: `not_due`
- Telegram close report key: `portfolio-close:hybrid-v4-bull5-2026-09-15:2026-09-22`
- Live review not before: `2026-10-16T13:41:18+00:00`

## Positions
- `MSFT` qty `2`, avg `$492.00`, price `$517.91`, value `$1035.82`, P/L `$51.82` (`+5.27%`), today `+4.01%`

## Open Orders
- `MSFT` sell stop qty `2`, limit `-`, status `accepted`

## Recent Actions
- `2026-09-26T01:27:57` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-26T01:26:55` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-26T01:25:52` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-26T01:24:50` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-26T01:23:48` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-26T01:22:46` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-26T01:21:44` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-26T01:20:42` `-` `monitor_heartbeat`: details_redacted_use_local_logs
