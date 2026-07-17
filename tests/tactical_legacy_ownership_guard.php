<?php

declare(strict_types=1);

use FulltimeTrading\Trading\TacticalLegacyOwnershipGuard;

require dirname(__DIR__) . '/bootstrap.php';

function ownershipGuardExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function ownershipGuardExpectFailure(?array $run, string $message): void
{
    try {
        TacticalLegacyOwnershipGuard::requiresProtection($run);
    } catch (RuntimeException) {
        return;
    }

    throw new RuntimeException($message);
}

ownershipGuardExpect(
    TacticalLegacyOwnershipGuard::requiresProtection(null) === false,
    'A missing tactical run must leave the pre-activation legacy portfolio unchanged.',
);
ownershipGuardExpect(
    TacticalLegacyOwnershipGuard::requiresProtection([
        'status' => 'transition',
        'activated_at' => null,
    ]) === false,
    'A genuine pre-activation transition must remain under legacy ownership.',
);
ownershipGuardExpect(
    TacticalLegacyOwnershipGuard::requiresProtection([
        'status' => 'active',
        'activated_at' => '2026-07-20T13:00:00+00:00',
    ]) === true,
    'An active tactical run must protect hybrid-owned symbols from the legacy monitor.',
);
ownershipGuardExpect(
    TacticalLegacyOwnershipGuard::requiresProtection([
        'status' => 'paused',
        'activated_at' => '2026-07-20T13:00:00+00:00',
    ]) === true,
    'A paused tactical run must retain ownership of its existing hybrid positions.',
);
ownershipGuardExpectFailure(
    ['status' => 'transition', 'activated_at' => '2026-07-20T13:00:00+00:00'],
    'An inconsistent post-activation transition must fail closed.',
);
ownershipGuardExpectFailure(
    ['status' => 'paused', 'activated_at' => null],
    'A paused run without activation evidence must fail closed.',
);
ownershipGuardExpectFailure(
    ['status' => 'unexpected', 'activated_at' => '2026-07-20T13:00:00+00:00'],
    'An unknown tactical ownership state must fail closed.',
);

echo "tactical legacy ownership guard tests passed\n";
