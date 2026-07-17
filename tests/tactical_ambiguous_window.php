<?php

declare(strict_types=1);

use FulltimeTrading\Storage\TacticalPaperRepository;
use FulltimeTrading\Trading\TacticalAmbiguousIntentReconciler;
use FulltimeTrading\Trading\TacticalOrderGateway;
use FulltimeTrading\Trading\TacticalRotationExecutionWindow;
use FulltimeTrading\Trading\TacticalRotationPaperPlanner;

require dirname(__DIR__) . '/bootstrap.php';

function ambiguousWindowExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class AmbiguousWindowNullGateway implements TacticalOrderGateway
{
    public int $lookupCount = 0;
    public int $submitCount = 0;

    public function orderByClientOrderId(string $clientOrderId): ?array
    {
        $this->lookupCount++;

        return null;
    }

    public function submitOrder(array $order): array
    {
        $this->submitCount++;
        throw new RuntimeException('A closed-window ambiguous order must never POST.');
    }
}

$db = sys_get_temp_dir() . '/ftt-ambiguous-window-' . bin2hex(random_bytes(6)) . '.sqlite';
try {
    $repo = new TacticalPaperRepository($db);
    $repo->migrate();
    $runId = 'ambiguous-window-run';
    $allocations = [
        'dynamic_loo10' => 0.60,
        'qqq200_full' => 0.13333333333333333,
        'spy200_full' => 0.13333333333333333,
        'qqq150_ex_crypto' => 0.13333333333333333,
    ];
    $repo->ensureRun([
        'run_id' => $runId,
        'profile' => 'causal-stock-rotation-hybrid-v4',
        'strategy_hash' => str_repeat('a', 64),
        'runtime_hash' => str_repeat('b', 64),
        'data_contract' => ['feed' => 'test'],
        'live_review_not_before' => '2026-08-17',
    ], $allocations);
    $repo->activate($runId, 25000.0, [
        'positions' => [],
        'open_orders' => [],
        'adoption' => 'flat_account_only',
        'stable_for_seconds' => 120,
    ]);

    $planner = new TacticalRotationPaperPlanner();
    $target = [
        'signal_date' => '2026-07-17',
        'execution' => 'next_session_open',
        'rebalance_due_next_session' => true,
        'action' => 'rebalance',
        'symbol' => 'AAPL',
        'gross' => 0.75,
    ];
    $opg = $planner->plan(
        $runId,
        'dynamic_loo10',
        '2026-07-20',
        $target,
        1000.0,
        100.0,
        [],
        1.20,
    )[0];
    $opg = $repo->createIntent($opg);
    ambiguousWindowExpect($repo->markSubmitting((string) $opg['decision_id']), 'OPG fixture must claim its first attempt.');
    $repo->markAmbiguous((string) $opg['decision_id'], 'simulated_disconnect');

    $day = $planner->plan(
        $runId,
        'qqq200_full',
        '2026-07-20',
        array_replace($target, ['symbol' => 'MSFT']),
        1000.0,
        100.0,
        [],
        1.20,
    )[0];
    $day = $planner->bindExecutionContract($day, 'day', [str_repeat('c', 64)]);
    $day = $repo->createIntent($day);
    ambiguousWindowExpect($repo->markSubmitting((string) $day['decision_id']), 'DAY fixture must claim its first attempt.');
    $repo->markAmbiguous((string) $day['decision_id'], 'simulated_disconnect');

    $timezone = new DateTimeZone('America/New_York');
    $windows = new TacticalRotationExecutionWindow('09:15', '09:27', '19:05', '09:32', '15:45');
    $reconciler = new TacticalAmbiguousIntentReconciler();
    $opgGateway = new AmbiguousWindowNullGateway();
    $firstClosed = $reconciler->reconcile(
        $repo,
        $opgGateway,
        array_replace($repo->intent((string) $opg['decision_id']), ['updated_at' => '2026-07-20T09:00:00-04:00']),
        new DateTimeImmutable('2026-07-20 09:29:00', $timezone),
        $windows->resolve(
            '2026-07-20',
            new DateTimeImmutable('2026-07-20 09:29:00', $timezone),
            '2026-07-17',
            false,
        ),
        3,
        120,
        2,
    );
    ambiguousWindowExpect($firstClosed['outcome'] === 'window_closed_lookup_only', '09:29 OPG must lookup-only on first confirmed 404.');
    ambiguousWindowExpect($opgGateway->submitCount === 0, '09:29 OPG ambiguity must never POST.');
    $missedOpg = $reconciler->reconcile(
        $repo,
        $opgGateway,
        $repo->intent((string) $opg['decision_id']),
        new DateTimeImmutable('2026-07-21 19:05:00', $timezone),
        $windows->resolve(
            '2026-07-20',
            new DateTimeImmutable('2026-07-21 19:05:00', $timezone),
            '2026-07-17',
            false,
        ),
        3,
        120,
        2,
    );
    ambiguousWindowExpect($missedOpg['outcome'] === 'window_missed_latched', 'Repeated 404 after the original session must latch missed OPG.');
    ambiguousWindowExpect($opgGateway->submitCount === 0, 'Next-day 19:05 OPG ambiguity must remain lookup-only.');
    ambiguousWindowExpect($missedOpg['intent']['status'] === 'ambiguous_missed', 'Missed ambiguity must retain a lookup-reconcilable fail-closed status.');
    ambiguousWindowExpect($repo->run($runId)['status'] === 'paused', 'Missed ambiguous execution must pause the run.');

    $dayGateway = new AmbiguousWindowNullGateway();
    $dayClosed = $reconciler->reconcile(
        $repo,
        $dayGateway,
        array_replace($repo->intent((string) $day['decision_id']), ['updated_at' => '2026-07-20T09:00:00-04:00']),
        new DateTimeImmutable('2026-07-20 09:33:00', $timezone),
        $windows->resolve(
            '2026-07-20',
            new DateTimeImmutable('2026-07-20 09:33:00', $timezone),
            '2026-07-17',
            true,
        ),
        3,
        120,
        2,
    );
    ambiguousWindowExpect($dayClosed['outcome'] === 'window_closed_lookup_only', 'DAY buy after 09:32 must remain lookup-only.');
    ambiguousWindowExpect($dayGateway->submitCount === 0, 'DAY buy ambiguity after 09:32 must never POST.');
} finally {
    @unlink($db);
    @unlink($db . '-wal');
    @unlink($db . '-shm');
}

echo "tactical ambiguous window tests passed\n";
