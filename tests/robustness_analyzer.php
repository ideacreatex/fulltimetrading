<?php

declare(strict_types=1);

use FulltimeTrading\Backtest\RobustnessAnalyzer;
use FulltimeTrading\Domain\Trade;

require __DIR__ . '/../bootstrap.php';

function testTrade(string $symbol, string $exitDate, float $pnl): Trade
{
    return new Trade(
        $symbol,
        'test',
        new DateTimeImmutable($exitDate . ' -5 days'),
        new DateTimeImmutable($exitDate),
        100.0,
        100.0 + $pnl,
        1.0,
        $pnl,
        $pnl / 10.0,
        'test',
        [],
    );
}

function assertNear(float $expected, float $actual, string $message, float $epsilon = 1.0e-9): void
{
    if (abs($expected - $actual) > $epsilon) {
        throw new RuntimeException(sprintf('%s: expected %.12f, got %.12f', $message, $expected, $actual));
    }
}

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%s: expected %s, got %s',
            $message,
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}

$trades = [
    testTrade('AAA', '2023-02-01', 100.0),
    testTrade('AAA', '2023-08-01', -60.0),
    testTrade('BBB', '2024-02-01', 50.0),
    testTrade('BBB', '2024-04-01', 40.0),
    testTrade('CCC', '2024-06-01', 30.0),
    testTrade('CCC', '2024-08-01', -20.0),
    testTrade('DDD', '2025-01-01', 10.0),
    testTrade('EEE', '2025-02-01', 5.0),
];
$curve = [
    ['date' => '2024-07-01', 'equity' => 180.0],
    ['date' => '2023-01-01', 'equity' => 100.0],
    ['date' => '2023-07-01', 'equity' => 120.0],
    ['date' => '2023-12-31', 'equity' => 90.0],
    ['date' => '2024-01-02', 'equity' => 81.0],
    ['date' => '2025-01-01', 'equity' => 135.0],
];

$metrics = (new RobustnessAnalyzer())->analyze($trades, $curve);
assertSameValue(8, $metrics['closed_trades'], 'closed trade count');
assertNear(155.0, $metrics['closed_pnl'], 'closed PnL');
assertNear(235.0, $metrics['gross_profit'], 'gross profit');
assertNear(100.0 / 235.0, $metrics['best_trade_gross_profit_share_pct'], 'best trade share');
assertNear(230.0 / 235.0, $metrics['top_5_trades_gross_profit_share_pct'], 'top-five share');
assertNear(55.0, $metrics['pnl_without_best_trade'], 'PnL without best trade');
assertNear(-75.0, $metrics['pnl_without_top_5_trades'], 'PnL without top five trades');
assertSameValue('AAA', $metrics['top_symbol'], 'top gross-profit symbol');
assertNear(100.0 / 235.0, $metrics['top_symbol_gross_profit_share_pct'], 'top symbol share');
assertSameValue(2, $metrics['pre_split_closed_trades'], 'pre-split trade count');
assertSameValue(6, $metrics['post_split_closed_trades'], 'post-split trade count');
assertNear(1.0, $metrics['pre_split_trade_metrics']['best_trade_gross_profit_share_pct'], 'pre-split best trade share');
assertNear(50.0 / 135.0, $metrics['post_split_trade_metrics']['best_trade_gross_profit_share_pct'], 'post-split best trade share');
assertNear(-0.10, $metrics['pre_split']['return_pct'], 'pre-split return');
assertNear(-0.25, $metrics['pre_split']['max_drawdown_pct'], 'pre-split drawdown');
assertNear(0.50, $metrics['post_split']['return_pct'], 'post-split return');
assertNear(-0.25, $metrics['post_split']['max_drawdown_pct'], 'post-split drawdown');
assertSameValue('2023-12-31', $metrics['post_split']['anchor_date'], 'post-split boundary anchor');
if ((float) $metrics['post_split']['annualized_return_pct'] <= 0.0) {
    throw new RuntimeException('Post-split annualized return must be positive.');
}

$policy = [
    'min_trades' => 5,
    'min_post_split_trades' => 5,
    'max_best_trade_gross_profit_share_pct' => 0.50,
    'max_top_5_trades_gross_profit_share_pct' => 1.00,
    'max_top_symbol_gross_profit_share_pct' => 0.65,
    'max_post_split_drawdown_pct' => 0.35,
];
$validation = (new RobustnessAnalyzer())->validate($metrics, $policy);
assertSameValue(false, $validation['passes'], 'full validation catches concentration');
$holdoutValidation = (new RobustnessAnalyzer())->validateHoldout($metrics, $policy);
assertSameValue(false, $holdoutValidation['passes'], 'holdout validation catches top-five dependency');
assertSameValue(true, in_array('non_positive_post_pnl_without_top_5_trades', $holdoutValidation['failures'], true), 'holdout top-five failure');

$boundary = (new RobustnessAnalyzer())->analyze([
    testTrade('PRE', '2023-12-31', 1.0),
    testTrade('POST', '2024-01-01', 2.0),
], []);
assertSameValue(1, $boundary['pre_split_closed_trades'], 'trade before boundary is train');
assertSameValue(1, $boundary['post_split_closed_trades'], 'trade on boundary is holdout');
assertSameValue('PRE', $boundary['pre_split_trade_metrics']['top_symbol'], 'post trade cannot alter train concentration');

$lossOnly = (new RobustnessAnalyzer())->analyze(
    [testTrade('LOSS', '2024-03-01', -25.0)],
    [],
);
assertNear(0.0, $lossOnly['gross_profit'], 'loss-only gross profit');
assertNear(0.0, $lossOnly['best_trade_gross_profit_share_pct'], 'loss-only best share');
assertNear(-25.0, $lossOnly['pnl_without_best_trade'], 'loss-only PnL without best');
assertSameValue(0, $lossOnly['pre_split']['points'], 'empty pre-split curve');
assertSameValue(null, $lossOnly['pre_split']['start_date'], 'empty pre-split start');

echo "Robustness analyzer OK\n";
