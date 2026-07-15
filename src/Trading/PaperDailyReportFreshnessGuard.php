<?php

declare(strict_types=1);

namespace FulltimeTrading\Trading;

final class PaperDailyReportFreshnessGuard
{
    private const MARKET_TIMEZONE = 'America/New_York';
    private const DAILY_BAR_SETTLE_TIME = '16:15:00';
    private const MAX_CALENDAR_AGE_DAYS = 5;
    private const MAX_MISSING_TRADING_SESSIONS = 0;

    /**
     * @param ?array<string, mixed> $reportStep
     * @return array<string, mixed>
     */
    public static function evaluate(
        string $reportPath,
        bool $createdByCurrentCycle,
        ?array $reportStep,
        \DateTimeImmutable $cycleStartedAt,
        string $requestedAsOf,
        bool $submitRequested,
        ?\DateTimeImmutable $now = null,
    ): array {
        $now ??= new \DateTimeImmutable();
        $base = [
            'ok' => false,
            'report_path' => $reportPath,
            'created_by_current_cycle' => $createdByCurrentCycle,
            'submit_requested' => $submitRequested,
            'requested_as_of' => $requestedAsOf,
        ];

        if ($createdByCurrentCycle && empty($reportStep['ok'])) {
            return self::failure($base, 'daily_signal_report_failed');
        }
        if (!$createdByCurrentCycle && $submitRequested) {
            return self::failure($base, 'submit_requires_report_created_by_current_cycle');
        }
        if (!is_file($reportPath)) {
            return self::failure($base, 'report_file_missing');
        }

        try {
            $report = json_decode((string) file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            return self::failure($base, 'report_json_invalid', $e->getMessage());
        }
        if (!is_array($report)) {
            return self::failure($base, 'report_payload_invalid');
        }

        $generatedAtRaw = trim((string) ($report['generated_at'] ?? ''));
        if ($generatedAtRaw === '') {
            return self::failure($base, 'report_generated_at_missing');
        }
        try {
            $generatedAt = new \DateTimeImmutable($generatedAtRaw);
        } catch (\Throwable $e) {
            return self::failure($base, 'report_generated_at_invalid', $e->getMessage());
        }
        if ($generatedAt > $now->modify('+30 seconds')) {
            return self::failure($base, 'report_generated_at_in_future');
        }
        if ($createdByCurrentCycle && $generatedAt < $cycleStartedAt->modify('-2 seconds')) {
            return self::failure($base, 'report_not_generated_during_current_cycle');
        }

        $asOfRaw = trim((string) ($report['as_of'] ?? ''));
        $asOf = self::parseDate($asOfRaw);
        if ($asOf === null) {
            return self::failure($base, 'report_as_of_invalid');
        }

        $requestedDate = null;
        if ($requestedAsOf !== '') {
            $requestedDate = self::parseDate($requestedAsOf);
            if ($requestedDate === null) {
                return self::failure($base, 'requested_as_of_invalid');
            }
            if ($requestedDate->format('Y-m-d') !== $asOf->format('Y-m-d')) {
                return self::failure($base, 'report_as_of_mismatch');
            }
        }

        $latestClosedDate = self::latestExpectedClosedBarDate($now);
        if ($asOf > $latestClosedDate) {
            return self::failure($base, 'report_as_of_is_not_a_closed_daily_bar');
        }
        $asOfSettledAt = $asOf->setTime(16, 15);
        if ($generatedAt->setTimezone(new \DateTimeZone(self::MARKET_TIMEZONE)) < $asOfSettledAt) {
            return self::failure($base, 'report_generated_before_as_of_bar_closed');
        }

        $requiresCurrentBar = $submitRequested || $requestedDate === null;
        $calendarAgeDays = (int) $asOf->diff($latestClosedDate)->days;
        $missingSessions = self::tradingSessionGap($asOf, $latestClosedDate);
        if ($requiresCurrentBar && (
            $calendarAgeDays > self::MAX_CALENDAR_AGE_DAYS
            || $missingSessions > self::MAX_MISSING_TRADING_SESSIONS
        )) {
            return self::failure($base, 'report_as_of_stale');
        }

        return array_merge($base, [
            'ok' => true,
            'reason' => 'fresh_report_verified',
            'generated_at' => $generatedAt->format(\DateTimeInterface::ATOM),
            'as_of' => $asOf->format('Y-m-d'),
            'latest_expected_closed_bar' => $latestClosedDate->format('Y-m-d'),
            'calendar_age_days' => $calendarAgeDays,
            'missing_trading_sessions' => $missingSessions,
        ]);
    }

    public static function latestExpectedClosedBarDate(\DateTimeImmutable $now): \DateTimeImmutable
    {
        $marketNow = $now->setTimezone(new \DateTimeZone(self::MARKET_TIMEZONE));
        $weekday = (int) $marketNow->format('N');
        $todayIsClosed = $weekday <= 5 && $marketNow->format('H:i:s') >= self::DAILY_BAR_SETTLE_TIME;
        $candidate = $marketNow->setTime(0, 0);
        if (!$todayIsClosed) {
            $candidate = $candidate->modify('-1 day');
        }
        while (!self::isExpectedUsTradingDay($candidate)) {
            $candidate = $candidate->modify('-1 day');
        }

        return $candidate;
    }

    public static function closedBarReportEnd(
        string $requestedEnd,
        string $requestedAsOf,
        \DateTimeImmutable $now,
    ): string {
        $cap = $requestedAsOf !== ''
            ? $requestedAsOf
            : self::latestExpectedClosedBarDate($now)->format('Y-m-d');
        if (self::parseDate($requestedEnd) === null || self::parseDate($cap) === null) {
            return $requestedEnd;
        }

        return $requestedEnd <= $cap ? $requestedEnd : $cap;
    }

    /** @param array<string, mixed> $report */
    public static function barWasClosedWhenReportGenerated(array $report, string $barDate): bool
    {
        $bar = self::parseDate($barDate);
        $generatedAtRaw = trim((string) ($report['generated_at'] ?? ''));
        if ($bar === null || $generatedAtRaw === '') {
            return false;
        }
        try {
            $generatedAt = new \DateTimeImmutable($generatedAtRaw);
        } catch (\Throwable) {
            return false;
        }

        return $bar <= self::latestExpectedClosedBarDate($generatedAt);
    }

    /** @param array<string, mixed> $assessment */
    public static function allowsDownstream(array $assessment): bool
    {
        return ($assessment['ok'] ?? false) === true;
    }

    private static function parseDate(string $value): ?\DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value,
            new \DateTimeZone(self::MARKET_TIMEZONE),
        );

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    private static function tradingSessionGap(\DateTimeImmutable $from, \DateTimeImmutable $through): int
    {
        $count = 0;
        for ($date = $from->modify('+1 day'); $date <= $through; $date = $date->modify('+1 day')) {
            if (self::isExpectedUsTradingDay($date)) {
                $count++;
            }
        }

        return $count;
    }

