<?php

declare(strict_types=1);

namespace FulltimeTrading\Trading;

use FulltimeTrading\Storage\TacticalPaperRepository;

/**
 * Recovers an unknown POST outcome without ever changing the immutable order
 * body or client_order_id. Every retry is preceded by a broker lookup, is
 * delayed, and is bounded by the persisted attempt counter.
 */
final class TacticalAmbiguousIntentReconciler
{
    /**
     * @param array<string,mixed> $intent
     * @return array{outcome:string,intent:array<string,mixed>,error_code:?string}
     */
    public function reconcile(
        TacticalPaperRepository $repo,
        TacticalOrderGateway $gateway,
        array $intent,
        \DateTimeImmutable $now,
        array $executionWindow,
        int $maximumAttempts = 3,
        int $retryDelaySeconds = 120,
        int $missedWindowConfirmations = 2,
    ): array {
        TacticalRotationPaperPlanner::assertExecutionIdentity($intent);
        $status = strtolower((string) ($intent['status'] ?? ''));
        if (!in_array($status, ['submitting', 'ambiguous', 'ambiguous_missed'], true)) {
            throw new \InvalidArgumentException('Ambiguous reconciler requires submitting/ambiguous intent.');
        }
        if ($maximumAttempts < 1 || $maximumAttempts > 10
            || $retryDelaySeconds < 30
            || $missedWindowConfirmations < 2
            || $missedWindowConfirmations > 10) {
            throw new \InvalidArgumentException('Ambiguous retry policy is outside its safety envelope.');
        }

        // Mandatory pre-submit lookup. If Alpaca already has the deterministic
        // client ID, applying that order is the only permitted action.
        $existing = $gateway->orderByClientOrderId((string) $intent['client_order_id']);
        if ($existing !== null) {
            return [
                'outcome' => 'found_reconciled',
                'intent' => $repo->applyBrokerOrder((string) $intent['decision_id'], $existing),
                'error_code' => null,
            ];
        }
        if ($status === 'ambiguous_missed') {
            return [
                'outcome' => 'window_missed_lookup_only',
                'intent' => $intent,
                'error_code' => 'ambiguous_window_missed:' . substr((string) $intent['decision_id'], 0, 12),
            ];
        }
        $intent = $repo->recordAmbiguousNotFound((string) $intent['decision_id']);
        if ($status === 'submitting') {
            $repo->markAmbiguous((string) $intent['decision_id'], 'broker_order_not_found_after_restart');
            $intent = $repo->intent((string) $intent['decision_id']) ?? $intent;
        }

        if (!$this->retryAllowedInWindow($repo, $intent, $executionWindow)) {
            if ((int) ($intent['payload']['ambiguous_not_found_count'] ?? 0) >= $missedWindowConfirmations) {
                $errorCode = 'ambiguous_window_missed:' . substr((string) $intent['decision_id'], 0, 12);

                return [
                    'outcome' => 'window_missed_latched',
                    'intent' => $repo->markAmbiguousWindowMissed(
                        (string) $intent['decision_id'],
                        $errorCode,
                    ),
                    'error_code' => $errorCode,
                ];
            }

            return ['outcome' => 'window_closed_lookup_only', 'intent' => $intent, 'error_code' => null];
        }
        if ($status === 'submitting') {
            return ['outcome' => 'marked_ambiguous', 'intent' => $intent, 'error_code' => null];
        }

        try {
            $updatedAt = new \DateTimeImmutable((string) ($intent['updated_at'] ?? ''));
        } catch (\Throwable) {
            throw new \RuntimeException('Ambiguous intent has invalid recovery timestamp.');
        }
        if ($now->getTimestamp() - $updatedAt->getTimestamp() < $retryDelaySeconds) {
            return ['outcome' => 'retry_wait', 'intent' => $intent, 'error_code' => null];
        }
        if ((int) ($intent['attempt_count'] ?? 0) >= $maximumAttempts) {
            return [
                'outcome' => 'retry_exhausted',
                'intent' => $intent,
                'error_code' => 'ambiguous_retry_exhausted:' . substr((string) $intent['decision_id'], 0, 12),
            ];
        }
        if (!$repo->markSubmitting((string) $intent['decision_id'])) {
            $current = $repo->intent((string) $intent['decision_id']) ?? $intent;

            return [
                'outcome' => 'retry_claim_blocked',
                'intent' => $current,
                'error_code' => 'ambiguous_retry_claim_blocked:' . substr((string) $intent['decision_id'], 0, 12),
            ];
        }
        $claimed = $repo->intent((string) $intent['decision_id']) ?? $intent;
        $body = self::orderBody($claimed);
        try {
            $submitted = $gateway->submitOrder($body);

            return [
                'outcome' => 'retry_submitted',
                'intent' => $repo->applyBrokerOrder((string) $intent['decision_id'], $submitted),
                'error_code' => null,
            ];
        } catch (\Throwable $submitError) {
            // The POST may have reached Alpaca despite the local exception.
            // A second lookup closes that race without a different client ID.
            try {
                $appeared = $gateway->orderByClientOrderId((string) $intent['client_order_id']);
                if ($appeared !== null) {
                    return [
                        'outcome' => 'retry_found_after_submit_error',
                        'intent' => $repo->applyBrokerOrder((string) $intent['decision_id'], $appeared),
                        'error_code' => null,
                    ];
                }
                $repo->recordAmbiguousNotFound((string) $intent['decision_id']);
            } catch (\Throwable) {
                // Keep the same ambiguous identity for the next bounded cycle.
            }
            $repo->markAmbiguous(
                (string) $intent['decision_id'],
                'retry_unknown_' . substr(hash('sha256', $submitError->getMessage()), 0, 12),
            );

            return [
                'outcome' => 'retry_ambiguous',
                'intent' => $repo->intent((string) $intent['decision_id']) ?? $claimed,
                'error_code' => null,
            ];
        }
    }

    /** @param array<string,mixed> $intent @param array<string,mixed> $window */
    private function retryAllowedInWindow(
        TacticalPaperRepository $repo,
        array $intent,
        array $window,
    ): bool {
        if (!hash_equals(
            (string) ($intent['scheduled_session'] ?? ''),
            (string) ($window['scheduled_session'] ?? ''),
        )) {
            return false;
        }
        $dependencies = [];
        foreach ((array) ($intent['payload']['required_exit_decision_ids'] ?? []) as $decisionId) {
            $dependency = $repo->intent((string) $decisionId);
            if ($dependency === null) {
                return false;
            }
            $dependencies[] = $dependency;
        }

        return (new TacticalRotationPaperPlanner())->isAllowedInWindow($intent, $window, $dependencies);
    }

    /** @param array<string,mixed> $intent @return array<string,mixed> */
    private static function orderBody(array $intent): array
    {
        return [
            'symbol' => (string) $intent['symbol'],
            'qty' => (string) (int) $intent['requested_qty'],
            'side' => (string) $intent['side'],
            'type' => 'market',
            'time_in_force' => strtolower((string) ($intent['payload']['time_in_force'] ?? '')),
            'client_order_id' => (string) $intent['client_order_id'],
        ];
    }
}
