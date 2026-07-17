<?php

declare(strict_types=1);

use FulltimeTrading\Trading\TacticalExecutionStateGuard;

require dirname(__DIR__) . '/bootstrap.php';

function executionStateExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string,mixed> */
function executionStateIntent(
    string $id,
    string $status,
    float $requested,
    float $filled,
    string $session = '2026-07-20',
    string $timeInForce = 'opg',
): array {
    return [
        'decision_id' => hash('sha256', $id),
        'sleeve_id' => 'dynamic_loo10',
        'scheduled_session' => $session,
        'symbol' => 'PANW',
        'side' => 'buy',
        'requested_qty' => $requested,
        'cumulative_filled_qty' => $filled,
        'status' => $status,
        'payload' => ['time_in_force' => $timeInForce],
    ];
}

$timezone = new DateTimeZone('America/New_York');
$filled = TacticalExecutionStateGuard::assess(
    [executionStateIntent('filled', 'filled', 10, 10)],
    ['PANW' => 10],
    ['PANW' => 10],
    [],
    new DateTimeImmutable('2026-07-20 09:35:00', $timezone),
);
executionStateExpect(
    $filled['execution_state'] === 'reconciled'
        && $filled['entries_allowed'] === true
        && $filled['reason_codes'] === [],
    'A complete fill with matching broker state must remain eligible for the next entry.',
);

$terminalPartial = TacticalExecutionStateGuard::assess(
    [executionStateIntent('partial-canceled', 'canceled', 10, 2)],
    ['PANW' => 2],
    ['PANW' => 2],
    [],
    new DateTimeImmutable('2026-07-20 09:35:00', $timezone),
);
executionStateExpect(
    $terminalPartial['execution_state'] === 'diverged'
        && $terminalPartial['entries_allowed'] === false
        && in_array('terminal_incomplete_order', $terminalPartial['reason_codes'], true)
        && $terminalPartial['details']['terminal_incomplete_intents'][0]['remaining_qty'] === 8.0,
    'A partial terminal order must block later entries even when broker and fill ledger agree.',
);

$rejected = TacticalExecutionStateGuard::assess(
    [executionStateIntent('rejected', 'rejected', 10, 0)],
    [],
    [],
    [],
    new DateTimeImmutable('2026-07-20 09:20:00', $timezone),
);
executionStateExpect(
    $rejected['entries_allowed'] === false
        && in_array('terminal_incomplete_order', $rejected['reason_codes'], true),
    'A rejected zero-fill target must latch replay/execution divergence immediately.',
);

$plannedBeforeCutoff = TacticalExecutionStateGuard::assess(
    [executionStateIntent('planned-before', 'planned', 10, 0)],
    [],
    [],
    [],
    new DateTimeImmutable('2026-07-20 09:26:59', $timezone),
);
executionStateExpect(
    $plannedBeforeCutoff['entries_allowed'] === true,
    'A persisted OPG intent is not missed before its deterministic cutoff.',
);
$plannedMissed = TacticalExecutionStateGuard::assess(
    [executionStateIntent('planned-missed', 'planned', 10, 0)],
    [],
    [],
    [],
    new DateTimeImmutable('2026-07-20 09:27:00', $timezone),
);
executionStateExpect(
    $plannedMissed['execution_state'] === 'diverged'
        && in_array('missed_execution_window', $plannedMissed['reason_codes'], true),
    'An unsubmitted OPG intent must become a persistent divergence at the cutoff.',
);
$dayStillRecoverable = TacticalExecutionStateGuard::assess(
    [executionStateIntent('day-before', 'planned', 10, 0, '2026-07-20', 'day')],
    [],
    [],
    [],
    new DateTimeImmutable('2026-07-20 09:31:59', $timezone),
);
executionStateExpect($dayStillRecoverable['entries_allowed'] === true, 'A deferred DAY rotation remains eligible inside its bounded recovery window.');
$dayMissed = TacticalExecutionStateGuard::assess(
    [executionStateIntent('day-missed', 'planned', 10, 0, '2026-07-20', 'day')],
    [],
    [],
    [],
    new DateTimeImmutable('2026-07-20 09:32:00', $timezone),
);
executionStateExpect(
    in_array('missed_execution_window', $dayMissed['reason_codes'], true),
    'A deferred DAY rotation must not turn into an unbounded chase.',
);

