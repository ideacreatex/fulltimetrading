<?php

declare(strict_types=1);

namespace FulltimeTrading\Trading;

use FulltimeTrading\Storage\TacticalPaperRepository;

/**
 * Keeps an active paper run fail-closed when its executable runtime changes,
 * while allowing the caller to enter a separate notification-only branch.
 */
final class TacticalRunIdentityGate
{
    /**
     * @param array{
     *   run_id:string,
     *   profile:string,
     *   strategy_hash:string,
     *   runtime_hash:string,
     *   data_contract:array<string,mixed>,
     *   live_review_not_before:string
     * } $identity
     * @param array<string,float> $allocations
     * @return array{
     *   run:array<string,mixed>,
     *   execution_verified:bool,
     *   notification_only:bool,
     *   error_code:?string
     * }
     */
    public static function resolve(
        TacticalPaperRepository $repo,
        array $identity,
        array $allocations,
    ): array {
        try {
            return [
                'run' => $repo->ensureRun($identity, $allocations),
                'execution_verified' => true,
                'notification_only' => false,
                'error_code' => null,
            ];
        } catch (\RuntimeException $e) {
            if (!hash_equals('Tactical run identity drift: runtime_hash', $e->getMessage())) {
                throw $e;
            }
            $run = $repo->run((string) $identity['run_id']);
            if ($run === null) {
                throw $e;
            }
            // ensureRun() checks runtime_hash before sleeve definitions. Repeat
            // the remaining read-only identity assertion so this branch is
            // available only when runtime_hash is the sole drift.
            $repo->assertSleeveDefinitions((string) $identity['run_id'], $allocations);

            return [
                'run' => $run,
                'execution_verified' => false,
                'notification_only' => true,
                'error_code' => 'runtime_identity_drift:runtime_hash',
            ];
        }
    }
}
