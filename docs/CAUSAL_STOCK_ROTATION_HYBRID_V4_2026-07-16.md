# Causal stock rotation hybrid v4 — 2026-07-16

## Выбранный результат

Новый frozen-профиль `causal-stock-rotation-hybrid-v4` достиг целевого
масштаба доходности и прошёл все заранее зафиксированные historical gates при
базовых расходах 20 bps и обязательном stress 30 bps:

- completed close `D` формирует решение, исполнение возможно только на open
  следующей торговой сессии `D+1`;
- Alpaca SIP, split-adjusted 1Day OHLCV, торговый период
  2021-01-04 — 2026-07-15;
- при 20 bps: train CAGR `116.98%`, validation CAGR `109.23%`, full CAGR
  `126.95%`, total `+9158.90%`, max DD `−30.23%`;
- при 30 bps: train CAGR `108.83%`, validation CAGR `101.60%`, full CAGR
  `118.42%`, total `+7391.45%`, max DD `−30.48%`;
- `$30,000` в replay превращаются в `$2,777,671` при 20 bps или
  `$2,247,436` при 30 bps;
- `selected=true`, `failed_gates=[]` при обоих обязательных уровнях расходов.

Машинный результат:
[`research_results/causal_stock_rotation_hybrid_v4_20260716.json`](research_results/causal_stock_rotation_hybrid_v4_20260716.json).
В нём сохранены data hash, implementation hashes, все метрики, cost stress,
rolling windows, leave-one-out и stable-universe проверки.

`selected=true` означает historical qualification, а не разрешение реальных
заявок. Профиль оставлен в отдельном fail-closed paper shadow:
`production_approved=false`, `order_submission_enabled=false`.

## Почему выбран именно 60/40

Портфель состоит из четырёх независимых static-capital books. Между ними нет
переводов капитала и оптимистичного неттинга издержек:

| Sleeve | Вес | Market filter | Особенности риска |
|---|---:|---|---|
| `dynamic_loo10` | 60.00% | SPY > SMA200 | max gross 1.16x, QQQ50 throttle 0.85, DD circuit 19%/40 сессий, static + dynamic trailing |
| `qqq200_full` | 13.33% | QQQ > SMA200 | max gross 1.18x, DD circuit 15%/50 сессий, trailing 22.5% |
| `spy200_full` | 13.33% | SPY > SMA200 | max gross 1.18x, DD circuit 15%/50 сессий, trailing 22.5% |
| `qqq150_ex_crypto` | 13.33% | QQQ > SMA150 | тот же риск, universe без MSTR/COIN |

Граница веса dynamic-sleeve была проверена отдельно. `65%` давали немного
больше total (`+7513.70%` при 30 bps), но при 20 bps доля лучшего прибыльного
эпизода стала выше лимита `15%`. Веса `70–80%` нарушали тот же concentration
gate уже в stress. `60%` — максимальная замороженная точка, которая проходит
лимит при обоих уровнях расходов, поэтому более красивый, но менее устойчивый
headline не выбран.

Сравнение с предыдущими допустимыми вариантами при 30 bps:

| Вариант | Train CAGR | Validation CAGR | Full CAGR | Total | DD | LOO 20/30 |
|---|---:|---:|---:|---:|---:|---:|
| Balanced v2 | 100.20% | 103.04% | 108.64% | +5717.25% | −26.55% | 4/20 |
| Три defensive sleeves | 101.11% | 101.98% | 109.86% | +5907.45% | −26.86% | 5/20 |
| **Hybrid v4 60/40** | **108.83%** | **101.60%** | **118.42%** | **+7391.45%** | **−30.48%** | **8/20** |

## Замороженная логика

Общий cross-sectional score:

```text
score = (−2×R5 + R20 + R60 + 2×R90 − R120) / volatility20
```

- Universe: `AAPL, MSFT, NVDA, AMZN, META, GOOGL, TSLA, AVGO, AMD, MU,
  MRVL, ANET, PLTR, SMCI, MSTR, COIN, CRWD, PANW, DELL, WDC`.
- Нулевая 252-дневная factor-компонента не меняет score, но требует полную
  годовую историю; дополнительно нужны ADV20 не ниже `$5m` и положительный
  score.
- Выбирается один лучший тикер в каждом sleeve; tie-break по символу
  детерминирован.
