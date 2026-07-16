# Fulltime Trading Bot

PHP-каркас для проверки торгового алгоритма из материалов FTT. Сейчас реализован backtest и guarded paper-trading слой; live trading не используется, а paper-ордера отправляются только при явном `FTT_ORDERS_ENABLED=true`. Новые entry-заявки дополнительно требуют `FTT_PRODUCTION_ENTRY_ENABLED=true`; после реалистичной проверки 2026-07-15 этот флаг по умолчанию выключен, поскольку ни один кандидат не прошел production gates.

## Что уже есть

- Market-regime слой: SPY как "король", QQQ/SMH/RSP/IWM/DIA, секторные ETF и крупные весовые акции.
- POOS scanner: поиск кандидатов на первый pullback к EMA20 после сильного роста на объеме.
- Support regularity scanner: поиск акций, которые повторяемо реагируют на EMA/SMA поддержки, с проверкой прошлых касаний и forward-реакции.
- Backtester: общий календарь портфеля, стартовый капитал из конфига, дробные акции, размер новой позиции от marked equity, занятая нагрузка по текущей рыночной стоимости остатка позиции, правила клуба #1, лимит открытых позиций, лимитный вход у поддержки, стоп, частичная фиксация, перенос стопа в безубыток, trailing по EMA10.
- Club rules: `+1%` переводит стоп в БУ отдельным событием, swing-stop по умолчанию mental, hard-stop включается только явным режимом/после БУ.
- Performance report: кварталы, SPY benchmark, разрез по стратегиям/символам, win rate, profit factor, max drawdown, Sharpe, Sortino, recovery days, closed/unrealized/total PnL.
- SQLite-хранилище баров, сигналов, сделок, dashboard-метрик, paper-позиций, paper-ордеров и журнала действий бота.
- Источники данных:
  - `alpaca`: `https://data.alpaca.markets/v2/stocks/bars`
  - `yahoo`: открытый chart endpoint, может rate-limit без предупреждения
  - `stooq`: CSV endpoint, но сейчас может отдавать JS verification вместо CSV
  - `db`: чтение уже загруженных баров из SQLite

## Установка

Нужен PHP 8.4+ с `curl`, `pdo_sqlite`, `json`.

```bash
php -v
php -m
```

Composer не нужен.

## Alpaca ключи и безопасность

```bash
cp .env.example .env
```

Заполнить:

```text
APCA_DATA_API_KEY_ID=...
APCA_DATA_API_SECRET_KEY=...

APCA_PAPER_API_KEY_ID=...
APCA_PAPER_API_SECRET_KEY=...
APCA_PAPER_BASE_URL=https://paper-api.alpaca.markets/v2
APCA_PAPER_ACCOUNT_ID=...
APCA_PAPER_ACCOUNT_LABEL=paper_strategy_test
APCA_PAPER_EXPECTED_MULTIPLIER=2
APCA_PAPER_EXPECTED_SHORTING_ENABLED=true
FTT_PAPER_ONLY=true
FTT_ORDERS_ENABLED=false
```

По умолчанию используется feed `iex`, потому что `sip` часто требует отдельную подписку.

Что безопасно в этом проекте:

- `.env` добавлен в `.gitignore`.
- Market-data код использует только `https://data.alpaca.markets/v2/stocks/bars`.
- `AlpacaBarsProvider` откажется работать с `api.alpaca.markets` и `paper-api.alpaca.markets`.
- `APCA_DATA_*` используются для исторических данных. Старые `APCA_API_KEY_ID/APCA_API_SECRET_KEY` поддерживаются только как fallback для совместимости.
- `APCA_PAPER_*` используются только `AlpacaPaperClient` против `paper-api.alpaca.markets/v2`.
- По умолчанию `FTT_ORDERS_ENABLED=false`, поэтому команды `paper-plan`, `paper-monitor` и `paper-cycle` работают в dry-run/guarded режиме и не отправляют ордера.
- Если paper accounts несколько, выбери нужный paper account в Alpaca dashboard и создай/скопируй ключи именно из него. Для Trading API пара ключей определяет аккаунт; отдельный account id не отправляется в каждом запросе.
- `APCA_PAPER_ACCOUNT_ID` нужен как локальная защита: после read-only `/v2/account` бот сверяет ожидаемое значение с фактическим `id` или `account_number`. Значение из кабинета вида `Paper - PA3BEBVCD1SY` нужно указывать как `PA3BEBVCD1SY`.
- Для paper-теста с авторской нагрузкой нужен paper account equity минимум `$2,000`, `Shorting Enabled=on`, `Fractional Trading=on`, `Max Margin Multiplier=2x`, `Trades Suspended=off`, `Allow PTP Entry=off`. При equity `$1,000` Alpaca оставит фактический `multiplier=1`, даже если shorting включен в настройках.
- `Max Margin Multiplier=4x` можно включать только как отдельный агрессивный/intraday эксперимент. Для swing/overnight логики базовый safety-check остается `APCA_PAPER_EXPECTED_MULTIPLIER=2`, пока отдельный minute/intraday replay не подтвердит 4x.
- `APCA_PAPER_ACCOUNT_ID`, `APCA_PAPER_EXPECTED_MULTIPLIER=2` и `APCA_PAPER_EXPECTED_SHORTING_ENABLED=true` обязательны для submit-daemon/LaunchAgent. Если поле отсутствует, аккаунт не совпадает, заблокирован либо Alpaca вернет другой multiplier/shorting, monitor не начнет отправку ордеров.
- По документации Alpaca historical bars берутся с `https://data.alpaca.markets/v2/stocks/bars`.
- По документации Alpaca live/paper различаются для Trading API (`api.alpaca.markets` и `paper-api.alpaca.markets`), а Market Data API для обоих идет через `data.alpaca.markets`.
- Обычный key/secret Alpaca не является "data-only" сам по себе. Data-only поведение задается тем, что код ходит только в data endpoint. Если эти же ключи использовать в другом коде против Trading API, это уже не data-only сценарий.

