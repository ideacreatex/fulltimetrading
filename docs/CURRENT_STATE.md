# FullTimeTrading — current paper state

> Research update 2026-07-16: активный paper runtime ниже не переключался и не перезапускался. Новая изолированная регрессия не выбрала production-кандидата; старые `+395.79%` зависели от порядка universe. Актуальные детерминированные сравнения: `docs/STRATEGY_REGRESSION_2026-07-16.md`.

Обновлено: 2026-07-15 (Asia/Nicosia). Здесь хранится только sanitized operational context: без ключей и полных идентификаторов аккаунта.

## Safety и выбранное решение

- Торговый endpoint жестко ограничен `https://paper-api.alpaca.markets/v2`; redirects для Alpaca trading API запрещены.
- `FTT_PAPER_ONLY=true` и общий submit-контур использует `FTT_ORDERS_ENABLED=true` только для Alpaca paper.
- Новые entry-заявки отдельно заблокированы: `FTT_PRODUCTION_ENTRY_ENABLED=false`, причина `no_planned_quantity_walk_forward_candidate_2026-07-15`.
- Сигналы продолжают формироваться; мониторинг и защитные partial/stop/full exits уже открытых paper-позиций остаются активными.
- Observation-профиль `tuned-daily`: universe `UPRO,TQQQ,SOXL,USD,TECL`, `max_gross=1.75`, `max_open=4`, `family_cap=1.20`, cooldown `5`, same-strength `45`, обязательный close выше support, mental stop, БУ `+1%`, partial `50%`, `next_touch`, DAY-validity `1` бар.

## Основание для блокировки входов

- Финальный Alpaca IEX daily replay: 2021-01-01 — 2026-07-14, `1366` вариантов; в production envelope вошли `716`, eligible — `0`.
- Максимальная train annualized внутри envelope отрицательная (`-2.4281%`); ни один вариант не сохранил положительный train P/L без пяти лучших сделок, а минимальная train top-5 concentration — `64.3890%` при gate `60%`.
- Observation-профиль воспроизведен daily-report на том же cache: total `+395.7899%`, annualized `+33.6314%`, DD `-49.1552%`, `177` сделок. Train total `-20.7892%`, top-5 `89.1044%` и P/L без top-5 `-$16,564.89`, поэтому это не production-valid результат.
- Полный разбор Telegram, PDF, видео, расхождений с автором и тестов: `docs/AUTHOR_REFRESH_2026-07-15.md`.

## Защита исполнения

- Entry planner требует одновременно config-gate и gate внутри свежего отчета; crafted/stale report не может сам включить покупки.
- Daily report создается во временном файле, проверяется как закрытый актуальный бар текущего цикла и только затем атомарно публикуется.
- Partial и full exits используют persistent события, стабильные `client_order_id`, reconciliation после crash/неоднозначного HTTP-ответа и retry только оставшегося количества.
- Backtest и planner фиксируют quantity после signal-close; fill-день не пересчитывает размер по будущему close/regime. Gross/family limits считают текущую рыночную стоимость позиций, remaining notional активных buy-заявок и новые заявки текущего цикла.

## Состояние миграции

- Старый ручной daemon PID `2278` штатно остановлен. Транзакционный `bin/install-launchd` установил оба LaunchAgent и повторно верифицировал финальный HEAD 2026-07-15 21:36 UTC; daemon запущен как PID `95088`, и этот PID совпал с lock и heartbeat. Heartbeat содержит `account_guard_verified=true`, последний monitor завершился с кодом `0`.
- `com.fulltimetrading.paper-status-export` запущен сразу и затем каждые 900 секунд; финальная проверка завершилась с кодом `0`, создала sanitized snapshot и запушила commit `7c08e38`. Legacy cron-дубль удален, других cron-строк не было.
- Snapshot после миграции: account `ACTIVE`, identity/multiplier `2`/shorting/block-проверки пройдены; equity `$27,174.10`, cash `$1,906.00`, buying power `$16,078.10`. Открыты `TECL 66` и `TQQQ 165`, активных ордеров нет.
- После закрытия рынка выполнен свежий current-cycle report `as_of=2026-07-15`: сигнал `TQQQ EMA10` рассчитан, но production-shaped planner создал `0` ордеров и пометил сигнал `production_validation_blocks_entries`. Freshness gate подтвердил закрытый бар и `missing_trading_sessions=0`.

Legacy cycle успел открыть `TQQQ 165` по отчету, созданному 15 июля до закрытия дневной свечи. Такой вход нарушает новый closed-bar guard, не включается в доказательство стратегии и не разрешает следующие покупки. Позиция не ликвидируется задним числом без отдельного решения, но остается под stop/monitor protection.

## Быстрая проверка

```bash
php tools/check_alpaca_env.php
php bin/trade alpaca-account
php bin/trade paper-status --limit=20
bin/install-launchd --status
php tests/paper_monitor_dedupe.php
php tests/paper_entry_validation_guard.php
php tests/smoke.php
```

Live trading не включать. Entry gate можно пересмотреть только после новой заранее заданной гипотезы, train-only selector и еще не просмотренной forward-статистики.
