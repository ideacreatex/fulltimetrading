<?php

declare(strict_types=1);

use FulltimeTrading\Storage\TacticalPaperRepository;
use FulltimeTrading\Trading\TacticalRunIdentityGate;

require __DIR__ . '/../bootstrap.php';

function identityGateExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$database = sys_get_temp_dir() . '/ftt-runtime-identity-gate-' . bin2hex(random_bytes(6)) . '.sqlite';
try {
    $repo = new TacticalPaperRepository($database);
    $repo->migrate();
    $identity = [
        'run_id' => 'runtime-identity-gate-test',
        'profile' => 'causal-stock-rotation-hybrid-v4',
        'strategy_hash' => str_repeat('a', 64),
        'runtime_hash' => str_repeat('b', 64),
        'data_contract' => ['historical_feed' => 'sip', 'fresh_feed' => 'iex'],
        'live_review_not_before' => '2026-09-18',
    ];
    $allocations = [
        'dynamic_loo10' => 0.60,
        'qqq200_full' => 0.13333333333333333,
        'spy200_full' => 0.13333333333333333,
        'qqq150_ex_crypto' => 0.13333333333333333,
    ];

    $verified = TacticalRunIdentityGate::resolve($repo, $identity, $allocations);
    identityGateExpect($verified['execution_verified'], 'Matching runtime identity must allow execution.');
    identityGateExpect(!$verified['notification_only'], 'Matching runtime identity must not enter notification-only mode.');

    $repo->activate($identity['run_id'], 25000.0, [
        'positions' => [],
        'open_orders' => [],
        'adoption' => 'flat_account_only',
        'stable_for_seconds' => 120,
    ]);
    $driftedIdentity = array_replace($identity, ['runtime_hash' => str_repeat('c', 64)]);
    $storedBeforeDrift = $repo->run($identity['run_id']);
    $blocked = TacticalRunIdentityGate::resolve($repo, $driftedIdentity, $allocations);
    identityGateExpect(!$blocked['execution_verified'], 'Runtime drift must keep execution fail-closed.');
    identityGateExpect($blocked['notification_only'], 'Exact runtime drift must enter notification-only mode.');
    identityGateExpect(
        $blocked['error_code'] === 'runtime_identity_drift:runtime_hash',
        'Runtime drift must expose a stable degraded error code.',
    );
    identityGateExpect(
        $repo->run($identity['run_id'])['runtime_hash'] === str_repeat('b', 64),
        'Notification-only mode must never rewrite the stored runtime hash.',
    );
    identityGateExpect(
        $repo->run($identity['run_id']) === $storedBeforeDrift,
        'Notification-only identity resolution must leave the persisted run byte-for-byte unchanged.',
    );
    identityGateExpect($repo->intents($identity['run_id']) === [], 'Identity resolution must not create intents.');

    $combinedDriftRejected = false;
    try {
        TacticalRunIdentityGate::resolve(
            $repo,
            $driftedIdentity,
            [
                'dynamic_loo10' => 0.50,
                'qqq200_full' => 1.0 / 6.0,
                'spy200_full' => 1.0 / 6.0,
                'qqq150_ex_crypto' => 1.0 / 6.0,
            ],
        );
    } catch (RuntimeException $e) {
        $combinedDriftRejected = str_starts_with(
            $e->getMessage(),
            'Tactical run sleeve allocation drift:',
        );
    }
    identityGateExpect(
        $combinedDriftRejected,
        'Runtime plus sleeve drift must remain fatal instead of entering notification-only mode.',
    );
    identityGateExpect(
        $repo->run($identity['run_id']) === $storedBeforeDrift,
        'Combined identity drift rejection must not mutate the persisted run.',
    );

    $repo->queueNotification('portfolio-open:test:2026-08-26:v3', 'open');
    $repo->queueNotification('runtime-error:test', 'runtime');
    $allowedPending = $repo->pendingNotificationsForKeys(['portfolio-open:test:2026-08-26:v3']);
    identityGateExpect(count($allowedPending) === 1, 'Allowlisted notification lookup must return exactly the requested key.');
    identityGateExpect(
        $allowedPending[0]['notification_key'] === 'portfolio-open:test:2026-08-26:v3',
        'Notification-only delivery lookup must not expose unrelated pending rows.',
    );

    $strategyDriftRejected = false;
    try {
        TacticalRunIdentityGate::resolve(
            $repo,
            array_replace($identity, ['strategy_hash' => str_repeat('d', 64)]),
            $allocations,
        );
    } catch (RuntimeException $e) {
        $strategyDriftRejected = $e->getMessage() === 'Tactical run identity drift: strategy_hash';
    }
    identityGateExpect($strategyDriftRejected, 'Non-runtime identity drift must remain fatal.');
} finally {
    @unlink($database);
}

echo "tactical run identity gate tests passed\n";