Практическая проверка:

```bash
rg "api.alpaca.markets|paper-api.alpaca.markets|/v2/orders|/v2/account" .
```

В текущем проекте таких live/paper trading-вызовов быть не должно.

Paper trading endpoint уже прописан в `config/config.php` как `trading.alpaca.paper_base_url`. Безопасный порядок запуска: сначала read-only проверка `/v2/account`, затем dry-run генерация заявок, затем paper-submit с kill-switch `FTT_ORDERS_ENABLED`.

Проверить, что ключи видны проекту, не выводя сами секреты:

```bash
php tools/check_alpaca_env.php
```

Paper-проверки:

```bash
php bin/trade alpaca-account
php bin/trade paper-plan --submit=false --telegram=false
php bin/trade paper-monitor --submit=false --telegram=false
php bin/trade paper-cycle --profile=tuned-daily --submit=false --telegram=true
php bin/trade paper-status --limit=20
```

## Команды

Инициализация БД:

```bash
php bin/trade init-db
```

Загрузка исторических баров из Alpaca:

```bash
php bin/trade fetch \
  --provider=alpaca \
  --symbols=SPY,QQQ,SMH,AAPL,MSFT,NVDA \
  --start=2021-01-01 \
  --end=2025-12-31 \
  --feed=iex
```

Backtest через открытый Yahoo endpoint:

```bash
php bin/trade backtest \
  --provider=yahoo \
  --start=2021-01-01 \
  --end=2026-06-13 \
  --benchmark=SPY
```

Если `--symbols` не указан, используется watchlist USA stocks из `config/config.php`.

Backtest по universe из материалов с экспериментальным strict-фильтром закономерности:

```bash
php bin/trade backtest \
  --provider=yahoo \
  --symbols-file=var/reports/universe_symbols.txt \
  --start=2021-01-01 \
  --end=2026-06-13 \
  --benchmark=SPY \
  --support-min-touches=4 \
  --support-min-success-rate=0.70 \
  --support-require-close-above=true
```

`--support-min-touches=4` не является правилом автора из документа. Это наш экспериментальный фильтр строгости; базовый конфиг использует `3` касания.

После импорта SRT-транскриптов universe разделяется на несколько файлов:

- `var/reports/universe_symbols.txt` - stock-only.
- `var/reports/universe_leveraged_symbols.txt` - все найденные плечевые/инверсные инструменты.
- `var/reports/universe_leveraged_long_symbols.txt` - только long-leverage инструменты для текущей long-only стратегии.
- `var/reports/universe_symbols_with_long_leverage.txt` - акции + long-leverage.
- `var/reports/universe_symbols_with_leverage.txt` - акции + все плечевые/inverse, только для исследования.

Inverse/hedge инструменты вроде `SQQQ` и `SCO`, а также short-volatility `SVXY/SVIX/SVYX`, не должны автоматически попадать в long-only стратегию. Для них нужен отдельный hedge/risk-off режим.

Актуальные отчеты:

