<?php

declare(strict_types=1);

use FulltimeTrading\Support\PaperPlanStatusSummary;

require __DIR__ . '/../bootstrap.php';

function planSummaryExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$nested = PaperPlanStatusSummary::fromPayload([
    'generated_at' => '2026-07-15T21:33:48+00:00',
    'submit_requested' => true,
    'plan' => [
        'orders' => [],
        'skipped' => [['symbol' => 'TQQQ', 'reason' => 'production_validation_blocks_entries']],
    ],
]);
planSummaryExpect(($nested['orders_count'] ?? null) === 0, 'Nested empty orders must be reported as zero.');
planSummaryExpect(($nested['skipped_count'] ?? null) === 1, 'Nested skipped rows must be counted.');
planSummaryExpect(($nested['submit_requested'] ?? null) === true, 'Top-level submit intent must be preserved.');

$legacy = PaperPlanStatusSummary::fromPayload([
    'orders' => [['symbol' => 'USD']],
    'skipped' => [],
]);
planSummaryExpect(($legacy['orders_count'] ?? null) === 1, 'Legacy top-level plan payloads must remain supported.');
planSummaryExpect(($legacy['skipped_count'] ?? null) === 0, 'Legacy empty skipped rows must report zero.');

echo "Paper plan status summary OK\n";
