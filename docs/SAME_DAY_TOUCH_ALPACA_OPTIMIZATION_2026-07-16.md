# FTT: повторная проверка `same_day_touch` на Alpaca — 2026-07-16

> **Статус изменён 2026-07-16.** После требования не принимать результат ниже `100% CAGR` профиль `advance-touch-alpaca-20260716` отклонён и больше не является paper-forward кандидатом. Приведённые ниже `14.97% CAGR` сохранены только как воспроизводимый исторический контроль. Более строгая SIP-проверка причинного внутридневного исполнения, train-only выбора и фактической нагрузки описана в `docs/CAUSAL_100PCT_SEARCH_2026-07-16.md`.

## Итог

На момент этого промежуточного исследования для paper-forward наблюдения был выбран профиль `advance-touch-alpaca-20260716`:

- причинный вход `advance_next_session`: решение после close дня `D`, DAY limit только на сессию `D+1`;
- `family cap = 0.44`, цель `2.70 ATR`, стоп `1.5 ATR`, БУ после `+5%`, partial `50%`;
- исторический Alpaca IEX backtest: `+14.97%` годовых при максимальной просадке `−19.24%`;
- q95 просадки moving-block bootstrap: `34.61%` на основном seed и не выше `34.79%` на десяти seed;
- full-period и post-2024 concentration gates пройдены;
- прежний статус `paper_forward_candidate` отменён; текущий статус `rejected_below_100pct_cagr_floor`, `production_approved=false`; отправка entry-заявок принудительно выключена.

Старый `same_day_touch` не выбран. Его высокая доходность возникала из-за look-ahead, а не из-за исполнимого преимущества.

## Сравнение вариантов

Период `2021-01-04..2026-07-15`, initial equity `$30,000`, Alpaca IEX split-adjusted, расходы `10 bps` на каждое исполнение.

| Вариант | Total | Annualized | Max DD | Сделки | PF | Решение |
|---|---:|---:|---:|---:|---:|---|
| Старый контролируемый `same_day_touch` | +6207.61% | +111.80% | −33.56% | 285 | 8.77 | Отклонён: look-ahead |
| Causal point leader, cap .65 / target 3.0 | +230.13% | +24.13% | −25.59% | 75 | 3.05 | Отклонён: post-2024 top-5 = 63.45% |
| Causal cap .60 / target 2.75 | +178.12% | +20.34% | −24.44% | 74 | 2.68 | Отклонён: bootstrap q95 DD = 43.14% |
| **Прежний cap .44 / target 2.70** | **+116.12%** | **+14.97%** | **−19.24%** | **72** | **2.53** | **Отклонён: ниже 100% CAGR** |

Точечный лидер прибыльнее, но пять лучших сделок дают `63.45%` post-2024 gross profit при лимите `60%`. У финального профиля эта доля `58.20%`, а P/L post-2024 без пяти лучших сделок остаётся положительным: `+$2,048.69`.

## Почему старый `same_day_touch` был завышен

В отдельном deterministic control было 260 закрытых сделок:

- 220 заявок ретроспективно «исполнились» внутри уже увиденной scanner дневной свечи;
- все `220/220` таких свечей закрылись выше цены входа;
- средний результат уже к close дня входа был `+1.898%`, медиана `+1.288%`;
- `132/220` уже показывали не менее `+1%`;
- эти 220 сделок дали `94.85%` P/L control;
- их PF был `16.06`, у 40 действительно будущих pending fills — `2.13`.

Scanner сначала видел low/high/close, MA, ATR и режим завершившегося дня, а backtest затем задним числом ставил лимит внутрь той же свечи. Paper-заявкой до касания это воспроизвести нельзя.

## Финальные параметры

