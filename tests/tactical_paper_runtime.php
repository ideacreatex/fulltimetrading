<?php

declare(strict_types=1);

use FulltimeTrading\Storage\TacticalPaperRepository;
use FulltimeTrading\Trading\TacticalAmbiguousIntentReconciler;
use FulltimeTrading\Trading\TacticalOrderGateway;
use FulltimeTrading\Trading\TacticalRotationExecutionWindow;
use FulltimeTrading\Trading\TacticalRotationPaperPlanner;
use FulltimeTrading\Trading\TacticalSignalArtifactGuard;

require dirname(__DIR__) . '/bootstrap.php';

function tacticalExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function tacticalSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

function tacticalSetPersistedIntentUpdatedAt(string $database, string $decisionId, string $updatedAt): void
{
    $pdo = new PDO('sqlite:' . $database);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $statement = $pdo->prepare(
        'UPDATE tactical_paper_intent SET updated_at=:updated_at WHERE decision_id=:decision_id'
    );
    $statement->execute([':updated_at' => $updatedAt, ':decision_id' => $decisionId]);
    if ($statement->rowCount() !== 1) {
        throw new RuntimeException('Unable to set deterministic ambiguous retry timestamp fixture.');
    }
}

final class TacticalTestOrderGateway implements TacticalOrderGateway
{
    public int $lookupCount = 0;
    public int $submitCount = 0;
    /** @var list<array<string,mixed>> */
    public array $submittedBodies = [];

    /**
     * @param list<array<string,mixed>|null> $lookupResponses
     * @param array<string,mixed>|null $submitResponse
     */
    public function __construct(
        private array $lookupResponses,
        private ?array $submitResponse = null,
        private bool $submitThrows = false,
    ) {
    }

    public function orderByClientOrderId(string $clientOrderId): ?array
    {
        $this->lookupCount++;
        $response = array_shift($this->lookupResponses);

        return is_array($response) ? $response : null;
    }

    public function submitOrder(array $order): array
    {
        $this->submitCount++;
        $this->submittedBodies[] = $order;
        if ($this->submitThrows) {
            throw new RuntimeException('simulated unknown POST outcome');
        }

        return $this->submitResponse ?? throw new RuntimeException('Missing fake submit response.');
    }
}

$timezone = new DateTimeZone('America/New_York');
$window = new TacticalRotationExecutionWindow('09:15', '09:27', '19:05', '09:32');
tacticalSame(
    'waiting_for_opg_window',
    $window->resolve('2026-07-20', new DateTimeImmutable('2026-07-19 19:04:59', $timezone))['status'],
    'OPG must not be submitted before the evening queue window.',
);
tacticalSame(
    'queue_for_open',
    $window->resolve('2026-07-20', new DateTimeImmutable('2026-07-19 19:05:00', $timezone))['status'],
    'Evening OPG queue must open deterministically.',
);
tacticalSame(
    'submit_preopen',
    $window->resolve('2026-07-20', new DateTimeImmutable('2026-07-20 09:15:00', $timezone))['status'],
    'Premarket recovery window must accept the same OPG intent.',
);
tacticalSame(
    'locked_for_open',
    $window->resolve('2026-07-20', new DateTimeImmutable('2026-07-20 09:27:00', $timezone))['status'],
    'The OPG cutoff must lock submissions before Alpaca 09:28.',
);
tacticalSame(
    'rotation_reentry_and_risk_exit_window',
    $window->resolve('2026-07-20', new DateTimeImmutable('2026-07-20 09:30:00', $timezone), null, true)['status'],
    'A confirmed opening-auction exit may unlock only the bounded DAY re-entry window.',
);
tacticalSame(
    false,
    $window->resolve('2026-07-20', new DateTimeImmutable('2026-07-20 09:32:00', $timezone), null, true)['rotation_reentry_allowed'],
    'The replacement-entry window must close without chasing at its exact cutoff.',
);
tacticalSame(
    'risk_exit_recovery_window',
    $window->resolve('2026-07-20', new DateTimeImmutable('2026-07-20 09:32:00', $timezone), null, true)['status'],
    'Risk-reducing DAY exits must remain recoverable after the entry cutoff.',
);
tacticalSame(
    'awaiting_broker_open_confirmation',
    $window->resolve('2026-07-20', new DateTimeImmutable('2026-07-20 10:00:00', $timezone))['status'],
    'Clock time alone must never authorize a DAY fallback.',
);
tacticalSame(
    'missed_no_chase',
    $window->resolve('2026-07-20', new DateTimeImmutable('2026-07-20 15:45:00', $timezone), null, true)['status'],
    'Risk exits must stop at the exact bounded RTH cutoff.',
);
tacticalSame(
    'late_risk_exit_recovery_window',
    $window->resolve('2026-07-20', new DateTimeImmutable('2026-07-21 10:00:00', $timezone), null, true)['status'],
    'A stale scheduled-session sell may reduce risk during the next confirmed RTH session.',
);
tacticalSame(
    'queue_for_open',
    $window->resolve('2026-07-20', new DateTimeImmutable('2026-07-17 19:05:00', $timezone), '2026-07-17')['status'],
    'A Friday close signal must be queueable Friday evening for Monday open.',
);

