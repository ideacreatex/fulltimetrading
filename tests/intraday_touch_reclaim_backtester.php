<?php

declare(strict_types=1);

use FulltimeTrading\Backtest\IntradayEntryDecision;
use FulltimeTrading\Backtest\IntradayTouchReclaimConfirmer;
use FulltimeTrading\Backtest\PoosBacktester;
use FulltimeTrading\Domain\Bar;
use FulltimeTrading\Domain\Signal;
use FulltimeTrading\Indicators\IndicatorCalculator;
use FulltimeTrading\Strategy\MarketRegimeAnalyzer;
use FulltimeTrading\Strategy\PoosScanner;

require __DIR__ . '/../bootstrap.php';

function intradayBacktestAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$strategy = [
    'support_regularity' => ['entry_signal_mode' => 'advance_next_session'],
    'order_fill_mode' => 'intraday_touch_reclaim',
    'intraday_touch_reclaim' => ['target_mode' => 'rebase_distance'],
    'club_rules' => [
        'enabled' => true,
        'default_swing_stop_mode' => 'mental',
        'break_even_profit_pct' => 0.05,
    ],
];
$indicators = new IndicatorCalculator();
$missingConfirmerRejected = false;
try {
    new PoosBacktester(
        $indicators,
        new MarketRegimeAnalyzer($indicators, []),
        new PoosScanner($indicators, $strategy),
        $strategy,
        [],
    );
} catch (InvalidArgumentException) {
    $missingConfirmerRejected = true;
}
intradayBacktestAssert($missingConfirmerRejected, 'Intraday fill mode must require an explicit causal confirmer.');

$backtester = new PoosBacktester(
    $indicators,
    new MarketRegimeAnalyzer($indicators, []),
    new PoosScanner($indicators, $strategy),
    $strategy,
    ['allow_fractional_shares' => true, 'position_sizing_mode' => 'capital_pct'],
    new IntradayTouchReclaimConfirmer(),
);
$planned = new Signal(
    'TEST',
    new DateTimeImmutable('2026-07-13T20:00:00+00:00'),
    'SUPPORT_REGULARITY',
    100.0,
    95.0,
    115.0,
    5.0,
    5.0,
    ['planned'],
    'long',
    [
        'entry_signal_mode' => 'advance_next_session',
        'planned_entry_level' => 100.0,
        'support_atr' => 10.0,
        'setup_key' => 'TEST:causal',
    ],
);
$decision = new IntradayEntryDecision(
    'filled',
    '2026-07-14',
    new DateTimeImmutable('2026-07-14T13:30:00+00:00'),
    new DateTimeImmutable('2026-07-14T13:35:00+00:00'),
    new DateTimeImmutable('2026-07-14T13:40:00+00:00'),
    new DateTimeImmutable('2026-07-14T13:40:00+00:00'),
    101.9,
    102.0,
    0,
    true,
    'test fill',
);

$open = new ReflectionMethod($backtester, 'openPendingPosition');
$positions = [];
$pending = [
    'key' => 'TEST:causal',
    'signal' => $planned,
    'age' => 1,
    'planned_shares' => 10.0,
    'planned_at' => '2026-07-13',
    'events' => ['planned'],
];
$daily = new Bar('TEST', new DateTimeImmutable('2026-07-14T04:00:00+00:00'), 101.0, 105.0, 90.0, 103.0, 10000.0);
$openArgs = [&$positions, $pending, $daily, $decision];
intradayBacktestAssert($open->invokeArgs($backtester, $openArgs) === true, 'A resolved causal decision must open a position.');
$position = $positions['TEST:causal'];
/** @var Signal $executed */
$executed = $position['signal'];
intradayBacktestAssert(abs($executed->entry - 102.0) < 1.0e-9, 'P/L and BE must use the executable next-bar price.');
intradayBacktestAssert(abs($executed->target - 117.0) < 1.0e-9, 'Rebased target must preserve the planned target distance.');
intradayBacktestAssert(abs((float) $position['shares'] - 9.803921) < 1.0e-9, 'A higher execution price must not exceed planned notional.');
intradayBacktestAssert(
    $position['entry_time'] instanceof DateTimeImmutable
        && $position['entry_time']->format('H:i') === '13:40',
    'Entry time must be the causal next-bar timestamp.',
);

$postEntry = new ReflectionMethod($backtester, 'postEntrySessionBar');
$tail = [
    new Bar('TEST', new DateTimeImmutable('2026-07-14T13:35:00+00:00'), 99.0, 101.0, 90.0, 100.0, 100.0),
    new Bar('TEST', new DateTimeImmutable('2026-07-14T13:40:00+00:00'), 101.9, 104.0, 100.0, 103.0, 100.0),
    new Bar('TEST', new DateTimeImmutable('2026-07-14T20:00:00+00:00'), 50.0, 200.0, 1.0, 50.0, 100.0),
];
$synthetic = $postEntry->invoke($backtester, $decision, $tail, $daily);
intradayBacktestAssert(abs($synthetic->open - 102.0) < 1.0e-9, 'Post-entry path must start at the slipped execution price.');
intradayBacktestAssert(abs($synthetic->low - 100.0) < 1.0e-9, 'Pre-entry lows must never trigger an entry-day stop.');
intradayBacktestAssert(abs($synthetic->high - 104.0) < 1.0e-9, 'After-hours prices must not leak into regular-session exits.');
intradayBacktestAssert(abs($synthetic->close - 103.0) < 1.0e-9, 'Fill-day close must use the last post-entry regular bar.');

