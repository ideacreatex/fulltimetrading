# FullTimeTrading — current paper state

> Research update 2026-07-16: выбран causal stock-rotation hybrid с total
> `+7391.45%`, CAGR `118.42%` и DD `−30.48%` уже при stress 30 bps. Train
> `108.83%` и validation `101.60%` также проходят целевой порог. Он добавлен
> только как fail-closed paper shadow: production/order submission выключены.
> Активный author-style paper runtime ниже не переключался и не
> перезапускался. Актуальный разбор:
> `docs/CAUSAL_STOCK_ROTATION_HYBRID_V4_2026-07-16.md`.

Обновлено: 2026-07-16 (Asia/Nicosia). Здесь хранится только sanitized operational context: без ключей и полных идентификаторов аккаунта.

## Safety и выбранное решение

- Торговый endpoint жестко ограничен `https://paper-api.alpaca.markets/v2`; redirects для Alpaca trading API запрещены.
- `FTT_PAPER_ONLY=true` и общий submit-контур использует `FTT_ORDERS_ENABLED=true` только для Alpaca paper.
- Новые author-style entry-заявки отдельно заблокированы:
  `FTT_PRODUCTION_ENTRY_ENABLED=false`, причина
  `author_style_unqualified_tactical_rotation_shadow_only_2026-07-16`.
- Сигналы продолжают формироваться; мониторинг и защитные partial/stop/full exits уже открытых paper-позиций остаются активными.
- Observation-профиль `tuned-daily`: universe `UPRO,TQQQ,SOXL,USD,TECL`, `max_gross=1.75`, `max_open=4`, `family_cap=1.20`, cooldown `5`, same-strength `45`, обязательный close выше support, mental stop, БУ `+1%`, partial `50%`, `next_touch`, DAY-validity `1` бар.

## Новый causal stock-rotation shadow

- Frozen profile: `causal-stock-rotation-hybrid-v4`, Alpaca SIP 1Day,
  completed close `D` → open `D+1`, четыре независимых static-capital sleeve.
- При 20 bps: train 2021–23 CAGR `116.98%`, validation 2024–25
  `109.23%`, full `126.95%`, total `+9158.90%`, DD `−30.23%`.
- При обязательных 30 bps: train `108.83%`, validation `101.60%`, full
  `118.42%`, total `+7391.45%`, DD `−30.48%`.
- Full-результат при 30 bps: `255` положительных holding episodes, `19`
  тикеров, доля лучшего эпизода `14.60%`; без пяти лучших дней CAGR остаётся
  `79.27%`.
- Одновременно 20/30 bps проходят `8/20` leave-one-out проверок. Stable
  complete-at-2020 universe сохраняет train выше 100%, но validation даёт
  только `74.75%`; поэтому нужен paper-forward shadow.
- Текущий shadow на close 2026-07-15: dynamic sleeve удерживает `PANW` с
  gross `0.82153`, но следующая сессия не является ребалансировкой — action
  `hold`. Три defensive sleeve в cash с DD-cooldown `46` (`45` после open).
  Новых заявок нет.
- Новый backtester/tool не содержит submit-пути. `production_approved=false`,
  `order_submission_enabled=false`; активный daemon PID `95088` не затронут.

## Предыдущая author-style / touch проверка

- Новый обязательный порог — `100% CAGR` одновременно на train и замороженном OOS при DD до `35%`, planned gross до `1.25x` и observed/bounded gross до `1.30x`.
- В свежем Alpaca SIP 5m grid `SOXL,TQQQ,UPRO` все `18/18` вариантов прошли DQ; `14/18` прошли gross envelope, но ни один не достиг порога или concentration gates. Лучший ряд внутри envelope: train `8.00%`, full `6.43%`, OOS `4.62%` CAGR; 69 сделок, full DD `−22.63%`, top-5 `97.27%` gross profit.
- Четырёхтикерный grid с TECL диагностически дал до `13.50%` full CAGR, но все варианты fail-closed из-за неполных использованных 5m-сессий; они не являются допустимым результатом.
- Walk-forward выбирает только по train и не подменяет отклонённый вариант после просмотра OOS: `candidate_count=18`, `eligible_count=0`, `selected_variant=null`, `qualified_variants=0`.
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
