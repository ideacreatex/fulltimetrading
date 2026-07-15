# FTT: регрессионная проверка после доработок — 2026-07-16

Проверка выполнена в отдельном worktree `fulltimetrading-strategy-regression`, ветка `codex/strategy-regression-2026-07-16`. Активный Alpaca paper worktree не переключался, daemon не перезапускался, live не включался. Новые entry-заявки в активном контуре остаются заблокированы; сигналы и защита уже открытых paper-позиций продолжают работать.

## Решение

Доработки не ухудшили активный paper-бот: они не развернуты в его worktree. В исследовательском replay headline стал существенно ниже, потому что устранены два источника завышения и невоспроизводимости:

1. старый backtest распределял капитал по порядку тикеров во входном массиве, а не по глобальному приоритету сигналов;
2. старые exploratory-прогоны допускали исполнение по дневному бару, close которого еще не был доступен в момент решения, либо использовали слишком долгую валидность заявки.

После канонической сортировки universe и глобального ранжирования `score desc + stable setup key` перестановка тикеров дает побайтово одинаковые метрики. Но ни locked5, ни 2x, ни 3x, ни mixed не прошли проверку устойчивости. Поэтому production-кандидат не выбран и включать новые paper-входы нельзя.

## Как менялся результат

Эти строки показывают последовательное устранение optimistic assumptions; это не один и тот же execution contract, поэтому их нельзя трактовать как обычную деградацию параметров.

| Этап | Total | Annualized | Max DD | Сделки | PF | Что изменилось |
|---|---:|---:|---:|---:|---:|---|
| Последний известный `tuned-daily` status | около +6650.17% | +116.65% | −33.56% | — | — | Старый full-sample headline; требовал перепроверки |
| Контролируемый старый `same_day_touch`, valid 10 | +6207.61% | +111.80% | −33.56% | 285 | 8.77 | Точный локальный артефакт старой семантики |
| Planned quantity + pending reservations, USD-first | +395.79% | +33.63% | −49.16% | 177 | 3.18 | Реалистичнее sizing/fill, но еще зависел от порядка universe |
| Те же данные/параметры, старый paper-order | +333.13% | +30.38% | −47.14% | 187 | 2.75 | Только переставлены входные тикеры |
| Новый детерминированный allocator, hold | +143.34% | +17.46% | −47.87% | 190 | 2.02 | Глобальный score-priority, routine partial 0% |
| Новый детерминированный allocator, routine partial 50% | +140.51% | +17.21% | −47.12% | 190 | 1.97 | Тот же allocator, partial на target |

Изолированный old-code control на одинаковых Alpaca IEX барах подтвердил баг порядка: `USD,SOXL,TECL,TQQQ,UPRO` дал `+384.45%`, а paper-order `UPRO,TQQQ,SOXL,USD,TECL` — `+333.13%`. Хеши universe и market data были одинаковыми. После исправления forward и reverse universe дают один SHA-256 метрик: `d70b583e93685b5ee21a9bdff967e0d82400d12e1dcb3fed0e9437bed9345b30`.

После расширения coverage gate на 25 market-context рядов offline replay повторен еще раз: массивы вариантов locked5 и pure3 совпали с предыдущими побайтово (`b1dc7ae2003fda1f49b66bf7424a3beaff7c21a883782fd4bf1d0d08f7efa8ac` и `aa404d3f8e09889b3c36542de9887520f040d2d0cb607d83fd42f3abf2caf595`). Значит защитная проверка данных не изменила сделки или метрики задним числом.

## Locked5: Alpaca против Yahoo

Период `2021-01-01..2026-07-15`, initial cash `$30,000`, long-only, `next_touch`, DAY validity 1, mental initial stop с gap-open исполнением, BE `+1%`, max open 4, family cap 1.20, planned max gross 1.75.

| Источник / выход | Total | Ann. | DD | W/L | Win rate | PF | Train ann. | 2024+ ann. | Top-5 | P/L без top-5 | Avg gross active | Max gross |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Alpaca IEX, hold | +143.34% | +17.46% | −47.87% | 39/151 | 20.53% | 2.02 | −13.84% | +68.93% | 58.28% | −$6,708 | 0.67x | 2.00x |
| Alpaca IEX, partial 50% | +140.51% | +17.21% | −47.12% | 40/150 | 21.05% | 1.97 | −14.29% | +69.17% | 59.48% | −$8,853 | 0.62x | 2.00x |
| Yahoo, hold | +25.62% | +4.21% | −69.32% | 40/176 | 18.52% | 1.26 | −21.36% | +44.97% | 55.40% | −$16,838 | 0.72x | 2.10x |
| Yahoo, partial 50% | +15.00% | +2.56% | −69.72% | 40/178 | 18.35% | 1.17 | −21.15% | +39.59% | 54.59% | −$18,710 | 0.66x | 2.10x |

