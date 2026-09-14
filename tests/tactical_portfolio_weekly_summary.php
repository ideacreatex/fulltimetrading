<?php

declare(strict_types=1);

use FulltimeTrading\Trading\TacticalPortfolioWeeklySummary;

require __DIR__ . '/../bootstrap.php';

function weeklySummaryExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$summary = TacticalPortfolioWeeklySummary::fromSnapshots([
    ['captured_at' => '2026-08-17T16:20:00-04:00', 'equity' => 28947.84],
    ['captured_at' => '2026-08-18T16:20:00-04:00', 'equity' => 27568.25],
    ['captured_at' => '2026-08-19T16:20:00-04:00', 'equity' => 27610.10],
], '2026-08-21', 28110.55);

weeklySummaryExpect(is_array($summary), 'A covered week must produce a summary.');
weeklySummaryExpect(
    $summary['week_start'] === '2026-08-17'
    && $summary['observed_from'] === '2026-08-17'
    && $summary['week_end'] === '2026-08-21',
    'Weekly boundaries must track the Monday-to-close window.',
);
weeklySummaryExpect(
    abs($summary['start_equity'] - 28947.84) < 0.0001
    && abs($summary['end_equity'] - 28110.55) < 0.0001
    && abs($summary['delta_equity'] + 837.29) < 0.0001,
    'Weekly P/L must use the first observed week snapshot and the current close equity.',
);
weeklySummaryExpect(
    $summary['observed_sessions'] === 3
    && abs($summary['high_equity'] - 28947.84) < 0.0001
    && abs($summary['low_equity'] - 27568.25) < 0.0001,
    'Weekly range stats must reflect the observed session coverage.',
);
weeklySummaryExpect(
    TacticalPortfolioWeeklySummary::fromSnapshots([], '2026-08-21', 28110.55) === null,
    'No week snapshots must fail closed instead of inventing a weekly result.',
);

echo "tactical portfolio weekly summary tests passed\n";