- `var/reports/yahoo_backtest_material_stock_universe.json` - stock-only baseline.
- `var/reports/yahoo_backtest_material_stock_universe_strict.json` - stock-only strict.
- `var/reports/yahoo_backtest_material_leveraged_long_universe_strict.json` - long-leverage only strict.
- `var/reports/yahoo_backtest_material_with_long_leverage_universe_strict.json` - stock + long-leverage strict.
- `var/reports/yahoo_backtest_material_with_leverage_universe_strict.json` - stock + all leveraged/inverse, диагностический отчет.
- `var/reports/yahoo_compare_1_long_us_instruments_strict.json` - long-only US instruments.
- `var/reports/yahoo_compare_2_long_plus_short_stocks_strict.json` - long + actual short по акциям.
- `var/reports/yahoo_compare_3_long_plus_short_and_inverse_strict.json` - long + actual short + inverse ETF.
- `var/reports/yahoo_compound_2x_compare_3_strict.json` - compounding + 2x gross exposure для режима long + short + inverse ETF.

Для проверки режимов нагрузки:

```bash
php bin/trade backtest \
  --provider=yahoo \
  --symbols-file=var/reports/universe_symbols_legacy_cached_all_leverage.txt \
  --exclude-symbols=SVXY,SVIX,SVYX \
  --short-symbols-file=var/reports/universe_symbols.txt \
  --inverse-symbols-file=var/reports/universe_inverse_hedge_symbols.txt \
  --start=2021-01-01 \
  --end=2026-06-13 \
  --benchmark=SPY \
  --support-min-touches=4 \
  --support-min-success-rate=0.70 \
  --support-require-close-above=true \
  --short-min-touches=4 \
  --short-min-success-rate=0.70 \
  --short-require-close-below=true \
  --max-open-positions=2 \
  --max-gross-exposure-pct=2
```

Author-mode grid после добавления weekly/layered loading:

```bash
php tools/run_author_grid.php
php tools/classify_telegram_messages.php \
  --input=var/reports/telegram_setups.json \
  --output=var/reports/telegram_classified_ftt_admin.json \
  --authors="FTT_Admin Official"
php tools/compare_telegram_signals.php \
  --telegram=var/reports/telegram_classified_ftt_admin.json \
  --signals=var/reports/author_grid/high_beta_leverage_3x_hard_stop_caps_reentry_signals.json \
  --authors="FTT_Admin Official" \
  --window-days=3 \
  --classes=entry,add \
  --class-match=primary
php tools/compare_telegram_positions.php \
  --telegram=var/reports/telegram_classified_ftt_admin.json \
  --positions=var/reports/author_grid/high_beta_leverage_3x_hard_stop_caps_reentry_positions.json \
  --authors="FTT_Admin Official" \
  --window-days=3 \
  --classes=entry,add,hold \
  --class-match=primary
php tools/analyze_drawdown_causes.php \
  --equity=var/reports/author_grid/high_beta_leverage_3x_hard_stop_caps_reentry_equity.json \
  --trades=var/reports/author_grid/high_beta_leverage_3x_hard_stop_caps_reentry_trades.json
```

Grid пишет отчеты, сигналы, trades, equity curve и daily active-position journal в `var/reports/author_grid/`. В grid есть варианты `hard_stop`, `family_caps` и `reentry_after_stop`. Исторические `FBMA/fstock/seasonality` можно сделать обязательными только после импорта истории через `php bin/trade import-history ...`; для hard-filter включается `--require-external-indicators=true`.

Архивный focused risk grid для подбора нагрузки, family caps и reentry на high-beta leveraged ETF:

```bash
php tools/run_risk_grid.php
php tools/analyze_drawdown_causes.php \
  --equity=var/reports/risk_grid/best_40_35_equity.json \
  --trades=var/reports/risk_grid/best_40_35_trades.json \
  --output=var/reports/risk_grid/drawdown_causes_best_40_35.json
php tools/compare_telegram_signals.php \
  --telegram=var/reports/telegram_classified_ftt_admin.json \
  --signals=var/reports/risk_grid/best_40_35_signals.json \
  --authors="FTT_Admin Official" \
  --window-days=3 \
  --classes=entry,add \
  --class-match=primary
php tools/compare_telegram_positions.php \
  --telegram=var/reports/telegram_classified_ftt_admin.json \
  --positions=var/reports/risk_grid/best_40_35_positions.json \
  --authors="FTT_Admin Official" \
  --window-days=3 \
  --classes=entry,add,hold \
  --class-match=primary
```

Исторический лидер этого pre-execution-parity grid — `risk_grid_g2.0_cap1.10_cd0_same30`: total `+1410.51%`, annualized `+64.80%`, max drawdown `-28.43%`, profit factor `4.17`, Sharpe `1.39`, `322` сделки. Эти цифры сохранены для воспроизводимости старого исследования, но не являются текущим paper benchmark и не сопоставимы с финальным `next_touch` grid.

