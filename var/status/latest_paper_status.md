# FTT Paper Status

## Что Происходит

ALPACA PAPER | ЕСТЬ ОТКРЫТЫЕ ЗАЯВКИ У БРОКЕРА
Снимок: 2026-09-22 14:45:18 Нью-Йорк
У брокера: позиций 1, открытых заявок 1.
Капитал $27,581.08; деньги на счёте $26,583.66.
Лимит покупок брокера $54,164.74: может включать заёмные средства, это не бюджет новой сделки.
Заявка: продажа MSFT; количество 2, исполнено 0. Наличие заявки не означает полного исполнения.
Стратегия не в состоянии ACTIVE; новые покупки не подтверждены.
Допуск нового выпуска: только экспериментальный paper. validation_selected=false: строгий исторический отбор не пройден; это не live-допуск.

Бот: новые покупки сейчас не разрешены или разрешение не подтверждено.
Почему: Брокер сообщил окончательный статус заявки с неисполненным остатком. Это остановило новые покупки, но не означает закрытие имеющихся позиций.
Что дальше: Сверить конкретную заявку, исполнения и стопы. Перезапуск не снимает эту паузу; повторять остаток вручную нельзя.
Почему: Запуск находится на защитной паузе. Даже свежий сигнал не разрешает новые покупки.
Что дальше: Проверить сохранённую причину паузы и защиту фактических акций. Не сбрасывать историю и не снимать блокировку автоматически.
Почему: Последний торговый цикл завершился с блокировкой или ошибкой. Это само по себе не доказывает, что сервис упал.
Что дальше: Проверить причину цикла и heartbeat; сначала диагностика, затем восстановление при подтверждённом сбое.
Почему: Торговый запуск ещё не активен или на паузе.
Что дальше: Проверить этап перехода и защитные условия.
Остальные причины сохранены в полном JSON-отчёте.

Расчёт модели: закрытие 2026-09-21; план на 2026-09-22.
На наблюдении: MSFT. Это лидеры рейтинга, НЕ заявка и НЕ факт покупки.
Частей стратегии: 8 | модель не планирует новую покупку.
Частей стратегии: 4 | модель предлагает пересмотр позиции; допуск проверяется отдельно.
Ручных действий по этому сообщению не требуется. Сообщение не отправляет заявки.

## Технические Подробности

- Generated: `2026-09-22T18:45:18+00:00`
- Market open: `yes`
- Orders enabled: `yes`
- Paper account guard: `verified`
- New production entries: `blocked`
- Entry block reason: `author_style_unqualified_tactical_rotation_shadow_only_2026-07-16`
- Equity: `$27,581.08`
- Cash: `$26,583.66`
- Buying power: `$54,164.74`
- Hybrid-v4 runtime: `paused`
- Hybrid-v4 health: `failed`
- Hybrid-v4 health errors: `tactical_heartbeat_failed, tactical_cycle_failed, tactical_run_failed`
- Telegram outbox: `0 pending, 0 failed pending, 130 delivered`
- Hybrid reconciliation: `blocked_candidate_cycle`
- Hybrid entry/add now: `blocked`
- Hybrid entry/add reasons: `Отправка и исполнение проверяются отдельно от целей модели.`
- Telegram opening report key: `portfolio-open:d1d8ddc9925b:2026-09-22:v3`
- Telegram close report key: `portfolio-close:hybrid-v4-bull5-2026-09-15:2026-09-21`
- Live review not before: `2026-10-16T13:41:18+00:00`

## Positions
- `MSFT` qty `2`, avg `$492.00`, price `$498.71`, value `$997.42`, P/L `$13.42` (`+1.36%`), today `-0.58%`

## Open Orders
- `MSFT` sell stop qty `2`, limit `-`, status `new`

## Recent Actions
- `2026-09-22T18:44:21` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-22T18:43:18` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-22T18:42:16` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-22T18:41:14` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-22T18:40:11` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-22T18:39:09` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-22T18:38:07` `-` `monitor_heartbeat`: details_redacted_use_local_logs
- `2026-09-22T18:37:04` `-` `monitor_heartbeat`: details_redacted_use_local_logs