Alpaca locked5 не проходит новый data-quality gate 98%: у `USD` только `1316/1388 = 94.81%` benchmark-сессий; у `TECL` отсутствуют 2 сессии, но покрытие `99.86%`. Yahoo имеет 100% относительное покрытие всех пяти тикеров, однако результат существенно слабее и также неустойчив. Alpaca SIP был запрошен отдельно, но API вернул HTTP 403: текущая подписка не разрешает recent SIP data.

На совпадающих датах median close difference Alpaca IEX против Yahoo составляет примерно `0.05–0.27%`, но p95 для `USD` достигает `3.20%`, а максимальное расхождение `48.59%`; это достаточно, чтобы менять касания, stops и compounded path. Поэтому результаты разных источников нельзя смешивать в одну equity curve.

## 2x, 3x и mixed

Все строки используют тот же execution contract. `Best routine partial` означает лучший full-period return/DD среди проверенных `0/25/33/50%`, но не production selection.

| Universe | Gross / partial | Total | Ann. | DD | Сделки | Win | PF | Train ann. | 2024+ ann. | P/L без top-5 | Coverage |
|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---|
| 2x: SSO/QLD/USD/ROM | 2x / hold | +23.80% | +3.94% | −34.33% | 104 | 21.15% | 1.39 | −10.56% | +23.96% | −$10,139 | FAIL |
| 2x: SSO/QLD/USD/ROM | 2x / 50% | +36.22% | +5.75% | −34.32% | 104 | 21.15% | 1.58 | −9.70% | +27.26% | −$10,310 | FAIL |
| 3x: UPRO/TQQQ/SOXL/TECL | 2x / hold | +173.03% | +19.94% | −57.00% | 171 | 21.64% | 2.18 | −9.29% | +66.39% | −$3,828 | PASS |
| 3x: UPRO/TQQQ/SOXL/TECL | 2x / 50% | +190.07% | +21.26% | −53.89% | 172 | 20.93% | 2.23 | −5.73% | +62.88% | −$2,647 | PASS |
| 3x: UPRO/TQQQ/SOXL/TECL | 3x / 50% | +243.54% | +25.03% | −65.48% | 174 | 21.84% | 2.34 | −13.10% | +91.51% | −$9,234 | PASS |
| Mixed 8 | 2x / hold | +163.89% | +19.20% | −62.58% | 243 | 20.58% | 2.36 | −21.53% | +94.59% | −$9,408 | FAIL |
| Mixed 8 | 2x / 25% | +175.47% | +20.13% | −62.21% | 236 | 21.19% | 2.43 | −21.60% | +98.11% | −$7,650 | FAIL |
| Mixed 8 | 3x / hold | +198.61% | +21.90% | −69.13% | 237 | 20.68% | 2.58 | −25.44% | +116.90% | −$1,799 | FAIL |

Pure2 и mixed проваливают coverage из-за `USD 94.81%` и `ROM 97.62%`. Все 25 market-context рядов Alpaca имеют `1388/1388 = 100%`; pure3 проходит объединенный coverage, но все его варианты имеют отрицательный train, DD хуже допустимых 35% и отрицательный P/L без пяти лучших сделок. Наблюдаемая нагрузка после движения цен доходила до `2.39x` при pre-trade cap 2x и до `4.21x` при cap 3x; cap заявки не является гарантией будущей нагрузки.

## Годы и кварталы

| Вариант | Ann. | DD | Худший год | Отрицательные годы | Худший квартал | Отрицательные кварталы |
|---|---:|---:|---|---:|---|---:|
| Pure2 hold | +3.94% | −34.33% | 2022: −27.76% | 3/6 | 2022-Q3: −17.52% | 14/23 |
| Pure3 hold | +19.94% | −57.00% | 2022: −47.86% | 1/6 | 2022-Q2: −19.76% | 9/23 |
| Mixed hold | +19.20% | −62.58% | 2022: −60.28% | 1/6 | 2022-Q3: −24.66% | 8/23 |
| Locked5 Alpaca, partial 50% | +17.21% | −47.12% | 2022: −44.77% | 1/6 | 2022-Q3: −20.97% | 8/23 |
| Locked5 Yahoo, hold | +4.21% | −69.32% | 2022: −65.81% | 1/6 | 2022-Q1: −31.82% | 12/23 |

`2026-Q3` неполный и включен только как текущий partial period. Высокие результаты после 2024 года не исправляют отрицательный train задним числом.

## Частичные выходы и прошлое уточнение