Почему total из старых `6000%+` последовательно уменьшался: ранние прогоны были оптимистичнее по исполнению стопов, нагрузке и доступности входа. `hard_stop_fill_mode=gap_open`, marked-equity sizing и family caps исправили часть расхождений; `next_touch`/valid-1 исключил fill в день уже закрывшегося сигнала. Регрессия 16 июля дополнительно устранила зависимость allocation от входного порядка тикеров и ввела глобальный score priority, совпадающий с paper planner. Только детерминированный replay используется для решения об entry gate.

Param experiment вокруг лучших настроек:

```bash
php tools/run_param_experiment.php
php tools/report_period_returns.php \
  --report=var/reports/param_experiment/best_consistent_40_35_report.json \
  --period=all \
  --output=var/reports/param_experiment/best_consistent_40_35_periods.md
php tools/stress_trade_costs.php \
  --trades=var/reports/param_experiment/best_consistent_40_35_trades.json \
  --equity=var/reports/param_experiment/best_consistent_40_35_equity.json \
  --slippage-bps=0,5,10,20,50 \
  --output=var/reports/param_experiment/best_consistent_40_35_cost_stress.json
```

`run_param_experiment.php` теперь разделяет два разных результата. Старые `best_*` — exploratory ranking по полному периоду, в котором post-2024 метрики влияют на score. Для production используется `walk_forward_production_envelope`: selector получает только 2021–2023 metrics и заранее заданный envelope, после чего ровно один замороженный вариант оценивается на 2024–2026 без fallback. Одновременно считаются доля top-1/top-5, концентрация по тикеру и P/L без лучших сделок — отдельно для train, holdout и полного периода.

Финальная проверка 2026-07-15 в `var/reports/param_experiment_production_planned_qty_20260715/summary.json` использует только доступное paper-исполнение: closed-bar сигнал фиксирует количество и создает `next_touch` заявку на следующий бар с DAY-validity 1; pending-заявка резервирует слот/notional, а mental-stop close исполняется на следующем open с учетом gap. Из 1366 вариантов 716 попали в envelope `gross <= 2`, `max_open <= 4`, но production selector не выбрал ни одного. Максимальная train annualized была отрицательной (`-2.4281%`), минимальная train top-5 концентрация — `64.3890%` при gate `60%`; ни один кандидат не сохранил положительный train P/L без пяти лучших сделок. Старые результаты, где quantity пересчитывалась по close/regime будущего fill-дня, как и `same_day_touch`, считаются look-ahead и не используются.

Новый причинный `advance_next_session` research-профиль, который планирует лимит только на следующую сессию и проходит 35% daily block-bootstrap tail gate, описан в [`docs/SAME_DAY_TOUCH_ALPACA_OPTIMIZATION_2026-07-16.md`](docs/SAME_DAY_TOUCH_ALPACA_OPTIMIZATION_2026-07-16.md). Alpaca IEX minute replay теперь fail-closed: неполный набор выходов не публикует portfolio CAGR/DD. Профиль остается `paper_forward_candidate`, не меняет production defaults и не включает entry submission.

Точный realistic grid воспроизводится так:

```bash
php tools/run_param_experiment.php \
  --provider=offline-cache \
  --cache-namespace=alpaca-param-experiment-iex \
  --symbols=USD,SOXL,TECL,TQQQ,UPRO \
  --start=2021-01-01 \
  --end=2026-07-14 \
  --initial-cash=30000 \
  --swing-stop-mode=mental \
  --hard-stop-fill-mode=gap_open \
  --support-require-close-above=true \
  --order-fill-mode=next_touch \
  --order-fill-modes=next_touch \
  --order-valid-bars=1 \
  --order-valid-bars-values=1 \
  --break-even-profit-pct-values=0.01,0.02 \
  --partial-take-profit-pct-values=0.25,0.333333,0.5 \
  --min-pre-split-annualized-return-pct=0.20 \
  --output-dir=var/reports/param_experiment_production_planned_qty_20260715
```

Лучший по полному периоду exploratory-вариант дал total `+604.3570%`, annualized `+42.4043%`, DD `-48.6522%`, но на train имел total `-10.9508%`, annualized `-3.8153%`, top-5 `82.1527%` и P/L без top-5 `-$18,253.86`. Поэтому post-2024 результат не используется для ретроспективного разрешения входов. Для observation 15 июля был оставлен профиль `gross=1.75`, `open=4`, `family=1.20`, cooldown `5`, same-strength `45`, обязательный close над support, mental stop, BE `+1%`, partial `50%`: полный период `+395.7899%`, annualized `+33.6314%`, DD `-49.1552%`, 177 сделок; его train total `-20.7892%`, top-5 `89.1044%` и P/L без top-5 `-$16,564.89` также не проходят production gates.