$planner = new TacticalRotationPaperPlanner();
$baseTarget = [
    'signal_date' => '2026-07-17',
    'execution' => 'next_session_open',
    'rebalance_due_next_session' => true,
    'action' => 'rebalance',
    'symbol' => 'PANW',
    'gross' => 0.75,
];
$legs = $planner->plan('run', 'dynamic_loo10', '2026-07-20', $baseTarget, 10000.0, 200.0, [], 1.20);
tacticalSame(1, count($legs), 'A cash sleeve must create one entry leg.');
tacticalSame(37, $legs[0]['requested_qty'], 'OPG sizing must round down to whole shares.');
tacticalExpect(strlen((string) $legs[0]['client_order_id']) <= 48, 'Client order ID must stay under the compatibility limit.');
tacticalSame(
    $legs[0]['client_order_id'],
    $planner->plan('run', 'dynamic_loo10', '2026-07-20', $baseTarget, 10000.0, 200.0, [], 1.20)[0]['client_order_id'],
    'Restart must reproduce the exact client order ID.',
);
$rotation = $planner->plan(
    'run',
    'dynamic_loo10',
    '2026-07-20',
    $baseTarget,
    10000.0,
    200.0,
    ['NVDA' => ['qty' => 20.0]],
    1.20,
);
tacticalSame(['sell', 'buy'], array_column($rotation, 'side'), 'Rotation must preserve separate sell and buy turnover legs.');
$rotationBeforeExit = $planner->prepareSequentialExecution($rotation, []);
tacticalSame(['sell'], array_column($rotationBeforeExit, 'side'), 'A rotation buy must not be persisted before its sell exists and fills.');
$riskExitWindow = $window->resolve(
    '2026-07-20',
    new DateTimeImmutable('2026-07-20 10:00:00', $timezone),
    null,
    true,
);
$riskExitFallback = $planner->prepareSequentialExecution($rotation, [], $riskExitWindow);
tacticalSame(['sell'], array_column($riskExitFallback, 'side'), 'A missed OPG rotation may recover only its risk-reducing sell.');
tacticalSame('day', $riskExitFallback[0]['payload']['time_in_force'], 'Recovered sell must bind an actual DAY broker body.');
tacticalSame(false, $riskExitFallback[0]['payload']['late_risk_reduction'], 'Same-session fallback must not be mislabeled late.');
tacticalExpect(
    $rotation[0]['client_order_id'] !== $riskExitFallback[0]['client_order_id'],
    'OPG-to-DAY sell fallback must change the deterministic client order ID.',
);
tacticalExpect(
    $planner->isAllowedInWindow($riskExitFallback[0], $riskExitWindow),
    'A DAY sell fallback requires a confirmed-open bounded RTH window.',
);
$lateRiskWindow = $window->resolve(
    '2026-07-20',
    new DateTimeImmutable('2026-07-21 10:00:00', $timezone),
    null,
    true,
);
$lateRiskExit = $planner->prepareSequentialExecution($rotation, [], $lateRiskWindow)[0];
tacticalSame(true, $lateRiskExit['payload']['late_risk_reduction'], 'Next-session recovery must be explicit in the body identity.');
$lateFilledExit = array_replace($lateRiskExit, [
    'status' => 'filled',
    'cumulative_filled_qty' => $lateRiskExit['requested_qty'],
]);
tacticalSame(
    [],
    $planner->prepareSequentialExecution([$rotation[1]], [$lateFilledExit], $lateRiskWindow),
    'A late risk reduction must never revive the stale replacement entry.',
);
$syntheticFilledExit = array_replace($rotation[0], [
    'status' => 'filled',
    'cumulative_filled_qty' => $rotation[0]['requested_qty'],
]);
$rotationAfterExit = $planner->prepareSequentialExecution([$rotation[1]], [$syntheticFilledExit]);
tacticalSame(['buy'], array_column($rotationAfterExit, 'side'), 'A fully reconciled sell may unlock its replacement buy.');
tacticalSame('day', $rotationAfterExit[0]['payload']['time_in_force'], 'A post-auction replacement must use DAY, never OPG.');
tacticalSame(
    [$syntheticFilledExit['decision_id']],
    $rotationAfterExit[0]['payload']['required_exit_decision_ids'],
    'The DAY order identity must bind its exact sell dependency.',
);
tacticalExpect(
    $rotation[1]['client_order_id'] !== $rotationAfterExit[0]['client_order_id'],
    'Changing OPG to dependency-gated DAY must change the client order ID.',
);
tacticalSame(
    $rotationAfterExit[0]['client_order_id'],
    $planner->prepareSequentialExecution([$rotation[1]], [$syntheticFilledExit])[0]['client_order_id'],
    'Sequential recovery must reproduce the exact DAY client order ID.',
);
$partialExit = array_replace($syntheticFilledExit, [
    'status' => 'canceled',
    'cumulative_filled_qty' => (float) $syntheticFilledExit['requested_qty'] - 1.0,
]);
tacticalSame(
    [],
    $planner->prepareSequentialExecution([$rotation[1]], [$partialExit]),
    'A terminal partial sell must never unlock its replacement buy.',
);
$dayWindow = $window->resolve('2026-07-20', new DateTimeImmutable('2026-07-20 09:30:00', $timezone), null, true);
tacticalExpect(
    $planner->isAllowedInWindow($rotationAfterExit[0], $dayWindow, [$syntheticFilledExit]),
    'The exact fully-filled dependency must authorize DAY re-entry during its bounded window.',
);
tacticalExpect(
    !$planner->isAllowedInWindow($rotationAfterExit[0], $dayWindow, [$partialExit]),
    'A partial dependency must fail closed even inside the DAY window.',
);

