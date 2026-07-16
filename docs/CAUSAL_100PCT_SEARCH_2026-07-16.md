# FTT: проверка цели 100% CAGR на причинном Alpaca SIP — 2026-07-16

## Решение

Профиль с исторически подтверждёнными `>=100% CAGR`, просадкой не хуже `−35%`, допустимой для Alpaca нагрузкой и устойчивым train/OOS результатом **не найден**. Поэтому новый профиль не выбран, `best_qualified_variant=null`, а отправка новых entry-заявок остаётся заблокированной.

Это не означает, что старый отчёт был прочитан как `12–14%` вместо `120%`. Старые цифры `+7000%` найдены и воспроизведены:

| Старый артефакт `same_day_touch` | Total | CAGR | Max DD | Сделки | Фактический max gross |
|---|---:|---:|---:|---:|---:|
| Максимальный headline сетки | +7877.12% | +121.00% | −39.82% | 302 | 2.665x |
| Лучший старый ряд с DD до 35% | +6207.61% | +111.80% | −33.56% | 285 | 2.105x |
| Лучший полностью сохранённый отчёт | +4849.10% | +102.70% | −29.24% | 277 | 2.154x |

Однако это не исполнимая доходность. `same_day_touch` сначала видел завершённые `low/high/close`, ATR, средние и режим дня, после чего задним числом ставил вход внутрь уже увиденной свечи этого же дня. Возврат к такому коду восстановил бы красивую цифру, но не возможность получить её на paper/live.

## Где именно возникали старые +7000%

Для полностью сохранённого ряда `+4849.10%`:

- `150/277` сделок были запланированы и «исполнены» в одну scanner-date; у всех `150/150` close уже был выше цены входа;
- к закрытию той же свечи среднее преимущество составляло `+2.3103%`, медиана `+1.4920%`, а `104/150` уже достигли `+1%`;
- эта ретроспективная группа дала `$763,824.65`, или `60.53%` закрытого P/L;
- `237` сделок активировали БУ `+2%`; в `89` дневных свечах одновременно были `high >= trigger` и `low <= entry`. Старый порядок сначала проверял low, затем включал БУ, поэтому все 89 сделок пережили неоднозначную свечу; их последующий P/L — `$459,474.50`, или `36.41%`;
- эти группы пересекаются, поэтому проценты нельзя складывать;
- `2025 + 2026 YTD` дали `80.30%` номинального закрытого P/L.

Отдельный детерминированный контроль подтвердил проблему независимо от параметров: `220/260` входов были ретроспективными same-day fills, все `220/220` закрылись выше entry и дали `94.85%` P/L; PF этой группы `16.06` против `2.13` у действительно будущих pending fills.

Старый результат не зависел только от одной сделки: P/L без пяти лучших сделок оставался положительным. Но это не спасает результат, потому что основная выборка сделок получила недоступную в реальном времени информацию.

## Исполнимый контракт

Новый тест использует только следующую последовательность:

1. После close дня `D-1` замораживаются support, ATR, stop, target, размер и рыночный режим.
2. В сессию `D` ожидается касание замороженной поддержки.
3. Reclaim принимается только после закрытия 5-минутной свечи.
4. Исполнение моделируется на open следующей наблюдаемой 5-минутной свечи с `10 bps` slippage, задержкой не более 5 минут и chase cap `0.1 ATR`.
5. Если до исполнимого входа нарушен stop, вход отклоняется.
6. После fill стоп, БУ и partial обрабатываются по хронологической последовательности 5-минутных баров. Неоднозначность разрешается консервативно.
7. Дневные close-правила применяются один раз после intraday-пути; дневной fallback при отсутствии минутных данных запрещён.

Базовая модель также включает `10 bps` transaction cost на исполнения и `6.25%` годовых на заёмную сумму, включая календарные дни между сессиями.

Production envelope исследования:

