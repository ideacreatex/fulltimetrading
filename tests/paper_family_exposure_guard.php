<?php

declare(strict_types=1);

use FulltimeTrading\Trading\PaperFamilyExposureGuard;

require __DIR__ . '/../bootstrap.php';

function familyAssertNear(float $expected, float $actual, string $message): void
{
    if (abs($expected - $actual) > 1.0e-9) {
        throw new RuntimeException(sprintf('%s: expected %.6f, got %.6f', $message, $expected, $actual));
    }
}

function familyAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf('%s: expected %s, got %s', $message, var_export($expected, true), var_export($actual, true)));
    }
}

$guard = new PaperFamilyExposureGuard();
$exposure = $guard->exposureByFamily(
    [
        ['symbol' => 'SOXL', 'market_value' => '12000'],
        ['symbol' => 'TECL', 'market_value' => '5000'],
    ],
    [
        ['symbol' => 'USD', 'side' => 'buy', 'status' => 'partially_filled', 'qty' => '100', 'filled_qty' => '40', 'limit_price' => '50'],
        ['symbol' => 'TQQQ', 'side' => 'buy', 'status' => 'accepted', 'notional' => '2500'],
        ['symbol' => 'TQQQ', 'side' => 'buy', 'status' => 'canceled', 'qty' => '100', 'limit_price' => '50'],
        ['symbol' => 'SOXL', 'side' => 'sell', 'status' => 'new', 'qty' => '10', 'limit_price' => '100'],
    ],
);
familyAssertNear(15000.0, $exposure['SEMICONDUCTORS'], 'position plus remaining buy order');
familyAssertNear(5000.0, $exposure['TECH'], 'separate family position');
familyAssertNear(2500.0, $exposure['NASDAQ_100'], 'notional buy order exposure');
familyAssertNear(22500.0, $guard->grossExposure(
    [
        ['symbol' => 'SOXL', 'market_value' => '12000'],
        ['symbol' => 'TECL', 'market_value' => '-5000'],
    ],
    [
        ['symbol' => 'USD', 'side' => 'buy', 'status' => 'partially_filled', 'qty' => '100', 'filled_qty' => '40', 'limit_price' => '50'],
        ['symbol' => 'TQQQ', 'side' => 'buy', 'status' => 'accepted', 'notional' => '2500'],
        ['symbol' => 'SOXL', 'side' => 'sell', 'status' => 'new', 'qty' => '10', 'limit_price' => '100'],
    ],
), 'gross exposure includes positions and remaining active buys');
familyAssertSame('SEMICONDUCTORS', $guard->familyForSymbol('usd'), 'family lookup');
familyAssertSame(null, $guard->familyForSymbol('UNKNOWN'), 'unknown family is uncapped');
familyAssertNear(7500.0, $guard->availableNotional('SOXL', 30000.0, 0.75, $exposure), 'available family budget');
if (!is_infinite($guard->availableNotional('UNKNOWN', 30000.0, 0.75, $exposure))) {
    throw new RuntimeException('Unknown family must remain uncapped.');
}
$guard->reserve('USD', 2500.0, $exposure);
familyAssertNear(5000.0, $guard->availableNotional('SOXL', 30000.0, 0.75, $exposure), 'planned order reserves family budget');

echo "Paper family exposure guard OK\n";
