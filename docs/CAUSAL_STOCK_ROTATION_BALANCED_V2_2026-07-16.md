# Causal stock rotation balanced v2 — 2026-07-16

> Historical runner-up. После дополнительного factor/risk/ensemble поиска
> этот профиль заменён более доходным и более устойчивым к leave-one-out
> [`causal-stock-rotation-hybrid-v4`](CAUSAL_STOCK_ROTATION_HYBRID_V4_2026-07-16.md).
> Параметры и цифры ниже сохранены без переписывания для regression-сравнения.

## Выбранный результат

После расширенного поиска выбран исторически прошедший профиль
`causal-stock-rotation-balanced-v2`. Он воспроизводит требуемый масштаб
доходности без same-day look-ahead:

- сигнал строится только после завершённого close дня `D`;
- целевая позиция может исполниться только на open следующей сессии `D+1`;
- данные: Alpaca SIP, split-adjusted, дневные OHLCV;
- период торговли: 2021-01-04 — 2026-07-15;
- начальный капитал `$30,000`, итог при 20 bps — `$2,105,349`;
- total `+6917.83%`, CAGR `115.85%`, max DD `−26.26%`;
- при обязательном stress 30 bps: total `+5717.25%`, CAGR `108.64%`,
  max DD `−26.55%`.

`selected=true` в артефакте означает прохождение исторических gates при 20 и
30 bps. Это не production approval. Профиль намеренно оставлен в
`paper_shadow`, а отправка заявок в нём отсутствует и заблокирована.

Машинный результат: [`research_results/causal_stock_rotation_balanced_v2_20260716.json`](research_results/causal_stock_rotation_balanced_v2_20260716.json).

## Замороженная логика

- Universe: 20 преимущественно технологических/high-beta акций.
- Раз в три сессии выбирается один лучший тикер.
- Score: `−2×R5 + R20 + R60 + 2×R90 − R120`, затем деление на
  20-дневную annualized volatility.
- Вход разрешён только при `SPY > SMA200` и положительном score.
- При `QQQ < SMA50` рассчитанная нагрузка уменьшается на 25%.
- Asset-vol target `45%`; SPY-vol target `30%`; planned gross не выше `1.18x`.
- Минимум 253 наблюдения истории и ADV20 не ниже `$5m`.
- Закрытие позиции: close на 22.5% ниже максимального close с causal-выходом
  на следующем open; после выхода семь сессий cooldown.
- Portfolio circuit: drawdown 15%, causal-выход на следующем open и 50
  сессий cash. После cooldown circuit переармируется относительно нового
  equity epoch, а не ждёт восстановления старого ATH.
- One-way execution cost моделируется непосредственно на каждом изменении
  позиции; margin interest `6.25%` начисляется по календарным дням.

## Cost stress

| One-way cost | Train 2021–23 CAGR / DD | Validation 2024–25 CAGR / DD | 2026 YTD total / annualized / DD | Full total / CAGR / DD | Gate |
|---:|---:|---:|---:|---:|---|
| 20 bps | 106.91% / −25.10% | 110.35% / −26.26% | +80.55% / 200.74% / −19.39% | +6917.83% / 115.85% / −26.26% | pass |
| 30 bps | 100.20% / −25.10% | 103.04% / −26.55% | +77.26% / 190.61% / −19.83% | +5717.25% / 108.64% / −26.55% | pass |
| 35 bps | 97.02% / −25.10% | 99.47% / −26.69% | +75.64% / 185.67% / −20.05% | +5202.93% / 105.18% / −26.69% | fail train/validation 100% |
| 40 bps | 93.77% / −25.10% | 95.98% / −26.84% | +74.03% / 180.82% / −20.27% | +4725.91% / 101.71% / −26.84% | fail |
| 50 bps | 87.43% / −25.10% | 92.99% / −27.13% | +70.86% / 171.36% / −20.72% | +4060.82% / 96.37% / −27.13% | fail |

`2026 annualized` — пересчёт неполных 133 сессий, а не уже полученная
доходность полного календарного года.

