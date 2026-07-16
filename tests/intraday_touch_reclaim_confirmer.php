<?php

declare(strict_types=1);

use FulltimeTrading\Backtest\IntradayTouchReclaimConfirmer;
use FulltimeTrading\Domain\Bar;
use FulltimeTrading\Domain\Signal;

require __DIR__ . '/../bootstrap.php';

function reclaimAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function reclaimBar(string $utc, float $open, float $high, float $low, float $close): Bar
{
    return new Bar('TEST', new DateTimeImmutable($utc), $open, $high, $low, $close, 1000.0);
}

$signal = new Signal(
    'TEST',
    new DateTimeImmutable('2026-07-13T20:00:00+00:00'),
    'SUPPORT_REGULARITY',
    100.0,
    95.0,
    115.0,
    5.0,
    5.0,
    ['frozen after close'],
    'long',
    [
        'entry_signal_mode' => 'advance_next_session',
        'planned_entry_level' => 100.0,
        'support_atr' => 10.0,
    ],
);
$confirmer = new IntradayTouchReclaimConfirmer(
    barMinutes: 5,
    touchTolerancePct: 0.0,
    reclaimBufferPct: 0.0,
    maxBarsAfterTouch: 3,
    maxFillDelayMinutes: 5,
    maxChaseAtr: 0.25,
    slippageBps: 10.0,
    requireCompleteSession: false,
);

$bars = [
    // Previous-session touch must not activate the next-session candidate.
    reclaimBar('2026-07-13T19:50:00+00:00', 101.0, 102.0, 99.0, 101.0),
    reclaimBar('2026-07-14T13:30:00+00:00', 101.0, 101.5, 99.0, 100.5),
    reclaimBar('2026-07-14T13:35:00+00:00', 100.8, 101.2, 100.4, 101.0),
];
$filled = $confirmer->resolve($signal, '2026-07-14', array_reverse($bars));
reclaimAssert($filled->isFilled(), 'A same-bar touch/reclaim must become executable.');
reclaimAssert($filled->reclaimBarStart?->format('H:i') === '13:30', 'The completed reclaim bar must be recorded.');
reclaimAssert($filled->fillAt?->format('H:i') === '13:35', 'Execution must wait for the next bar open.');
reclaimAssert(abs((float) $filled->rawFillPrice - 100.8) < 1.0e-9, 'Raw fill must use the next bar open.');
reclaimAssert(abs((float) $filled->fillPrice - 100.9008) < 1.0e-9, 'Configured entry slippage must be applied once.');

$noTouch = $confirmer->resolve($signal, '2026-07-14', [
    reclaimBar('2026-07-14T13:30:00+00:00', 102.0, 103.0, 101.0, 102.0),
]);
reclaimAssert($noTouch->status === 'no_touch', 'A reclaim-looking close before any touch cannot fill.');

$noReclaim = $confirmer->resolve($signal, '2026-07-14', [
    reclaimBar('2026-07-14T13:30:00+00:00', 101.0, 101.0, 99.0, 99.5),
    reclaimBar('2026-07-14T13:35:00+00:00', 99.5, 99.8, 98.0, 99.0),
]);
reclaimAssert($noReclaim->status === 'no_reclaim', 'A touch without a completed reclaim cannot fill.');

$preStop = $confirmer->resolve($signal, '2026-07-14', [
    reclaimBar('2026-07-14T13:30:00+00:00', 99.0, 99.5, 94.0, 99.0),
    reclaimBar('2026-07-14T13:35:00+00:00', 99.0, 101.0, 98.5, 100.5),
    reclaimBar('2026-07-14T13:40:00+00:00', 100.0, 101.0, 99.8, 100.5),
]);
reclaimAssert(
    $preStop->status === 'pre_entry_stop_breached' && $preStop->preEntryStopBreach,
    'The conservative default must reject a setup whose frozen stop broke before entry.',
);
$diagnosticConfirmer = new IntradayTouchReclaimConfirmer(
    barMinutes: 5,
    touchTolerancePct: 0.0,
    reclaimBufferPct: 0.0,
    maxBarsAfterTouch: 3,
    maxFillDelayMinutes: 5,
    maxChaseAtr: 0.25,
    slippageBps: 10.0,
    rejectPreEntryStopBreach: false,
    requireCompleteSession: false,
);
$permissivePreStop = $diagnosticConfirmer->resolve($signal, '2026-07-14', [
    reclaimBar('2026-07-14T13:30:00+00:00', 99.0, 99.5, 94.0, 99.0),
    reclaimBar('2026-07-14T13:35:00+00:00', 99.0, 101.0, 98.5, 100.5),
    reclaimBar('2026-07-14T13:40:00+00:00', 100.0, 101.0, 99.8, 100.5),
]);
reclaimAssert(
    $permissivePreStop->isFilled() && $permissivePreStop->preEntryStopBreach,
    'The explicit sensitivity mode may retain a pre-entry stop breach as a diagnostic.',
);