- CAGR не ниже `100%` одновременно на train и замороженном OOS;
- max drawdown не хуже `−35%`;
- плановый gross не выше `1.25x`, наблюдаемый или консервативно ограниченный gross не выше `1.30x`;
- не более четырёх позиций;
- не менее 50 train-сделок и 10 OOS-сделок;
- top-1 не выше 25%, top-5 не выше 60%, один тикер не выше 65% gross profit;
- выбор только на train, затем единственная замороженная OOS-проверка без выбора запасного варианта по результату holdout.

## Данные Alpaca SIP

5-минутные данные принудительно выгружены заново напрямую из Alpaca, `feed=sip`, `adjustment=split`, период `2021-01-01..2026-07-16` с правой границей exclusive.

Полный снимок `USD,SOXL,TECL,TQQQ,UPRO` содержит `30` chunks и `1,028,438` баров. Для каждого chunk сохранён immutable provenance-sidecar с точными endpoint, feed, adjustment, timeframe, границами, SHA-256, размером и числом строк. Повторное использование разрешено только при полном совпадении данных и provenance; IEX-файл нельзя объявить SIP-файлом.

Основной четырёхтикерный снимок:

- `SOXL,TECL,TQQQ,UPRO`;
- manifest `058bd381c1acfb6c1724758ebd7d81a929a40a56.manifest.json`;
- SHA-256 `179af3a637170051afc65093f792f12a342fd62a9e70ec67f7cfe8db42836c06`;
- `24/24` provenance sidecars verified;
- `907,989` баров, `216,737,840` bytes.

Глобальное покрытие по сессиям полное, но на нескольких реально использованных TECL-днях отсутствовала одна из 78 ожидаемых 5-минутных свечей. Alpaca не публикует bar, если нет подходящих trades, поэтому такой интервал нельзя автоматически трактовать ни как безопасный flat-price bar, ни как известный stop/exposure path. Эти варианты закрыты fail-closed.

Дополнительный чистый снимок без TECL:

- `SOXL,TQQQ,UPRO`;
- manifest `828288101ac576708d59c51f77d7e32a1abaac77.manifest.json`;
- SHA-256 `5c4d3bea0cc381042e3ce523bc37ce2b9199feb7f6b83901c02dc49f3c5ad052`;
- `18/18` sidecars verified;
- `750,361` бар, `179,152,606` bytes.

## Финальный grid на `SOXL,TECL,TQQQ,UPRO`

Проверено `18` сочетаний `max gross = 0.9, 1.0, 1.1, 1.15, 1.2, 1.25` и `family cap = 0.7, 0.8, 0.9`. Общие параметры: stop `1.5 ATR`, target `5 ATR`, БУ `+3%`, partial `75%`, causal touch/reclaim, costs `10/10 bps` и margin `6.25%`.

| Строка | Train CAGR / DD / trades | Full CAGR / DD / trades | OOS CAGR / DD / trades | Gross bound | Итог |
|---|---:|---:|---:|---:|---|
| Raw max: gross 1.25, family .70 | 12.94% / −22.44% / 45 | 13.50% / −26.37% / 97 | 14.16% / −19.53% / 52 | 1.404x partial | DQ false, gross > 1.30x |
| Лучший численно внутри gross: 1.15 / .70 | 12.08% / −20.91% / 45 | 12.67% / −23.79% / 97 | 13.36% / −17.95% / 52 | 1.271x partial | DQ false |

У raw-лидера full-period top-5 дают `68.83%` gross profit, а P/L без них `−$5,111.17`. У строки внутри gross top-5 дают `67.14%`, P/L без них `−$3,713.29`. То есть даже диагностические `12–14%` не проходят критерий стабильности.

Все `18/18` строки получили DQ=false из-за использованных TECL-сессий, включая `2023-07-31`, `2024-09-24`, `2024-09-30`, `2024-10-28`, `2025-01-21`, `2025-10-06`, `2025-12-08` и `2026-04-21`; `2025-12-09` встречается в `17/18` строк. Поэтому по ним не опубликованы promoted trades/equity-артефакты и не запускался cost stress поверх уже невалидной базы.