Максимальный planned gross равен `1.18x`; консервативная OHLC-граница
high-notional/low-equity достигала `1.29649x`. Она проходит лимит `1.30x`, но
запас небольшой. Средний annualized turnover полного ряда — `33.90x`, максимум
отдельного календарного года — `54.07x`.

## Результаты по годам при 20 bps

| Год | Результат |
|---:|---:|
| 2021 | +130.49% |
| 2022 | −17.34% |
| 2023 | +358.73% |
| 2024 | +124.90% |
| 2025 | +97.76% |
| 2026 до 15 июля | +80.55% |

Есть один отрицательный год. Поэтому результат не следует читать как
гарантированные `100%` в каждом календарном году: требование выполнено по CAGR
train, validation и полному периоду.

## Проверка, что итог не создан одной сделкой

| Период, 20 bps | Top-1 positive day | Top-5 positive days | CAGR без 5 лучших дней | Positive holding episodes | Top-1 episode | Тикеров |
|---|---:|---:|---:|---:|---:|---:|
| Train | 5.29% | 18.35% | 53.04% | 31 | 17.13% | 17 |
| Validation | 5.98% | 16.67% | 51.41% | 20 | 16.92% | 14 |
| 2026 YTD | 12.68% | 27.45% | 27.00% | 10 | 40.34% | 6 |
| Full | 2.50% | 10.51% | 77.55% | 60 | 8.06% | 19 |

Holding episode — непрерывный участок attribution к одному тикеру; на
rotation-day совокупный return относится к позиции после ребалансировки, а на
exit-to-cash — к предыдущей позиции. Этот показатель используется вместе с
дневной концентрацией, а не как точная broker tax-lot бухгалтерия.

Профиль проходит gates по числу эпизодов, числу разных тикеров, доле лучшего
эпизода и результату без пяти лучших дней. В 2026 выборка короче и заметно
более концентрированна, что отдельно сохранено в отчёте.

Rolling windows при 20 bps:

| Окно | Количество | Median CAGR | Доля окон CAGR ≥100% | Худший CAGR | Худший DD |
|---:|---:|---:|---:|---:|---:|
| 252 сессии | 1,137 | 103.24% | 50.92% | −19.20% | −26.26% |
| 504 сессии | 885 | 121.77% | 71.41% | 25.64% | −26.26% |

При stress 30 bps median равен `95.73%` и `115.09%`, а доля окон выше
100% — `48.37%` и `67.91%` соответственно.

## Universe robustness

Диагностический leave-one-ticker-out не использовался для подмены уже
замороженного профиля:

- только `4/20` исключений проходят одновременно основные gates 20 и 30 bps;
- проходят исключения `AAPL`, `MSFT`, `AMZN`, `MRVL`;
- без `ANET` train CAGR падает примерно до `53.7%`, full DD достигает `−42%`;
- без `WDC` validation CAGR падает примерно до `50.9%`;
- universe, полностью доступный к началу 2020 года, без `COIN` и `PLTR`, при
  20 bps даёт train `103.39%`, validation `76.36%`, full DD `−40.42%`.

Это главный аргумент за paper-forward shadow, а не за немедленное включение
покупок. Исторический профиль найден и воспроизводим, но чувствительность к
составу universe нельзя скрывать headline CAGR.

## Расхождения с алгоритмом автора

| Автор FTT | Balanced v2 |
|---|---|
| ПООС: катализатор и первый плавный откат к 10/20 EMA | Cross-sectional momentum/reversal ranking |
| Exact limit у MA или подтверждённая консолидация | Completed close `D` → open `D+1` |
| FBMA/FBMA20, ATR, свечи, объём и закономерность MA | Эти признаки напрямую в score не входят |
| Stop около EMA − 1 ATR либо стратегическое нарушение, часто 3–5% | Position trailing close 22.5% и portfolio circuit 15% |
| БУ примерно после +1% | БУ отсутствует |
| Partial 1/2 или 1/3 около 1.5R | Частичная фиксация отсутствует |
| Обычный старт позиции 5–10%, последовательная нагрузка после БУ | Один тикер, planned gross до 118% |
| Следующая позиция после снижения риска предыдущей | Фиксированная ротация раз в три сессии |
| SPY/QQQ/SMH, широта, VIX и сезонность как dashboard | Автоматизированы SPY200, QQQ50 и volatility scaling |

