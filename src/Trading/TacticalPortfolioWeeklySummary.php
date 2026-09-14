<?php

declare(strict_types=1);

namespace FulltimeTrading\Trading;

final class TacticalPortfolioWeeklySummary
{
    /**
     * @param list<array<string,mixed>> $snapshots
     * @return array{
     *   week_start:string,
     *   week_end:string,
     *   observed_from:string,
     *   observed_sessions:int,
     *   start_equity:float,
     *   end_equity:float,
     *   delta_equity:float,
     *   delta_pct:float,
     *   high_equity:float,
     *   low_equity:float
     * }|null
     */
    public static function fromSnapshots(
        array $snapshots,
        string $sessionDate,
        float $currentEquity,
    ): ?array {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $sessionDate) !== 1) {
            return null;
        }

        try {
            $timezone = new \DateTimeZone('America/New_York');
            $sessionDay = new \DateTimeImmutable($sessionDate . ' 12:00:00', $timezone);
            $weekStart = $sessionDay->modify('monday this week')->setTime(0, 0, 0);
            $weekEnd = $sessionDay->setTime(23, 59, 59);
        } catch (\Throwable) {
            return null;
        }

        $eligible = [];
        foreach ($snapshots as $snapshot) {
            if (!is_array($snapshot)) {
                continue;
            }
            try {
                $capturedAt = (new \DateTimeImmutable((string) ($snapshot['captured_at'] ?? '')))
                    ->setTimezone($timezone);
            } catch (\Throwable) {
                continue;
            }
            if ($capturedAt < $weekStart || $capturedAt > $weekEnd) {
                continue;
            }
            $snapshot['_captured_at_ny'] = $capturedAt;
            $eligible[] = $snapshot;
        }

        if ($eligible === []) {
            return null;
        }

        usort($eligible, static function (array $left, array $right): int {
            /** @var \DateTimeImmutable $leftTime */
            $leftTime = $left['_captured_at_ny'];
            /** @var \DateTimeImmutable $rightTime */
            $rightTime = $right['_captured_at_ny'];

            return $leftTime <=> $rightTime;
        });

        $first = $eligible[0];
        $last = $eligible[count($eligible) - 1];
        $endEquity = $currentEquity > 0.0 ? $currentEquity : (float) ($last['equity'] ?? 0.0);
        $equities = array_map(
            static fn (array $row): float => (float) ($row['equity'] ?? 0.0),
            $eligible,
        );
        $equities[] = $endEquity;
        $observedSessions = [];
        foreach ($eligible as $row) {
            /** @var \DateTimeImmutable $capturedAt */
            $capturedAt = $row['_captured_at_ny'];
            $observedSessions[$capturedAt->format('Y-m-d')] = true;
        }

        $startEquity = (float) ($first['equity'] ?? 0.0);
        $deltaEquity = $endEquity - $startEquity;

        return [
            'week_start' => $weekStart->format('Y-m-d'),
            'week_end' => $sessionDate,
            'observed_from' => $first['_captured_at_ny']->format('Y-m-d'),
            'observed_sessions' => count($observedSessions),
            'start_equity' => $startEquity,
            'end_equity' => $endEquity,
            'delta_equity' => $deltaEquity,
            'delta_pct' => $startEquity > 0.0 ? 100.0 * $deltaEquity / $startEquity : 0.0,
            'high_equity' => max($equities),
            'low_equity' => min($equities),
        ];
    }
}
