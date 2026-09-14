<?php

declare(strict_types=1);

use FulltimeTrading\Domain\Bar;
use FulltimeTrading\Research\HybridV4Research;

require dirname(__DIR__) . '/bootstrap.php';

function researchAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$profile = require dirname(__DIR__) . '/config/tactical_rotation.php';
$original = $profile;
$tester = HybridV4Research::backtester($profile, ['risk_scale' => 0.75, 'drawdown_cooldown_sessions' => 20], 40.0);
$config = $tester->config();
researchAssert($profile === $original, 'Experiments must not change the operational profile.');
$diversified = HybridV4Research::backtester($profile, ['dynamic_allocation' => 0.4, 'defensive_overrides' => ['rebalance_sessions' => 5]], 30.0)->config();
researchAssert(abs(array_sum(array_column($diversified, 'allocation')) - 1.0) < 1.0e-12, 'Allocation experiments must conserve capital.');
researchAssert($diversified['dynamic_loo10']['config']['rebalance_sessions'] === 3 && $diversified['spy200_full']['config']['rebalance_sessions'] === 5, 'Different horizons must remain sleeve-specific.');
researchAssert(abs($config['dynamic_loo10']['config']['max_gross'] - 0.87) < 1.0e-12, 'Risk changes must apply after sleeve overrides.');
foreach ($config as $sleeve) {
    researchAssert($sleeve['config']['drawdown_cooldown_sessions'] === 20, 'All sleeves must receive the experimental cooldown.');
    researchAssert($sleeve['config']['cost_bps'] === 40.0, 'Stress costs must not be overwritten by sleeve defaults.');
}
$screen = [
    'baseline' => ['train' => ['cagr' => 10.0, 'max_drawdown' => -0.1], 'train_failed_gates' => []],
    'b' => ['train' => ['cagr' => 1.1, 'max_drawdown' => -0.2], 'train_failed_gates' => [], 'later_return' => 10000],
    'a' => ['train' => ['cagr' => 1.1, 'max_drawdown' => -0.2], 'train_failed_gates' => [], 'later_return' => -1],
    'failed' => ['train' => ['cagr' => 100.0, 'max_drawdown' => -0.1], 'train_failed_gates' => ['gross']],
];
researchAssert(HybridV4Research::shortlist($screen) === ['a', 'b'], 'Selection must ignore later returns, reject failed gates, and break ties deterministically.');
$screen['a']['later_return'] = -100000;
$screen['b']['later_return'] = 1000000;
researchAssert(HybridV4Research::shortlist($screen) === ['a', 'b'], 'Later-period changes cannot select another candidate.');
$bars = ['AAA' => [
    new Bar('AAA', new DateTimeImmutable('2023-12-29T21:00:00Z'), 1, 1, 1, 1, 100),
    new Bar('AAA', new DateTimeImmutable('2024-01-02T21:00:00Z'), 1000, 1000, 1000, 1000, 100),
]];
researchAssert(count(HybridV4Research::truncateBars($bars, '2023-12-29')['AAA']) === 1, 'Training data must physically exclude later bars.');
$activity = HybridV4Research::activity(['book' => [
    ['date' => '2024-01-01', 'holding' => 'AAA', 'turnover' => 1.0],
    ['date' => '2024-01-02', 'holding' => 'AAA', 'turnover' => 0.1],
    ['date' => '2024-01-03', 'holding' => 'BBB', 'turnover' => 2.0],
    ['date' => '2024-01-04', 'holding' => null, 'turnover' => 1.0],
]], '2024-01-02');
researchAssert($activity['book']['entries'] === 1 && $activity['book']['exits'] === 2 && $activity['book']['resize_days'] === 1, 'Activity must distinguish new entries, resizes, and an inherited position.');
researchAssert($activity['book']['cash_sessions'] === 1, 'Cash observations must be counted independently.');

echo "Hybrid-v4 offline research tests OK\n";