Регрессия 16 июля установила, что этот старый результат зависел от порядка тикеров. После глобального детерминированного signal priority актуальный locked5 hold-control дает total `+143.34%`, annualized `+17.46%`, DD `-47.87%`, train annualized `-13.84%` и P/L без top-5 `-$6,708`. Это не production-кандидат; entry gate остается закрыт. Полная сверка старых/новых результатов, 2x/3x/mixed, Alpaca/Yahoo и периодов: [`docs/STRATEGY_REGRESSION_2026-07-16.md`](docs/STRATEGY_REGRESSION_2026-07-16.md). Источники автора: [`docs/AUTHOR_REFRESH_2026-07-15.md`](docs/AUTHOR_REFRESH_2026-07-15.md).

Архивный minute replay прежней выборки для диагностики hard breakeven stop на Alpaca 1Min:

```bash
php tools/replay_trades_intraday.php \
  --trades=var/reports/param_experiment_mental_latest/best_consistent_40_35_trades.json \
  --signals=var/reports/param_experiment_mental_latest/best_consistent_40_35_signals.json \
  --output=var/reports/param_experiment_mental_latest/best_consistent_40_35_intraday_replay_all_regular.json \
  --limit=all \
  --feed=iex \
  --session=regular \
  --skip-fetch-errors=true
```

В этом архивном replay было `289` matched trades и `2` fetch errors: daily PnL `+19579.87`, minute PnL `+9194.87`. Он показывает, что жесткий minute-БУ часто режет будущие победители, но не валидирует текущий `next_touch` observation-профиль и не разрешает новые entries.

Архивный leverage-only grid для проверки `3x/4x` без смешивания с другими гипотезами:

```bash
php tools/run_param_experiment.php \
  --output-dir=var/reports/leverage_experiment_full \
  --leverage-only=true \
  --max-gross-values=1.75,2,2.5,3,3.5,4 \
  --family-cap-values=1.0,1.1,1.2,1.5 \
  --cooldown-days=0,2 \
  --same-after-days=15,30,45 \
  --max-open=5
```

Этот pre-execution-parity full-period grid оставлен только как исторический exploratory-артефакт. Его прежний лидер `risk_maxgross3.5_maxopen5_familycap1.5_reentrycooldowndays0_allowsamestrengthafterdays30` показывал total `+3080.93%`, annualized `+89.01%`, max drawdown `-33.02%`, `367` сделок, но эти цифры нельзя сравнивать с реалистичным `next_touch` grid и нельзя делать базовым paper.

Cost stress реалистичного full-period exploratory-лидера: 0 bps `+42.40%/-48.65%` annualized/DD; 5 bps `+41.97%/-49.78%`; 10 bps `+41.54%/-50.91%`; 20 bps `+40.65%/-53.21%`; 50 bps `+37.80%/-60.70%`. Это приближенный post-trade stress, не полноценный engine-level commission/slippage backtest; он не исправляет отрицательный train и не делает вариант production-eligible.

Daily observation/status report без новых entry-заявок:

Для обычного paper-запуска используй единый цикл. По умолчанию он применяет observation-профиль `tuned-daily`: locked universe `UPRO,TQQQ,SOXL,USD,TECL`, Alpaca IEX cache namespace `alpaca-param-experiment-iex`, `max_gross=1.75`, `max_open=4`, `family_cap=1.20`, обязательный close над support, BE `+1%`, partial take profit `50%`, order validity `1` бар и `next_touch` entries. `FTT_PRODUCTION_ENTRY_ENABLED=false` блокирует новые входы с причиной `production_validation_blocks_entries`; сигналы, мониторинг уже открытых позиций и защитные exits продолжают работать.

Значения `+395.7899% / +33.6314% / −49.1552%` выше описывают deployed observation snapshot 15 июля, но больше не являются корректным детерминированным benchmark. Исследовательский locked5 replay до 2026-07-15 после исправления allocation order: hold total `+143.34%`, annualized `+17.46%`, DD `−47.87%`; routine partial 50% total `+140.51%`, annualized `+17.21%`, DD `−47.12%`. Оба имеют отрицательный train и отрицательный P/L без top-5; ни один не разрешает автоматические входы. В daily JSON записывается блок `model.robustness`, а новый data-quality block дополнительно проверяет session coverage.

Entry orders are limit orders, so paper-plan rounds entry quantity down to whole shares by default (`--integer-qty-for-limit=true`). Fractional trading is still useful for future market partial exits, but fractional limit entries should not be assumed to work at Alpaca.

