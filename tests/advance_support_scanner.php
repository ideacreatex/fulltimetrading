<?php

declare(strict_types=1);

use FulltimeTrading\Domain\Bar;
use FulltimeTrading\Indicators\IndicatorCalculator;
use FulltimeTrading\Strategy\PoosScanner;

require __DIR__ . '/../bootstrap.php';

function advanceScannerAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$scanner = new PoosScanner(new IndicatorCalculator(), []);
$candidate = new ReflectionMethod($scanner, 'isAdvanceSupportCandidate');
$projection = new ReflectionMethod($scanner, 'projectSupportLevel');

$baseDisabledScanner = new PoosScanner(new IndicatorCalculator(), ['poos_base_enabled' => false]);
advanceScannerAssert(
    $baseDisabledScanner->scan('TEST', [], []) === [],
    'Disabling the generic POOS family must leave an empty scan when no explicit setup family is enabled.',
);

$untouched = new Bar(
    'TEST',
    new DateTimeImmutable('2026-07-15'),
    110.0,
    112.0,
    106.0,
    109.0,
    1000.0,
);
advanceScannerAssert(
    $candidate->invoke($scanner, $untouched, 100.0, 99.5, 5.0, 0.015, 0.10, 3.0, 0.0, true) === true,
    'An untouched rising support inside both distance caps must be eligible for a next-session limit.',
);

$alreadyTouched = new Bar(
    'TEST',
    new DateTimeImmutable('2026-07-15'),
    105.0,
    110.0,
    101.0,
    108.0,
    1000.0,
);
advanceScannerAssert(
    $candidate->invoke($scanner, $alreadyTouched, 100.0, 99.5, 5.0, 0.015, 0.10, 3.0, 0.0, true) === false,
    'A planning bar that already touched support must not be back-selected as an exact first-touch setup.',
);
advanceScannerAssert(
    $candidate->invoke($scanner, $alreadyTouched, 100.0, 99.5, 5.0, 0.015, 0.10, 3.0, 0.0, false) === true,
    'The explicit retest sensitivity mode may arm after an earlier touch.',
);
advanceScannerAssert(
    $candidate->invoke($scanner, $untouched, 100.0, 101.0, 5.0, 0.015, 0.10, 3.0, 0.0, true) === false,
    'A falling support must fail a non-negative slope requirement.',
);

$bars = [];
foreach ([10.0, 11.0, 12.0, 13.0, 14.0] as $i => $close) {
    $bars[] = new Bar(
        'TEST',
        new DateTimeImmutable('2026-07-' . sprintf('%02d', 10 + $i)),
        $close,
        $close,
        $close,
        $close,
        1000.0,
    );
}
$dynamicSma = (float) $projection->invoke(
    $scanner,
    12.0,
    11.0,
    'dynamic_exact',
    0.10,
    'sma',
    5,
    $bars,
    4,
);
advanceScannerAssert(
    abs($dynamicSma - 12.5) < 1.0e-9,
    'The next-session dynamic SMA touch must equal the mean of the previous N-1 closes.',
);
$cappedDynamicSma = (float) $projection->invoke(
    $scanner,
    10.0,
    9.9,
    'dynamic_exact',
    0.01,
    'sma',
    5,
    $bars,
    4,
);
advanceScannerAssert(
    abs($cappedDynamicSma - 10.1) < 1.0e-9,
    'Exact dynamic SMA projection must respect the configured maximum shift.',
);
$dynamicEma = (float) $projection->invoke(
    $scanner,
    12.0,
    11.0,
    'dynamic_exact',
    0.01,
    'ema',
    5,
    $bars,
    4,
);
advanceScannerAssert(
    abs($dynamicEma - 12.0) < 1.0e-9,
    'The completed EMA is already the exact next-session dynamic EMA touch level.',
);
$cappedLinear = (float) $projection->invoke(
    $scanner,
    100.0,
    90.0,
    'linear',
    0.01,
    'ema',
    20,
    $bars,
    4,
);
advanceScannerAssert(abs($cappedLinear - 101.0) < 1.0e-9, 'Linear sensitivity projection must respect its cap.');

echo "Advance support scanner OK\n";
