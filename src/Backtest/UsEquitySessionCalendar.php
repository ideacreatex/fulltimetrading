<?php

declare(strict_types=1);

namespace FulltimeTrading\Backtest;

/**
 * Frozen US-equity regular-session closes for the historical research window.
 *
 * The half days below come from Alpaca's /v2/calendar response for
 * 2021-01-01 through 2026-07-16. Keeping the snapshot in code makes an
 * offline backtest deterministic and prevents post-close IEX prints from
 * being mistaken for regular-session execution data.
 */
final class UsEquitySessionCalendar
{
    private const OPEN_TIME = '09:30';

    private const DEFAULT_CLOSE_TIME = '16:00';

    /** @var array<string, string> */
    private const SPECIAL_CLOSES = [
        '2021-11-26' => '13:00',
        '2022-11-25' => '13:00',
        '2023-07-03' => '13:00',
        '2023-11-24' => '13:00',
        '2024-07-03' => '13:00',
        '2024-11-29' => '13:00',
        '2024-12-24' => '13:00',
        '2025-07-03' => '13:00',
        '2025-11-28' => '13:00',
        '2025-12-24' => '13:00',
    ];

    public static function openTime(string $session): string
    {
        self::assertSession($session);

        return self::OPEN_TIME;
    }

    public static function closeTime(string $session): string
    {
        self::assertSession($session);

        return self::SPECIAL_CLOSES[$session] ?? self::DEFAULT_CLOSE_TIME;
    }

    public static function isSpecialClose(string $session): bool
    {
        self::assertSession($session);

        return isset(self::SPECIAL_CLOSES[$session]);
    }

    public static function isRegularBarStart(string $session, string $time): bool
    {
        self::assertTime($time);

        return $time >= self::openTime($session) && $time < self::closeTime($session);
    }

    /**
     * A reclaim needs its own completed bar plus a later bar whose open can be
     * used as the causal execution price. Therefore the last reclaim start is
     * two bar widths before the actual session close, clamped by configuration.
     */
    public static function lastReclaimBarStart(
        string $session,
        int $barMinutes,
        string $configuredLastStart,
    ): string {
        if ($barMinutes <= 0) {
            throw new \InvalidArgumentException('Intraday bar minutes must be positive.');
        }
        self::assertTime($configuredLastStart);

        $timezone = new \DateTimeZone('America/New_York');
        $close = new \DateTimeImmutable($session . ' ' . self::closeTime($session), $timezone);
        $latestExecutable = $close->modify('-' . ($barMinutes * 2) . ' minutes')->format('H:i');

        return min($configuredLastStart, $latestExecutable);
    }

    private static function assertSession(string $session): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $session)) {
            throw new \InvalidArgumentException('Session must use YYYY-MM-DD.');
        }
    }

    private static function assertTime(string $time): void
    {
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time)) {
            throw new \InvalidArgumentException('Time must use HH:MM.');
        }
    }
}
