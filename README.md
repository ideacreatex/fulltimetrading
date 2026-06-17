# Fulltime Trading Bot

PHP-каркас для проверки торгового алгоритма из материалов FTT. Сейчас реализован backtest и guarded paper-trading слой; live trading не используется, а paper-ордера отправляются только при явном `trading.alpaca.orders_enabled=true`.

## Что уже есть

- Market-regime слой: SPY как "король", QQQ/SMH/RSP/IWM/DIA, секторные ETF и крупные весовые акции.
- POOS scanner: поиск кандидатов на первый pullback к EMA20 после сильного роста на объеме.
- Support regularity scanner: поиск акций, которые повторяемо реагируют на EMA/SMA поддержки, с проверкой прошлых касаний и forward-реакции.
- Backtester: общий календарь портфеля, стартовый капитал из конфига, дробные акции, размер позиции от доли капитала и правила клуба #1, лимит открытых позиций, лимитный вход у поддержки, стоп, частичная фиксация, перенос стопа в безубыток, trailing по EMA10.
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
```

По умолчанию используется feed `iex`, потому что `sip` часто требует отдельную подписку.

Что безопасно в этом проекте:

- `.env` добавлен в `.gitignore`.
- Market-data код использует только `https://data.alpaca.markets/v2/stocks/bars`.
- `AlpacaBarsProvider` откажется работать с `api.alpaca.markets` и `paper-api.alpaca.markets`.
- `APCA_DATA_*` используются для исторических данных. Старые `APCA_API_KEY_ID/APCA_API_SECRET_KEY` поддерживаются только как fallback для совместимости.
- `APCA_PAPER_*` используются только `AlpacaPaperClient` против `paper-api.alpaca.markets/v2`.
- По умолчанию `trading.alpaca.orders_enabled=false`, поэтому команды `paper-plan`, `paper-monitor` и `paper-cycle` работают в dry-run/guarded режиме и не отправляют ордера.
- Если paper accounts несколько, выбери нужный paper account в Alpaca dashboard и создай/скопируй ключи именно из него. Для Trading API пара ключей определяет аккаунт; отдельный account id не отправляется в каждом запросе.
- `APCA_PAPER_ACCOUNT_ID` нужен как локальная защита: после read-only `/v2/account` бот сверяет ожидаемое значение с фактическим `id` или `account_number`. Значение из кабинета вида `Paper - PA3BEBVCD1SY` нужно указывать как `PA3BEBVCD1SY`.
- Для paper-теста с авторской нагрузкой нужен paper account equity минимум `$2,000`, `Shorting Enabled=on`, `Fractional Trading=on`, `Max Margin Multiplier=2x`, `Trades Suspended=off`, `Allow PTP Entry=off`. При equity `$1,000` Alpaca оставит фактический `multiplier=1`, даже если shorting включен в настройках.
- `Max Margin Multiplier=4x` можно включать только как отдельный агрессивный/intraday эксперимент. Для swing/overnight логики базовый safety-check остается `APCA_PAPER_EXPECTED_MULTIPLIER=2`, пока отдельный minute/intraday replay не подтвердит 4x.
- `APCA_PAPER_EXPECTED_MULTIPLIER=2` и `APCA_PAPER_EXPECTED_SHORTING_ENABLED=true` нужны как локальная проверка перед paper trading. Если Alpaca вернет другой multiplier или `shorting_enabled=false`, команда `php bin/trade alpaca-account` остановится с ошибкой.
- По документации Alpaca historical bars берутся с `https://data.alpaca.markets/v2/stocks/bars`.
- По документации Alpaca live/paper различаются для Trading API (`api.alpaca.markets` и `paper-api.alpaca.markets`), а Market Data API для обоих идет через `data.alpaca.markets`.
- Обычный key/secret Alpaca не является "data-only" сам по себе. Data-only поведение задается тем, что код ходит только в data endpoint. Если эти же ключи использовать в другом коде против Trading API, это уже не data-only сценарий.

Практическая проверка:

```bash
rg "api.alpaca.markets|paper-api.alpaca.markets|/v2/orders|/v2/account" .
```

В текущем проекте таких live/paper trading-вызовов быть не должно.