## Чистый контроль без TECL

На `SOXL,TQQQ,UPRO` те же `18` вариантов прошли строгую проверку полностью:

- DQ-valid train/OOS/full: `18/18`;
- в production gross envelope: `14/18`;
- вариантов с `>=100% CAGR`: `0`;
- robust / holdout-valid / historically qualified: `0 / 0 / 0`.

| Строка | Train CAGR / DD / trades | Full CAGR / DD / trades | OOS CAGR / DD / trades | Gross bound | Итог |
|---|---:|---:|---:|---:|---|
| Raw max: gross 1.25, family .70 | 8.86% / −16.31% / 35 | 7.17% / −22.63% / 69 | 5.22% / −12.00% / 34 | 1.3465x | Выше gross envelope, концентрирован |
| Лучший внутри envelope: 1.20 / .70 | 8.00% / −16.07% / 35 | **6.43%** / −22.63% / 69 | **4.62%** / −11.99% / 34 | **1.2898x** | Ниже 100%, train < 50, concentration fail |

У raw-лидера top-5 дают `97.39%` full-period gross profit, а P/L без них `−$397.84`; на train top-5 дают `100%`. У лучшей строки внутри gross top-5 также дают `97.27%`, а P/L без них `−$384.48`. Исключение TECL убрало дефекты данных, но не создало устойчивого преимущества. Walk-forward selector вернул `selected_variant=null`.

## Другие проверенные направления

До финального SIP v2 прогона отдельно проверялись stop/target, БУ `1–5%`, partial от `0%` до `100%`, дневной exact touch, completed-bar reclaim, разные chase/delay/touch фильтры, 2x/3x subsets, mixed universe, long rotation и inverse sleeve. Предыдущие intraday цифры считаются superseded после усиления provenance и full-session DQ, но важен отрицательный границевой тест:

- causal long rotation с cap `1.2x`: диагностически около `61% CAGR`, но DD `47.19%`;
- стабильный inverse sleeve: около `31.38% CAGR`, DD `31.27%`;
- агрессивный gross `6x`: около `67.88% CAGR`, DD `49.52%`, фактический gross до `5.33x`.

То есть даже заведомо неприемлемое для этого Alpaca-контура увеличение плеча не дало честные `100% CAGR / 35% DD`. Эти строки не являются кандидатами и не используются для deployment.

## Расхождения с автором

Подробная сверка PDF, Telegram и доступных видео находится в [`AUTHOR_REFRESH_2026-07-15.md`](AUTHOR_REFRESH_2026-07-15.md). Для этого теста критичны следующие отличия:

| Логика автора | Финальный causal diagnostic |
|---|---|
| Заранее установленный exact first-touch limit | Touch, затем completed 5m reclaim, затем next-bar open |
| БУ примерно после `+1%` | Проверены `+1%`, `+3%`, `+5%`; финальный grid использует `+3%` |
| Базовый stop около EMA − 1 ATR / стратегическое нарушение | Фиксированный initial stop `1.5 ATR` |
| Пример target около `1.5 ATR`, partial 1/2 или 1/3 | Target `5 ATR`, partial `75%` в grid; альтернативно проверялось полное удержание |
| Типичный размер позиции 5–10%, наращивание после подтверждения | Family cap 70–90%, gross до 1.25x |
| Значимая часть сделок — отдельные акции с catalyst/закономерностью | Финальный тест ограничен leveraged ETF |

Следовательно, старые +7000% нельзя называть точным воспроизведением автора: и исполнение, и нагрузка, и часть risk rules существенно отличались. Новый reclaim-вариант тоже не идентичен автору; это более консервативная автоматизируемая проверка причинности.

## Что изменено в коде