$aggregateExitTarget = [
    'signal_date' => '2026-07-17',
    'execution' => 'next_session_open',
    'rebalance_due_next_session' => true,
    'action' => 'exit_to_cash',
    'symbol' => null,
    'gross' => 0.0,
];
$aggregateSellA = $planner->plan(
    'aggregate-run',
    'dynamic_loo10',
    '2026-07-20',
    $aggregateExitTarget,
    10000.0,
    0.0,
    ['XYZ' => ['qty' => 100.0]],
    1.20,
)[0];
$aggregateSellB = $planner->plan(
    'aggregate-run',
    'qqq200_full',
    '2026-07-20',
    $aggregateExitTarget,
    10000.0,
    0.0,
    ['XYZ' => ['qty' => 100.0]],
    1.20,
)[0];
$aggregateLedger = [
    'dynamic_loo10' => ['XYZ' => ['qty' => 100.0]],
    'qqq200_full' => ['XYZ' => ['qty' => 100.0]],
];
$aggregateCapped = $planner->capAggregateSellReservations(
    [$aggregateSellB, $aggregateSellA],
    ['XYZ' => 150.0],
    $aggregateLedger,
);
$aggregateBySleeve = [];
foreach ($aggregateCapped as $reservedLeg) {
    $aggregateBySleeve[(string) $reservedLeg['sleeve_id']] = (int) $reservedLeg['requested_qty'];
    TacticalRotationPaperPlanner::assertExecutionIdentity($reservedLeg);
}
tacticalSame(
    ['dynamic_loo10' => 100, 'qqq200_full' => 50],
    $aggregateBySleeve,
    'Aggregate reservation must deterministically cap 100+100 sleeve sells to the 150-share broker long.',
);
tacticalSame(
    150,
    array_sum(array_map(static fn (array $leg): int => (int) $leg['requested_qty'], $aggregateCapped)),
    'Aggregate sell bodies must never create a broker short even when shorting is enabled.',
);
tacticalExpect(
    $aggregateSellB['client_order_id'] !== $aggregateCapped[1]['client_order_id'],
    'A capped sell body must receive a new quantity-bound deterministic client order ID.',
);
$aggregateRepeated = $planner->capAggregateSellReservations(
    [$aggregateSellA, $aggregateSellB],
    ['XYZ' => 150.0],
    $aggregateLedger,
);
tacticalSame(
    array_column($aggregateCapped, 'client_order_id'),
    array_column($aggregateRepeated, 'client_order_id'),
    'Aggregate reservations must be independent of incoming sleeve order.',
);
tacticalSame(
    [],
    $planner->capAggregateSellReservations(
        [$aggregateSellA, $aggregateSellB],
        ['XYZ' => 0.0],
        $aggregateLedger,
    ),
    'Zero broker long capacity must suppress every tactical sell body.',
);
$identityTamperRejected = false;
try {
    TacticalRotationPaperPlanner::assertExecutionIdentity(array_replace_recursive(
        $rotationAfterExit[0],
        ['payload' => ['time_in_force' => 'opg']],
    ));
} catch (RuntimeException) {
    $identityTamperRejected = true;
}
tacticalExpect($identityTamperRejected, 'Changing TIF without changing the deterministic identity must fail closed.');
tacticalSame([], $planner->plan('run', 'dynamic_loo10', '2026-07-20', array_replace($baseTarget, [
    'rebalance_due_next_session' => false,
    'action' => 'hold',
    'symbol' => null,
    'gross' => 0.0,
]), 10000.0, 0.0, [], 1.20), 'Hold must never create an order.');
$nextArtifactHold = [
    'signal_date' => '2026-07-20',
    'execution' => 'next_session_open',
    'rebalance_due_next_session' => false,
    'action' => 'hold',
    'symbol' => null,
    'gross' => 0.0,
    'current_symbol' => 'BBB',
    'current_gross' => 0.50,
];
$staleHoldExit = $planner->plan(
    'restart-run',
    'dynamic_loo10',
    '2026-07-21',
    $nextArtifactHold,
    10000.0,
    0.0,
    ['AAA' => ['qty' => 12.0]],
    1.20,
);
tacticalSame(['sell'], array_column($staleHoldExit, 'side'), 'A missed Monday A-to-B rotation followed by Tuesday hold must de-risk stale A only.');
tacticalSame(['AAA'], array_column($staleHoldExit, 'symbol'), 'A stale hold must never chase model symbol B.');
tacticalSame(true, $staleHoldExit[0]['payload']['stale_model_mismatch'], 'Stale model de-risk must be explicit in persisted identity.');
$staleHoldAfterRestart = (new TacticalRotationPaperPlanner())->plan(
    'restart-run',
    'dynamic_loo10',
    '2026-07-21',
    $nextArtifactHold,
    10000.0,
    0.0,
    ['AAA' => ['qty' => 12.0]],
    1.20,
);
tacticalSame(
    $staleHoldExit[0]['client_order_id'],
    $staleHoldAfterRestart[0]['client_order_id'],
    'Restart must reproduce the exact stale-model sell client ID.',
);
tacticalSame([], $planner->plan(
    'restart-run',
    'dynamic_loo10',
    '2026-07-21',
    array_replace($nextArtifactHold, ['current_symbol' => 'AAA']),
    10000.0,
    0.0,
    ['AAA' => ['qty' => 12.0]],
    1.20,
), 'A hold whose model current symbol matches the ledger must remain a true hold.');
$missedResizeReduction = $planner->plan(
    'restart-run-resize',
    'dynamic_loo10',
    '2026-07-21',
    array_replace($nextArtifactHold, ['current_symbol' => 'AAA', 'current_gross' => 0.50]),
    10000.0,
    200.0,
    ['AAA' => ['qty' => 40.0]],
    1.20,
);
tacticalSame(['sell'], array_column($missedResizeReduction, 'side'), 'A missed same-symbol resize may only reduce excess risk.');
tacticalSame(15, $missedResizeReduction[0]['requested_qty'], 'Recovery sizing must cap the held quantity at current model gross.');
tacticalSame(true, $missedResizeReduction[0]['payload']['stale_model_resize_reduction'], 'Missed resize reduction must be explicit in intent identity.');
$cashModelMismatch = $planner->plan(
    'restart-run-cash',
    'dynamic_loo10',
    '2026-07-21',
    array_replace($nextArtifactHold, ['current_symbol' => null]),
    10000.0,
    0.0,
    ['AAA' => ['qty' => 12.0]],
    1.20,
);
tacticalSame(['sell'], array_column($cashModelMismatch, 'side'), 'A model hold in cash must de-risk every stale tactical holding.');
$staleRiskWindow = $window->resolve(
    '2026-07-21',
    new DateTimeImmutable('2026-07-21 10:00:00', $timezone),
    '2026-07-20',
    true,
);
$staleDayExit = $planner->prepareSequentialExecution($staleHoldExit, [], $staleRiskWindow);
tacticalSame('day', $staleDayExit[0]['payload']['time_in_force'], 'Restart during RTH must convert stale-model de-risk to bounded DAY sell.');
tacticalSame(true, $staleDayExit[0]['payload']['stale_model_mismatch'], 'OPG-to-DAY fallback must retain stale-model causality.');
$malformedRejected = false;
try {
    $planner->plan(
        'run',
        'dynamic_loo10',
        '2026-07-20',
        array_replace($baseTarget, ['symbol' => null, 'gross' => 0.75]),
        10000.0,
        200.0,
        ['NVDA' => ['qty' => 10.0]],
        1.20,
    );
} catch (RuntimeException) {
    $malformedRejected = true;
}
tacticalExpect($malformedRejected, 'A malformed positive-gross cash target must never liquidate a sleeve.');