Paper trading endpoint уже прописан в `config/config.php` как `trading.alpaca.paper_base_url`. Безопасный порядок запуска: сначала read-only проверка `/v2/account`, затем dry-run генерация заявок, затем paper-submit с kill-switch `trading.alpaca.orders_enabled`.

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

Focused risk grid для подбора нагрузки, family caps и reentry на high-beta leveraged ETF:

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

Текущий лучший risk-adjusted вариант из `var/reports/risk_grid/summary.json`: `risk_grid_g2.0_cap1.10_cd0_same30`. Параметры: `max_gross=2.0`, family cap `1.10`, reentry cooldown `0` дней, same-strength reentry только после `30` дней. Результат с 2021 года: total `+1410.51%`, annualized `+64.80%`, max drawdown `-28.43%`, profit factor `4.17`, Sharpe `1.39`, `322` сделки. Это лучше по риску, чем `high_beta_leverage_3x_hard_stop_caps_reentry` из author-grid: total `+1294.36%`, annualized `+62.39%`, max drawdown `-46.21%`.

Почему total из старых `6000%+` уменьшился: старый прогон был оптимистичнее по исполнению стопов и нагрузке. После `hard_stop_fill_mode=gap_open` стоп, пробитый гэпом, исполняется по open, а `family_exposure_caps` режут одновременную нагрузку в связанных ETF (`UPRO/TQQQ/SOXL/USD/TECL`). Поэтому итоговая доходность ниже, но просадка и риск разорения стали ближе к реальной торговле.

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

Лучший устойчивый вариант из `286` проверенных: `risk_maxgross1.75_maxopen4_familycap1.2_reentrycooldowndays2_allowsamestrengthafterdays45`. Результат: total `+1898.68%`, annualized `+73.52%`, max drawdown `-25.62%`, profit factor `7.16`, Sharpe `1.62`, `265` сделок. По полным годам нет отрицательных лет: `2021 +0.11%`, `2022 +90.37%`, `2023 +48.78%`, `2024 +50.10%`, `2025 +249.34%`; `2026` partial `+31.26%`.

Author-style stop mode (`mental` до +1%, затем hard breakeven stop) проверяется явно:

```bash
php tools/run_param_experiment.php \
  --output-dir=var/reports/param_experiment_mental_latest \
  --swing-stop-mode=mental
```

Лучший consistent-вариант текущего `mental` grid: `risk_maxgross2.5_maxopen5_familycap0.85_reentrycooldowndays0_allowsamestrengthafterdays45_breakevenaddonfraction0`. Результат: total `+1994.28%`, annualized `+75.02%`, max drawdown `-27.76%`, profit factor `7.86`, Sharpe `1.52`, `297` сделок. Годовые строки: `2021 -0.69%`, `2022 +90.77%`, `2023 +98.77%`, `2024 +38.17%`, `2025 +207.25%`, `2026 partial +28.12%`. Периоды лежат в `var/reports/param_experiment_mental_latest/best_consistent_40_35_periods.md`.

Minute replay для проверки hard breakeven stop на Alpaca 1Min:

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

Последний full replay: `289` matched trades, `2` fetch errors, daily PnL `+19579.87`, minute PnL `+9194.87`. Worst-80 replay улучшил хвостовые убытки (`-2871.77` daily vs `-1021.97` minute), но full replay показывает, что жесткий minute-БУ часто режет будущие победители. Поэтому paper trading должен стартовать как наблюдаемый dry-run/paper этап, не как live.

Leverage-only grid для проверки `3x/4x` без смешивания с другими гипотезами:

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

Лучший full-period вариант в этом grid: `risk_maxgross3.5_maxopen5_familycap1.5_reentrycooldowndays0_allowsamestrengthafterdays30`, total `+3080.93%`, annualized `+89.01%`, max drawdown `-33.02%`, `367` сделок. Лучшие `4x` варианты дают выше gross-return, но просадка уходит примерно в `-35%...-47%`, поэтому их нельзя делать базовым paper без отдельного intraday/minute replay.

Stress-test издержек для лучшего варианта: при `20 bps` на сторону annualized `+71.67%`, max drawdown `-30.69%`; при `50 bps` на сторону annualized `+68.73%`, max drawdown `-43.03%`. Это приближенный post-trade stress test, не полноценный engine-level commission/slippage backtest.

Для `mental` best consistent cost stress: при `20 bps` на сторону annualized `+72.97%`, max drawdown `-31.70%`; при `50 bps` на сторону annualized `+69.68%`, max drawdown `-39.29%`.