$missingSlot = $confirmer->resolve($signal, '2026-07-14', [
    reclaimBar('2026-07-14T13:30:00+00:00', 101.0, 102.0, 99.0, 101.0),
    reclaimBar('2026-07-14T13:45:00+00:00', 100.0, 101.0, 99.0, 100.0),
]);
reclaimAssert($missingSlot->status === 'fill_delay_exceeded', 'A sparse-feed execution gap beyond the limit must fail closed.');

$sparseWindow = $confirmer->resolve($signal, '2026-07-14', [
    reclaimBar('2026-07-14T13:30:00+00:00', 100.0, 100.0, 99.0, 99.5),
    reclaimBar('2026-07-14T14:30:00+00:00', 100.4, 101.0, 100.2, 100.5),
]);
reclaimAssert(
    $sparseWindow->status === 'reclaim_window_expired',
    'The reclaim window must use elapsed time, not a sparse-feed bar count.',
);

$chase = $confirmer->resolve($signal, '2026-07-14', [
    reclaimBar('2026-07-14T13:30:00+00:00', 101.0, 102.0, 99.0, 101.0),
    reclaimBar('2026-07-14T13:35:00+00:00', 103.0, 104.0, 102.0, 103.5),
]);
reclaimAssert($chase->status === 'chase_cap_exceeded', 'A next-bar gap above the frozen ATR chase cap must cancel the entry.');

$late = $confirmer->resolve($signal, '2026-07-14', [
    reclaimBar('2026-07-14T19:55:00+00:00', 99.0, 101.0, 98.0, 100.5),
]);
reclaimAssert($late->status === 'late_reclaim', 'A 15:55 New York reclaim cannot create an overnight fill.');

$missing = $confirmer->resolve($signal, '2026-07-14', []);
reclaimAssert($missing->status === 'missing_session_data', 'Missing candidate-session data must be explicit.');

$duplicate = $bars;
$duplicate[] = $bars[1];
$deduped = $confirmer->resolve($signal, '2026-07-14', $duplicate);
reclaimAssert($deduped->isFilled(), 'Identical duplicate bars must be deterministically deduplicated.');

$conflict = $bars;
$conflict[] = reclaimBar('2026-07-14T13:30:00+00:00', 101.0, 102.0, 98.0, 100.5);
$conflicting = $confirmer->resolve($signal, '2026-07-14', $conflict);
reclaimAssert($conflicting->status === 'conflicting_duplicate', 'Conflicting duplicate bars must fail closed.');

$earlyCloseFill = $confirmer->resolve($signal, '2025-07-03', [
    reclaimBar('2025-07-03T16:50:00+00:00', 101.0, 102.0, 99.0, 101.0),
    reclaimBar('2025-07-03T16:55:00+00:00', 100.8, 101.2, 100.4, 101.0),
]);
reclaimAssert(
    $earlyCloseFill->isFilled() && $earlyCloseFill->fillAt?->format('H:i') === '16:55',
    'A 12:50 reclaim may fill at 12:55 before a 13:00 early close.',
);
$earlyCloseLate = $confirmer->resolve($signal, '2025-07-03', [
    reclaimBar('2025-07-03T16:55:00+00:00', 101.0, 102.0, 99.0, 101.0),
    reclaimBar('2025-07-03T17:00:00+00:00', 100.8, 101.2, 100.4, 101.0),
]);
reclaimAssert(
    $earlyCloseLate->status === 'late_reclaim',
    'A 12:55 reclaim is too late to obtain a causal fill before a 13:00 close.',
);
$afterEarlyCloseIgnored = $confirmer->resolve($signal, '2025-07-03', [
    reclaimBar('2025-07-03T16:45:00+00:00', 102.0, 103.0, 101.0, 102.0),
    reclaimBar('2025-07-03T17:05:00+00:00', 101.0, 102.0, 99.0, 101.0),
    reclaimBar('2025-07-03T17:10:00+00:00', 100.8, 101.2, 100.4, 101.0),
]);
reclaimAssert(
    $afterEarlyCloseIgnored->status === 'no_touch',
    'Prints after an official early close must not become entries.',
);