$paperConfig = require dirname(__DIR__) . '/config/tactical_paper.php';
$contract = (array) $paperConfig['data'];
$guardSymbols = ['AAPL', 'SPY'];
$provenance = [
    'mode' => 'frozen_alpaca_sip_plus_completed_alpaca_iex',
    'request' => ['symbols' => $guardSymbols, 'end' => '2026-07-16'],
    'boundary' => [
        'frozen_sip_cutoff_inclusive' => '2026-07-15',
        'recent_iex_start_inclusive' => '2026-07-16',
        'overlap_policy' => 'reject',
        'overlap_sessions' => 0,
    ],
    'cross_feed_audit' => [
        'mode' => 'audit_only_cutoff_overlap_v1',
        'enabled' => true,
        'used' => true,
        'passed' => true,
        'role' => 'audit_only_not_decision_data',
        'decision_data_usage' => 'none',
        'used_for_merged_bars' => false,
        'contract' => $contract['cross_feed_audit'],
        'feeds' => ['reference' => 'sip', 'candidate' => 'iex'],
        'cache_namespace' => $contract['fresh_cache_namespace'],
        'request' => [
            'symbols' => $guardSymbols,
            'timeframe' => '1Day',
            'start' => '2026-07-09',
            'end' => '2026-07-15',
        ],
        'window' => [
            'start' => '2026-07-09',
            'end' => '2026-07-15',
            'sessions' => ['2026-07-09', '2026-07-10', '2026-07-13', '2026-07-14', '2026-07-15'],
        ],
        'compared_symbols' => $guardSymbols,
        'compared_sessions' => 5,
        'compared_bars' => 10,
        'violations' => 0,
        'observed' => [
            'maximum_price_deviation_bps' => [
                'open' => 80.0,
                'high' => 40.0,
                'low' => 70.0,
                'close' => 15.0,
            ],
            'minimum_iex_to_sip_volume_ratio' => 0.02,
            'maximum_iex_to_sip_volume_ratio' => 0.06,
        ],
        'canonical_sha256' => [
            'frozen_sip' => str_repeat('d', 64),
            'audit_iex' => str_repeat('e', 64),
        ],
    ],
    'segments' => [
        'frozen_sip' => [
            'feed' => 'sip',
            'expected_sha256' => $contract['historical_snapshot_sha256'],
            'sha256' => $contract['historical_snapshot_sha256'],
            'namespace' => $contract['cache_namespace'],
            'snapshot_canonical_sha256' => str_repeat('a', 64),
        ],
        'recent_iex' => [
            'feed' => 'iex',
            'namespace' => $contract['fresh_cache_namespace'],
            'used' => true,
            'request' => ['start' => '2026-07-16', 'end' => '2026-07-16'],
            'coverage' => [
                'AAPL' => ['last_session' => '2026-07-16'],
                'SPY' => ['last_session' => '2026-07-16'],
            ],
            'canonical_sha256' => str_repeat('b', 64),
        ],
    ],
    'merged' => [
        'effective_completed_session' => '2026-07-16',
        'canonical_sha256' => str_repeat('c', 64),
    ],
];
TacticalSignalArtifactGuard::validateDataProvenance($provenance, $contract, '2026-07-16', $guardSymbols);
foreach ([
    ['segments', 'frozen_sip', 'sha256'],
    ['segments', 'recent_iex', 'used'],
    ['merged', 'effective_completed_session', 'date'],
    ['cross_feed_audit', 'used_for_merged_bars', 'audit_usage'],
    ['cross_feed_audit', 'observed', 'audit_tolerance'],
] as $mutation) {
    $corrupt = $provenance;
    if ($mutation[2] === 'sha256') {
        $corrupt[$mutation[0]][$mutation[1]][$mutation[2]] = str_repeat('0', 64);
    } elseif ($mutation[2] === 'used') {
        $corrupt[$mutation[0]][$mutation[1]][$mutation[2]] = false;
    } elseif ($mutation[2] === 'audit_usage') {
        $corrupt[$mutation[0]][$mutation[1]] = true;
    } elseif ($mutation[2] === 'audit_tolerance') {
        $corrupt[$mutation[0]][$mutation[1]]['maximum_price_deviation_bps']['close'] = 500.0;
    } else {
        $corrupt[$mutation[0]][$mutation[1]] = '2026-07-15';
    }
    $rejected = false;
    try {
        TacticalSignalArtifactGuard::validateDataProvenance($corrupt, $contract, '2026-07-16', $guardSymbols);
    } catch (RuntimeException) {
        $rejected = true;
    }
    tacticalExpect($rejected, 'Corrupted end-to-end provenance must fail closed.');
}