| Параметр | Значение |
|---|---:|
| Universe | `UPRO,TQQQ,SOXL,TECL` |
| Entry / fill | `advance_next_session` / `next_touch`, valid 1 session |
| Min touches / success | 5 / 80% |
| Touch tolerance | 1.0% |
| Max distance | 5.0% и 3 ATR |
| Support slope / untouched | неотрицательный / да |
| Projection | daily `dynamic_exact`, cap 1%; weekly — last completed week |
| Initial stop | mental, 1.5 ATR; close-confirmed exit at next open |
| Break-even | high reaches +5%, hard stop at BE |
| Target / partial | 2.70 ATR / 50% |
| Family cap | 0.44 equity |
| Max positions / gross | 4 / 2.0x |
| Unstable-market position | 8% equity |
| Costs | 10 bps на entry, partial и exit |

`USD` исключён: его IEX coverage `94.81%`. UPRO, TQQQ и SOXL имеют `1388/1388` сессий, TECL — `1386/1388 = 99.856%`; минимальный gate `98%` пройден.

## Результат финального профиля

Дневной причинный backtest:

- ending equity `$64,835.00`, total `+116.12%`, annualized `+14.97%`;
- max drawdown `−19.24%`, PF `2.53`, Sharpe `0.85`, Sortino `0.55`;
- 72 закрытые сделки: 28 winners / 44 losses; две модельные позиции открыты;
- closed P/L `+$36,630.66`, unrealized P/L `−$1,795.65`;
- максимальная наблюдаемая gross exposure `1.33x`;
- все календарные годы положительные: от `+0.83%` в 2021 до `+40.55%` в 2025.

Устойчивость результата:

| Сегмент | Сделки | Annualized | DD | Top-1 | Top-5 | P/L без top-5 |
|---|---:|---:|---:|---:|---:|---:|
| Весь период | 72 | +14.97% | −19.24% | 11.97% | 48.34% | +$7,356.50 |
| 2021–2023 | 23 | +5.62% | −12.45% | 25.48% | 95.16% | −$4,455.25 |
| 2024+ | 49 | +26.99% | −19.24% | 14.41% | 58.20% | +$2,048.69 |

На всём периоде крупнейший источник gross profit — TECL, `47.67%`, ниже лимита `65%`. Слабое место остаётся видимым: ранний сегмент содержит только 23 сделки и без пяти лучших убыточен. Поэтому профиль не production-approved.

Moving-block bootstrap, 1000 итераций, блок 20 сессий:

- CAGR q05 / median / q95: `+1.52% / +14.28% / +30.22%`;
- max-DD median / q95: `20.63% / 34.61%`;
- вероятность положительного CAGR: `96.7%`;
- на seed `20260710..20260719` q95 DD: `32.99%..34.79%`, все `10/10` ниже лимита 35%;
- минимальный q05 CAGR среди seed `+1.52%`, минимальная вероятность положительного CAGR `96.6%`.

## Почему cap .44 и target 2.70

Дополнительный хвостовой frontier показал:

| Family cap | Target | Annualized | Historical DD | Bootstrap q95 DD | Решение |
|---:|---:|---:|---:|---:|---|
| .60 | 2.75 | +20.34% | −24.44% | 43.14% | Не проходит 35% |
| .50 | 2.75 | +17.19% | −21.09% | 38.11% | Не проходит 35% |
| .45 | 2.70 | +15.30% | −19.58% | 35.24% | Не проходит 35% |
| **.44** | **2.70** | **+14.97%** | **−19.24%** | **34.61%** | **Проходит** |
| .43 | 2.70 | +14.64% | −18.90% | 34.01% | Проходит, но менее доходен |

Таким образом, `.44` — наибольший проверенный целый процент family cap, который проходит tail gate; `.45` уже нарушает его.

Соседние target ATR при cap `.44`:

| Target ATR | Annualized | DD | Сделки |
|---:|---:|---:|---:|
| 2.65 | +14.31% | −19.40% | 75 |
| **2.70** | **+14.97%** | **−19.24%** | **72** |
| 2.72 | +15.07% | −19.18% | 72 |
| 2.74 | +15.16% | −19.12% | 72 |
| 2.75 | +15.21% | −19.09% | 72 |
| 2.76 | +14.24% | −22.95% | 72 |
| 2.78 | +13.85% | −22.91% | 73 |