Следовательно, balanced v2 — не точная автоматизация ПООС. Это отдельная
causal tactical-rotation модель, использующая часть принципов автора:
market-first, выбор лидеров, трендовый контекст, контроль нагрузки и выход при
нарушении риска. Полная сверка материалов автора остаётся в
[`AUTHOR_REFRESH_2026-07-15.md`](AUTHOR_REFRESH_2026-07-15.md).

## Что дополнительно проверено и отклонено

- Старые `same_day_touch` результаты до `+7877%` воспроизведены, но исключены:
  вход использовал low/high/close той же ещё не исполненной свечи.
- Leveraged/inverse rotation: более 552 тысяч комбинаций; найденные train
  лидеры разваливались на validation либо превышали DD/gross.
- Author-style causal grid: EMA/FBMA/20W/ATR/catalyst-приближения не дали
  одновременно 100% train/validation при допустимом риске.
- Top-K 2/3 и complete-history microgrids не сохранили цель.
- На 100,800 Alpaca SIP 5-minute bars проверено 3,000 hard/ATR/trailing/BE
  вариантов. Лидер дал train `150.94%` и OOS `113.67%`, но OOS DD `−43.56%`,
  поэтому отклонён.

После заморозки balanced v2 дополнительно проверено 229,743 factor/regime
варианта. Самый робастный rounded multifactor повысил leave-one-out до `12/20`
и при 30 bps дал train `115.23%`, validation `124.54%`, 2026 annualized
`124.20%`, full `119.44%`, DD `−34.61%`; complete-at-2020 universe тоже
сохранил train/validation выше 100%. Несмотря на это, он не заменил выбранный
профиль:

- один validation holding episode дал `27.44%` положительного episode-return
  при gate `20%`;
- в 2026 один MRVL episode сформировал `55.70%` положительного episode-return;
- без пяти лучших дней 2026 CAGR стал `−5.53%` при gate `+20%`.

Это полезный robustness frontier, но не ответ на требование «результат не
создан одной сделкой». Параметры balanced v2 задним числом ради красивого LOO
не менялись.

## Реализация и текущий shadow

- [`config/tactical_rotation.php`](../config/tactical_rotation.php) — frozen
  профиль и gates.
- [`src/Backtest/CausalTacticalRotationBacktester.php`](../src/Backtest/CausalTacticalRotationBacktester.php)
  — causal replay и риск/accounting.
- [`tools/run_tactical_rotation_backtest.php`](../tools/run_tactical_rotation_backtest.php)
  — загрузка Alpaca SIP, stress, rolling/robustness audit и атомарный shadow.
- [`tests/causal_tactical_rotation_backtester.php`](../tests/causal_tactical_rotation_backtester.php)
  — causality, schedule, deterministic tie-break, повторный portfolio circuit,
  concentration profile и boundary tests.

На close 2026-07-15 этот отдельный профиль находится в cash. Portfolio circuit
сработал 8 июля, выход — на open 9 июля; cooldown равен 46 сессиям на close и
45 после следующего open-tick. `PANW` — только текущий ranking с расчётной
нагрузкой 56.61%; следующая сессия не является датой ребалансировки, поэтому
исполняемые `symbol/gross` пусты, action=`hold`, заявки нет.

## Воспроизведение

```bash
php tools/run_tactical_rotation_backtest.php \
  --end=2026-07-15 \
  --cost-bps=20,30,35,40,50 \
  --include-robustness \
  --output=docs/research_results/causal_stock_rotation_balanced_v2_20260716.json \
  --shadow-output=var/reports/daily/tactical_rotation_shadow.json
```

Инструмент читает market data и, только когда есть актуальная shadow-action,
может сделать read-only проверки calendar/asset на Alpaca paper. Он не содержит
пути отправки ордера. Любая ошибка broker-check закрывает shadow fail-closed.