$db = sys_get_temp_dir() . '/ftt-tactical-' . bin2hex(random_bytes(6)) . '.sqlite';
try {
    $repo = new TacticalPaperRepository($db);
    $repo->migrate();
    $identity = [
        'run_id' => 'hybrid-v4-paper-test',
        'profile' => 'causal-stock-rotation-hybrid-v4',
        'strategy_hash' => str_repeat('a', 64),
        'runtime_hash' => str_repeat('b', 64),
        'data_contract' => ['historical_feed' => 'sip', 'fresh_feed' => 'iex'],
        'live_review_not_before' => '2026-08-17',
    ];
    $allocations = [
        'dynamic_loo10' => 0.60,
        'qqq200_full' => 0.13333333333333333,
        'spy200_full' => 0.13333333333333333,
        'qqq150_ex_crypto' => 0.13333333333333333,
    ];
    $run = $repo->ensureRun($identity, $allocations);
    tacticalSame('transition', $run['status'], 'A new run must start behind the legacy transition gate.');
    $nonFlatRejected = false;
    try {
        $repo->activate($identity['run_id'], 25000.0, [
            'positions' => [['symbol' => 'TECL', 'qty' => 1]],
            'open_orders' => [],
            'adoption' => 'flat_account_only',
            'stable_for_seconds' => 120,
        ]);
    } catch (RuntimeException) {
        $nonFlatRejected = true;
    }
    tacticalExpect($nonFlatRejected, 'Non-flat broker state must not be double-counted as tactical cash.');
    $repo->activate($identity['run_id'], 25000.0, [
        'positions' => [],
        'open_orders' => [],
        'adoption' => 'flat_account_only',
        'stable_for_seconds' => 120,
    ]);
    tacticalSame('active', $repo->run($identity['run_id'])['status'], 'Flat verified account may activate.');
    $sleeves = $repo->sleeves($identity['run_id']);
    $capital = array_sum(array_map(static fn (array $row): float => (float) $row['cash'], $sleeves));
    tacticalExpect(abs($capital - 25000.0) < 0.00001, 'Actual activation equity must be allocated exactly once.');

    $intent = $planner->plan(
        $identity['run_id'],
        'dynamic_loo10',
        '2026-07-20',
        $baseTarget,
        800.0,
        200.0,
        [],
        1.20,
    )[0];
    tacticalSame(3, $intent['requested_qty'], 'Repository fixture must retain a valid deterministic order identity.');
    $stored = $repo->createIntent($intent);
    tacticalExpect($repo->markSubmitting((string) $stored['decision_id']), 'The first submission claimant must win.');
    tacticalExpect(!$repo->markSubmitting((string) $stored['decision_id']), 'A second submission claimant must lose the CAS.');
    $repo->applyBrokerOrder((string) $stored['decision_id'], [
        'id' => 'paper-order-1',
        'client_order_id' => $stored['client_order_id'],
        'symbol' => 'PANW',
        'side' => 'buy',
        'qty' => '3',
        'type' => 'market',
        'time_in_force' => 'opg',
        'extended_hours' => false,
        'status' => 'partially_filled',
        'filled_qty' => '2',
        'filled_avg_price' => '100',
        'submitted_at' => '2026-07-20T13:20:00Z',
    ]);
    $repo->applyBrokerOrder((string) $stored['decision_id'], [
        'id' => 'paper-order-1',
        'client_order_id' => $stored['client_order_id'],
        'symbol' => 'PANW',
        'side' => 'buy',
        'qty' => '3',
        'type' => 'market',
        'time_in_force' => 'opg',
        'extended_hours' => false,
        'status' => 'filled',
        'filled_qty' => '3',
        'filled_avg_price' => '110',
        'submitted_at' => '2026-07-20T13:20:00Z',
    ]);
    // Replaying the same cumulative broker snapshot must not apply cash/qty twice.
    $repo->applyBrokerOrder((string) $stored['decision_id'], [
        'id' => 'paper-order-1',
        'client_order_id' => $stored['client_order_id'],
        'symbol' => 'PANW',
        'side' => 'buy',
        'qty' => '3',
        'type' => 'market',
        'time_in_force' => 'opg',
        'extended_hours' => false,
        'status' => 'filled',
        'filled_qty' => '3',
        'filled_avg_price' => '110',
        'submitted_at' => '2026-07-20T13:20:00Z',
    ]);
    tacticalSame(3.0, $repo->expectedBrokerPositions($identity['run_id'])['PANW'], 'Cumulative partial fills must apply only their delta.');
    $dynamic = $repo->sleeves($identity['run_id'])['dynamic_loo10'];
    tacticalExpect(abs((float) $dynamic['cash'] - (15000.0 - 330.0)) < 0.00001, 'Sleeve cash must use cumulative fill notional exactly once.');
    $repo->markAmbiguous((string) $stored['decision_id'], 'must_not_regress');
    tacticalSame('filled', $repo->intent((string) $stored['decision_id'])['status'], 'Ambiguous recovery must not overwrite a terminal fill.');

    $epochDriftRejected = false;
    try {
        $repo->createIntent(array_replace($intent, [
            'decision_id' => hash('sha256', 'changed-size'),
            'client_order_id' => 'ftr4-' . substr(hash('sha256', 'changed-size'), 0, 32),
            'requested_qty' => 4,
        ]));
    } catch (RuntimeException) {
        $epochDriftRejected = true;
    }
    tacticalExpect($epochDriftRejected, 'A changed order body must not create a second intent in one target epoch.');

    $ambiguousTarget = array_replace($baseTarget, ['symbol' => 'AAPL']);
    $ambiguousIntent = $planner->plan(
        $identity['run_id'],
        'qqq150_ex_crypto',
        '2026-07-20',
        $ambiguousTarget,
        800.0,
        200.0,
        [],
        1.20,
    )[0];
    $ambiguousIntent = $repo->createIntent($ambiguousIntent);
    tacticalExpect($repo->markSubmitting((string) $ambiguousIntent['decision_id']), 'Ambiguous retry fixture must claim its first POST attempt.');
    $repo->markAmbiguous((string) $ambiguousIntent['decision_id'], 'simulated_timeout');
    tacticalSetPersistedIntentUpdatedAt(
        $db,
        (string) $ambiguousIntent['decision_id'],
        '2026-07-20T09:00:00-04:00',
    );
    $ambiguousIntent = $repo->intent((string) $ambiguousIntent['decision_id']);
    $absentGateway = new TacticalTestOrderGateway([null, null], null, true);
    $ambiguousReconciler = new TacticalAmbiguousIntentReconciler();
    $ambiguousRetryWindow = $window->resolve(
        '2026-07-20',
        new DateTimeImmutable('2026-07-20 09:03:00', $timezone),
        '2026-07-17',
        false,
    );
    $retry = $ambiguousReconciler->reconcile(
        $repo,
        $absentGateway,
        $ambiguousIntent,
        new DateTimeImmutable('2026-07-20 09:03:00', $timezone),
        $ambiguousRetryWindow,
        2,
        120,
    );
    tacticalSame('retry_ambiguous', $retry['outcome'], 'A 404 after the delay must release exactly one bounded retry.');
    tacticalSame(2, $absentGateway->lookupCount, 'Unknown retry must lookup before POST and once more after the POST exception.');
    tacticalSame(1, $absentGateway->submitCount, 'One recovery cycle may issue at most one POST.');
    tacticalSame(
        $ambiguousIntent['client_order_id'],
        $absentGateway->submittedBodies[0]['client_order_id'],
        'Ambiguous retry must reuse the exact deterministic client order ID.',
    );
    tacticalSame(2, (int) $retry['intent']['attempt_count'], 'Persisted attempt count must bound restart retries.');
    tacticalSetPersistedIntentUpdatedAt(
        $db,
        (string) $ambiguousIntent['decision_id'],
        '2026-07-20T09:00:00-04:00',
    );
    $exhaustedGateway = new TacticalTestOrderGateway([null], null, true);
    $exhausted = $ambiguousReconciler->reconcile(
        $repo,
        $exhaustedGateway,
        $repo->intent((string) $ambiguousIntent['decision_id']),
        new DateTimeImmutable('2026-07-20 09:05:00', $timezone),
        $window->resolve(
            '2026-07-20',
            new DateTimeImmutable('2026-07-20 09:05:00', $timezone),
            '2026-07-17',
            false,
        ),
        2,
        120,
    );
    tacticalSame('retry_exhausted', $exhausted['outcome'], 'Maximum ambiguous attempts must remain permanently fail-closed.');
    tacticalSame(0, $exhaustedGateway->submitCount, 'Exhausted ambiguity must never issue another POST.');
    tacticalExpect(str_starts_with((string) $exhausted['error_code'], 'ambiguous_retry_exhausted:'), 'Exhaustion must expose a stable status/Telegram error code.');

    $eventualFoundTarget = array_replace($baseTarget, [
        'signal_date' => '2026-07-21',
        'symbol' => 'MSFT',
    ]);
    $eventualFound = $planner->plan(
        $identity['run_id'],
        'spy200_full',
        '2026-07-22',
        $eventualFoundTarget,
        800.0,
        200.0,
        [],
        1.20,
    )[0];
    $eventualFound = $repo->createIntent($eventualFound);
    tacticalExpect($repo->markSubmitting((string) $eventualFound['decision_id']), 'Eventually-found fixture must claim its first POST attempt.');
    $repo->markAmbiguous((string) $eventualFound['decision_id'], 'simulated_disconnect');
    $foundOrder = [
        'id' => 'paper-eventually-found',
        'client_order_id' => $eventualFound['client_order_id'],
        'symbol' => 'MSFT',
        'side' => 'buy',
        'qty' => (string) $eventualFound['requested_qty'],
        'type' => 'market',
        'time_in_force' => 'opg',
        'extended_hours' => false,
        'status' => 'accepted',
        'filled_qty' => '0',
        'submitted_at' => '2026-07-22T13:20:00Z',
    ];
    $foundGateway = new TacticalTestOrderGateway([$foundOrder], null, true);
    $found = $ambiguousReconciler->reconcile(
        $repo,
        $foundGateway,
        $repo->intent((string) $eventualFound['decision_id']),
        new DateTimeImmutable('2026-07-22 09:25:00', $timezone),
        $window->resolve(
            '2026-07-22',
            new DateTimeImmutable('2026-07-22 09:25:00', $timezone),
            '2026-07-21',
            false,
        ),
        3,
        120,
    );
    tacticalSame('found_reconciled', $found['outcome'], 'An eventually visible client ID must reconcile instead of retrying.');
    tacticalSame(1, $foundGateway->lookupCount, 'Eventually-found recovery needs exactly one pre-submit lookup.');
    tacticalSame(0, $foundGateway->submitCount, 'A found broker order must never produce a duplicate POST.');
    tacticalSame('paper-eventually-found', $found['intent']['order_id'], 'Eventually-found order identity must persist for restart reconciliation.');

    // Reproduce a full two-cycle rotation. The first cycle may persist only
    // the opening-auction sell; after its full reconciliation, the next cycle
    // deterministically creates a dependency-bound DAY buy.
    $rotationTarget = array_replace($baseTarget, [
        'signal_date' => '2026-07-20',
        'symbol' => 'NVDA',
    ]);
    $rotationLegs = $planner->plan(
        $identity['run_id'],
        'dynamic_loo10',
        '2026-07-21',
        $rotationTarget,
        15000.0,
        300.0,
        ['PANW' => ['qty' => 3.0]],
        1.20,
    );
    tacticalSame(['sell', 'buy'], array_column($rotationLegs, 'side'), 'The repository rotation fixture requires both raw legs.');
    $firstCycle = $planner->prepareSequentialExecution($rotationLegs, $repo->intents($identity['run_id']));
    tacticalSame(['sell'], array_column($firstCycle, 'side'), 'First rotation cycle must persist only the sell.');
    $sellIntent = $repo->createIntent($firstCycle[0]);
    $originalOpgDecision = $sellIntent['decision_id'];
    $fallbackAtOpen = $window->resolve(
        '2026-07-21',
        new DateTimeImmutable('2026-07-21 09:30:00', $timezone),
        '2026-07-20',
        true,
    );
    $fallbackCycle = $planner->prepareSequentialExecution(
        $rotationLegs,
        $repo->intents($identity['run_id']),
        $fallbackAtOpen,
    );
    $sellIntent = $repo->createIntent($fallbackCycle[0]);
    tacticalExpect(
        $sellIntent['decision_id'] !== $originalOpgDecision
            && $repo->intent((string) $originalOpgDecision) === null,
        'A pristine missed OPG sell must atomically rebind, not coexist, as DAY.',
    );
    tacticalSame('day', $sellIntent['payload']['time_in_force'], 'Rebound sell must persist its actual DAY body.');
    tacticalExpect($repo->markSubmitting((string) $sellIntent['decision_id']), 'The rotation sell must be claimable while active.');
    $filledSell = $repo->applyBrokerOrder((string) $sellIntent['decision_id'], [
        'id' => 'paper-order-rotation-sell',
        'client_order_id' => $sellIntent['client_order_id'],
        'symbol' => 'PANW',
        'side' => 'sell',
        'qty' => '3',
        'type' => 'market',
        'time_in_force' => 'day',
        'extended_hours' => false,
        'status' => 'filled',
        'filled_qty' => '3',
        'filled_avg_price' => '111',
        'submitted_at' => '2026-07-20T13:20:00Z',
    ]);
    tacticalSame([], $repo->expectedBrokerPositions($identity['run_id']), 'A full sell must reconcile the old sleeve position to zero.');

    $rawReplacement = $planner->plan(
        $identity['run_id'],
        'dynamic_loo10',
        '2026-07-21',
        $rotationTarget,
        15003.0,
        300.0,
        [],
        1.20,
    );
    $secondCycle = $planner->prepareSequentialExecution($rawReplacement, $repo->intents($identity['run_id']));
    tacticalSame(1, count($secondCycle), 'Exactly one replacement buy must appear after the full sell.');
    tacticalSame('day', $secondCycle[0]['payload']['time_in_force'], 'The replacement must use the bounded DAY contract.');
    $rotationDayWindow = $window->resolve(
        '2026-07-21',
        new DateTimeImmutable('2026-07-21 09:30:00', $timezone),
        '2026-07-20',
        true,
    );
    tacticalExpect(
        $planner->isAllowedInWindow($secondCycle[0], $rotationDayWindow, [$filledSell]),
        'Persisted full sell reconciliation must authorize its exact DAY replacement.',
    );
    $dayBuy = $repo->createIntent($secondCycle[0]);
    tacticalExpect($repo->markSubmitting((string) $dayBuy['decision_id']), 'Dependency-gated DAY buy must be claimable after full sell.');
    $repo->applyBrokerOrder((string) $dayBuy['decision_id'], [
        'id' => 'paper-order-rotation-buy',
        'client_order_id' => $dayBuy['client_order_id'],
        'symbol' => 'NVDA',
        'side' => 'buy',
        'qty' => (string) $dayBuy['requested_qty'],
        'type' => 'market',
        'time_in_force' => 'day',
        'extended_hours' => false,
        'status' => 'filled',
        'filled_qty' => (string) $dayBuy['requested_qty'],
        'filled_avg_price' => '300',
        'submitted_at' => '2026-07-20T13:30:01Z',
    ]);
    tacticalSame(
        (float) $dayBuy['requested_qty'],
        $repo->expectedBrokerPositions($identity['run_id'])['NVDA'],
        'A DAY fill must reconcile exactly once under its bound body identity.',
    );

    foreach (['filled', 'canceled', 'cancelled', 'expired', 'rejected', 'done_for_day'] as $terminalStatus) {
        tacticalExpect(TacticalPaperRepository::isTerminalIncompleteIntent([
            'status' => $terminalStatus,
            'requested_qty' => 2.0,
            'cumulative_filled_qty' => 1.0,
        ]), 'Every incomplete terminal status must classify as fail-closed.');
    }
    tacticalExpect(!TacticalPaperRepository::isTerminalIncompleteIntent([
        'status' => 'canceled',
        'requested_qty' => 2.0,
        'cumulative_filled_qty' => 2.0,
    ]), 'A terminal snapshot with the full cumulative quantity is not incomplete.');

    // Seed a second sleeve and pre-create an unrelated planned entry. A
    // partial terminal sell must atomically pause the whole run, prevent that
    // planned intent from being claimed, and remain paused after restart.
    $spyEntryTarget = array_replace($baseTarget, ['symbol' => 'SPY']);
    $spyEntry = $planner->plan(
        $identity['run_id'],
        'qqq200_full',
        '2026-07-20',
        $spyEntryTarget,
        400.0,
        100.0,
        [],
        1.20,
    )[0];
    $spyStored = $repo->createIntent($spyEntry);
    tacticalExpect($repo->markSubmitting((string) $spyStored['decision_id']), 'The terminal fixture entry must submit while active.');
    $repo->applyBrokerOrder((string) $spyStored['decision_id'], [
        'id' => 'paper-order-spy-entry',
        'client_order_id' => $spyStored['client_order_id'],
        'symbol' => 'SPY',
        'side' => 'buy',
        'qty' => '3',
        'type' => 'market',
        'time_in_force' => 'opg',
        'extended_hours' => false,
        'status' => 'filled',
        'filled_qty' => '3',
        'filled_avg_price' => '100',
        'submitted_at' => '2026-07-20T13:20:00Z',
    ]);
    $plannedBlockedAfterPause = $planner->plan(
        $identity['run_id'],
        'spy200_full',
        '2026-07-20',
        array_replace($baseTarget, ['symbol' => 'QQQ']),
        800.0,
        200.0,
        [],
        1.20,
    )[0];
    $plannedBlockedAfterPause = $repo->createIntent($plannedBlockedAfterPause);
    $cashTarget = array_replace($baseTarget, [
        'signal_date' => '2026-07-20',
        'action' => 'exit_to_cash',
        'symbol' => null,
        'gross' => 0.0,
    ]);
    $spyExit = $planner->plan(
        $identity['run_id'],
        'qqq200_full',
        '2026-07-21',
        $cashTarget,
        400.0,
        0.0,
        ['SPY' => ['qty' => 3.0]],
        1.20,
    )[0];
    $spyExit = $repo->createIntent($spyExit);
    tacticalExpect($repo->markSubmitting((string) $spyExit['decision_id']), 'The terminal fixture sell must submit while active.');
    $terminalSell = $repo->applyBrokerOrder((string) $spyExit['decision_id'], [
        'id' => 'paper-order-spy-exit',
        'client_order_id' => $spyExit['client_order_id'],
        'symbol' => 'SPY',
        'side' => 'sell',
        'qty' => '3',
        'type' => 'market',
        'time_in_force' => 'opg',
        'extended_hours' => false,
        'status' => 'canceled',
        'filled_qty' => '1',
        'filled_avg_price' => '101',
        'submitted_at' => '2026-07-20T13:20:00Z',
    ]);
    tacticalExpect(TacticalPaperRepository::isTerminalIncompleteIntent($terminalSell), 'Integrated partial cancel must be terminal-incomplete.');
    tacticalSame('paused', $repo->run($identity['run_id'])['status'], 'Partial terminal sell must atomically pause the run.');
    tacticalSame(1, count($repo->terminalIncompleteIntents($identity['run_id'])), 'The pause cause must remain queryable for recovery.');
    tacticalExpect(
        !$repo->markSubmitting((string) $plannedBlockedAfterPause['decision_id']),
        'A pre-existing planned buy must not be claimable after fail-closed pause.',
    );
    tacticalSame(2.0, $repo->positions($identity['run_id'])['qqq200_full']['SPY']['qty'], 'Only the confirmed partial sell delta may change the ledger.');

    $remainingSell = $planner->plan(
        $identity['run_id'],
        'qqq200_full',
        '2026-07-21',
        $cashTarget,
        400.0,
        0.0,
        ['SPY' => ['qty' => 2.0]],
        1.20,
    );
    $terminalRecoveryWindow = $window->resolve(
        '2026-07-21',
        new DateTimeImmutable('2026-07-21 10:00:00', $timezone),
        '2026-07-20',
        true,
    );
    $terminalRecovery = $planner->prepareSequentialExecution(
        $remainingSell,
        $repo->intents($identity['run_id']),
        $terminalRecoveryWindow,
    );
    tacticalSame(1, count($terminalRecovery), 'A paused partial sell may create exactly one bounded recovery sell.');
    tacticalSame(true, $terminalRecovery[0]['payload']['terminal_recovery_sell'], 'Recovery identity must explicitly bind terminal causality.');
    tacticalSame(
        [$terminalSell['decision_id']],
        $terminalRecovery[0]['payload']['required_terminal_decision_ids'],
        'Recovery sell must bind the exact incomplete terminal decision.',
    );
    $terminalRecovery = $repo->createIntent($terminalRecovery[0]);
    tacticalExpect($repo->markSubmitting((string) $terminalRecovery['decision_id']), 'Only the bound recovery sell may submit while paused.');
    $repo->applyBrokerOrder((string) $terminalRecovery['decision_id'], [
        'id' => 'paper-order-spy-recovery',
        'client_order_id' => $terminalRecovery['client_order_id'],
        'symbol' => 'SPY',
        'side' => 'sell',
        'qty' => '2',
        'type' => 'market',
        'time_in_force' => 'day',
        'extended_hours' => false,
        'status' => 'filled',
        'filled_qty' => '2',
        'filled_avg_price' => '102',
        'submitted_at' => '2026-07-21T14:00:00Z',
    ]);
    tacticalSame('paused', $repo->run($identity['run_id'])['status'], 'Successful de-risking must remain paused until review.');
    tacticalExpect(
        !isset($repo->positions($identity['run_id'])['qqq200_full']['SPY']),
        'Only the confirmed recovery fill may remove the remaining ledger position.',
    );
    tacticalSame(1, count($repo->terminalIncompleteIntents($identity['run_id'])), 'Original divergence must remain latched after de-risking.');

    $newRiskRejected = false;
    try {
        $repo->createIntent($planner->plan(
            $identity['run_id'],
            'qqq150_ex_crypto',
            '2026-07-21',
            array_replace($baseTarget, ['signal_date' => '2026-07-20', 'symbol' => 'AAPL']),
            800.0,
            200.0,
            [],
            1.20,
        )[0]);
    } catch (RuntimeException) {
        $newRiskRejected = true;
    }
    tacticalExpect($newRiskRejected, 'A paused run must reject every new risk intent.');

    unset($repo);
    $repo = new TacticalPaperRepository($db);
    $repo->migrate();
    tacticalSame('paused', $repo->run($identity['run_id'])['status'], 'Restart must preserve the fail-closed pause.');
    tacticalSame(1, count($repo->terminalIncompleteIntents($identity['run_id'])), 'Restart must deterministically recover the terminal cause.');
    tacticalExpect(
        !$repo->markSubmitting((string) $plannedBlockedAfterPause['decision_id']),
        'Restart must not submit risk that was planned before the pause.',
    );

    $repo->queueNotification('runtime-test', 'persistent message');
    tacticalSame(1, count($repo->pendingNotifications()), 'Telegram events must persist before delivery.');
    $repo->markNotificationAttempted('runtime-test', 60);
    $repo->markNotificationDelivered('runtime-test');
    tacticalExpect($repo->notificationDelivered('runtime-test'), 'Telegram outbox must latch successful delivery.');

    $driftRejected = false;
    try {
        $repo->ensureRun(array_replace($identity, ['runtime_hash' => str_repeat('c', 64)]), $allocations);
    } catch (RuntimeException) {
        $driftRejected = true;
    }
    tacticalExpect($driftRejected, 'Runtime identity drift must fail closed on restart.');
} finally {
    @unlink($db);
    @unlink($db . '-wal');
    @unlink($db . '-shm');
}

echo "tactical paper runtime tests passed\n";
