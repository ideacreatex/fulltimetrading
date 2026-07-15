<?php

declare(strict_types=1);

namespace FulltimeTrading\Research;

/**
 * Converts already-sanitized offline observations into comparable features.
 * It does not fetch data and intentionally does not estimate profitability.
 */
final class PremarketFeatureMatrixBuilder
{
    private const CHECKPOINTS = ['open', '5m', '15m', '30m', '60m'];

    /**
     * @param array<string, mixed> $observation
     * @return array<string, mixed>
     */
    public static function buildRow(array $observation): array
    {
        $symbol = strtoupper(trim((string) ($observation['symbol'] ?? '')));
        $sessionDate = trim((string) ($observation['session_date'] ?? ''));
        $base = [
            'valid' => false,
            'reason' => 'feature_row_not_completed',
            'symbol' => $symbol,
            'session_date' => $sessionDate,
            'features' => null,
            'entry_checkpoints' => null,
        ];

        if ($symbol === '' || preg_match('/^[A-Z][A-Z0-9.\-]{0,14}$/', $symbol) !== 1) {
            return self::invalid($base, 'symbol_invalid');
        }
        if (!self::validDate($sessionDate)) {
            return self::invalid($base, 'session_date_invalid');
        }

        $requiredPositive = [
            'previous_close',
            'premarket_open',
            'premarket_high',
            'premarket_low',
            'premarket_last',
            'premarket_vwap',
            'reference_premarket_volume',
        ];
        $values = [];
        foreach ($requiredPositive as $field) {
            $value = self::positiveFloat($observation[$field] ?? null);
            if ($value === null) {
                return self::invalid($base, $field . '_invalid');
            }
            $values[$field] = $value;
        }

        $volume = self::nonNegativeFloat($observation['premarket_volume'] ?? null);
        if ($volume === null) {
            return self::invalid($base, 'premarket_volume_invalid');
        }
        $dailyRsi = self::boundedFloat($observation['daily_rsi'] ?? null, 0.0, 100.0);
        if ($dailyRsi === null) {
            return self::invalid($base, 'daily_rsi_invalid');
        }
        $weeklyRsi = self::boundedFloat($observation['weekly_rsi'] ?? null, 0.0, 100.0);
        if ($weeklyRsi === null) {
            return self::invalid($base, 'weekly_rsi_invalid');
        }
        $weeklyEma20 = self::positiveFloat($observation['weekly_ema20'] ?? null);
        if ($weeklyEma20 === null) {
            return self::invalid($base, 'weekly_ema20_invalid');
        }
        $weeklySma20 = self::positiveFloat($observation['weekly_sma20'] ?? null);
        if ($weeklySma20 === null) {
            return self::invalid($base, 'weekly_sma20_invalid');
        }
        if ($values['premarket_high'] < $values['premarket_low']) {
            return self::invalid($base, 'premarket_range_invalid');
        }
        if (
            $values['premarket_open'] < $values['premarket_low']
            || $values['premarket_open'] > $values['premarket_high']
            || $values['premarket_last'] < $values['premarket_low']
            || $values['premarket_last'] > $values['premarket_high']
            || $values['premarket_vwap'] < $values['premarket_low']
            || $values['premarket_vwap'] > $values['premarket_high']
        ) {
            return self::invalid($base, 'premarket_ohlcv_inconsistent');
        }

        $checkpointPayload = $observation['checkpoints'] ?? null;
        if (!is_array($checkpointPayload)) {
            return self::invalid($base, 'checkpoints_invalid');
        }
        $checkpointPrices = [];
        foreach (self::CHECKPOINTS as $checkpoint) {
            $price = self::positiveFloat($checkpointPayload[$checkpoint] ?? null);
            if ($price === null) {
                return self::invalid($base, 'checkpoint_' . $checkpoint . '_invalid');
            }
            $checkpointPrices[$checkpoint] = $price;
        }

        $previousClose = $values['previous_close'];
        $premarketLast = $values['premarket_last'];
        $regularOpen = $checkpointPrices['open'];
        $checkpoints = [];
        foreach (self::CHECKPOINTS as $checkpoint) {
            $price = $checkpointPrices[$checkpoint];
            $checkpoints[$checkpoint] = [
                'price' => $price,
                'vs_previous_close_pct' => self::percentChange($previousClose, $price),
                'vs_premarket_last_pct' => self::percentChange($premarketLast, $price),
                'vs_regular_open_pct' => self::percentChange($regularOpen, $price),
            ];
        }

        return array_merge($base, [
            'valid' => true,
            'reason' => 'feature_row_built',
            'features' => [
                'premarket_open_gap_pct' => self::percentChange($previousClose, $values['premarket_open']),
                'premarket_last_gap_pct' => self::percentChange($previousClose, $premarketLast),
                'premarket_range_pct_of_previous_close' => self::metric(
                    ($values['premarket_high'] - $values['premarket_low']) / $previousClose * 100.0,
                ),
                'premarket_last_vs_vwap_pct' => self::percentChange($values['premarket_vwap'], $premarketLast),
                'premarket_volume' => $volume,
                'reference_premarket_volume' => $values['reference_premarket_volume'],
                'premarket_volume_ratio' => self::metric($volume / $values['reference_premarket_volume']),
                'daily_rsi' => $dailyRsi,
                'weekly_rsi' => $weeklyRsi,
                'premarket_last_vs_20w_ema_pct' => self::percentChange($weeklyEma20, $premarketLast),
                'premarket_last_vs_20w_sma_pct' => self::percentChange($weeklySma20, $premarketLast),
                'regular_open_vs_20w_ema_pct' => self::percentChange($weeklyEma20, $regularOpen),
                'regular_open_vs_20w_sma_pct' => self::percentChange($weeklySma20, $regularOpen),
                'weekly_trend_context' => match (true) {
                    $premarketLast >= $weeklyEma20 && $premarketLast >= $weeklySma20 => 'above_20w_ema_and_sma',
                    $premarketLast < $weeklyEma20 && $premarketLast < $weeklySma20 => 'below_20w_ema_and_sma',
                    default => 'between_20w_ema_and_sma',
                },
            ],
            'entry_checkpoints' => $checkpoints,
            'exit_reference_levels' => [
                'premarket_low' => $values['premarket_low'],
                'premarket_vwap' => $values['premarket_vwap'],
                'regular_open' => $checkpointPrices['open'],
                'regular_5m' => $checkpointPrices['5m'],
                'regular_15m' => $checkpointPrices['15m'],
            ],
        ]);
    }

    /** @param array<string, mixed> $base @return array<string, mixed> */
    private static function invalid(array $base, string $reason): array
    {
        $base['reason'] = $reason;

        return $base;
    }

    private static function validDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value;
    }

    private static function positiveFloat(mixed $value): ?float
    {
        $number = self::nonNegativeFloat($value);

        return $number !== null && $number > 0.0 ? $number : null;
    }

    private static function nonNegativeFloat(mixed $value): ?float
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return null;
        }
        $number = (float) $value;

        return is_finite($number) && $number >= 0.0 ? $number : null;
    }

    private static function boundedFloat(mixed $value, float $minimum, float $maximum): ?float
    {
        $number = self::nonNegativeFloat($value);

        return $number !== null && $number >= $minimum && $number <= $maximum ? $number : null;
    }

    private static function percentChange(float $from, float $to): float
    {
        return self::metric(($to / $from - 1.0) * 100.0);
    }

    private static function metric(float $value): float
    {
        return round($value, 6);
    }
}