    private static function isExpectedUsTradingDay(\DateTimeImmutable $date): bool
    {
        if ((int) $date->format('N') > 5) {
            return false;
        }

        return !isset(self::usMarketHolidays((int) $date->format('Y'))[$date->format('Y-m-d')]);
    }

    /** @return array<string, true> */
    private static function usMarketHolidays(int $year): array
    {
        static $cache = [];
        if (isset($cache[$year])) {
            return $cache[$year];
        }

        $timezone = new \DateTimeZone(self::MARKET_TIMEZONE);
        $holidays = [];
        $add = static function (\DateTimeImmutable $date) use (&$holidays): void {
            $holidays[$date->format('Y-m-d')] = true;
        };
        $observed = static function (\DateTimeImmutable $date): \DateTimeImmutable {
            return match ((int) $date->format('N')) {
                6 => $date->modify('-1 day'),
                7 => $date->modify('+1 day'),
                default => $date,
            };
        };
        $nthWeekday = static function (int $month, int $weekday, int $nth) use ($year, $timezone): \DateTimeImmutable {
            $date = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), $timezone);
            while ((int) $date->format('N') !== $weekday) {
                $date = $date->modify('+1 day');
            }

            return $date->modify('+' . (($nth - 1) * 7) . ' days');
        };
        $lastWeekday = static function (int $month, int $weekday) use ($year, $timezone): \DateTimeImmutable {
            $date = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month), $timezone))->modify('last day of this month');
            while ((int) $date->format('N') !== $weekday) {
                $date = $date->modify('-1 day');
            }

            return $date;
        };

        // Include adjacent New Year's observations because Jan 1 Saturday is
        // observed on Dec 31 of the prior calendar year.
        foreach ([$year, $year + 1] as $newYear) {
            $date = new \DateTimeImmutable(sprintf('%04d-01-01', $newYear), $timezone);
            $observedDate = $observed($date);
            if ((int) $observedDate->format('Y') === $year) {
                $add($observedDate);
            }
        }
        $add($nthWeekday(1, 1, 3)); // Martin Luther King Jr. Day
        $add($nthWeekday(2, 1, 3)); // Presidents Day
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $easterMonth = intdiv($h + $l - 7 * $m + 114, 31);
        $easterDay = (($h + $l - 7 * $m + 114) % 31) + 1;
        $easter = new \DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $easterMonth, $easterDay), $timezone);
        $add($easter->modify('-2 days')); // Good Friday
        $add($lastWeekday(5, 1)); // Memorial Day
        if ($year >= 2022) {
            $add($observed(new \DateTimeImmutable(sprintf('%04d-06-19', $year), $timezone)));
        }
        $add($observed(new \DateTimeImmutable(sprintf('%04d-07-04', $year), $timezone)));
        $add($nthWeekday(9, 1, 1)); // Labor Day
        $add($nthWeekday(11, 4, 4)); // Thanksgiving
        $add($observed(new \DateTimeImmutable(sprintf('%04d-12-25', $year), $timezone)));

        return $cache[$year] = $holidays;
    }

    /** @param array<string, mixed> $base @return array<string, mixed> */
    private static function failure(array $base, string $reason, string $detail = ''): array
    {
        $base['reason'] = $reason;
        if ($detail !== '') {
            $base['detail'] = $detail;
        }

        return $base;
    }
}