$chronological = new ReflectionMethod($backtester, 'updateIntradayConfirmedPosition');
$chronologicalPosition = $position;
$chronologicalPosition['remaining_shares'] = $chronologicalPosition['shares'];
$chronologicalPosition['stop'] = 95.0;
$chronologicalPosition['initial_stop'] = 95.0;
$chronologicalPosition['hard_stop_active'] = false;
$chronologicalPosition['break_even_armed'] = false;
$chronologicalPosition['took_partial'] = false;
$chronologicalPosition['realized_pnl'] = 0.0;
$chronologicalPosition['events'] = [];
$chronologicalBars = [
    new Bar('TEST', new DateTimeImmutable('2026-07-14T13:40:00+00:00'), 101.9, 103.0, 100.0, 102.0, 100.0),
    new Bar('TEST', new DateTimeImmutable('2026-07-14T13:45:00+00:00'), 106.0, 108.0, 106.0, 107.0, 100.0),
    new Bar('TEST', new DateTimeImmutable('2026-07-14T13:50:00+00:00'), 107.0, 109.0, 105.0, 108.0, 100.0),
];
$chronologicalArgs = [
    &$chronologicalPosition,
    $decision,
    $chronologicalBars,
    $daily,
    ['ema10' => [null, null]],
    1,
    0.5,
];
$chronologicalTrade = $chronological->invokeArgs($backtester, $chronologicalArgs);
intradayBacktestAssert(
    $chronologicalTrade === null && $chronologicalPosition['break_even_armed'] === true,
    'A low printed before a later 5m BE trigger must not become a retroactive fill-day stop.',
);

$sparseClosePosition = $position;
$sparseClosePosition['remaining_shares'] = $sparseClosePosition['shares'];
$sparseClosePosition['stop'] = 95.0;
$sparseClosePosition['initial_stop'] = 95.0;
$sparseClosePosition['hard_stop_active'] = false;
$sparseClosePosition['break_even_armed'] = false;
$sparseClosePosition['took_partial'] = false;
$sparseClosePosition['realized_pnl'] = 0.0;
$sparseClosePosition['events'] = [];
$sparseOnlyBars = [
    new Bar('TEST', new DateTimeImmutable('2026-07-14T18:20:00+00:00'), 104.0, 106.0, 103.0, 105.0, 100.0),
];
$officialDownClose = new Bar(
    'TEST',
    new DateTimeImmutable('2026-07-14T04:00:00+00:00'),
    101.0,
    106.0,
    93.0,
    94.0,
    10000.0,
);
$sparseCloseArgs = [
    &$sparseClosePosition,
    $decision,
    $sparseOnlyBars,
    $officialDownClose,
    ['ema10' => [null, null]],
    1,
    0.5,
];
$sparseCloseTrade = $chronological->invokeArgs($backtester, $sparseCloseArgs);
intradayBacktestAssert(
    $sparseCloseTrade === null && ($sparseClosePosition['mental_exit_pending'] ?? false) === true,
    'Close rules must use the official daily close, not the final sparse IEX print.',
);

$emaPosition = $position;
$emaPosition['remaining_shares'] = $emaPosition['shares'] / 2.0;
$emaPosition['stop'] = 95.0;
$emaPosition['initial_stop'] = 95.0;
$emaPosition['hard_stop_active'] = true;
$emaPosition['break_even_armed'] = true;
$emaPosition['took_partial'] = true;
$emaPosition['realized_pnl'] = 0.0;
$emaPosition['events'] = [];
$officialBelowEma = new Bar(
    'TEST',
    new DateTimeImmutable('2026-07-14T04:00:00+00:00'),
    101.0,
    106.0,
    100.0,
    103.0,
    10000.0,
);
$emaArgs = [
    &$emaPosition,
    $decision,
    $sparseOnlyBars,
    $officialBelowEma,
    ['ema10' => [null, 104.0]],
    1,
    0.5,
];
$chronological->invokeArgs($backtester, $emaArgs);
intradayBacktestAssert(
    abs((float) $emaPosition['stop'] - 95.0) < 1.0e-9,
    'An intraday print above EMA10 cannot activate a daily-close EMA trail.',
);