- `advance_next_session + same_day_touch` теперь запрещён как несовместимый контракт.
- Добавлен causal 5m touch/reclaim с next-observed-open fill и хронологическим fill-day risk.
- Добавлена 100% проверка используемой regular session: open/close bar, сетка timestamp, отсутствие пропущенных интервалов и conflicting duplicates.
- Gross exposure включает позиции, открытые и закрытые внутри дня; при неполном пути bound становится `null`, а вариант — DQ failure.
- DQ-события датированы и разделяются на train/OOS; OOS может отклонить замороженный выбор, но не выбрать запасной вариант.
- Alpaca intraday manifest переведён на v2 с immutable per-chunk provenance и exact hash verification.
- Production qualification требует train, OOS и full-period gross, DQ, концентрацию, `100% CAGR / 35% DD` и разрешённый execution contract.
- Отклонённый IEX-профиль `advance-touch-alpaca-20260716` больше не помечен paper-forward кандидатом.
- `FTT_PRODUCTION_ENTRY_ENABLED` по умолчанию остаётся `false`; причина — `no_causal_100pct_cagr_candidate_2026-07-16`.

Intraday touch/reclaim пока не имеет полного paper planner/monitor executor (`paper_execution_parity=false`). Даже хороший backtest не мог бы автоматически включить заявки без отдельной реализации и forward-проверки.

## Воспроизведение чистого контроля

```bash
php tools/fetch_alpaca_intraday_snapshot.php \
  --symbols=SOXL,TQQQ,UPRO \
  --start=2021-01-01 --end=2026-07-16 \
  --timeframe=5Min --feed=sip --adjustment=split \
  --namespace=alpaca-causal-touch-reclaim-v1-feed-sip-adjustment-split-timeframe-5min
```

```bash
php tools/run_param_experiment.php \
  --start=2021-01-01 --end=2026-07-15 \
  --provider=alpaca --feed=sip --symbols=SOXL,TQQQ,UPRO \
  --robust-split-date=2024-01-01 \
  --joint-grid=true --joint-max-variants=18 \
  --max-gross-values=0.9,1.0,1.1,1.15,1.2,1.25 \
  --family-cap-values=0.7,0.8,0.9 --max-open=4 \
  --support-entry-signal-modes=advance_next_session \
  --order-fill-modes=intraday_touch_reclaim --order-valid-bars-values=1 \
  --stop-atr-multiple-values=1.5 --target-atr-multiple-values=5 \
  --break-even-profit-pct-values=0.03 --partial-take-profit-pct-values=0.75 \
  --intraday-max-bars-after-touch-values=3 \
  --intraday-max-fill-delay-minutes-values=5 \
  --intraday-max-chase-atr-values=0.1 \
  --intraday-slippage-bps-values=10 --transaction-cost-bps-values=10 \
  --margin-interest-annual-pct=0.0625 --poos-base-enabled=false \
  --intraday-cache-namespace=alpaca-causal-touch-reclaim-v1-feed-sip-adjustment-split-timeframe-5min \
  --intraday-cache-symbols=SOXL,TQQQ,UPRO \
  --intraday-timeframe=5Min --intraday-feed=sip --intraday-adjustment=split \
  --intraday-snapshot-start=2021-01-01 --intraday-snapshot-end=2026-07-16 \
  --production-max-gross=1.25 --production-max-observed-gross=1.30 \
  --min-pre-split-trades=50 --min-pre-split-annualized-return-pct=1.00 \
  --min-acceptable-annualized-return-pct=1.00 --max-acceptable-drawdown-pct=0.35 \
  --output-dir=var/reports/causal_production_envelope_soxl_tqqq_upro_v2
```

Компактный машинный итог сохранён в [`research_results/causal_100pct_search_20260716.json`](research_results/causal_100pct_search_20260716.json). Raw cache, ключи, полные временные отчёты и account data не коммитятся.
