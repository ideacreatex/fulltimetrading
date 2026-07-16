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
$beBar = new Bar('TEST', new DateTimeImmutable('2026-07-14'), 95.0, 103.0, 94.0, 99.0, 1000.0);
$beArgs = [&$bePosition, $beBar, ['ema10' => [null]], 0, 0.5];
$beTrade = $update->invokeArgs($backtester, $beArgs);
executionAssert($beTrade instanceof Trade, 'A close through a newly armed hard BE stop must not remain open overnight.');
executionAssert($beTrade->exitReason === 'break_even_stop', 'Same-session BE reversal must use the hard BE exit reason.');
executionAssert(abs($beTrade->exit - 100.0) < 1.0e-9, 'Same-session BE reversal must exit at the armed stop.');

$updateFillDay = new ReflectionMethod($backtester, 'updateNewlyFilledPosition');
$canFill = new ReflectionMethod($backtester, 'canFill');
$longGapBelow = new Bar('TEST', new DateTimeImmutable('2026-07-14'), 95.0, 97.0, 92.0, 94.0, 1000.0);
executionAssert(
    $canFill->invoke($backtester, $signal, $longGapBelow, [], 0) === true,
    'A long DAY limit must fill when a favorable opening gap is already below the limit.',
);
$shortGapSignal = new Signal(
    'TEST',
    new DateTimeImmutable('2026-07-13'),
    'RESISTANCE_REGULARITY_SHORT',
    100.0,
    110.0,
    70.0,
    10.0,
    1.0,
    ['test'],
    'short',
);
$shortGapAbove = new Bar('TEST', new DateTimeImmutable('2026-07-14'), 105.0, 108.0, 103.0, 107.0, 1000.0);
executionAssert(
    $canFill->invoke($backtester, $shortGapSignal, $shortGapAbove, [], 0) === true,
    'A short DAY limit must fill when a favorable opening gap is already above the limit.',
);
$marketableFillPosition = array_merge($bePosition, [
    'remaining_shares' => 10.0,
    'stop' => 90.0,
    'hard_stop_active' => false,
    'break_even_armed' => false,
    'took_partial' => false,
    'realized_pnl' => 0.0,
    'events' => [],
]);
$marketableFillBar = new Bar('TEST', new DateTimeImmutable('2026-07-14'), 100.0, 103.0, 99.0, 101.0, 1000.0);
$marketableArgs = [&$marketableFillPosition, $marketableFillBar, ['ema10' => [null]], 0, 0.5];
$marketableTrade = $updateFillDay->invokeArgs($backtester, $marketableArgs);
executionAssert(
    $marketableTrade instanceof Trade && $marketableTrade->exitReason === 'break_even_stop',
    'A next-session limit marketable at the open must process conservative fill-day risk rules.',
);

$intraminuteFillPosition = array_merge($bePosition, [
    'remaining_shares' => 10.0,
    'stop' => 90.0,
    'hard_stop_active' => false,
    'break_even_armed' => false,
    'took_partial' => false,
    'realized_pnl' => 0.0,
    'events' => [],
]);
$intraminuteFillBar = new Bar('TEST', new DateTimeImmutable('2026-07-14'), 105.0, 135.0, 99.0, 110.0, 1000.0);
$intraminuteArgs = [&$intraminuteFillPosition, $intraminuteFillBar, ['ema10' => [null]], 0, 0.5];
$intraminuteTrade = $updateFillDay->invokeArgs($backtester, $intraminuteArgs);
executionAssert(
    $intraminuteTrade === null
        && $intraminuteFillPosition['break_even_armed'] === false
        && $intraminuteFillPosition['took_partial'] === false,
    'A high printed before an intraminute limit fill must not arm BE or take profit retroactively.',
);

$shortSignal = new Signal(
    'TEST',
    new DateTimeImmutable('2026-07-13'),
    'RESISTANCE_REGULARITY',
    100.0,
    110.0,
    70.0,
    10.0,
    1.0,
    ['test'],
    'short',
);
$shortPosition = array_merge($bePosition, [
    'signal' => $shortSignal,
    'remaining_shares' => 10.0,
    'stop' => 110.0,
    'initial_stop' => 110.0,
    'hard_stop_active' => false,
    'break_even_armed' => false,
    'took_partial' => false,
    'realized_pnl' => 0.0,
    'events' => [],
]);
$shortMarketableBar = new Bar('TEST', new DateTimeImmutable('2026-07-14'), 105.0, 106.0, 97.0, 99.0, 1000.0);
$shortMarketableArgs = [&$shortPosition, $shortMarketableBar, ['ema10' => [null]], 0, 0.5];
$shortMarketableTrade = $updateFillDay->invokeArgs($backtester, $shortMarketableArgs);
executionAssert(
    $shortMarketableTrade instanceof Trade && $shortMarketableTrade->exitReason === 'break_even_stop',
    'A short sell limit marketable at the open must process fill-day risk rules.',
);