`2.70` выбран как округлённая внутренняя точка гладкого плато. Локальный максимум `2.75` стоит непосредственно перед резким ухудшением на `2.76`; дополнительные `0.24` п.п. CAGR не оправдывают риск переоптимизации границы.

Всего на исправленной gap-aware модели выполнено более 600 grid evaluations, затем отдельные cap-tail, target-plateau, reverse-universe и multi-seed проверки. Старые результаты до исправления exact cache namespace и gap-open semantics не использовались для финального выбора.

## Минутная проверка Alpaca IEX: fail-closed

Первый replay ошибочно подставлял дневные exit/date, если минутные правила не давали выход, и называл такие строки минутными. Повторный аудит обнаружил `35/74` таких fallback. Код исправлен:

- daily fallback полностью запрещён;
- EMA10 trailing levels из завершённого дня активируются только на следующей сессии;
- portfolio CAGR/DD/bootstrap не публикуются, если хотя бы один minute exit не разрешён;
- unresolved trade получает явную причину и увеличивает fail-closed счётчик.

Для финального профиля:

- exact signal/entry matches `72/72`;
- полностью разрешённые minute exits `66/72`;
- fetch errors `0`, missing bars `0`, daily fallback `0`;
- 6 небольших дневных BE-exit расходятся из-за выгодной фактической цены входа и/или минутного порядка событий при постановке БУ; они отмечены `unresolved_after_daily_exit`;
- по 66 разрешённым сделкам minute P/L `+$44,497.42` против дневного `+$36,712.61`, но это неполная подвыборка и не используется как доходность портфеля;
- minute portfolio status — `blocked`; CAGR/DD/bootstrap намеренно отсутствуют.

Это менее эффектный, но корректный результат. До моделирования всех расходящихся BE-путей минутные цифры нельзя использовать для повышения нагрузки.

## Изменения в коде

- Добавлен causal scanner mode `advance_next_session` с daily/weekly projection без будущих данных.
- `advance_next_session + same_day_touch` запрещён исключением.
- Исправлены next-session fill-day risk, favorable opening gaps для long/short limits и консервативный порядок неоднозначных OHLC.
- Добавлены costs на entry/partial/exit и exact Alpaca cache namespace.
- Optimizer получил совместный Cartesian grid, дополнительные параметры и ограничение размера сетки.
- Добавлены deterministic block bootstrap и fixed-share execution path.
- Minute replay использует exact date/symbol/price match, regular session, fail-closed short/unresolved handling и completed-session EMA10 trail.
- Добавлены тесты scanner, fill semantics, favorable gaps, bootstrap, fixed-share equity, research profile и fail-closed minute replay.
- Именованный research-профиль не меняет deployed defaults и не может включить отправку заявок без отдельного production approval.

## Текущий снимок и запуск

На полностью проверенном close `2026-07-15` новых сигналов нет. В модельном backtest открыты TQQQ (`−2.73%`, stop `68.51`) и TECL (`−3.40%`, stop `172.51`); БУ не активирован. Это модель, не состояние фактического Alpaca account.

Read-only probe Alpaca paper endpoint от `2026-07-16T02:01:10Z` прошёл: account `ACTIVE`, currency `USD`, `trading_blocked=false`, `account_blocked=false`. Баланс в evidence не сохранялся, заявок не отправлялось; safety gate блокировал entry submission.

```bash
php tools/daily_signal_report.php \
  --profile=advance-touch-alpaca-20260716 \
  --include-account=false \
  --telegram=false
```

Воспроизводимый offline snapshot:

```bash
php tools/daily_signal_report.php \
  --profile=advance-touch-alpaca-20260716 \
  --offline=true \
  --end=2026-07-15 \
  --as-of=2026-07-15 \
  --output=var/reports/daily/advance_touch_20260715.json \
  --text-output=var/reports/daily/advance_touch_20260715.txt
```

Профиль сохранён только как отклонённый воспроизводимый контроль. Он не предназначен для новых paper/live входов; автоматическая отправка entry-заявок не включена.