When Alpaca paper sync is enabled, order sizing uses actual marked paper account equity (`--paper-sizing-cash=true`) instead of blindly trusting the historical report's `initial_cash`. Backtest and daily report share `PositionSizingPolicy`, while current positions are valued at market rather than entry notional. This keeps the same economic sizing basis on a demo account with a different balance.

Paper-plan also applies an estimated overnight maintenance guard by default (`--maintenance-guard=true`, `--maintenance-buffer-pct=0.70`). In addition, it enforces both global gross exposure and `family_exposure_cap_pct` against current Alpaca positions, remaining notional in active buy orders and orders already planned in the same cycle. This keeps paper sizing aligned with the gross/family assumptions used in backtest. The guards limit new orders; gaps and subsequent price changes can still move observed exposure above a pre-trade cap, and no software rule can guarantee that leveraged ETFs will never create margin pressure.

Production folder on the target laptop:

```bash
/Users/admin/Documents/fulltimetrading/fulltimetrading
```

Always-on paper daemon:

```bash
cd /Users/admin/Documents/fulltimetrading/fulltimetrading
php bin/trade paper-daemon \
  --submit=false \
  --telegram=true \
  --monitor-interval-seconds=60
```

For paper trading after `FTT_ORDERS_ENABLED=true` in `.env`:

```bash
cd /Users/admin/Documents/fulltimetrading/fulltimetrading
php bin/trade paper-daemon \
  --submit=true \
  --telegram=true \
  --monitor-interval-seconds=60
```

На macOS daemon и sanitized status export устанавливаются как два user LaunchAgent. Скрипт сам подставляет абсолютные пути текущего репозитория и `php`; перед установкой можно посмотреть и провалидировать оба plist без изменения `~/Library` или состояния `launchd`:

```bash
bin/install-launchd --dry-run
bin/install-launchd
bin/install-launchd --status
```

Обычный запуск `bin/install-launchd` сразу загружает jobs. `com.fulltimetrading.paper-daemon` запускается с `--submit=true`, `RunAtLoad` и `KeepAlive`, поэтому сначала должны быть осознанно настроены `.env` и `FTT_ORDERS_ENABLED=true`. Старый ручной/cron daemon нужно остановить: installer откажется продолжать, если живой процесс вне LaunchAgent уже держит `var/run/paper_daemon.lock`. После bootstrap installer по умолчанию до `45` секунд (`DAEMON_VERIFY_TIMEOUT_SECONDS`) ждёт совпадения launchd PID, PID владельца lock и PID свежего heartbeat, а также свежего успешного monitor-run, завершившегося уже после старта текущего daemon. При ошибке или сигнале предыдущий daemon plist восстанавливается. Успешно проверенный daemon остаётся запущенным, даже если последующая установка status-export завершится ошибкой. Одновременно держать cron и LaunchAgent для одной задачи нельзя.

`com.fulltimetrading.paper-status-export` запускается при загрузке и каждые `900` секунд. Он напрямую выполняет `php bin/trade paper-status-export --git=true --push=true`, без `git pull`, `--rebase` или `--autostash`. Installer фиксирует текущую ветку в plist; ее можно явно задать через `STATUS_GIT_BRANCH`, remote — через `STATUS_GIT_REMOTE`. После переключения рабочей ветки LaunchAgent нужно переустановить. До изменения launchd installer требует чистое рабочее дерево и точное совпадение локального `HEAD` с уже опубликованной remote-веткой. После bootstrap он по умолчанию до `45` секунд (`STATUS_VERIFY_TIMEOUT_SECONDS`) проверяет первый свежий snapshot и `last exit code = 0`: Alpaca sync без errors, доступный и не заблокированный account, `orders_enabled=true`, `paper_only=true`, корректный paper host, установленные data/paper keys и ожидаемый safety-state `production_entry_enabled=false`. Затем повторно проверяется совпадение local/remote; иначе status LaunchAgent откатывается, а уже проверенный daemon остается запущенным. Safe-push preflight проверяет ancestry и разрешает во всех исходящих коммитах только `var/status/latest_paper_status.json` и `.md`. Push отправляет точный проверенный commit с exact `--force-with-lease`, поэтому удалённая или перемотанная ветка не будет перезаписана. При code commit, diverged history или другом output path экспорт завершается с ошибкой до `git add/commit`; накопившиеся status-only коммиты можно безопасно отправить повторным запуском. Прежнюю cron-запись status export нужно удалить в рамках миграции, сохранив все остальные cron jobs и не оставляя параллельных commit/push.

Удаление обоих LaunchAgent (логи, heartbeat и state остаются в проекте):

```bash
bin/install-launchd --uninstall
```

