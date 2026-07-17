<?php

declare(strict_types=1);

namespace FulltimeTrading\Trading;

/**
 * Decides when the legacy author monitor must yield symbol ownership to the
 * tactical sleeve ledger. A pause freezes new risk; it does not transfer an
 * already activated portfolio back to the legacy monitor.
 */
final class TacticalLegacyOwnershipGuard
{
    /** @param ?array<string,mixed> $run */
    public static function requiresProtection(?array $run): bool
    {
        if ($run === null) {
            return false;
        }
        $status = strtolower(trim((string) ($run['status'] ?? '')));
        $activatedAt = trim((string) ($run['activated_at'] ?? ''));
        if ($status === 'transition') {
            if ($activatedAt !== '') {
                throw new \RuntimeException('A transition tactical run unexpectedly has post-activation state.');
            }

            return false;
        }
        if (in_array($status, ['active', 'paused'], true)) {
            if ($activatedAt === '') {
                throw new \RuntimeException('A post-activation tactical run is missing its activation timestamp.');
            }

            return true;
        }

        throw new \RuntimeException('Unknown tactical ownership state.');
    }
}
