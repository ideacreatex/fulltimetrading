<?php

declare(strict_types=1);

use FulltimeTrading\Domain\Signal;
use FulltimeTrading\Strategy\MarketRegime;
use FulltimeTrading\Trading\PositionSizingPolicy;

require __DIR__ . '/../bootstrap.php';

$strategy = [
    'club_rules' => [
        'enabled' => true,
        'stable_market_position_pct' => 1.0,
        'unstable_market_position_pct' => 0.05,
        'stable_market_score_threshold' => 2.5,
        'unstable_warning_count' => 3,
    ],
    'layered_positions' => [
        'support_hierarchy_sizing' => [
            'enabled' => true,
            'max_position_pct' => 1.5,
            'multipliers' => ['D' => [10 => 0.75], 'W' => [20 => 1.4]],
        ],
    ],
];
$risk = ['max_position_pct' => 1.0];
$signal = static fn (string $timeframe, int $period): Signal => new Signal(
    'TQQQ',
    new DateTimeImmutable('2026-07-15'),
    'SUPPORT_REGULARITY',
    100.0,
    90.0,
    120.0,
    10.0,
    1.0,
    [],
    'long',
    ['timeframe' => $timeframe, 'ma_period' => $period],
);
$stable = new MarketRegime(new DateTimeImmutable('2026-07-15'), true, 3.0, [], 0.0, 55.0);
$unstable = new MarketRegime(new DateTimeImmutable('2026-07-15'), true, 2.0, ['risk'], 0.0, 40.0);

if (abs(PositionSizingPolicy::positionPct($strategy, $risk, $stable, $signal('D', 10)) - 0.75) > 1.0e-9) {
    throw new RuntimeException('Stable D10 hierarchy sizing mismatch.');
}
if (abs(PositionSizingPolicy::positionPct($strategy, $risk, $stable, $signal('W', 20)) - 1.4) > 1.0e-9) {
    throw new RuntimeException('Stable W20 hierarchy sizing mismatch.');
}
if (abs(PositionSizingPolicy::positionPct($strategy, $risk, $unstable, $signal('D', 10)) - 0.0375) > 1.0e-9) {
    throw new RuntimeException('Unstable hierarchy sizing mismatch.');
}

echo "Position sizing policy OK\n";