- Плановая ротация раз в три торговые сессии.
- Нагрузка ограничивается asset-volatility target, SPY-volatility target и
  max-gross каждого sleeve.
- Dynamic sleeve использует static trailing `22.5%` и causal dynamic trailing
  `clip(4 × daily volatility, 12%, 25%)`; нарушение на close ставит выход
  только на следующий open. После выхода cooldown восемь сессий.
- Остальные sleeves используют trailing `22.5%` и cooldown семь сессий.
- Portfolio circuits переармируются после cash-cooldown относительно нового
  equity epoch, поэтому защита может сработать повторно.
- Каждый перевод позиции оплачивает one-way cost отдельно. Заёмная часть
  оплачивает margin interest `6.25%` годовых по календарным дням.
- Консервативная gross-граница считается как high-notional / low-equity;
  исторический максимум `1.28125x` ниже gate `1.30x`.

## Cost stress

| One-way cost | Train CAGR | Validation CAGR | 2026 YTD total / annualized | Full total / CAGR | Full DD | Gate |
|---:|---:|---:|---:|---:|---:|---|
| 20 bps | 116.98% | 109.23% | +108.96% / 294.87% | +9158.90% / 126.95% | −30.23% | pass |
| 30 bps | 108.83% | 101.60% | +104.18% / 278.21% | +7391.45% / 118.42% | −30.48% | pass |
| 35 bps | 96.64% | 97.91% | +96.41% / 251.82% | +5703.64% / 108.56% | −30.41% | fail 100% train/validation |
| 40 bps | 93.33% | 94.28% | +94.25% / 244.62% | +5157.05% / 104.86% | −30.54% | fail |
| 50 bps | 86.87% | 88.70% | +74.04% / 180.83% | +3915.26% / 95.10% | −30.79% | fail |

2026 annualized — пересчёт неполного периода, а не обещание результата
полного календарного года. В cost model `20 bps` означает `0.20%` на каждое
одностороннее изменение позиции; при полном развороте расходы списываются с
продажи и новой покупки.

## Проверка, что результат не создан одной сделкой

При 20 bps:

| Период | Top-1 day | Top-5 days | CAGR без 5 лучших дней | Положительных episodes | Top-1 episode | Тикеров |
|---|---:|---:|---:|---:|---:|---:|
| Train 2021–23 | 4.85% | 16.81% | 60.97% | 138 | 13.12% | 19 |
| Validation 2024–25 | 6.05% | 16.52% | 49.64% | 93 | 13.20% | 15 |
| 2026 YTD | 11.51% | 26.85% | 48.79% | 37 | 28.17% | 7 |
| Full | 2.30% | 9.91% | 86.27% | 260 | 14.94% | 19 |

На дне ротации episode P/L делится точно по execution-open: overnight P/L,
margin и exit-cost относятся к старому тикеру, entry-cost и open-to-close P/L
— к новому. Сумма сегментов программно сверяется с изменением equity каждого
дня. Поэтому `14.94%` — не приближённая атрибуция всей дневной свечи. Запас до
gate `15%` равен `0.06` процентного пункта; больший вес dynamic-sleeve не
допускается.

При обязательных 30 bps full CAGR без пяти лучших дней остаётся `79.27%`,
положительных holding episodes — `255`, доля лучшего — `14.60%`, тикеров с
return attribution — `19`. Короткий 2026 holdout сильнее концентрирован, но
даже после удаления пяти лучших дней его annualized CAGR равен `42.85%`.

Годовые результаты при 30 bps: 2021 `+143.29%`, 2022 `−17.17%`, 2023
`+345.86%`, 2024 `+124.48%`, 2025 `+81.92%`, 2026 до 15 июля `+104.18%`.
Цель выполнена по train/validation/full CAGR, а не по каждому календарному
году: отрицательный 2022 сохранён и не скрыт.

Rolling windows при 30 bps:

| Окно | Количество | Худший CAGR | Median CAGR | Доля CAGR ≥100% | Худший DD |
|---:|---:|---:|---:|---:|---:|
| 252 сессии | 1,137 | −19.15% | 96.58% | 48.46% | −30.48% |
| 504 сессии | 885 | 29.96% | 115.63% | 70.06% | −30.48% |

## Universe robustness

