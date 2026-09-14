<?php

declare(strict_types=1);

namespace FulltimeTrading\Trading;

/** Reporting-only normalization; it does not alter ledger or runtime state. */
final class TacticalPaperObservation
{
    public static function maxDrawdown(array $snapshots, float $initialEquity): float
    {
        $peak = $initialEquity;
        $drawdown = 0.0;
        foreach ($snapshots as $row) {
            $equity = (float) $row['equity'];
            $peak = max($peak, $equity);
            if ($peak > 0.0) { $drawdown = min($drawdown, $equity / $peak - 1.0); }
        }
        return $drawdown;
    }

    public static function since(array $snapshots, string $since, \DateTimeImmutable $now): array
    {
        $start = new \DateTimeImmutable($since);
        $rows = array_values(array_filter($snapshots, static function (array $row) use ($start, $now): bool {
            $at = new \DateTimeImmutable((string) $row['captured_at']);
            return $at >= $start && $at <= $now;
        }));
        usort($rows, static fn (array $a, array $b): int =>
            (new \DateTimeImmutable($a['captured_at'])) <=> (new \DateTimeImmutable($b['captured_at']))
            ?: (($a['id'] ?? 0) <=> ($b['id'] ?? 0)));
        return $rows;
    }

    public static function dates(array $snapshots): array
    {
        $stored = $market = [];
        $ny = new \DateTimeZone('America/New_York');
        foreach ($snapshots as $snapshot) {
            $at = (new \DateTimeImmutable($snapshot['captured_at']))->setTimezone($ny);
            $date = $at->format('Y-m-d');
            $stored[$date] = true;
            // Reuse the runtime's US holiday calendar, evaluated after settlement.
            $closed = PaperDailyReportFreshnessGuard::latestExpectedClosedBarDate($at->setTime(23, 59));
            if ($closed->format('Y-m-d') === $date && ($snapshot['payload']['dry_run'] ?? false) !== true) {
                $market[$date] = true;
            }
        }
        return ['stored' => array_keys($stored), 'market' => array_keys($market)];
    }
}
