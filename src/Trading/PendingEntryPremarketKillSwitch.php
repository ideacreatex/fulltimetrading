<?php

declare(strict_types=1);

namespace FulltimeTrading\Trading;

/**
 * A pure, advisory-only policy for pending entries before the regular session.
 *
 * It deliberately fails closed: invalid policy, incomplete observations, stale
 * data and observations outside the premarket window all produce `cancel`.
 * This class has no broker, HTTP, storage or configuration dependencies.
 */
final class PendingEntryPremarketKillSwitch
{
    private const MARKET_TIMEZONE = 'America/New_York';

    /**
     * @param array<string, mixed> $snapshot
     * @param array<string, mixed> $policy
     * @return array<string, mixed>
     */
    public static function evaluate(
        array $snapshot,
        array $policy,
        \DateTimeImmutable $evaluatedAt,
    ): array {
        $symbol = strtoupper(trim((string) ($snapshot['symbol'] ?? '')));
        $base = [
            'symbol' => $symbol,
            'decision' => 'cancel',
            'cancel_pending_entry' => true,
            'reason' => 'evaluation_not_completed',
            'trigger_reasons' => [],
            'observed_at' => null,
            'evaluated_at' => $evaluatedAt->format(\DateTimeInterface::ATOM),
            'age_seconds' => null,
            'previous_close' => null,
            'premarket_price' => null,
            'gap_down_pct' => null,
            'support_price' => null,
            'support_floor' => null,
            'policy' => null,
            'advisory_only' => true,
        ];

        $normalizedPolicy = self::normalizePolicy($policy);
        if (is_string($normalizedPolicy)) {
            return self::cancel($base, $normalizedPolicy);
        }
        $base['policy'] = $normalizedPolicy;

        if ($symbol === '' || preg_match('/^[A-Z][A-Z0-9.\-]{0,14}$/', $symbol) !== 1) {
            return self::cancel($base, 'snapshot_symbol_invalid');
        }

        $previousClose = self::positiveFloat($snapshot['previous_close'] ?? null);
        if ($previousClose === null) {
            return self::cancel($base, 'snapshot_previous_close_invalid');
        }
        $base['previous_close'] = $previousClose;

        $premarketPrice = self::positiveFloat($snapshot['premarket_price'] ?? null);
        if ($premarketPrice === null) {
            return self::cancel($base, 'snapshot_premarket_price_invalid');
        }
        $base['premarket_price'] = $premarketPrice;
        $gapDownPct = max(0.0, ($previousClose - $premarketPrice) / $previousClose * 100.0);
        $base['gap_down_pct'] = self::roundMetric($gapDownPct);

        $observedAtRaw = trim((string) ($snapshot['observed_at'] ?? ''));
        if ($observedAtRaw === '') {
            return self::cancel($base, 'snapshot_observed_at_missing');
        }
        if (!self::hasExplicitTimezone($observedAtRaw)) {
            return self::cancel($base, 'snapshot_observed_at_timezone_missing');
        }
        try {
            $observedAt = new \DateTimeImmutable($observedAtRaw);
        } catch (\Throwable) {
            return self::cancel($base, 'snapshot_observed_at_invalid');
        }
        $base['observed_at'] = $observedAt->format(\DateTimeInterface::ATOM);

        if ($normalizedPolicy['require_session_calendar_confirmation']) {
            $sessionExpected = $snapshot['regular_session_expected'] ?? null;
            if (!is_bool($sessionExpected)) {
                return self::cancel($base, 'snapshot_regular_session_confirmation_invalid');
            }
            if (!$sessionExpected) {
                return self::cancel($base, 'regular_session_not_expected');
            }
        }

        $supportFloor = null;
        if ($normalizedPolicy['support_breach_enabled']) {
            $supportPrice = self::positiveFloat($snapshot['support_price'] ?? null);
            if ($supportPrice === null) {
                return self::cancel($base, 'snapshot_support_price_invalid');
            }
            $supportFloor = $supportPrice * (1.0 - $normalizedPolicy['support_breach_buffer_pct'] / 100.0);
            $base['support_price'] = $supportPrice;
            $base['support_floor'] = self::roundMetric($supportFloor);
        }

        $ageSeconds = $evaluatedAt->getTimestamp() - $observedAt->getTimestamp();
        $base['age_seconds'] = $ageSeconds;
        if ($ageSeconds < -$normalizedPolicy['max_future_skew_seconds']) {
            return self::cancel($base, 'snapshot_observed_at_in_future');
        }
        if ($ageSeconds > $normalizedPolicy['max_data_age_seconds']) {
            return self::cancel($base, 'snapshot_stale');
        }

        if (!self::isInPremarketWindow($observedAt, $normalizedPolicy)) {
            return self::cancel($base, 'snapshot_outside_premarket_window');
        }
        if (!self::isInPremarketWindow($evaluatedAt, $normalizedPolicy)) {
            return self::cancel($base, 'evaluation_outside_premarket_window');
        }

        $triggers = [];
        if ($gapDownPct >= $normalizedPolicy['gap_down_threshold_pct']) {
            $triggers[] = 'sharp_premarket_gap_down';
        }
        if (
            $normalizedPolicy['support_breach_enabled']
            && $supportFloor !== null
            && $premarketPrice < $supportFloor
        ) {
            $triggers[] = 'premarket_support_breached';
        }

        if ($triggers !== []) {
            $base['trigger_reasons'] = $triggers;

            return self::cancel($base, $triggers[0]);
        }

        return array_merge($base, [
            'decision' => 'keep',
            'cancel_pending_entry' => false,
            'reason' => 'premarket_conditions_acceptable',
        ]);
    }