- `8/20` leave-one-ticker-out рядов проходят core gates одновременно при 20
  и 30 bps: без `AAPL`, `MSFT`, `AMZN`, `AVGO`, `AMD`, `MRVL`, `COIN` или
  `PANW`.
- Это вдвое лучше `4/20` у balanced v2, но всё ещё не независимость от
  universe.
- Complete-at-2020 universe исключает `PLTR` и `COIN`: при 30 bps train CAGR
  остаётся `100.71%`, full CAGR `103.15%`, DD `−32.66%`, но validation CAGR
  падает до `74.75%`.
- Поэтому профиль запускается как paper-forward shadow, а не автоматически
  включённый production executor.

## Расхождения с алгоритмом автора

| Автор FTT | Hybrid v4 |
|---|---|
| ПООС: catalyst и первый плавный откат к EMA10/20 | Cross-sectional ranking доходностей и volatility |
| Limit у поддержки или подтверждённая консолидация | Completed close `D` → scheduled open `D+1` |
| FBMA/FBMA20, ATR, свечи, объём, закономерность MA | В score напрямую не входят; ADV используется только как liquidity gate |
| Stop около EMA − ATR/нарушения структуры, часто 3–5% | Wide close trailing 12–25% и portfolio DD circuits |
| БУ после примерно +1% | БУ отсутствует |
| Partial 1/2 или 1/3 около 1.5R | Partial отсутствует |
| Старт 5–10%, нагрузка растёт после снижения риска | Один тикер на sleeve, aggregate gross до 1.30x bound |
| SPY/QQQ/SMH/VIX/сезонность как комплексный dashboard | Автоматизированы SPY/QQQ SMA и volatility throttles |

Hybrid v4 не выдаётся за точную автоматизацию ПООС. Это отдельная причинная
rotation-модель, которая использует совместимые идеи автора: market-first,
лидеры, контроль нагрузки и выход при нарушении риска. Полная сверка PDF,
Telegram и видео сохранена в
[`AUTHOR_REFRESH_2026-07-15.md`](AUTHOR_REFRESH_2026-07-15.md).

## Реализация и текущий shadow

- [`config/tactical_rotation.php`](../config/tactical_rotation.php) — frozen
  веса, параметры и qualification gates.
- [`src/Backtest/CausalTacticalRotationBacktester.php`](../src/Backtest/CausalTacticalRotationBacktester.php)
  — causal single-sleeve replay.
- [`src/Backtest/CausalTacticalRotationEnsembleBacktester.php`](../src/Backtest/CausalTacticalRotationEnsembleBacktester.php)
  — независимые static-capital sleeves и aggregate accounting.
- [`src/Backtest/TacticalRotationQualification.php`](../src/Backtest/TacticalRotationQualification.php)
  — единый набор gates.
- [`src/Trading/TacticalRotationShadowContext.php`](../src/Trading/TacticalRotationShadowContext.php)
  — read-only calendar/asset guard без submit-зависимости.
- [`tools/run_tactical_rotation_backtest.php`](../tools/run_tactical_rotation_backtest.php)
  — Alpaca replay, stress, rolling, robustness и atomic shadow report.

На close 2026-07-15 dynamic sleeve удерживает `PANW` с gross `0.82153` внутри
своего 60%-ного капитального book. Следующий open не является scheduled
rebalance, поэтому action=`hold` и исполняемая цель пуста. Три defensive
sleeves находятся в cash, circuit cooldown `46` (`45` после следующего
open-tick), `PANW` там только ranking. Итог: **на следующую сессию новой
заявки нет**.

Активный author-style daemon и его существующие paper-позиции этим runner не
изменялись. В коде hybrid shadow отсутствуют submit/replace/cancel методы.

## Воспроизведение

```bash
php tools/run_tactical_rotation_backtest.php \
  --end=2026-07-15 \
  --cost-bps=20,30,35,40,50 \
  --include-robustness \
  --output=docs/research_results/causal_stock_rotation_hybrid_v4_20260716.json \
  --shadow-output=var/reports/daily/tactical_rotation_shadow.json
```

Команда выполняется со стандартным PHP memory limit, читает Alpaca market
data и не отправляет заявки. Если появляется новая торговая action, допустимы
только read-only проверки paper calendar/asset; любая ошибка блокирует shadow.
