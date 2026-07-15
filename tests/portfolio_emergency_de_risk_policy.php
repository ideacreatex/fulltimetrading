<?php

declare(strict_types=1);

use FulltimeTrading\Trading\PortfolioEmergencyDeRiskPolicy;

require __DIR__ . '/../bootstrap.php';

function deRiskAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function deRiskSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf('%s: expected %s, got %s', $message, var_export($expected, true), var_export($actual, true)));
    }
}

function deRiskNear(float $expected, float $actual, string $message): void
{
    if (abs($expected - $actual) > 1.0e-8) {
        throw new RuntimeException(sprintf('%s: expected %.10f, got %.10f', $message, $expected, $actual));
    }
}

$policy = new PortfolioEmergencyDeRiskPolicy();

// A profitable position or touched target must never trigger this policy by itself.
$below = $policy->plan(100000.0, [[
    'symbol' => 'TQQQ',
    'side' => 'long',
    'qty' => 100.0,
    'price' => 100.0,
    'target' => 90.0,
]], 1.50, 1.25, 0.25, 1.0);
deRiskSame(false, $below['emergency'], 'Below-trigger portfolio must not create emergency reductions.');
deRiskSame(false, $below['routine_target_partial'], 'Emergency policy must explicitly exclude routine target partials.');
deRiskSame('gross_exposure_below_emergency_trigger', $below['reason'], 'Below-trigger reason mismatch.');
deRiskSame([], $below['reductions'], 'Below-trigger plan must be empty even when a target field is present.');

// Equality is a trigger. Input order must not change the proportional plan.
$positionsOne = [
    ['symbol' => 'ZZZ', 'side' => 'long', 'qty' => 10.0, 'price' => 10.0],
    ['symbol' => 'AAA', 'side' => 'long', 'qty' => 10.0, 'price' => 10.0],
];
$positionsTwo = array_reverse($positionsOne);
$equalOne = $policy->plan(100.0, $positionsOne, 2.0, 1.5, 0.50, 1.0);
$equalTwo = $policy->plan(100.0, $positionsTwo, 2.0, 1.5, 0.50, 1.0);
deRiskSame(true, $equalOne['emergency'], 'Gross ratio equal to trigger must activate emergency mode.');
deRiskSame('emergency_de_risk_to_target', $equalOne['reason'], 'Successful emergency reason mismatch.');
deRiskNear(50.0, (float) $equalOne['required_reduction_notional'], 'Required reduction mismatch.');
deRiskNear(50.0, (float) $equalOne['reduction_notional'], 'Planned reduction mismatch.');
deRiskSame(['AAA', 'ZZZ'], array_column($equalOne['reductions'], 'symbol'), 'Reduction symbols must be stable and sorted.');
deRiskNear(2.5, (float) $equalOne['reductions'][0]['qty'], 'AAA proportional quantity mismatch.');
deRiskNear(2.5, (float) $equalOne['reductions'][1]['qty'], 'ZZZ proportional quantity mismatch.');
deRiskSame($equalOne['reductions'], $equalTwo['reductions'], 'Input permutation must not change emergency reductions.');

// Minimum quantity caps AAA; the unresolved amount is redistributed to BBB.
$redistributed = $policy->plan(100.0, [
    ['symbol' => 'BBB', 'side' => 'long', 'qty' => 10.0, 'price' => 10.0],
    ['symbol' => 'AAA', 'side' => 'long', 'qty' => 1.5, 'price' => 100.0],
], 2.0, 1.3, 0.80, 1.0);
deRiskSame(['AAA', 'BBB'], array_column($redistributed['reductions'], 'symbol'), 'Redistributed plan ordering mismatch.');
deRiskNear(0.5, (float) $redistributed['reductions'][0]['qty'], 'AAA must preserve its minimum quantity.');
deRiskNear(1.0, (float) $redistributed['reductions'][0]['remaining_qty'], 'AAA minimum remaining quantity mismatch.');
deRiskNear(7.0, (float) $redistributed['reductions'][1]['qty'], 'BBB must receive the remaining proportional reduction.');
deRiskNear(120.0, (float) $redistributed['reduction_notional'], 'Water-filled reduction must reach target exactly.');
deRiskNear(1.3, (float) $redistributed['remaining_gross_ratio'], 'Remaining gross ratio must reach target.');

// A tight cap is fail-safe: report the shortfall instead of exceeding any position cap.
$limited = $policy->plan(100.0, [
    ['symbol' => 'AAA', 'side' => 'long', 'qty' => 10.0, 'price' => 10.0],
    ['symbol' => 'BBB', 'side' => 'long', 'qty' => 10.0, 'price' => 10.0],
], 1.5, 1.0, 0.10, 1.0);
deRiskSame(true, $limited['emergency'], 'Capacity-limited portfolio is still an emergency.');
deRiskSame(true, $limited['capacity_limited'], 'Capacity-limited flag mismatch.');
deRiskSame('emergency_de_risk_capacity_limited', $limited['reason'], 'Capacity-limited reason mismatch.');
deRiskNear(20.0, (float) $limited['reduction_notional'], 'Per-position fraction cap must limit total reduction.');
deRiskNear(80.0, (float) $limited['reduction_shortfall_notional'], 'Capacity shortfall mismatch.');
foreach ($limited['reductions'] as $reduction) {
    deRiskAssert((float) $reduction['reduction_fraction'] <= 0.10 + 1.0e-9, 'A reduction exceeded the per-position cap.');
}

// Explicit shorts are outside this long-only policy and do not affect gross ratio.
$withShort = $policy->plan(100.0, [
    ['symbol' => 'SHORT', 'side' => 'short', 'qty' => 'not-used', 'price' => 0],
    ['symbol' => 'LONG', 'side' => 'long', 'qty' => 10.0, 'price' => 10.0],
], 1.50, 1.25, 0.25, 1.0);
deRiskSame(1, $withShort['ignored_non_long_positions'], 'Explicit short must be counted as ignored.');
deRiskNear(1.0, (float) $withShort['current_gross_ratio'], 'Short must not enter long-only gross exposure.');

// Invalid equity, policy values, direction, quantity, or price must return no sell plan.
$invalidCases = [
    $policy->plan(0.0, [], 1.5, 1.25, 0.25, 1.0),
    $policy->plan(100.0, [], 1.5, 1.5, 0.25, 1.0),
    $policy->plan(100.0, [['symbol' => 'AAA', 'qty' => 10, 'price' => 10]], 1.5, 1.25, 0.25, 1.0),
    $policy->plan(100.0, [['symbol' => 'AAA', 'side' => 'long', 'qty' => NAN, 'price' => 10]], 1.5, 1.25, 0.25, 1.0),
    $policy->plan(100.0, [['symbol' => 'AAA', 'side' => 'long', 'qty' => 10, 'price' => INF]], 1.5, 1.25, 0.25, 1.0),
];
foreach ($invalidCases as $invalid) {
    deRiskSame(false, $invalid['emergency'], 'Invalid inputs must fail closed.');
    deRiskSame([], $invalid['reductions'], 'Invalid inputs must never produce sell quantities.');
    deRiskAssert((string) $invalid['validation_failure'] !== '', 'Invalid inputs must expose a validation reason.');
}

echo "Portfolio emergency de-risk policy OK\n";