Прошлое обсуждение зафиксировало правильный intent: держать сильного winner до нарушения стратегии; BE после примерно `+1%`; partial использовать как аварийный risk-control при превышении нагрузки/maintenance, а не как постоянный take-profit.

Тест routine partial подтверждает это:

- locked5: partial 50% улучшил DD лишь на `0.76` п.п., но снизил annualized, PF и P/L без top-5;
- mixed 2x: partial 50% снизил DD менее чем на 1 п.п., но annualized упал с `19.20%` до `8.79%`;
- pure3: partial улучшает full-period DD на 3.11 п.п., но DD остается `−53.89%`, train отрицательный, а результат концентрирован в немногих сделках.

Следовательно, regular target partial не является найденным улучшением. `partial=0` — правильный author-intent control, но и он не проходит stability gates. Аварийное снижение нагрузки должно быть отдельной детерминированной политикой и отдельно проверяться; его нельзя выдавать за уже доказанный источник доходности.

## Сверка с автором

Полный разбор PDF, видео и Telegram до прошлого cutoff остается в `docs/AUTHOR_REFRESH_2026-07-15.md`. Эта регрессия не меняет основные расхождения:

- алгоритм торгует locked leveraged ETF, а классический ПООС автора начинается с individual stocks после catalyst;
- алгоритм требует историческую regularity и closed-bar `next_touch`, автор также использует заранее установленный exact first-touch limit;
- initial stop алгоритма `1.5 ATR`, в PDF базовый пример `1 ATR` либо фиксированный 3–5%;
- BE `+1%` совпадает по intent, но gap может исполнить ниже entry, поэтому БУ не гарантирует нулевой фактический loss;
- нет отдельных exact-touch/candle-confirmed режимов, sequential loading только после BE, bearish-engulfing/weekly invalidation и обязательной исторической FBMA/seasonality;
- premarket pending-entry kill-switch автора пока не внедрен в paper.

Premarket research вынесен в отдельный worktree/ветку `codex/premarket-research-2026-07-16` от safe HEAD `917234e`. Там реализованы только offline advisory kill-switch и feature matrix gap/range/VWAP/volume, RSI D/W, расстояний до 20W EMA/SMA, checkpoints open/5m/15m/30m/60m и exit reference levels. Ветка не подключена к paper/live. Без исторического предмаркет-датасета `profitability_evaluated=false`, поэтому улучшение результата не заявляется.

Telegram-экспорты после 13 июня покрывают public до 13 июля и private до 12 июля; отсутствие 14–16 июля является границей файлов, а не доказательством отсутствия сообщений. Отдельного содержательного Q&A в приложенных экспортных файлах нет.

