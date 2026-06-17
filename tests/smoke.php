<?php

declare(strict_types=1);

use FulltimeTrading\Backtest\PoosBacktester;
use FulltimeTrading\Domain\Bar;
use FulltimeTrading\Indicators\IndicatorCalculator;
use FulltimeTrading\Strategy\MarketRegimeAnalyzer;
use FulltimeTrading\Strategy\PoosScanner;

require __DIR__ . '/../bootstrap.php';

/** @return list<Bar> */
function synthetic_series(string $symbol, float $startPrice, int $days, bool $rocket = false): array
{
    $bars = [];
    $date = new DateTimeImmutable('2022-01-03');
    $price = $startPrice;
    for ($i = 0; $i < $days; $i++) {
        while ((int) $date->format('N') > 5) {
            $date = $date->modify('+1 day');
        }

        if ($rocket && $i > 210 && $i < 232) {
            $price *= 1.025;
            $volume = 4000000 + $i * 1000;
        } elseif ($rocket && $i >= 232 && $i < 240) {
            $price *= 0.975;
            $volume = 2500000;
        } elseif ($rocket && $i >= 240 && $i < 258) {
            $price *= 1.018;
            $volume = 2000000;
        } else {
            $price *= 1.0015;
            $volume = 1000000;
        }

        $open = $price * 0.995;
        $close = $price;
        $high = max($open, $close) * 1.01;
        $low = min($open, $close) * 0.99;
        $bars[] = new Bar($symbol, $date, $open, $high, $low, $close, $volume);
        $date = $date->modify('+1 day');
    }

    return $bars;
}

$strategy = [
    'ema_periods' => [10, 20, 21, 50, 100, 200],
    'atr_period' => 14,
    'volume_avg_period' => 50,
    'rocket_lookback' => 63,
    'rocket_min_gain_pct' => 0.15,
    'volume_spike_multiple' => 1.2,
    'first_pullback_lookback' => 8,
    'ema_touch_tolerance_pct' => 0.01,
    'max_distance_to_ema_pct' => 0.30,
    'order_valid_bars' => 10,
    'target_r_multiple' => 1.5,
    'partial_take_profit_pct' => 0.5,
    'market' => [
        'require_spy_above_ema20' => true,
        'atr_break_buffer' => 0.20,
    ],
];
$risk = [
    'initial_cash' => 100000.0,
    'risk_per_trade_pct' => 0.005,
    'max_position_pct' => 0.10,
];

$indicators = new IndicatorCalculator();
$marketAnalyzer = new MarketRegimeAnalyzer($indicators, $strategy['market']);
$scanner = new PoosScanner($indicators, $strategy);
$backtester = new PoosBacktester($indicators, $marketAnalyzer, $scanner, $strategy, $risk);

$result = $backtester->run(
    ['TEST' => synthetic_series('TEST', 20, 280, true)],
    [
        'SPY' => synthetic_series('SPY', 400, 280),
        'QQQ' => synthetic_series('QQQ', 350, 280),
        'SMH' => synthetic_series('SMH', 200, 280),
    ],
);

echo json_encode($result->summary(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
echo "Smoke OK\n";