$shortIntraminutePosition = array_merge($shortPosition, [
    'remaining_shares' => 10.0,
    'stop' => 110.0,
    'hard_stop_active' => false,
    'break_even_armed' => false,
    'took_partial' => false,
    'realized_pnl' => 0.0,
    'events' => [],
]);
$shortIntraminuteBar = new Bar('TEST', new DateTimeImmutable('2026-07-14'), 95.0, 101.0, 69.0, 90.0, 1000.0);
$shortIntraminuteArgs = [&$shortIntraminutePosition, $shortIntraminuteBar, ['ema10' => [null]], 0, 0.5];
$shortIntraminuteTrade = $updateFillDay->invokeArgs($backtester, $shortIntraminuteArgs);
executionAssert(
    $shortIntraminuteTrade === null
        && $shortIntraminutePosition['break_even_armed'] === false
        && $shortIntraminutePosition['took_partial'] === false,
    'A low printed before a nonmarketable short limit fill must not trigger BE or target retroactively.',
);

$closeTriggerStrategy = $strategy;
$closeTriggerStrategy['club_rules']['break_even_trigger_mode'] = 'close';
$closeTriggerBacktester = new PoosBacktester(
    $indicators,
    new MarketRegimeAnalyzer($indicators, []),
    new PoosScanner($indicators, $closeTriggerStrategy),
    $closeTriggerStrategy,
    [],
);
$closeTriggerUpdate = new ReflectionMethod($closeTriggerBacktester, 'updatePosition');
$closeTriggerPosition = array_merge($bePosition, [
    'remaining_shares' => 10.0,
    'stop' => 90.0,
    'hard_stop_active' => false,
    'break_even_armed' => false,
    'took_partial' => false,
    'realized_pnl' => 0.0,
    'events' => [],
]);
$closeTriggerBar = new Bar('TEST', new DateTimeImmutable('2026-07-14'), 95.0, 104.0, 94.0, 103.0, 1000.0);
$closeTriggerArgs = [&$closeTriggerPosition, $closeTriggerBar, ['ema10' => [null]], 0, 0.5];
$closeTriggerTrade = $closeTriggerUpdate->invokeArgs($closeTriggerBacktester, $closeTriggerArgs);
executionAssert(
    $closeTriggerTrade === null && $closeTriggerPosition['break_even_armed'] === true,
    'A close-triggered BE stop must not use an earlier intraday low as a retroactive same-bar exit.',
);

$closeStopStrategy = $strategy;
$closeStopStrategy['club_rules']['break_even_stop_mode'] = 'close';
$closeStopBacktester = new PoosBacktester(
    $indicators,
    new MarketRegimeAnalyzer($indicators, []),
    new PoosScanner($indicators, $closeStopStrategy),
    $closeStopStrategy,
    [],
);
$closeStopUpdate = new ReflectionMethod($closeStopBacktester, 'updatePosition');
$closeStopPosition = array_merge($bePosition, [
    'remaining_shares' => 10.0,
    'stop' => 90.0,
    'hard_stop_active' => false,
    'break_even_armed' => false,
    'took_partial' => false,
    'realized_pnl' => 0.0,
    'events' => [],
]);
$closeStopBar = new Bar('TEST', new DateTimeImmutable('2026-07-14'), 99.0, 103.0, 98.0, 99.0, 1000.0);
$closeStopArgs = [&$closeStopPosition, $closeStopBar, ['ema10' => [null]], 0, 0.5];
$closeStopTrade = $closeStopUpdate->invokeArgs($closeStopBacktester, $closeStopArgs);
executionAssert(
    $closeStopTrade === null
        && $closeStopPosition['break_even_armed'] === true
        && $closeStopPosition['mental_exit_pending'] === true
        && $closeStopPosition['mental_exit_trigger_type'] === 'break_even',
    'A newly armed close-mode BE stop violated at close must queue the next-open exit immediately.',
);

$openPending = new ReflectionMethod($backtester, 'openPendingPosition');
$plannedPositions = [];
$plannedPending = [
    'key' => 'TEST:planned',
    'signal' => $signal,
    'age' => 1,
    'planned_shares' => 7.0,
    'planned_at' => '2026-07-13',
    'events' => ['2026-07-13: planned'],
];
$futureFillBar = new Bar('TEST', new DateTimeImmutable('2026-07-14'), 150.0, 160.0, 99.0, 155.0, 1000.0);
$openArgs = [&$plannedPositions, $plannedPending, $futureFillBar];
$opened = $openPending->invokeArgs($backtester, $openArgs);
executionAssert($opened === true, 'A valid pending order must fill with its pre-planned quantity.');
executionAssert(
    abs((float) ($plannedPositions['TEST:planned']['shares'] ?? 0.0) - 7.0) < 1.0e-9,
    'Fill-session prices or regime must not resize a quantity fixed on the signal session.',
);

$reservationsMethod = new ReflectionMethod($backtester, 'positionsWithPendingReservations');
$reservations = $reservationsMethod->invoke($backtester, [], ['TEST:planned' => $plannedPending]);
$reservation = $reservations['pending:TEST:planned'] ?? [];
executionAssert(
    abs((float) ($reservation['remaining_shares'] ?? 0.0) - 7.0) < 1.0e-9
        && abs((float) ($reservation['reservation_price'] ?? 0.0) - 100.0) < 1.0e-9,
    'Pending limits must reserve their fixed order quantity at the limit price.',
);

