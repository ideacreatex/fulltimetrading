<?php

declare(strict_types=1);

use FulltimeTrading\Backtest\PoosBacktester;
use FulltimeTrading\Domain\Bar;
use FulltimeTrading\Domain\Signal;
use FulltimeTrading\Domain\Trade;
use FulltimeTrading\Indicators\IndicatorCalculator;
use FulltimeTrading\Strategy\MarketRegimeAnalyzer;
use FulltimeTrading\Strategy\PoosScanner;

require __DIR__ . '/../bootstrap.php';

function executionAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$strategy = [
    'club_rules' => [
        'enabled' => true,
        'default_swing_stop_mode' => 'mental',
        'mental_stop_exit_on_close' => true,
        'break_even_profit_pct' => 0.02,
        'break_even_trigger_mode' => 'high',
        'break_even_stop_mode' => 'hard',
        'break_even_stop_offset_pct' => 0.0,
    ],
];
$indicators = new IndicatorCalculator();
$backtester = new PoosBacktester(
    $indicators,
    new MarketRegimeAnalyzer($indicators, []),
    new PoosScanner($indicators, $strategy),
    $strategy,
    [],
);
$update = new ReflectionMethod($backtester, 'updatePosition');
$markFillDay = new ReflectionMethod($backtester, 'markNewlyFilledMentalExit');

$signal = new Signal(
    'TEST',
    new DateTimeImmutable('2026-07-13'),
    'SUPPORT_REGULARITY',
    100.0,
    90.0,
    130.0,
    10.0,
    1.0,
    ['test'],
);
$position = [
    'symbol' => 'TEST',
    'signal' => $signal,
    'entry_time' => new DateTimeImmutable('2026-07-14'),
    'shares' => 10.0,
    'remaining_shares' => 10.0,
    'stop' => 90.0,
    'initial_stop' => 90.0,
    'hard_stop_active' => false,
    'break_even_armed' => false,
    'took_partial' => false,
    'realized_pnl' => 0.0,
    'events' => [],
];
$fillDay = new Bar('TEST', new DateTimeImmutable('2026-07-14'), 101.0, 101.0, 84.0, 85.0, 1000.0);
$markArgs = [&$position, $fillDay];
$marked = $markFillDay->invokeArgs($backtester, $markArgs);
executionAssert($marked === true && !empty($position['mental_exit_pending']), 'A fill-day close below a mental stop must queue an exit.');

$nextDay = new Bar('TEST', new DateTimeImmutable('2026-07-15'), 80.0, 86.0, 79.0, 84.0, 1000.0);
$updateArgs = [&$position, $nextDay, ['ema10' => [null]], 0, 0.5];
$trade = $update->invokeArgs($backtester, $updateArgs);
executionAssert($trade instanceof Trade, 'A queued mental stop must close on the next available bar.');
executionAssert($trade->exitReason === 'mental_stop_next_open', 'Mental stop must expose next-open execution semantics.');
executionAssert(abs($trade->exit - 80.0) < 1.0e-9, 'Mental stop must include the next-open gap, not use the prior close.');
executionAssert(abs($trade->pnl - (-200.0)) < 1.0e-9, 'Mental stop P/L must be based on next-open execution.');

$bePosition = [
    'symbol' => 'TEST',
    'signal' => $signal,
    'entry_time' => new DateTimeImmutable('2026-07-10'),
    'shares' => 10.0,
    'remaining_shares' => 10.0,
    'stop' => 90.0,
    'initial_stop' => 90.0,
    'hard_stop_active' => false,
    'break_even_armed' => false,
    'took_partial' => false,
    'realized_pnl' => 0.0,
    'events' => [],
];
$beBar = new Bar('TEST', new DateTimeImmutable('2026-07-14'), 100.0, 103.0, 95.0, 99.0, 1000.0);
$beArgs = [&$bePosition, $beBar, ['ema10' => [null]], 0, 0.5];
$beTrade = $update->invokeArgs($backtester, $beArgs);
executionAssert($beTrade instanceof Trade, 'A close through a newly armed hard BE stop must not remain open overnight.');
executionAssert($beTrade->exitReason === 'break_even_stop', 'Same-session BE reversal must use the hard BE exit reason.');
executionAssert(abs($beTrade->exit - 100.0) < 1.0e-9, 'Same-session BE reversal must exit at the armed stop.');

echo "Backtester execution semantics OK\n";