Launchd stdout/stderr пишутся в `var/log/launchd_paper_daemon.*.log` и `var/log/launchd_paper_status_export.*.log`. Сам daemon использует lock `var/run/paper_daemon.lock`, heartbeat `var/run/paper_daemon_heartbeat.json`, state `var/run/paper_daemon_state.json` и log `var/log/paper_daemon.log`.

It commits and pushes only:

```text
var/status/latest_paper_status.json
var/status/latest_paper_status.md
```

It does not commit `.env`, SQLite DB, raw logs, cache, PDF/video materials, or full Alpaca account identifiers.

```bash
php bin/trade paper-cycle \
  --profile=tuned-daily \
  --submit=false \
  --telegram=true
```

`best-consistent` и `leverage-growth` оставлены только для сравнения. Их нельзя делать базовым paper-профилем без отдельного fresh replay и контроля просадки.

```bash
php tools/daily_signal_report.php \
  --provider=yahoo \
  --include-account=false \
  --telegram=false \
  --swing-stop-mode=mental \
  --max-gross-exposure-pct=2.0 \
  --family-cap=1.10 \
  --max-open-positions=4 \
  --initial-cash=30000
```

Отчет пишет JSON в `var/reports/daily/latest_signal_report.json` и текст для Telegram в `var/reports/daily/latest_signal_report.txt`. Для отправки в канал добавь в `.env` `TELEGRAM_BOT_TOKEN` и `TELEGRAM_CHAT_ID`, затем запусти с `--telegram=true`. Отчет отправляется даже без новых сигналов: там будет статус данных, режим рынка, open model positions, health warnings и action.

Если интернет пропал, используй локальный кэш без сетевых запросов:

```bash
php tools/daily_signal_report.php \
  --offline=true \
  --telegram=false \
  --swing-stop-mode=mental \
  --max-gross-exposure-pct=2.0 \
  --family-cap=1.10 \
  --max-open-positions=4 \
  --initial-cash=30000 \
  --output=var/reports/daily/offline_signal_report.json \
  --text-output=var/reports/daily/offline_signal_report.txt
```

Offline-отчет явно пишет `Mode: offline cache` и health warning `offline cache mode: no fresh network validation`. Это годится для контроля уже скачанных данных, но не заменяет свежий pre-market/regular status.

Minute replay тоже умеет работать только по кэшу:

```bash
php tools/replay_trades_intraday.php \
  --offline=true \
  --trades=var/reports/param_experiment_mental_latest/best_consistent_40_35_trades.json \
  --signals=var/reports/param_experiment_mental_latest/best_consistent_40_35_signals.json \
  --output=var/reports/param_experiment_mental_latest/offline_intraday_replay_worst10_regular.json \
  --limit=10 \
  --feed=iex \
  --session=regular
```

В summary есть `missing_minute_bars` и `unreplayable_trades`, чтобы было видно, где кэша не хватило.

Backtest прямо через Alpaca:

```bash
php bin/trade backtest \
  --provider=alpaca \
  --symbols=AAPL,MSFT,NVDA \
  --market=SPY,QQQ,SMH,RSP,IWM,XLK,XLV,XLF \
  --start=2021-01-01 \
  --end=2025-12-31
```

Сканер сигналов без симуляции сделок:

```bash
php bin/trade scan \
  --provider=db \
  --symbols=AAPL,MSFT,NVDA \
  --market=SPY,QQQ,SMH,RSP,IWM,XLK,XLV,XLF \
  --start=2021-01-01 \
  --end=2025-12-31
```

Импорт значений real-time dashboard из CSV:

```bash
php bin/trade import-dashboard --file=dashboard.csv --session=regular
```

Ожидаемые колонки: `captured_at,session_type,code,value`. Дополнительные колонки сохраняются в JSON `payload`.

Импорт FBMA/FBMA20/seasonality-снимков из CSV:

```bash
php bin/trade import-indicators --file=indicators.csv
```

Ожидаемые колонки: `captured_at,symbol,timeframe,indicator,signal,value`. Например `AAPL,1D,fbma,pullback_to_20ema,1`.

Импорт исторических выгрузок `fstock`/TradingView из wide CSV:

```bash
php bin/trade import-history --file=history.csv --source=fstock --session=regular
```

Поддерживаются две формы:

- длинная: `captured_at,code,value` или `captured_at,symbol,timeframe,indicator,signal,value`;
- широкая: `date,S5FD,S5TW,NDFD,fbma,fbma20,seasonality`.

Извлечение и проверка Telegram-сетапов:

