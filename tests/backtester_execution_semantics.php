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