$costBacktester = new PoosBacktester(
    $indicators,
    new MarketRegimeAnalyzer($indicators, []),
    new PoosScanner($indicators, $strategy),
    $strategy,
    ['transaction_cost_bps' => 10.0],
);
$costOpen = new ReflectionMethod($costBacktester, 'openPendingPosition');
$costPositions = [];
$costOpenArgs = [&$costPositions, $plannedPending, $futureFillBar];
$costOpen->invokeArgs($costBacktester, $costOpenArgs);
$costPosition = $costPositions['TEST:planned'];
executionAssert(
    abs((float) $costPosition['realized_pnl'] - (-0.7)) < 1.0e-9,
    'Modeled one-way entry costs must be charged when the limit fills.',
);
$tradeFromPosition = new ReflectionMethod($costBacktester, 'tradeFromPosition');
$costExitBar = new Bar('TEST', new DateTimeImmutable('2026-07-15'), 110.0, 111.0, 109.0, 110.0, 1000.0);
$costTrade = $tradeFromPosition->invoke(
    $costBacktester,
    $costPosition,
    $costExitBar,
    110.0,
    69.3,
    'test_exit',
    [],
);
executionAssert(
    abs($costTrade->pnl - 68.53) < 1.0e-9,
    'Modeled one-way exit costs must be charged on the remaining shares.',
);

$partialCostPosition = [
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
    'realized_pnl' => -1.0,
    'events' => [],
];
$partialBar = new Bar('TEST', new DateTimeImmutable('2026-07-14'), 110.0, 131.0, 105.0, 120.0, 1000.0);
$partialArgs = [&$partialCostPosition, $partialBar, ['ema10' => [null]], 0, 0.5];
$partialTrade = $update->invokeArgs($costBacktester, $partialArgs);
executionAssert($partialTrade === null && $partialCostPosition['took_partial'] === true, 'Partial target must remain open for its runner.');
$partialFinal = $tradeFromPosition->invoke(
    $costBacktester,
    $partialCostPosition,
    new Bar('TEST', new DateTimeImmutable('2026-07-15'), 120.0, 121.0, 119.0, 120.0, 1000.0),
    120.0,
    (float) $partialCostPosition['realized_pnl'] + 100.0,
    'test_exit',
    [],
);
executionAssert(
    abs($partialFinal->pnl - 247.75) < 1.0e-9,
    'Entry, partial and runner exit costs must each be charged exactly once.',
);

$advanceStrategy = $strategy;
$advanceStrategy['support_regularity']['entry_signal_mode'] = 'advance_next_session';
$advanceStrategy['order_fill_mode'] = 'same_day_touch';
$advanceGuarded = false;
try {
    new PoosBacktester(
        $indicators,
        new MarketRegimeAnalyzer($indicators, []),
        new PoosScanner($indicators, $advanceStrategy),
        $advanceStrategy,
        [],
    );
} catch (InvalidArgumentException) {
    $advanceGuarded = true;
}
executionAssert($advanceGuarded, 'advance_next_session must reject same-day order fills.');

$priorityMethod = new ReflectionMethod($backtester, 'prioritizedSignalsForDate');
$lower = new Signal(
    'AAA',
    new DateTimeImmutable('2026-07-13'),
    'SUPPORT_REGULARITY',
    100.0,
    90.0,
    130.0,
    10.0,
    1.0,
    ['lower'],
    'long',
    ['setup_key' => 'z-lower'],
);
$higherTieB = new Signal(
    'BBB',
    new DateTimeImmutable('2026-07-13'),
    'SUPPORT_REGULARITY',
    100.0,
    90.0,
    130.0,
    10.0,
    2.0,
    ['higher-b'],
    'long',
    ['setup_key' => 'b-higher'],
);
$higherTieA = new Signal(
    'CCC',
    new DateTimeImmutable('2026-07-13'),
    'SUPPORT_REGULARITY',
    100.0,
    90.0,
    130.0,
    10.0,
    2.0,
    ['higher-a'],
    'long',
    ['setup_key' => 'a-higher'],
);
$priorityOne = $priorityMethod->invoke($backtester, [
    'AAA' => [$lower],
    'BBB' => [$higherTieB],
    'CCC' => [$higherTieA],
]);
$priorityTwo = $priorityMethod->invoke($backtester, [
    'CCC' => [$higherTieA],
    'AAA' => [$lower],
    'BBB' => [$higherTieB],
]);
$priorityKeys = static fn (array $signals): array => array_map(
    static fn (Signal $candidate): string => (string) $candidate->metadata['setup_key'],
    $signals,
);
executionAssert(
    $priorityKeys($priorityOne) === ['a-higher', 'b-higher', 'z-lower'],
    'Signals from all symbols must compete globally by score with a stable setup-key tie break.',
);
executionAssert(
    $priorityKeys($priorityOne) === $priorityKeys($priorityTwo),
    'Signal priority must not depend on the input symbol order.',
);

echo "Backtester execution semantics OK\n";