16 июля дополнительно проверены Telegram Web во встроенном браузере и в Chrome: обе сессии требуют авторизации, поэтому сообщения за 14–16 июля без участия владельца аккаунта безопасно не выгружались. Официальная вкладка [Stream FTT](https://www.youtube.com/@ftt_streams/streams) перепроверена 16 июля: первым публичным видео по-прежнему остается стрим 8 июля; более нового публичного ролика на вкладке нет.

Новый воспроизводимый importer читает Telegram Desktop `result.json`, сохраняет абсолютные пути фото/video/voice/file, не выбрасывает media-only события и объединяет зеркала с provenance. В двух экспортных каталогах найдено `93` релевантных source events и `55` событий после media-aware dedupe; удалено `38` зеркал. Среди них `53` media events, `25` media-only и 6 уникальных ticker-bearing author messages (`26` ticker rows).

Старое сравнение только по симметричному окну `±3` дня и семейству дает `4/6 = 66.7%` temporal associations, но это не подтверждение одинакового setup или момента входа. Новая строгая action/direction/setup-aware сверка дает:

- проверенных совпадений `0/6` сообщений и `0/26` ticker rows; exact ticker matches также `0`;
- прежние `1/6` (`2/26`) остаются только coarse direction/family association. Плоский Telegram-export не связывает `support_mentions` с конкретным тикером внутри multi-ticker сообщения, поэтому два сообщения (`11` ticker rows) помечены `setup_ambiguous` и fail closed. Ручная проверка coarse-примера дополнительно показывает `10/20 EMA W` автора против дневных `SMA/EMA D` модели;
- сообщение 28 июня по `SOXX/NQ/SPX` имеет временные long-сигналы модели, но авторский текст bearish, поэтому отмечено как opposite, а не match;
- все `6/6` сообщений не дают проверяемого initial-entry setup: mixed/neutral direction, opposite signal, неоднозначная привязка ticker→MA либо отсутствуют точные timeframe/MA-параметры. Докупки, удержание и выходы требуют отдельного сравнения с состоянием уже открытого портфеля и намеренно не выдаются за initial-entry matches;
- DELL `+9% / +$1,040 / payout +$208` остается единственной новой сделкой с проверяемым ticker/P&L, но DELL отсутствует в locked universe, поэтому exact comparison `0/1`.

Консервативный trade-action extractor оставил только 2 текстовых action mentions (БУ/runner 29 июня и заявленное открытие позиции с `$5,000` 10 июля), но у обоих в тексте нет тикера, поэтому `verified_real_actions=0`. Скрин DELL проверен отдельно как media evidence; он не превращает соседний текст без ticker в автоматически верифицированную строку сделки.

Это консервативнее прежних ручных процентов и честнее отражает расхождение: текущая long leveraged-ETF модель не воспроизводит реальные действия автора по приложенному delta.

## Что исправлено в коде

- Backtest и paper planner используют глобальный score-priority со стабильным tie-break; universe перестановки больше не меняют результат.
- Cache key канонизирован; namespaced offline replay требует точного snapshot и больше не подбирает произвольный JSON из cache directory.
- Daily cache, созданный до закрытия запрошенного бара, обновляется атомарно.
- Freshness guard требует закрытый bar `as_of` для каждого торгового символа и каждого ряда рыночного режима, а не только свежий SPY.
- Добавлен 98% historical-session coverage gate для объединения торгового universe и market-context рядов; при провале production selector возвращает `selected_variant=null`.
- В отчетах добавлены wins/losses/win rate, среднее/максимальное число позиций и gross exposure.
- Исправлена сводка nested paper order plan; локальная история ордеров явно не называется текущими open orders.
- Исходный `setup_key` передается из daily report в paper planner, поэтому tie-break совпадает с backtest и не реконструируется иначе.
- Telegram classifier использует Unicode-границы слов (`БУ` не срабатывает внутри `будет`, `быч` внутри `обычно`, `падение` внутри `совпадения`), отличает удержание цены у MA от удержания позиции и «открыли график» от открытия сделки. Выполненное действие получает `verified_real_action=true` только при ticker и глаголе исполнения; multi-ticker MA без ticker-bound связи fail closed.

## Production и ограничения

Production-eligible вариантов: `0`. Стабильного нового профиля нет. Лучший headline pure3 имеет слишком глубокую просадку и отрицательный train; locked5 Alpaca дополнительно не проходит coverage. Поэтому:

- `FTT_PRODUCTION_ENTRY_ENABLED` не включать;
- live не включать;
- активный paper daemon не переносить на эту ветку;
- signals/exits можно продолжать в текущем guarded observation-контуре;
- решение о новых входах принимать только после новой заранее сформулированной гипотезы и paper-forward периода.

Локальные полные JSON/trades/equity находятся в ignored `var/reports/strategy_regression_20260716/`. Числа выше перенесены в tracked-документ, но raw артефакты, cache и секреты намеренно не коммитятся.

## Воспроизведение

Research-only аварийная policy теперь реализована отдельно в `src/Trading/PortfolioEmergencyDeRiskPolicy.php` и CLI `tools/research_emergency_de_risk.php`. Она не принимает target/profit как trigger, не подключена к daemon и не может отправлять ордера. При превышении gross-trigger она детерминированно и пропорционально рассчитывает минимальные сокращения до target-ratio с per-position cap и minimum remaining quantity; невалидные данные возвращают пустой fail-closed plan. Это закрывает кодовую форму policy, но не является backtest-доказательством ее доходности и не разрешает deployment.

Пример locked5 hold против routine partial:

```bash
php tools/run_param_experiment.php \
  --provider=offline-cache \
  --cache-namespace=alpaca-param-experiment-iex \
  --symbols=USD,SOXL,TECL,TQQQ,UPRO \
  --start=2021-01-01 --end=2026-07-15 --initial-cash=30000 \
  --risk-only=true --leverage-only=true \
  --max-gross-values=1.75 --max-open=4 --family-cap-values=1.2 \
  --cooldown-days=5 --same-after-days=45 \
  --break-even-profit-pct-values=0.01 \
  --partial-take-profit-pct-values=0,0.5 \
  --order-valid-bars-values=1 --order-fill-modes=next_touch \
  --swing-stop-mode=mental --hard-stop-fill-mode=gap_open \
  --support-require-close-above=true \
  --output-dir=var/reports/strategy_regression_20260716/locked5_alpaca_offline
```

Пример локальной проверки emergency plan без сети и ордеров:

```bash
php tools/research_emergency_de_risk.php \
  --input=/absolute/path/to/sanitized_snapshot.json \
  --trigger-ratio=1.75 \
  --target-ratio=1.50 \
  --max-reduction-fraction=0.25 \
  --minimum-remaining-quantity=1
```