$strictConfirmer = new IntradayTouchReclaimConfirmer(
    barMinutes: 5,
    touchTolerancePct: 0.0,
    reclaimBufferPct: 0.0,
    maxBarsAfterTouch: 3,
    maxFillDelayMinutes: 5,
    maxChaseAtr: 0.25,
    slippageBps: 10.0,
);
reclaimAssert(
    $strictConfirmer->sessionQualityPolicy() === [
        'required' => true,
        'bar_minutes' => 5,
        'minimum_coverage_pct' => 1.0,
        'maximum_gap_bars' => 1,
    ],
    'The fail-closed candidate-session quality contract must be machine-readable in research evidence.',
);
$strictSparse = $strictConfirmer->resolve($signal, '2026-07-14', [
    reclaimBar('2026-07-14T13:30:00+00:00', 101.0, 102.0, 99.0, 101.0),
    reclaimBar('2026-07-14T13:35:00+00:00', 100.8, 101.2, 100.4, 101.0),
]);
reclaimAssert(
    $strictSparse->status === 'incomplete_session_data'
        && str_contains($strictSparse->reason, 'missing_close_bar'),
    'The production default must reject an opening reclaim when the post-fill session path is missing.',
);

$completeSession = [];
$sessionStart = new DateTimeImmutable('2026-07-14 09:30:00', new DateTimeZone('America/New_York'));
for ($i = 0; $i < 78; $i++) {
    $time = $sessionStart->modify('+' . ($i * 5) . ' minutes')->setTimezone(new DateTimeZone('UTC'));
    $completeSession[] = $i === 0
        ? reclaimBar($time->format(DATE_ATOM), 101.0, 102.0, 99.0, 101.0)
        : ($i === 1
            ? reclaimBar($time->format(DATE_ATOM), 100.8, 101.2, 100.4, 101.0)
            : reclaimBar($time->format(DATE_ATOM), 102.0, 103.0, 101.0, 102.0));
}
$strictComplete = $strictConfirmer->resolve($signal, '2026-07-14', $completeSession);
reclaimAssert(
    $strictComplete->isFilled() && $strictComplete->fillAt?->format('H:i') === '13:35',
    'A complete 78-bar regular session must retain the causal next-bar fill.',
);
$completeValidation = $strictConfirmer->validateRegularSessionPath('TEST', '2026-07-14', $completeSession);
reclaimAssert(
    $completeValidation['passes'] === true
        && count($completeValidation['bars']) === 78
        && $completeValidation['failures'] === [],
    'The shared signal-independent validator must accept a complete on-grid regular session.',
);

$gapSession = $completeSession;
array_splice($gapSession, 20, 1);
$strictGap = $strictConfirmer->resolve($signal, '2026-07-14', $gapSession);
reclaimAssert(
    $strictGap->status === 'incomplete_session_data'
        && str_contains($strictGap->reason, 'insufficient_bars:77<78_of_78')
        && str_contains($strictGap->reason, 'gap_minutes:10'),
    'Deleting one expected 5m bar must fail closed even though more than 98% of the session remains.',
);
$gapValidation = $strictConfirmer->validateRegularSessionPath('TEST', '2026-07-14', $gapSession);
reclaimAssert(
    $gapValidation['passes'] === false
        && in_array('insufficient_bars:77<78_of_78', $gapValidation['failures'], true)
        && in_array('gap_minutes:10', $gapValidation['failures'], true),
    'The shared validator must expose the exact missing-bar failures without requiring a Signal.',
);

echo "Intraday touch/reclaim confirmer OK\n";