$grossUpperBound = new ReflectionMethod($backtester, 'intradayGrossExposureUpperBound');
$sameDayPosition = $position;
$sameDayPosition['remaining_shares'] = 10.0;
$sameDayPosition['shares'] = 10.0;
$sameDayPosition['realized_pnl'] = 0.0;
$sameDayPosition['entry_time'] = new DateTimeImmutable('2026-07-14T13:40:00+00:00');
$exposureBars = [];
$exposureSessionStart = new DateTimeImmutable('2026-07-14 09:30:00', new DateTimeZone('America/New_York'));
for ($i = 0; $i < 78; $i++) {
    $time = $exposureSessionStart->modify('+' . ($i * 5) . ' minutes')->setTimezone(new DateTimeZone('UTC'));
    $exposureBars[] = $i === 2
        ? new Bar('TEST', $time, 100.0, 120.0, 80.0, 105.0, 100.0)
        : new Bar('TEST', $time, 100.0, 106.0, 95.0, 101.0, 100.0);
}
$bound = $grossUpperBound->invoke(
    $backtester,
    1000.0,
    [],
    ['TEST:causal' => $sameDayPosition],
    [],
    ['TEST' => ['2026-07-14' => $exposureBars]],
    '2026-07-14',
);
intradayBacktestAssert(
    abs((float) ($bound['gross'] ?? 0.0) - (1200.0 / 780.0)) < 1.0e-9,
    'A complete regular-session path must retain a same-day round trip and combine high notional with adverse equity.',
);

$sparseOpeningBars = [
    new Bar('TEST', new DateTimeImmutable('2026-07-14T13:30:00+00:00'), 100.0, 120.0, 80.0, 105.0, 100.0),
];
$priorMark = new Bar(
    'TEST',
    new DateTimeImmutable('2026-07-13T20:00:00+00:00'),
    100.0,
    100.0,
    100.0,
    100.0,
    100.0,
);
$sparseOpeningBound = $grossUpperBound->invoke(
    $backtester,
    1000.0,
    ['TEST:causal' => $sameDayPosition],
    [],
    ['TEST' => $priorMark],
    ['TEST' => ['2026-07-14' => $sparseOpeningBars]],
    '2026-07-14',
);
intradayBacktestAssert(
    $sparseOpeningBound['gross'] === null
        && in_array('incomplete_regular_path:TEST:missing_close_bar', $sparseOpeningBound['failures'], true)
        && in_array('incomplete_regular_path:TEST:insufficient_bars:1<78_of_78', $sparseOpeningBound['failures'], true),
    'An opening position backed by one intraday bar must fail the exposure bound closed.',
);
$completeOpeningBound = $grossUpperBound->invoke(
    $backtester,
    1000.0,
    ['TEST:causal' => $sameDayPosition],
    [],
    ['TEST' => $priorMark],
    ['TEST' => ['2026-07-14' => $exposureBars]],
    '2026-07-14',
);
intradayBacktestAssert(
    abs((float) ($completeOpeningBound['gross'] ?? 0.0) - 1.5) < 1.0e-9
        && $completeOpeningBound['failures'] === [],
    'The same opening position must produce a finite conservative bound when all 78 bars are present.',
);
$missingBound = $grossUpperBound->invoke(
    $backtester,
    1000.0,
    [],
    ['TEST:causal' => $sameDayPosition],
    [],
    [],
    '2026-07-14',
);
intradayBacktestAssert(
    $missingBound['gross'] === null && in_array('missing_regular_path:TEST', $missingBound['failures'], true),
    'A missing intraday exposure path must fail closed instead of understating leverage.',
);

$recordQualityFailure = new ReflectionMethod($backtester, 'recordEntryDataQualityFailure');
$quality = [
    'data_quality_passes' => true,
    'data_quality_failures' => [],
    'data_quality_events' => [],
    'missing_candidate_sessions' => 0,
    'missing_candidate_session_examples' => [],
    'incomplete_candidate_sessions' => 0,
    'incomplete_candidate_session_examples' => [],
];
$qualityArgs = [&$quality, 'incomplete_candidate_intraday_session:missing_next_bar', 'TEST 2026-07-14'];
$recordQualityFailure->invokeArgs($backtester, $qualityArgs);
intradayBacktestAssert(
    $quality['data_quality_passes'] === false
        && $quality['incomplete_candidate_sessions'] === 1
        && $quality['data_quality_failures'] === ['incomplete_candidate_intraday_session:missing_next_bar']
        && ($quality['data_quality_events'][0]['date'] ?? null) === '2026-07-14',
    'An incomplete candidate path must become an explicit experiment-level data-quality failure.',
);

$exposureQuality = [
    'data_quality_passes' => true,
    'data_quality_failures' => [],
    'data_quality_events' => [],
    'missing_candidate_sessions' => 0,
    'missing_candidate_session_examples' => [],
    'incomplete_candidate_sessions' => 0,
    'incomplete_candidate_session_examples' => [],
];
$exposureFailure = 'intraday_exposure_upper_bound:' . $sparseOpeningBound['failures'][0];
$exposureQualityArgs = [&$exposureQuality, $exposureFailure, 'TEST 2026-07-14', '2026-07-14'];
$recordQualityFailure->invokeArgs($backtester, $exposureQualityArgs);
intradayBacktestAssert(
    $exposureQuality['data_quality_passes'] === false
        && $exposureQuality['data_quality_failures'] === [$exposureFailure]
        && ($exposureQuality['data_quality_events'][0]['date'] ?? null) === '2026-07-14',
    'An incomplete held-position path must propagate from a null gross bound into experiment DQ.',
);

echo "Intraday touch/reclaim backtester integration OK\n";
