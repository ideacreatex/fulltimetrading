# Предмаркет: изолированный research-контур

Ветка `codex/premarket-research-2026-07-16` создана от безопасного коммита
`917234e`. Код из этой ветки не подключен к paper/live циклу, конфигурации,
Alpaca, демону или отправке ордеров.

## Kill switch ожидающих входов

`PendingEntryPremarketKillSwitch` реализует исследовательскую трактовку правила
автора: ожидающий вход отменяется при резком падении на предмаркете или пробое
поддержки. Политика чистая и детерминированная: на вход получает уже очищенный
snapshot, настройки и фиксированное время оценки, на выходе возвращает только
совет `cancel`/`keep`.

Проверки fail-closed выполняются в стабильном порядке:

1. валидность настроек и тикера;
2. наличие положительных `previous_close` и `premarket_price`;
3. ISO-8601 время наблюдения с явным часовым поясом;
4. подтверждение торгового дня полем `regular_session_expected`;
5. наличие поддержки, если проверка поддержки включена;
6. отсутствие будущего или устаревшего snapshot;
7. попадание наблюдения и оценки в окно 04:00–09:30 New York;
8. порог gap-down и пробой поддержки.

Если одновременно сработали оба рыночных условия, `trigger_reasons` всегда
содержит сначала `sharp_premarket_gap_down`, затем
`premarket_support_breached`. Пробоем считается цена строго ниже
`support_price * (1 - support_breach_buffer_pct / 100)`. Касание уровня не
считается пробоем.

Пример входа:

```json
{
  "evaluated_at": "2026-07-16T08:30:00-04:00",
  "policy": {
    "gap_down_threshold_pct": 3.0,
    "support_breach_enabled": true,
    "support_breach_buffer_pct": 0.0,
    "require_session_calendar_confirmation": true,
    "max_data_age_seconds": 300,
    "max_future_skew_seconds": 15,
    "premarket_start": "04:00",
    "regular_open": "09:30"
  },
  "snapshots": [
    {
      "symbol": "TQQQ",
      "observed_at": "2026-07-16T08:28:00-04:00",
      "regular_session_expected": true,
      "previous_close": 100.0,
      "premarket_price": 96.5,
      "support_price": 97.0
    }
  ]
}
```

Офлайн-запуск:

```bash
php tools/evaluate_premarket_kill_switch.php \
  --input=/absolute/path/premarket-snapshots.json \
  --output=/absolute/path/premarket-decisions.json
```

Каждый ответ CLI явно содержит:

```json
{
  "mode": "offline_research_only",
  "execution_authorized": false,
  "network_used": false,
  "orders_submitted": false
}
```

Неверный JSON, отсутствующее `evaluated_at`, неоднозначный часовой пояс или
неполный список snapshots дают общий результат `aggregate_decision=cancel` и
код возврата `2`.

## Матрица предмаркет-признаков

`PremarketFeatureMatrixBuilder` и отдельный офлайн CLI нормализуют:

- gap относительно предыдущего закрытия для premarket open и последней цены;
- диапазон предмаркета в процентах от предыдущего закрытия;
- отклонение последней цены от VWAP;
- предмаркет-объём и его отношение к заранее рассчитанному эталону;
- daily/weekly RSI, расстояние до 20W EMA/SMA и положение относительно обеих средних;
- цены checkpoints `open`, `5m`, `15m`, `30m`, `60m` и их изменения
  относительно предыдущего закрытия, последней цены предмаркета и открытия.
- reference-уровни возможного выхода: PM low, PM VWAP, regular open, 5m и 15m.

Входной JSON должен содержать массив `observations`. Для каждой строки нужны
`symbol`, `session_date`, `previous_close`, `premarket_open`,
`premarket_high`, `premarket_low`, `premarket_last`, `premarket_vwap`,
`premarket_volume`, `reference_premarket_volume` и все пять checkpoints.
Также обязательны `daily_rsi`, `weekly_rsi`, `weekly_ema20` и `weekly_sma20`.

```bash
php tools/build_premarket_feature_matrix.php \
  --input=/absolute/path/premarket-observations.json \
  --output=/absolute/path/premarket-feature-matrix.json
```

Матрица имеет `profitability_evaluated=false`: это подготовка данных, а не
доказательство улучшения стратегии.

## Проверка

```bash
php tests/premarket_kill_switch.php
php tests/premarket_feature_matrix.php
```

## Ограничения и условие дальнейшего движения

Исторический предмаркет-датасет в проекте отсутствует. Поэтому сейчас нельзя
честно заявлять ни улучшение доходности, ни уменьшение просадки, ни лучший
checkpoint входа. Для такой оценки нужны исторические минутные данные до
открытия с полным покрытием тикеров, корректировками corporate actions,
реалистичными спредами/проскальзыванием и walk-forward сравнением с текущим
алгоритмом.

`regular_session_expected` намеренно приходит из внешнего, заранее
подготовленного торгового календаря: без явного `true` политика отменяет вход.
Подключение к paper/live циклу и любые брокерские действия требуют отдельного
явного решения после завершения исторической проверки.