Daily dry-run/status report без заявок:

Для обычного paper-запуска используй единый цикл. По умолчанию он применяет профиль `tuned-daily`: locked universe `UPRO,TQQQ,SOXL,USD,TECL`, Alpaca IEX cache namespace `alpaca-param-experiment-iex`, `max_gross=2.0`, `max_open=4`, `family_cap=1.00`, BE `+2%`, partial take profit `25%`, order validity `10` bars, same-day-touch entries.

Reference backtest этого профиля: total `+6163.98%`, annualized `+114.11%`, max drawdown `-33.56%`, profit factor `5.48`. Свежий offline status на том же cache namespace: total `+6642.19%`, annualized `+116.69%`, max drawdown `-33.56%`.

Entry orders are limit orders, so paper-plan rounds entry quantity down to whole shares by default (`--integer-qty-for-limit=true`). Fractional trading is still useful for future market partial exits, but fractional limit entries should not be assumed to work at Alpaca.

When Alpaca paper sync is enabled, order sizing uses actual paper account equity (`--paper-sizing-cash=true`) instead of blindly trusting the historical report's `initial_cash`. This keeps the same profile usable on another demo account with a different balance.

Paper-plan also applies an estimated overnight maintenance guard by default (`--maintenance-guard=true`, `--maintenance-buffer-pct=0.70`). This is intentionally more conservative than the raw backtest gross exposure: leveraged ETFs can have much higher maintenance requirements than ordinary stocks. With `$30,000` paper equity the guarded dry-run plan is `USD`, `SOXL`, `TQQQ`, with estimated maintenance about `69.81%` of equity. This leaves room for gaps/slippage, but no software rule can fully guarantee that a leveraged ETF portfolio will never hit margin pressure.

Production folder on the target laptop:

```bash
/Users/admin/Desktop/fulltimetrading
```

Always-on paper daemon:

```bash
cd /Users/admin/Desktop/fulltimetrading
php bin/trade paper-daemon \
  --submit=false \
  --telegram=true \
  --monitor-interval-seconds=60
```

For paper trading after `orders_enabled=true`:

```bash
cd /Users/admin/Desktop/fulltimetrading
php bin/trade paper-daemon \
  --submit=true \
  --telegram=true \
  --monitor-interval-seconds=60
```

Cron restart pattern:

```cron
* * * * /Users/admin/Desktop/fulltimetrading/bin/paper-daemon-cron >> /Users/admin/Desktop/fulltimetrading/var/log/paper_daemon_cron.log 2>&1
```

The daemon uses `var/run/paper_daemon.lock`, so cron can call it every minute without starting duplicates. If the daemon crashes, the lock is released and the next cron run starts it again. Heartbeat: `var/run/paper_daemon_heartbeat.json`; state: `var/run/paper_daemon_state.json`; log: `var/log/paper_daemon.log`.

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
php tools/extract_telegram_setups.php --output=var/reports/telegram_setups.json
php tools/analyze_telegram_setups.php \
  --input=var/reports/telegram_setups.json \
  --output=var/reports/telegram_setup_analysis.json \
  --start=2021-01-01 \
  --end=2026-06-13
```

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
```

Синтаксис всех PHP-файлов:

```bash
find . -path ./materials -prune -o -name '*.php' -print -o -path ./bin/trade -print
```

Затем прогнать `php -l` по найденным файлам.

## Важные ограничения

- Авторазмещение заявок пока не включено: `trading.alpaca.orders_enabled=false`, `paper_only=true`. Текущий слой безопасен для daily dry-run/status и paper preflight.
- Real-time dashboard `fstock` сохраняется в `dashboard_metrics`, но без исторического API эти значения не используются как hard-rule в backtest.
- FBMA/fstock/seasonality используются в backtest только после импорта исторических значений или формализации Pine-логики.
- MP4/YouTube стримы пока не расшифровываются автоматически в этой среде; нужен текстовый transcript или установка инструментов транскрибации.
- Stooq может блокировать автоматические CSV-запросы JS verification/Access denied.
- Yahoo может отдавать rate limit.
- До реальной торговли нужно пройти paper trading с minute-БУ стопами, daily Telegram status и ручной сверкой фактических заявок/исполнений.
# fulltimetrading