```bash
php tools/extract_telegram_setups.php \
  --dir='/absolute/ChatExport_2026-07-15' \
  --dir='/absolute/ChatExport_2026-07-15 (2)' \
  --after=2026-06-13 \
  --dedupe=1 \
  --output=var/reports/telegram_setups.json
php tools/classify_telegram_messages.php \
  --input=var/reports/telegram_setups.json \
  --output=var/reports/telegram_classified.json
php tools/compare_telegram_signals.php \
  --telegram=var/reports/telegram_classified.json \
  --signals=var/reports/author_grid/best_signals.json \
  --direction-aware=1 \
  --action-aware=1 \
  --setup-aware=1 \
  --output=var/reports/telegram_signal_alignment.json
php tools/analyze_telegram_setups.php \
  --input=var/reports/telegram_setups.json \
  --output=var/reports/telegram_setup_analysis.json \
  --start=2021-01-01 \
  --end=2026-06-13
```

Importer читает Telegram Desktop `result.json` или legacy `messages*.html`, сохраняет пути photo/video/voice/file и media-only сообщения, а dedupe объединяет public/private зеркала с provenance. Строгая initial-entry сверка требует совместимости направления, проверенного действия, timeframe, типа и периода MA; coarse совпадение только по дате/семейству сохраняется отдельно и не выдается за подтвержденный setup. Плоские MA-упоминания в multi-ticker сообщении fail closed, пока нет ticker-bound связи. Add/hold/exit требуют позиции автора и сравниваются как правила, а не как новые entry-сигналы.

Отчет показывает `message × ticker`: была ли цена рядом с дневной/недельной EMA/SMA, сколько похожих реакций было раньше, и что произошло через 5/10/20/63 торговых дня.

Построение universe из Telegram, отчетов и текстовых транскриптов видео:

```bash
php tools/extract_telegram_trades.php
php tools/build_universe_from_materials.php
php tools/build_regular_universe.php
```

Видео-файлы лежат в `materials/trading_pdfs/video/`, но в текущей среде нет `ffmpeg`/`whisper`/`yt-dlp`. Для учета стримов положи `.txt`, `.vtt` или `.srt` расшифровки в `materials/video_transcripts/`, затем запусти:

```bash
php tools/extract_transcript_setups.php
php tools/build_universe_from_materials.php
```

## TradingView / FBMA

В `config/config.php` сохранены обязательные внешние ссылки:

- FBMA: `https://ru.tradingview.com/script/2lwYNmF2-fbma/`
- FBMA 20: `https://ru.tradingview.com/script/cVfxQDkQ-fbma-20/`
- Seasonality and Presidential cycle: `https://ru.tradingview.com/script/psG4Vj0S-seasonality-and-presidential-cycle/`
- Дневные, 4h, 15m и недельные TradingView layouts из Telegram.

Они учтены как обязательный pre-trade слой. Численно в backtest они будут использоваться только после импорта Pine/source логики или исторического экспорта значений/сигналов.

## Проверка

```bash
php tests/smoke.php
php tests/paper_monitor_dedupe.php
php tests/paper_status_export_git_guard.php
php tests/paper_family_exposure_guard.php
php tests/robustness_analyzer.php
php tests/walk_forward_selector.php
php tests/position_sizing_policy.php
php tests/alpaca_paper_client_guard.php
php tests/paper_daily_report_freshness.php
php tests/backtester_execution_semantics.php
php tests/paper_entry_validation_guard.php
```

Синтаксис всех PHP-файлов:

```bash
find . -path ./materials -prune -o -name '*.php' -print -o -path ./bin/trade -print
```

Затем прогнать `php -l` по найденным файлам.

## Важные ограничения

- Live endpoint кодом не используется, `FTT_PAPER_ONLY=true`. Общий submit-контур требует `FTT_ORDERS_ENABLED=true`, но новые входы отдельно заблокированы `FTT_PRODUCTION_ENTRY_ENABLED=false`, потому что realistic grid не дал production-eligible варианта. Мониторинг и защитные exits существующих paper-позиций остаются активными; фактическое состояние фиксируется в `docs/CURRENT_STATE.md`.
- Real-time dashboard `fstock` сохраняется в `dashboard_metrics`, но без исторического API эти значения не используются как hard-rule в backtest.
- FBMA/fstock/seasonality используются в backtest только после импорта исторических значений или формализации Pine-логики.
- Последние публичные YouTube-стримы проверены вручную и отражены в `docs/AUTHOR_REFRESH_2026-07-15.md`; полный автоматический transcript для каждого MP4/стрима в репозитории не архивируется.
- Stooq может блокировать автоматические CSV-запросы JS verification/Access denied.
- Yahoo может отдавать rate limit.
- Entry gate нельзя включать по красивому full-period результату: сначала нужны новая заранее сформулированная гипотеза, train-only отбор и еще не просмотренная paper-forward статистика с ручной сверкой фактических сигналов/исполнений.
# fulltimetrading
