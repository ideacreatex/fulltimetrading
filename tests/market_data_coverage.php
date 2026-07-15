<?php

declare(strict_types=1);

use FulltimeTrading\Data\MarketDataCoverageAnalyzer;
use FulltimeTrading\Domain\Bar;

require __DIR__ . '/../bootstrap.php';

function coverageBar(string $symbol, string $date): Bar
{
    return new Bar($symbol, new DateTimeImmutable($date), 100.0, 101.0, 99.0, 100.0, 1000.0);
}

function coverageExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$benchmark = [
    coverageBar('SPY', '2026-07-13'),
    coverageBar('SPY', '2026-07-14'),
    coverageBar('SPY', '2026-07-15'),
];
$coverage = (new MarketDataCoverageAnalyzer())->analyze([
    'FULL' => [
        coverageBar('FULL', '2026-07-13'),
        coverageBar('FULL', '2026-07-14'),
        coverageBar('FULL', '2026-07-15'),
    ],
    'GAPPED' => [
        coverageBar('GAPPED', '2026-07-13'),
        coverageBar('GAPPED', '2026-07-15'),
    ],
    'MISSING' => [],
], $benchmark, 0.90);

coverageExpect(($coverage['passes'] ?? true) === false, 'A gapped symbol must fail the universe data-quality gate.');
coverageExpect(($coverage['symbols']['FULL']['passes'] ?? false) === true, 'Complete benchmark coverage must pass.');
coverageExpect(($coverage['symbols']['GAPPED']['missing_sessions'] ?? 0) === 1, 'Missing sessions must be counted.');
coverageExpect(
    ($coverage['symbols']['GAPPED']['missing_session_examples'][0] ?? '') === '2026-07-14',
    'Missing-session diagnostics must identify the absent date.',
);
coverageExpect(
    ($coverage['failures'][0] ?? '') === 'GAPPED_session_coverage_0.6667_below_0.9000',
    'Coverage failure labels must be deterministic and machine-readable.',
);
coverageExpect(
    (float) ($coverage['symbols']['MISSING']['coverage_pct'] ?? 1.0) === 0.0,
    'A completely absent requested symbol must fail with zero coverage.',
);

echo "Market data coverage analyzer OK\n";