    /**
     * @param array<string, mixed> $policy
     * @return array<string, bool|float|int|string>|string
     */
    private static function normalizePolicy(array $policy): array|string
    {
        $gapThreshold = self::finiteFloat($policy['gap_down_threshold_pct'] ?? 3.0);
        if ($gapThreshold === null || $gapThreshold <= 0.0 || $gapThreshold > 100.0) {
            return 'policy_gap_down_threshold_invalid';
        }

        $maxAge = self::nonNegativeInt($policy['max_data_age_seconds'] ?? 900);
        if ($maxAge === null || $maxAge === 0) {
            return 'policy_max_data_age_invalid';
        }

        $maxFutureSkew = self::nonNegativeInt($policy['max_future_skew_seconds'] ?? 30);
        if ($maxFutureSkew === null) {
            return 'policy_max_future_skew_invalid';
        }

        $supportEnabled = $policy['support_breach_enabled'] ?? true;
        if (!is_bool($supportEnabled)) {
            return 'policy_support_breach_enabled_invalid';
        }

        $requireSessionConfirmation = $policy['require_session_calendar_confirmation'] ?? true;
        if (!is_bool($requireSessionConfirmation)) {
            return 'policy_session_calendar_confirmation_invalid';
        }

        $supportBuffer = self::finiteFloat($policy['support_breach_buffer_pct'] ?? 0.0);
        if ($supportBuffer === null || $supportBuffer < 0.0 || $supportBuffer >= 100.0) {
            return 'policy_support_breach_buffer_invalid';
        }

        $premarketStart = trim((string) ($policy['premarket_start'] ?? '04:00'));
        $regularOpen = trim((string) ($policy['regular_open'] ?? '09:30'));
        if (!self::validClockTime($premarketStart) || !self::validClockTime($regularOpen)) {
            return 'policy_premarket_window_invalid';
        }
        if (self::clockMinutes($premarketStart) >= self::clockMinutes($regularOpen)) {
            return 'policy_premarket_window_invalid';
        }

        return [
            'gap_down_threshold_pct' => $gapThreshold,
            'support_breach_enabled' => $supportEnabled,
            'support_breach_buffer_pct' => $supportBuffer,
            'require_session_calendar_confirmation' => $requireSessionConfirmation,
            'max_data_age_seconds' => $maxAge,
            'max_future_skew_seconds' => $maxFutureSkew,
            'market_timezone' => self::MARKET_TIMEZONE,
            'premarket_start' => $premarketStart,
            'regular_open' => $regularOpen,
        ];
    }

    /** @param array<string, mixed> $base @return array<string, mixed> */
    private static function cancel(array $base, string $reason): array
    {
        $base['decision'] = 'cancel';
        $base['cancel_pending_entry'] = true;
        $base['reason'] = $reason;

        return $base;
    }

    /** @param array<string, bool|float|int|string> $policy */
    private static function isInPremarketWindow(\DateTimeImmutable $time, array $policy): bool
    {
        $marketTime = $time->setTimezone(new \DateTimeZone(self::MARKET_TIMEZONE));
        if ((int) $marketTime->format('N') > 5) {
            return false;
        }
        $minutes = ((int) $marketTime->format('H')) * 60 + (int) $marketTime->format('i');

        return $minutes >= self::clockMinutes((string) $policy['premarket_start'])
            && $minutes < self::clockMinutes((string) $policy['regular_open']);
    }

    private static function validClockTime(string $value): bool
    {
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    private static function hasExplicitTimezone(string $value): bool
    {
        return preg_match('/(?:Z|[+\-]\d{2}:?\d{2})$/i', $value) === 1;
    }

    private static function clockMinutes(string $value): int
    {
        [$hour, $minute] = array_map('intval', explode(':', $value, 2));

        return $hour * 60 + $minute;
    }

    private static function positiveFloat(mixed $value): ?float
    {
        $number = self::finiteFloat($value);

        return $number !== null && $number > 0.0 ? $number : null;
    }

    private static function finiteFloat(mixed $value): ?float
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return null;
        }
        $number = (float) $value;

        return is_finite($number) ? $number : null;
    }

    private static function nonNegativeInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (is_float($value) && is_finite($value) && $value >= 0.0 && floor($value) === $value) {
            return (int) $value;
        }
        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            return (int) $value;
        }

        return null;
    }

    private static function roundMetric(float $value): float
    {
        return round($value, 6);
    }
}