$inflight = TacticalExecutionStateGuard::assess(
    [executionStateIntent('inflight-partial', 'partially_filled', 10, 2)],
    ['PANW' => 2],
    ['PANW' => 2],
    [],
    new DateTimeImmutable('2026-07-20 09:30:00', $timezone),
);
executionStateExpect(
    $inflight['execution_state'] === 'reconcile_only'
        && $inflight['entries_allowed'] === false
        && in_array('incomplete_order_inflight', $inflight['reason_codes'], true),
    'Any active partial must reconcile to completion or terminal divergence before new risk.',
);

$ambiguous = TacticalExecutionStateGuard::assess(
    [executionStateIntent('ambiguous', 'ambiguous', 10, 0)],
    [],
    [],
    [],
    new DateTimeImmutable('2026-07-20 09:20:00', $timezone),
);
executionStateExpect(
    $ambiguous['entries_allowed'] === false
        && in_array('ambiguous_order_intent', $ambiguous['reason_codes'], true),
    'An ambiguous submit must block every new entry.',
);

$positionDrift = TacticalExecutionStateGuard::assess(
    [],
    ['PANW' => 10],
    ['PANW' => 9],
    [],
    new DateTimeImmutable('2026-07-20 10:00:00', $timezone),
);
executionStateExpect(
    $positionDrift['execution_state'] === 'diverged'
        && in_array('position_ledger_drift', $positionDrift['reason_codes'], true),
    'Broker quantities that differ from the actual-fill ledger must block entries.',
);

$foreign = TacticalExecutionStateGuard::assess(
    [],
    [],
    [],
    [['client_order_id' => 'manual-order']],
    new DateTimeImmutable('2026-07-20 10:00:00', $timezone),
);
executionStateExpect(
    $foreign['execution_state'] === 'reconcile_only'
        && in_array('foreign_open_order', $foreign['reason_codes'], true),
    'A foreign broker order must force reconciliation-only mode.',
);
$malformedRejected = false;
try {
    TacticalExecutionStateGuard::assess(
        [array_replace(executionStateIntent('malformed', 'planned', 10, 0), ['requested_qty' => 'not-a-number'])],
        [],
        [],
        [],
        new DateTimeImmutable('2026-07-20 09:20:00', $timezone),
    );
} catch (InvalidArgumentException) {
    $malformedRejected = true;
}
executionStateExpect($malformedRejected, 'Malformed persisted quantities must fail closed instead of casting to zero.');

$exitLeg = [
    'leg' => 'exit',
    'side' => 'sell',
    'symbol' => 'PANW',
    'sleeve_id' => 'dynamic_loo10',
    'requested_qty' => 2,
];
$ledger = ['dynamic_loo10' => ['PANW' => ['qty' => 2.0]]];
executionStateExpect(
    TacticalExecutionStateGuard::riskReducingSellAllowed(
        $exitLeg,
        ['PANW' => 2],
        $ledger,
        [],
        $terminalPartial,
    ),
    'A terminal divergence may be reduced only within both broker and sleeve ownership.',
);
executionStateExpect(
    !TacticalExecutionStateGuard::riskReducingSellAllowed(
        array_replace($exitLeg, ['requested_qty' => 3]),
        ['PANW' => 2],
        $ledger,
        [],
        $terminalPartial,
    ),
    'A risk-reducing exception must never oversell the actual broker position.',
);
executionStateExpect(
    !TacticalExecutionStateGuard::riskReducingSellAllowed(
        array_replace($exitLeg, ['side' => 'buy', 'leg' => 'entry']),
        ['PANW' => 2],
        $ledger,
        [],
        $terminalPartial,
    ),
    'The divergence exception must never authorize a buy.',
);
executionStateExpect(
    !TacticalExecutionStateGuard::riskReducingSellAllowed(
        $exitLeg,
        ['PANW' => 2],
        $ledger,
        [['client_order_id' => 'known-or-foreign']],
        $terminalPartial,
    ),
    'A risk-reducing sell must not race any broker order.',
);
executionStateExpect(
    !TacticalExecutionStateGuard::riskReducingSellAllowed(
        $exitLeg,
        ['PANW' => 2],
        $ledger,
        [],
        $ambiguous,
    ),
    'A risk-reducing sell must not race an ambiguous submit.',
);
executionStateExpect(
    !TacticalExecutionStateGuard::riskReducingSellAllowed(
        $exitLeg,
        ['PANW' => 2],
        $ledger,
        [],
        $foreign,
    ),
    'A risk-reducing sell must not race a foreign order.',
);

echo "tactical execution state guard tests passed\n";
