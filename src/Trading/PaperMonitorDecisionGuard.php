<?php

declare(strict_types=1);

namespace FulltimeTrading\Trading;

final class PaperMonitorDecisionGuard
{
    private const EVENT_KEY = 'paper_exit_event';
    private const PARTIAL_EVENT_KEY = 'paper_partial_event';
    private const EXIT_ACTIONS = ['close_stop', 'close_model_missing'];
    private const RETRYABLE_TERMINAL_STATUSES = ['canceled', 'cancelled', 'expired', 'rejected', 'replaced'];

    /** @param array<string, mixed> $state @param array<string, mixed> $decision @return array<string, mixed> */
    public static function ensureCloseStopEvent(array $state, array $decision, \DateTimeImmutable $now): array
    {
        $action = (string) ($decision['action'] ?? '');
        if (!in_array($action, self::EXIT_ACTIONS, true)) {
            return $state;
        }

        $fingerprint = self::closeStopFingerprint($state, $decision);
        $event = self::event($state);
        if (is_array($event) && hash_equals((string) ($event['fingerprint'] ?? ''), $fingerprint)) {
            $event['last_detected_at'] = $now->format(\DateTimeInterface::ATOM);
            $event['requested_qty'] ??= max(0.0, (float) ($decision['qty'] ?? $state['qty'] ?? 0.0));
            if (self::mayExpandUnsubmittedExitEvent($event)) {
                $reconciledPositionQty = self::positionQtyAfterKnownPartialFills(
                    $state,
                    max(0.0, (float) ($state['qty'] ?? 0.0)),
                );
                // Before the first external attempt there is no fill ambiguity: grow
                // the reservation with a real add-on/split snapshot while preserving
                // the stable event/client id. Once any attempt/fill exists, requested_qty
                // is immutable and remainingExitQty deducts known fills from that baseline.
                $previousRequestedQty = max(0.0, (float) ($event['requested_qty'] ?? 0.0));
                $event['requested_qty'] = max(
                    $previousRequestedQty,
                    max(0.0, (float) ($decision['qty'] ?? 0.0)),
                    $reconciledPositionQty,
                );
                if ((float) $event['requested_qty'] > $previousRequestedQty + 0.0000001) {
                    // Refresh a previously persisted market-closed/dry-run plan with
                    // the expanded quantity before it can be submitted.
                    $event['planned_status'] = null;
                    $event['planned_attempt'] = null;
                }
            }

            return self::withEvent($state, $event);
        }

        if (is_array($event) && (
            self::phaseBlocksReplacement((string) ($event['phase'] ?? ''))
            || self::knownFilledQty($event) > 0.0000001
        )) {
            $event['last_detected_at'] = $now->format(\DateTimeInterface::ATOM);
            $event['latest_detected_fingerprint'] = $fingerprint;
            $event['requested_qty'] ??= max(0.0, (float) ($decision['qty'] ?? $state['qty'] ?? 0.0));

            return self::withEvent($state, $event);
        }

        $eventId = hash('sha256', $fingerprint . '|' . $now->format(\DateTimeInterface::ATOM));
        $event = [
            'event_id' => $eventId,
            'action' => $action,
            'fingerprint' => $fingerprint,
            'first_detected_at' => $now->format(\DateTimeInterface::ATOM),
            'last_detected_at' => $now->format(\DateTimeInterface::ATOM),
            'phase' => 'pending',
            'attempt' => 1,
            'requested_qty' => max(0.0, (float) ($decision['qty'] ?? $state['qty'] ?? 0.0)),
            'filled_qty_by_attempt' => [],
            'external_filled_qty_by_order' => [],
            'client_order_id' => self::clientOrderIdForAttempt($state, $eventId, $now, 1),
            'order_id' => null,
            'order_status' => null,
            'retry_after' => null,
            'reconcile_after' => null,
            'last_error' => null,
            'trigger_mode' => (string) ($decision['stop_trigger_mode'] ?? ''),
            'trigger_price' => isset($decision['stop_trigger_price']) ? (float) $decision['stop_trigger_price'] : null,
            'trigger_bar_date' => (string) ($decision['stop_trigger_bar_date'] ?? ''),
            'alerts' => [],
        ];
        $state['last_action'] = $action . '_pending';

        return self::withEvent($state, $event);
    }

    /** @param array<string, mixed> $state @return array<string, mixed>|null */
    public static function event(array $state): ?array
    {
        $payload = is_array($state['payload'] ?? null) ? $state['payload'] : [];
        $event = $payload[self::EVENT_KEY] ?? null;

        return is_array($event) ? $event : null;
    }

    /** @param array<string, mixed> $state */
    public static function eventId(array $state): string
    {
        return (string) (self::event($state)['event_id'] ?? '');
    }

    /** @param array<string, mixed> $state */
    public static function phase(array $state): string
    {
        return strtolower((string) (self::event($state)['phase'] ?? ''));
    }

    /** @param array<string, mixed> $state */
    public static function clientOrderId(array $state): string
    {
        return (string) (self::event($state)['client_order_id'] ?? '');
    }

    /** @param array<string, mixed> $state */
    public static function action(array $state): string
    {
        return (string) (self::event($state)['action'] ?? 'close_stop');
    }

    /** @param array<string, mixed> $state */
    public static function maySubmit(array $state, bool $canSubmit, ?float $remainingQty = null): bool
    {
        return $canSubmit
            && ($remainingQty === null || $remainingQty > 0.0000001)
            && self::phase($state) === 'pending'
            && self::clientOrderId($state) !== '';
    }

    /**
     * Resolve the tested stop semantics without treating an intraday quote as a daily close.
     *
     * @param array<string, mixed> $state
     * @param array<string, mixed> $policy
     * @param array<string, mixed>|null $modelPosition
     * @return array{triggered:bool, deferred:bool, mode:string, observed_price:float|null, stop_price:float}
     */
    public static function evaluateStop(
        array $state,
        array $policy,
        float $intradayPrice,
        ?float $closedBarPrice,
        ?array $modelPosition = null,
    ): array {
        $stop = (float) ($state['stop_price'] ?? 0.0);
        $symbol = strtoupper((string) ($state['symbol'] ?? ''));
        $swingMode = strtolower((string) ($policy['swing_stop_mode'] ?? 'mental'));
        $breakEvenMode = strtolower((string) ($policy['break_even_stop_mode'] ?? 'hard'));
        $hardSymbols = array_map(
            static fn (mixed $value): string => strtoupper(trim((string) $value)),
            is_array($policy['hybrid_hard_stop_symbols'] ?? null) ? $policy['hybrid_hard_stop_symbols'] : [],
        );
        $breakEvenArmed = (bool) ($state['break_even_armed'] ?? false)
            || (bool) ($modelPosition['break_even_armed'] ?? false);
        $modelHardStop = array_key_exists('hard_stop_active', $modelPosition ?? [])
            && (bool) ($modelPosition['hard_stop_active'] ?? false);
        $entryMetadata = is_array($state['payload']['entry_order']['metadata'] ?? null)
            ? $state['payload']['entry_order']['metadata']
            : [];
        $explicitEntryMode = strtolower((string) ($entryMetadata['stop_mode'] ?? ''));
        $explicitHard = $explicitEntryMode === 'hard' || (bool) ($entryMetadata['hard_stop'] ?? false);

        $hardStop = $breakEvenArmed
            ? $breakEvenMode === 'hard'
            : ($swingMode === 'hard'
                || ($swingMode === 'hybrid' && in_array($symbol, $hardSymbols, true))
                || $modelHardStop
                || $explicitHard);
        $mode = $hardStop ? 'hard_intraday' : 'mental_close';
        $mentalCloseEnabled = (bool) ($policy['mental_stop_exit_on_close'] ?? true);
        $observedPrice = $hardStop
            ? ($intradayPrice > 0.0 ? $intradayPrice : null)
            : ($mentalCloseEnabled && $closedBarPrice !== null && $closedBarPrice > 0.0 ? $closedBarPrice : null);
        $triggered = $stop > 0.0 && $observedPrice !== null && $observedPrice <= $stop;

        return [
            'triggered' => $triggered,
            'deferred' => !$hardStop && $observedPrice === null,
            'mode' => $mode,
            'observed_price' => $observedPrice,
            'stop_price' => $stop,
        ];
    }

    /** @param array<string, mixed> $state */
    public static function isLatchedMentalStop(array $state): bool
    {
        $event = self::event($state);

        return is_array($event)
            && (string) ($event['action'] ?? '') === 'close_stop'
            && (string) ($event['trigger_mode'] ?? '') === 'mental_close';
    }

    /**
     * Quantity safe for a retry. Known fills are deducted even while Alpaca's position
     * snapshot still shows the pre-fill quantity.
     *
     * @param array<string, mixed> $state
     */
    public static function remainingExitQty(array $state, float $observedPositionQty): float
    {
        $event = self::event($state);
        if (!is_array($event)) {
            return max(0.0, $observedPositionQty);
        }

        $requestedQty = max(0.0, (float) ($event['requested_qty'] ?? 0.0));
        if ($requestedQty <= 0.0) {
            return 0.0;
        }
        $remainingFromEvent = max(0.0, $requestedQty - self::knownFilledQty($event));

        return max(0.0, min(max(0.0, $observedPositionQty), $remainingFromEvent));
    }

    /** @param array<string, mixed> $state @return array<string, mixed>|null */
    public static function partialEvent(array $state): ?array
    {
        $payload = is_array($state['payload'] ?? null) ? $state['payload'] : [];
        $event = $payload[self::PARTIAL_EVENT_KEY] ?? null;

        return is_array($event) ? $event : null;
    }

    /** @param array<string, mixed> $state @param array<string, mixed> $decision @return array<string, mixed> */
    public static function ensurePartialEvent(array $state, array $decision, \DateTimeImmutable $now): array
    {
        if ((string) ($decision['action'] ?? '') !== 'partial_take_profit') {
            return $state;
        }
        $fingerprint = self::partialFingerprint($state, $decision);
        $event = self::partialEvent($state);
        if (is_array($event) && hash_equals((string) ($event['fingerprint'] ?? ''), $fingerprint)) {
            $event['last_detected_at'] = $now->format(\DateTimeInterface::ATOM);

            return self::withPartialEvent($state, $event);
        }
        if (is_array($event) && (
            self::partialPhaseBlocksReplacement((string) ($event['phase'] ?? ''))
            || self::partialKnownFilledQty($event) > 0.0000001
            || trim((string) ($event['attempt_started_at'] ?? '')) !== ''
        )) {
            $event['last_detected_at'] = $now->format(\DateTimeInterface::ATOM);
            $event['latest_detected_fingerprint'] = $fingerprint;

            return self::withPartialEvent($state, $event);
        }

        $eventId = hash('sha256', $fingerprint . '|' . $now->format(\DateTimeInterface::ATOM));
        $requestedQty = max(0.0, (float) ($decision['qty'] ?? 0.0));
        $event = [
            'event_id' => $eventId,
            'action' => 'partial_take_profit',
            'fingerprint' => $fingerprint,
            'first_detected_at' => $now->format(\DateTimeInterface::ATOM),
            'last_detected_at' => $now->format(\DateTimeInterface::ATOM),
            'phase' => 'pending',
            'attempt' => 1,
            'requested_qty' => $requestedQty,
            'position_qty_at_event' => max(0.0, (float) ($state['qty'] ?? 0.0)),
            'filled_qty_by_attempt' => [],
            'filled_qty_by_order' => [],
            'position_confirmed_filled_qty' => 0.0,
            'remaining_qty' => $requestedQty,
            'client_order_id' => self::partialClientOrderIdForAttempt($state, $eventId, $now, 1),
            'order_id' => null,
            'order_status' => null,
            'retry_after' => null,
            'reconcile_after' => null,
            'last_error' => null,
            'alerts' => [],
        ];
        $state['last_action'] = 'partial_take_profit_pending';

        return self::withPartialEvent($state, $event);
    }

    /** @param array<string, mixed> $state */
    public static function partialPhase(array $state): string
    {
        return strtolower((string) (self::partialEvent($state)['phase'] ?? ''));
    }

    /** @param array<string, mixed> $state */
    public static function partialClientOrderId(array $state): string
    {
        return (string) (self::partialEvent($state)['client_order_id'] ?? '');
    }

    /** @param array<string, mixed> $state */
    public static function partialMaySubmit(array $state, bool $canSubmit, ?float $remainingQty = null): bool
    {
        return $canSubmit
            && ($remainingQty === null || $remainingQty > 0.0000001)
            && self::partialPhase($state) === 'pending'
            && self::partialClientOrderId($state) !== ''
            && self::partialActiveSellBlock($state) === null;
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    public static function syncPartialPosition(array $state, float $observedPositionQty, \DateTimeImmutable $now): array
    {
        $event = self::partialEvent($state);
        if (!is_array($event)) {
            return $state;
        }
        $baseQty = max(0.0, (float) ($event['position_qty_at_event'] ?? $observedPositionQty));
        $confirmed = max(0.0, $baseQty - max(0.0, $observedPositionQty));
        $event['position_confirmed_filled_qty'] = max(
            (float) ($event['position_confirmed_filled_qty'] ?? 0.0),
            $confirmed,
        );
        $event['last_position_qty'] = max(0.0, $observedPositionQty);
        $event['last_position_sync_at'] = $now->format(\DateTimeInterface::ATOM);
        $event = self::refreshPartialQuantities($event);
        if ((float) ($event['remaining_qty'] ?? 0.0) <= 0.0000001
            && (float) ($event['requested_qty'] ?? 0.0) > 0.0
        ) {
            $event['phase'] = 'filled';
            $event['filled_at'] ??= $now->format(\DateTimeInterface::ATOM);
            $state['partial_done'] = true;
            $state['last_action'] = 'partial_take_profit_filled';
        }

        return self::withPartialEvent($state, $event);
    }

    /** @param array<string, mixed> $state */
    public static function partialRemainingQty(array $state, float $observedPositionQty): float
    {
        $event = self::partialEvent($state);
        if (!is_array($event)) {
            return 0.0;
        }
        $requestedQty = max(0.0, (float) ($event['requested_qty'] ?? 0.0));
        $remaining = max(0.0, $requestedQty - self::partialKnownFilledQty($event));

        return max(0.0, min($remaining, self::positionQtyAfterKnownPartialFills($state, $observedPositionQty)));
    }

    /** @param array<string, mixed> $state */
    public static function positionQtyAfterKnownPartialFills(array $state, float $observedPositionQty): float
    {
        $event = self::partialEvent($state);
        if (!is_array($event)) {
            return max(0.0, $observedPositionQty);
        }
        $observedPositionQty = max(0.0, $observedPositionQty);
        $knownFilledQty = self::partialKnownFilledQty($event);
        $positionConfirmedFilledQty = max(0.0, (float) ($event['position_confirmed_filled_qty'] ?? 0.0));
        // Deduct only fills which the position snapshot has not reflected yet. Once
        // the reduction is confirmed, the observed quantity is authoritative and may
        // legitimately grow again after a layered add-on or a corporate-action split.
        $unreflectedFilledQty = max(0.0, $knownFilledQty - $positionConfirmedFilledQty);

        return max(0.0, $observedPositionQty - $unreflectedFilledQty);
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    public static function markPartialSubmitting(array $state, \DateTimeImmutable $now, int $reconcileDelaySeconds): array
    {
        $event = self::partialEvent($state);
        if (!is_array($event)) {
            return $state;
        }
        $event['phase'] = 'submitting';
        $event['attempt_started_at'] = $now->format(\DateTimeInterface::ATOM);
        $event['reconcile_after'] = $now->modify('+' . max(1, $reconcileDelaySeconds) . ' seconds')->format(\DateTimeInterface::ATOM);
        $event['last_error'] = null;
        $state['last_action'] = 'partial_take_profit_submitting';

        return self::withPartialEvent($state, $event);
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    public static function markPartialAmbiguousSubmit(array $state, string $error, \DateTimeImmutable $now, int $reconcileDelaySeconds): array
    {
        $event = self::partialEvent($state);
        if (!is_array($event)) {
            return $state;
        }
        $event['phase'] = 'submitting';
        $event['last_error'] = $error;
        $event['last_reconcile_at'] = $now->format(\DateTimeInterface::ATOM);
        $event['reconcile_after'] = $now->modify('+' . max(1, $reconcileDelaySeconds) . ' seconds')->format(\DateTimeInterface::ATOM);
        $state['last_action'] = 'partial_take_profit_reconciling';

        return self::withPartialEvent($state, $event);
    }

    /** @param array<string, mixed> $state @param array<string, mixed> $order @return array<string, mixed> */
    public static function applyPartialOrder(array $state, array $order, \DateTimeImmutable $now, int $retryDelaySeconds): array
    {
        $event = self::partialEvent($state);
        if (!is_array($event)) {
            return $state;
        }
        $status = strtolower((string) ($order['status'] ?? 'accepted'));
        $event['order_id'] = (string) ($order['id'] ?? $event['order_id'] ?? '');
        $event['client_order_id'] = (string) ($order['client_order_id'] ?? $event['client_order_id'] ?? '');
        $event['order_status'] = $status;
        $event['last_reconcile_at'] = $now->format(\DateTimeInterface::ATOM);
        $event['last_error'] = null;
        $event['reconcile_after'] = null;
        $attempt = max(1, (int) ($event['attempt'] ?? 1));
        $attemptKey = (string) $attempt;
        $filledByAttempt = is_array($event['filled_qty_by_attempt'] ?? null) ? $event['filled_qty_by_attempt'] : [];
        $filledQty = max(0.0, (float) ($order['filled_qty'] ?? 0.0));
        $filledByAttempt[$attemptKey] = max((float) ($filledByAttempt[$attemptKey] ?? 0.0), $filledQty);
        $event['filled_qty_by_attempt'] = $filledByAttempt;
        $orderKey = trim((string) ($order['id'] ?? ''));
        if ($orderKey === '') {
            $orderKey = 'attempt:' . $attemptKey . ':' . trim((string) ($order['client_order_id'] ?? $event['client_order_id'] ?? 'unknown'));
        }
        $filledByOrder = is_array($event['filled_qty_by_order'] ?? null) ? $event['filled_qty_by_order'] : [];
        $filledByOrder[$orderKey] = max((float) ($filledByOrder[$orderKey] ?? 0.0), $filledQty);
        $event['filled_qty_by_order'] = $filledByOrder;
        $event = self::refreshPartialQuantities($event);
        $qty = max(0.0, (float) ($order['qty'] ?? 0.0));
        $attemptFilledWithEvidence = $qty > 0.0 && $filledQty + 0.0000001 >= $qty;
        $targetFilled = (float) ($event['remaining_qty'] ?? 0.0) <= 0.0000001
            && (float) ($event['requested_qty'] ?? 0.0) > 0.0;
        $dayTerminal = in_array($status, ['done_for_day', 'calculated'], true)
            && strtolower((string) ($order['time_in_force'] ?? 'day')) === 'day';
        if ($targetFilled) {
            $event['phase'] = 'filled';
            $event['filled_at'] = (string) ($order['filled_at'] ?? $now->format(\DateTimeInterface::ATOM));
            $state['partial_done'] = true;
            $state['last_action'] = 'partial_take_profit_filled';
        } elseif ($status === 'filled' && !$attemptFilledWithEvidence) {
            // A terminal label without filled_qty/position evidence is not authority to
            // arm partial_done or retry the same shares.
            $event['phase'] = 'suspended';
            $event['last_error'] = 'filled status lacks complete filled_qty or position confirmation';
            $state['last_action'] = 'partial_take_profit_suspended';
        } elseif ($attemptFilledWithEvidence) {
            // This attempt filled its whole remainder, but the original target still has
            // quantity left (for example after a conservative position cap). Retry only it.
            $event['phase'] = 'retry_wait';
            $event['retry_after'] = $now->modify('+' . max(1, $retryDelaySeconds) . ' seconds')->format(\DateTimeInterface::ATOM);
            $state['last_action'] = 'partial_take_profit_retry_wait';
        } elseif (in_array($status, self::RETRYABLE_TERMINAL_STATUSES, true) || $dayTerminal) {
            $event['phase'] = 'retry_wait';
            $event['retry_after'] = $now->modify('+' . max(1, $retryDelaySeconds) . ' seconds')->format(\DateTimeInterface::ATOM);
            $state['last_action'] = 'partial_take_profit_retry_wait';
        } elseif ($status === 'suspended') {
            $event['phase'] = 'suspended';
            $state['last_action'] = 'partial_take_profit_suspended';
        } else {
            $event['phase'] = 'inflight';
            $event['accepted_at'] ??= $now->format(\DateTimeInterface::ATOM);
            $state['last_action'] = 'partial_take_profit';
        }
        $state['client_order_id'] = (string) ($event['client_order_id'] ?? '');

        return self::withPartialEvent($state, $event);
    }

    /** @param array<string, mixed> $state */
    public static function shouldReconcilePartial(array $state): bool
    {
        return in_array(self::partialPhase($state), ['submitting', 'inflight', 'suspended'], true);
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    public static function releasePartialAmbiguousAttemptIfDue(array $state, \DateTimeImmutable $now): array
    {
        $event = self::partialEvent($state);
        if (!is_array($event) || (string) ($event['phase'] ?? '') !== 'submitting') {
            return $state;
        }
        if (!self::timeReached((string) ($event['reconcile_after'] ?? ''), $now)) {
            return $state;
        }
        // Reusing the same id makes an ambiguous HTTP retry idempotent at Alpaca.
        $event['phase'] = 'pending';
        $event['reconcile_after'] = null;
        $state['last_action'] = 'partial_take_profit_pending';

        return self::withPartialEvent($state, $event);
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    public static function preparePartialRetryIfDue(array $state, \DateTimeImmutable $now): array
    {
        $event = self::partialEvent($state);
        if (!is_array($event) || (string) ($event['phase'] ?? '') !== 'retry_wait') {
            return $state;
        }
        if (!self::timeReached((string) ($event['retry_after'] ?? ''), $now)) {
            return $state;
        }
        $event = self::refreshPartialQuantities($event);
        if ((float) ($event['remaining_qty'] ?? 0.0) <= 0.0000001) {
            $event['phase'] = 'filled';
            $state['partial_done'] = true;
            $state['last_action'] = 'partial_take_profit_filled';

            return self::withPartialEvent($state, $event);
        }
        $history = is_array($event['attempt_history'] ?? null) ? $event['attempt_history'] : [];
        $history[] = [
            'attempt' => (int) ($event['attempt'] ?? 1),
            'client_order_id' => (string) ($event['client_order_id'] ?? ''),
            'order_id' => (string) ($event['order_id'] ?? ''),
            'order_status' => (string) ($event['order_status'] ?? ''),
            'filled_qty' => (float) (($event['filled_qty_by_attempt'][(string) ($event['attempt'] ?? 1)] ?? 0.0)),
        ];
        $event['attempt_history'] = array_slice($history, -5);
        $attempt = max(1, (int) ($event['attempt'] ?? 1)) + 1;
        $firstDetectedAt = self::dateOrNow((string) ($event['first_detected_at'] ?? ''), $now);
        $event['attempt'] = $attempt;
        $event['client_order_id'] = self::partialClientOrderIdForAttempt($state, (string) $event['event_id'], $firstDetectedAt, $attempt);
        $event['order_id'] = null;
        $event['order_status'] = null;
        $event['retry_after'] = null;
        $event['last_error'] = null;
        $event['planned_status'] = null;
        $event['planned_attempt'] = null;
        $event['attempt_started_at'] = null;
        $event['phase'] = 'pending';
        $state['last_action'] = 'partial_take_profit_pending';

        return self::withPartialEvent($state, $event);
    }

    /** @param array<string, mixed> $state */
    public static function partialNeedsPlannedOrderPersistence(array $state, string $status): bool
    {
        $event = self::partialEvent($state);
        if (!is_array($event)) {
            return false;
        }

        return (string) ($event['planned_status'] ?? '') !== $status
            || (int) ($event['planned_attempt'] ?? 0) !== (int) ($event['attempt'] ?? 1);
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    public static function markPartialPlannedOrderPersisted(array $state, string $status, \DateTimeImmutable $now): array
    {
        $event = self::partialEvent($state);
        if (!is_array($event)) {
            return $state;
        }
        $event['planned_status'] = $status;
        $event['planned_attempt'] = (int) ($event['attempt'] ?? 1);
        $event['planned_at'] = $now->format(\DateTimeInterface::ATOM);

        return self::withPartialEvent($state, $event);
    }

    /** @param array<string, mixed> $state @param array<string, mixed> $order @return array<string, mixed> */
    public static function markPartialBlockedByActiveSell(array $state, array $order, float $observedPositionQty, \DateTimeImmutable $now): array
    {
        $event = self::partialEvent($state);
        if (!is_array($event)) {
            return $state;
        }
        $existingBlock = is_array($event['blocked_by_active_sell'] ?? null) ? $event['blocked_by_active_sell'] : null;
        $sameReference = is_array($existingBlock) && (
            ((string) ($existingBlock['order_id'] ?? '') !== '' && (string) ($existingBlock['order_id'] ?? '') === (string) ($order['id'] ?? ''))
            || ((string) ($existingBlock['client_order_id'] ?? '') !== '' && (string) ($existingBlock['client_order_id'] ?? '') === (string) ($order['client_order_id'] ?? ''))
        );
        $event['blocked_by_active_sell'] = [
            'order_id' => (string) ($order['id'] ?? ''),
            'client_order_id' => (string) ($order['client_order_id'] ?? ''),
            'status' => (string) ($order['status'] ?? ''),
            'qty' => (float) ($order['qty'] ?? 0.0),
            'filled_qty' => (float) ($order['filled_qty'] ?? 0.0),
            'time_in_force' => (string) ($order['time_in_force'] ?? ''),
            'observed_position_qty' => $sameReference
                ? (float) ($existingBlock['observed_position_qty'] ?? $observedPositionQty)
                : $observedPositionQty,
            'observed_at' => $now->format(\DateTimeInterface::ATOM),
        ];
        if (in_array((string) ($event['phase'] ?? ''), ['pending', 'blocked_active_sell'], true)) {
            $event['phase'] = 'blocked_active_sell';
            $state['last_action'] = 'partial_take_profit_blocked_active_sell';
        }

        return self::withPartialEvent($state, $event);
    }

    /** @param array<string, mixed> $state @return array<string, mixed>|null */
    public static function partialActiveSellBlock(array $state): ?array
    {
        $block = self::partialEvent($state)['blocked_by_active_sell'] ?? null;

        return is_array($block) ? $block : null;
    }

    /** @param array<string, mixed> $state @param array<string, mixed> $order @return array<string, mixed> */
    public static function reconcilePartialActiveSellBlock(array $state, array $order, float $currentPositionQty, \DateTimeImmutable $now): array
    {
        $block = self::partialActiveSellBlock($state);
        if ($block === null) {
            return $state;
        }
        $status = strtolower((string) ($order['status'] ?? ''));
        $observedAtBlock = (float) ($block['observed_position_qty'] ?? 0.0);
        $positionReduced = $observedAtBlock > 0.0 && $currentPositionQty + 0.0000001 < $observedAtBlock;
        $qty = (float) ($order['qty'] ?? $block['qty'] ?? 0.0);
        $filledQty = (float) ($order['filled_qty'] ?? $block['filled_qty'] ?? 0.0);
        $event = self::partialEvent($state) ?? [];
        $baseQty = (float) ($event['position_qty_at_event'] ?? 0.0);
        $positionConfirmsFill = $filledQty > 0.0000001
            && $baseQty > 0.0
            && $baseQty - $currentPositionQty + 0.0000001 >= $filledQty;
        $terminal = in_array($status, ['canceled', 'cancelled', 'expired', 'rejected', 'replaced'], true)
            || (in_array($status, ['done_for_day', 'calculated'], true)
                && strtolower((string) ($order['time_in_force'] ?? $block['time_in_force'] ?? 'day')) === 'day');
        $fullyFilled = $status === 'filled' || ($qty > 0.0 && $filledQty + 0.0000001 >= $qty);
        if (self::orderStillBlocksQuantity($order)) {
            return self::markPartialBlockedByActiveSell($state, $order, $observedAtBlock, $now);
        }
        if ($positionReduced || $positionConfirmsFill || ($terminal && $filledQty <= 0.0000001)) {
            return self::clearPartialActiveSellBlock($state);
        }
        if ($fullyFilled || $terminal) {
            return self::markPartialBlockedByActiveSell($state, $order, $observedAtBlock, $now);
        }

        return self::markPartialBlockedByActiveSell($state, $order, $observedAtBlock, $now);
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    public static function clearPartialActiveSellBlock(array $state): array
    {
        $event = self::partialEvent($state);
        if (!is_array($event)) {
            return $state;
        }
        unset($event['blocked_by_active_sell']);
        if ((string) ($event['phase'] ?? '') === 'blocked_active_sell') {
            $event['phase'] = 'pending';
            $state['last_action'] = 'partial_take_profit_pending';
        }

        return self::withPartialEvent($state, $event);
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    public static function clearPartialEvent(array $state): array
    {
        $payload = is_array($state['payload'] ?? null) ? $state['payload'] : [];
        unset($payload[self::PARTIAL_EVENT_KEY]);
        $state['payload'] = $payload;

        return $state;
    }

    /** @param array<string, mixed> $state */
    public static function needsPlannedOrderPersistence(array $state, string $status): bool
    {
        $event = self::event($state);
        if (!is_array($event)) {
            return false;
        }

        return (string) ($event['planned_status'] ?? '') !== $status
            || (int) ($event['planned_attempt'] ?? 0) !== (int) ($event['attempt'] ?? 1);
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    public static function markPlannedOrderPersisted(array $state, string $status, \DateTimeImmutable $now): array
    {
        $event = self::event($state);
        if (!is_array($event)) {
            return $state;
        }

        $event['planned_status'] = $status;
        $event['planned_attempt'] = (int) ($event['attempt'] ?? 1);
        $event['planned_at'] = $now->format(\DateTimeInterface::ATOM);

        return self::withEvent($state, $event);
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    public static function markSubmitting(array $state, \DateTimeImmutable $now, int $reconcileDelaySeconds): array
    {
        $event = self::event($state);
        if (!is_array($event)) {
            return $state;
        }

        $event['phase'] = 'submitting';
        $event['attempt_started_at'] = $now->format(\DateTimeInterface::ATOM);
        $event['reconcile_after'] = $now->modify('+' . max(1, $reconcileDelaySeconds) . ' seconds')->format(\DateTimeInterface::ATOM);
        $event['last_error'] = null;
        $state['last_action'] = self::action($state) . '_submitting';
        $state['status'] = 'closing';

        return self::withEvent($state, $event);
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    public static function markAmbiguousSubmit(array $state, string $error, \DateTimeImmutable $now, int $reconcileDelaySeconds): array
    {
        $event = self::event($state);
        if (!is_array($event)) {
            return $state;
        }

        $event['phase'] = 'submitting';
        $event['last_error'] = $error;
        $event['last_reconcile_at'] = $now->format(\DateTimeInterface::ATOM);
        $event['reconcile_after'] = $now->modify('+' . max(1, $reconcileDelaySeconds) . ' seconds')->format(\DateTimeInterface::ATOM);
        $state['last_action'] = self::action($state) . '_reconciling';

        return self::withEvent($state, $event);
    }

    /** @param array<string, mixed> $state @param array<string, mixed> $order @return array<string, mixed> */
    public static function applyOrder(array $state, array $order, \DateTimeImmutable $now, int $retryDelaySeconds): array
    {
        $event = self::event($state);
        if (!is_array($event)) {
            return $state;
        }

        $status = strtolower((string) ($order['status'] ?? 'accepted'));
        $event['order_id'] = (string) ($order['id'] ?? $event['order_id'] ?? '');
        $event['client_order_id'] = (string) ($order['client_order_id'] ?? $event['client_order_id'] ?? '');
        $event['order_status'] = $status;
        $event['last_reconcile_at'] = $now->format(\DateTimeInterface::ATOM);
        $event['last_error'] = null;
        $event['reconcile_after'] = null;

        $qty = (float) ($order['qty'] ?? 0.0);
        $filledQty = (float) ($order['filled_qty'] ?? 0.0);
        $attempt = max(1, (int) ($event['attempt'] ?? 1));
        $filledByAttempt = is_array($event['filled_qty_by_attempt'] ?? null) ? $event['filled_qty_by_attempt'] : [];
        $attemptKey = (string) $attempt;
        $filledByAttempt[$attemptKey] = max((float) ($filledByAttempt[$attemptKey] ?? 0.0), max(0.0, $filledQty));
        $event['filled_qty_by_attempt'] = $filledByAttempt;
        if ((float) ($event['requested_qty'] ?? 0.0) <= 0.0 && $qty > 0.0) {
            $event['requested_qty'] = $qty + max(0.0, self::knownFilledQty($event) - (float) $filledByAttempt[$attemptKey]);
        }
        $event['known_filled_qty'] = self::knownFilledQty($event);
        $event['remaining_qty'] = max(0.0, (float) ($event['requested_qty'] ?? 0.0) - (float) $event['known_filled_qty']);
        $fullyFilled = $status === 'filled'
            || ($qty > 0.0 && $filledQty + 0.0000001 >= $qty)
            || ((float) ($event['requested_qty'] ?? 0.0) > 0.0 && (float) $event['remaining_qty'] <= 0.0000001);
        $dayTerminal = in_array($status, ['done_for_day', 'calculated'], true)
            && strtolower((string) ($order['time_in_force'] ?? 'day')) === 'day';
        if ($fullyFilled) {
            $event['phase'] = 'filled';
            $event['filled_at'] = (string) ($order['filled_at'] ?? $now->format(\DateTimeInterface::ATOM));
            $state['status'] = 'closing';
            $state['last_action'] = self::action($state) . '_filled';
        } elseif (in_array($status, self::RETRYABLE_TERMINAL_STATUSES, true) || $dayTerminal) {
            $event['phase'] = 'retry_wait';
            $event['retry_after'] = $now->modify('+' . max(1, $retryDelaySeconds) . ' seconds')->format(\DateTimeInterface::ATOM);
            $state['status'] = 'open';
            $state['last_action'] = self::action($state) . '_retry_wait';
        } elseif ($status === 'suspended') {
            $event['phase'] = 'suspended';
            $state['status'] = 'closing';
            $state['last_action'] = self::action($state) . '_suspended';
        } else {
            $event['phase'] = 'inflight';
            $event['accepted_at'] ??= $now->format(\DateTimeInterface::ATOM);
            $state['status'] = 'closing';
            $state['last_action'] = self::action($state);
        }

        $state['client_order_id'] = (string) ($event['client_order_id'] ?? '');

        return self::withEvent($state, $event);
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    public static function releaseAmbiguousAttemptIfDue(array $state, \DateTimeImmutable $now): array
    {
        $event = self::event($state);
        if (!is_array($event) || (string) ($event['phase'] ?? '') !== 'submitting') {
            return $state;
        }
        if (!self::timeReached((string) ($event['reconcile_after'] ?? ''), $now)) {
            return $state;
        }

        // Reuse the same client_order_id: Alpaca can then reject a duplicate without creating a second sell.
        $event['phase'] = 'pending';
        $event['reconcile_after'] = null;
        $state['status'] = 'open';
        $state['last_action'] = self::action($state) . '_pending';

        return self::withEvent($state, $event);
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    public static function prepareTerminalRetryIfDue(array $state, \DateTimeImmutable $now): array
    {
        $event = self::event($state);
        if (!is_array($event) || (string) ($event['phase'] ?? '') !== 'retry_wait') {
            return $state;
        }
        if (!self::timeReached((string) ($event['retry_after'] ?? ''), $now)) {
            return $state;
        }
        if ((float) ($event['requested_qty'] ?? 0.0) > 0.0
            && self::knownFilledQty($event) + 0.0000001 >= (float) $event['requested_qty']
        ) {
            $event['phase'] = 'filled';
            $state['status'] = 'closing';
            $state['last_action'] = self::action($state) . '_filled';

            return self::withEvent($state, $event);
        }

        $history = is_array($event['attempt_history'] ?? null) ? $event['attempt_history'] : [];
        $history[] = [
            'attempt' => (int) ($event['attempt'] ?? 1),
            'client_order_id' => (string) ($event['client_order_id'] ?? ''),
            'order_id' => (string) ($event['order_id'] ?? ''),
            'order_status' => (string) ($event['order_status'] ?? ''),
        ];
        $event['attempt_history'] = array_slice($history, -5);
        $attempt = max(1, (int) ($event['attempt'] ?? 1)) + 1;
        $firstDetectedAt = self::dateOrNow((string) ($event['first_detected_at'] ?? ''), $now);
        $event['attempt'] = $attempt;
        $event['client_order_id'] = self::clientOrderIdForAttempt($state, (string) $event['event_id'], $firstDetectedAt, $attempt);
        $event['order_id'] = null;
        $event['order_status'] = null;
        $event['retry_after'] = null;
        $event['last_error'] = null;
        $event['planned_status'] = null;
        $event['planned_attempt'] = null;
        $event['phase'] = 'pending';
        $state['status'] = 'open';
        $state['last_action'] = self::action($state) . '_pending';

        return self::withEvent($state, $event);
    }

    /** @param list<array<string, mixed>> $openOrders @return array<string, mixed>|null */
    public static function activeSellOrder(array $openOrders, string $symbol, ?string $preferredClientOrderId = null): ?array
    {
        $symbol = strtoupper(trim($symbol));
        $fallback = null;
        foreach ($openOrders as $order) {
            if (!is_array($order)) {
                continue;
            }
            if (
                strtoupper((string) ($order['symbol'] ?? '')) === $symbol
                && strtolower((string) ($order['side'] ?? '')) === 'sell'
                && self::orderStillBlocksQuantity($order)
            ) {
                if ($preferredClientOrderId !== null && $preferredClientOrderId !== ''
                    && hash_equals($preferredClientOrderId, (string) ($order['client_order_id'] ?? ''))
                ) {
                    return $order;
                }
                $fallback ??= $order;
            }
        }

        return $fallback;
    }

    /** @param array<string, mixed> $state @param array<string, mixed> $order @return array<string, mixed> */
    public static function markBlockedByActiveSell(array $state, array $order, float $observedPositionQty, \DateTimeImmutable $now): array
    {
        $event = self::event($state);
        if (!is_array($event)) {
            return $state;
        }

        $event['blocked_by_active_sell'] = [
            'order_id' => (string) ($order['id'] ?? ''),
            'client_order_id' => (string) ($order['client_order_id'] ?? ''),
            'status' => (string) ($order['status'] ?? ''),
            'qty' => (float) ($order['qty'] ?? 0.0),
            'filled_qty' => (float) ($order['filled_qty'] ?? 0.0),
            'time_in_force' => (string) ($order['time_in_force'] ?? ''),
            'observed_position_qty' => $observedPositionQty,
            'observed_at' => $now->format(\DateTimeInterface::ATOM),
        ];
        if (in_array((string) ($event['phase'] ?? ''), ['pending', 'blocked_active_sell'], true)) {
            $event['phase'] = 'blocked_active_sell';
            $state['status'] = 'open';
            $state['last_action'] = self::action($state) . '_blocked_active_sell';
        }

        return self::withEvent($state, $event);
    }

    /** @param array<string, mixed> $state @return array<string, mixed>|null */
    public static function activeSellBlock(array $state): ?array
    {
        $block = self::event($state)['blocked_by_active_sell'] ?? null;

        return is_array($block) ? $block : null;
    }

    /** @param array<string, mixed> $state @param array<string, mixed> $order @return array<string, mixed> */
    public static function reconcileActiveSellBlock(array $state, array $order, float $currentPositionQty, \DateTimeImmutable $now): array
    {
        $block = self::activeSellBlock($state);
        if ($block === null) {
            return $state;
        }

        $status = strtolower((string) ($order['status'] ?? ''));
        $observedPositionQty = (float) ($block['observed_position_qty'] ?? 0.0);
        $positionReduced = $observedPositionQty > 0.0 && $currentPositionQty + 0.0000001 < $observedPositionQty;
        $qty = (float) ($order['qty'] ?? $block['qty'] ?? 0.0);
        $filledQty = (float) ($order['filled_qty'] ?? $block['filled_qty'] ?? 0.0);
        $fullyFilled = $status === 'filled' || ($qty > 0.0 && $filledQty + 0.0000001 >= $qty);
        $fillTrackedByPartialEvent = self::orderMatchesPartialEvent($state, $order);
        if ($positionReduced) {
            if ($filledQty > 0.0000001 && !$fillTrackedByPartialEvent) {
                if (self::externalFillKey($order) === '') {
                    return self::markBlockedByActiveSell($state, $order, $observedPositionQty, $now);
                }
                $state = self::recordExternalFill($state, $order, $filledQty);
            }

            return self::clearActiveSellBlock($state);
        }
        if (in_array($status, ['canceled', 'cancelled', 'expired', 'rejected'], true)) {
            if ($filledQty > 0.0000001 && !$fillTrackedByPartialEvent) {
                if (self::externalFillKey($order) === '') {
                    return self::markBlockedByActiveSell($state, $order, $observedPositionQty, $now);
                }
                $state = self::recordExternalFill($state, $order, $filledQty);
            }

            return self::clearActiveSellBlock($state);
        }
        if ($fullyFilled) {
            $event = self::event($state);
            if (!is_array($event)) {
                return $state;
            }
            $event['blocked_by_active_sell']['status'] = $status;
            $event['blocked_by_active_sell']['filled_qty'] = $filledQty;
            $event['blocked_by_active_sell']['last_reconcile_at'] = $now->format(\DateTimeInterface::ATOM);

            return self::withEvent($state, $event);
        }
        if (in_array($status, ['done_for_day', 'calculated'], true)
            && strtolower((string) ($order['time_in_force'] ?? $block['time_in_force'] ?? 'day')) === 'day'
        ) {
            if ($filledQty > 0.0000001 && !$fillTrackedByPartialEvent) {
                if (self::externalFillKey($order) === '') {
                    return self::markBlockedByActiveSell($state, $order, $observedPositionQty, $now);
                }
                $state = self::recordExternalFill($state, $order, $filledQty);
            }

            return self::clearActiveSellBlock($state);
        }

        return self::markBlockedByActiveSell($state, $order, $observedPositionQty, $now);
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    public static function clearActiveSellBlock(array $state): array
    {
        $event = self::event($state);
        if (!is_array($event)) {
            return $state;
        }

        unset($event['blocked_by_active_sell']);
        if ((string) ($event['phase'] ?? '') === 'blocked_active_sell') {
            $event['phase'] = 'pending';
            $state['last_action'] = self::action($state) . '_pending';
        }

        return self::withEvent($state, $event);
    }

    /** @param array<string, mixed> $state */
    public static function shouldReconcile(array $state): bool
    {
        return in_array(self::phase($state), ['submitting', 'inflight', 'suspended'], true);
    }

    /** @param array<string, mixed> $state */
    public static function alertKey(array $state, string $kind): string
    {
        $attempt = max(1, (int) (self::event($state)['attempt'] ?? 1));

        return $kind . ':' . $attempt;
    }

    /** @param array<string, mixed> $state */
    public static function partialAlertKey(array $state, string $kind): string
    {
        $attempt = max(1, (int) (self::partialEvent($state)['attempt'] ?? 1));

        return $kind . ':' . $attempt;
    }

    /** @param array<string, mixed> $state */
    public static function shouldEmitAlert(
        array $state,
        string $alertKey,
        ?\DateTimeImmutable $now = null,
        int $retryCooldownSeconds = 900,
    ): bool
    {
        $alerts = self::event($state)['alerts'] ?? [];
        $alert = is_array($alerts) ? ($alerts[$alertKey] ?? null) : null;
        if (!is_array($alert)) {
            return true;
        }
        if (trim((string) ($alert['delivered_at'] ?? '')) !== '') {
            return false;
        }

        $attemptedAt = trim((string) ($alert['attempted_at'] ?? ''));
        if ($attemptedAt === '' || $now === null || $retryCooldownSeconds <= 0) {
            return true;
        }

        try {
            $attempted = new \DateTimeImmutable($attemptedAt);
        } catch (\Throwable) {
            return true;
        }

        return $now->getTimestamp() >= $attempted->getTimestamp() + $retryCooldownSeconds;
    }

    /** @param array<string, mixed> $state */
    public static function shouldEmitPartialAlert(
        array $state,
        string $alertKey,
        ?\DateTimeImmutable $now = null,
        int $retryCooldownSeconds = 900,
    ): bool {
        $alerts = self::partialEvent($state)['alerts'] ?? [];
        $alert = is_array($alerts) ? ($alerts[$alertKey] ?? null) : null;
        if (!is_array($alert)) {
            return true;
        }
        if (trim((string) ($alert['delivered_at'] ?? '')) !== '') {
            return false;
        }

        $attemptedAt = trim((string) ($alert['attempted_at'] ?? ''));
        if ($attemptedAt === '' || $now === null || $retryCooldownSeconds <= 0) {
            return true;
        }

        try {
            $attempted = new \DateTimeImmutable($attemptedAt);
        } catch (\Throwable) {
            return true;
        }

        return $now->getTimestamp() >= $attempted->getTimestamp() + $retryCooldownSeconds;
    }

    /** @param array<string, mixed> $state */
    public static function shouldLogAction(array $state, string $alertKey): bool
    {
        $alerts = self::event($state)['alerts'] ?? [];
        $alert = is_array($alerts) ? ($alerts[$alertKey] ?? null) : null;

        return !is_array($alert) || trim((string) ($alert['action_logged_at'] ?? '')) === '';
    }

    /** @param array<string, mixed> $state */
    public static function shouldLogPartialAction(array $state, string $alertKey): bool
    {
        $alerts = self::partialEvent($state)['alerts'] ?? [];
        $alert = is_array($alerts) ? ($alerts[$alertKey] ?? null) : null;

        return !is_array($alert) || trim((string) ($alert['action_logged_at'] ?? '')) === '';
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    public static function markActionLogged(array $state, string $eventId, string $alertKey, \DateTimeImmutable $now): array
    {
        return self::markAlertField($state, $eventId, $alertKey, 'action_logged_at', $now);
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    public static function markAlertAttempted(array $state, string $eventId, string $alertKey, \DateTimeImmutable $now): array
    {
        return self::markAlertField($state, $eventId, $alertKey, 'attempted_at', $now);
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    public static function markAlertDelivered(array $state, string $eventId, string $alertKey, \DateTimeImmutable $now): array
    {
        return self::markAlertField($state, $eventId, $alertKey, 'delivered_at', $now);
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    public static function markPartialActionLogged(array $state, string $eventId, string $alertKey, \DateTimeImmutable $now): array
    {
        return self::markPartialAlertField($state, $eventId, $alertKey, 'action_logged_at', $now);
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    public static function markPartialAlertAttempted(array $state, string $eventId, string $alertKey, \DateTimeImmutable $now): array
    {
        return self::markPartialAlertField($state, $eventId, $alertKey, 'attempted_at', $now);
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    public static function markPartialAlertDelivered(array $state, string $eventId, string $alertKey, \DateTimeImmutable $now): array
    {
        return self::markPartialAlertField($state, $eventId, $alertKey, 'delivered_at', $now);
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private static function markAlertField(
        array $state,
        string $eventId,
        string $alertKey,
        string $field,
        \DateTimeImmutable $now,
    ): array {
        $event = self::event($state);
        if (!is_array($event) || !hash_equals((string) ($event['event_id'] ?? ''), $eventId)) {
            return $state;
        }

        $alerts = is_array($event['alerts'] ?? null) ? $event['alerts'] : [];
        $alert = is_array($alerts[$alertKey] ?? null) ? $alerts[$alertKey] : [];
        $alert[$field] = $now->format(\DateTimeInterface::ATOM);
        $alerts[$alertKey] = $alert;
        $event['alerts'] = $alerts;

        return self::withEvent($state, $event);
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    private static function markPartialAlertField(
        array $state,
        string $eventId,
        string $alertKey,
        string $field,
        \DateTimeImmutable $now,
    ): array {
        $event = self::partialEvent($state);
        if (!is_array($event) || !hash_equals((string) ($event['event_id'] ?? ''), $eventId)) {
            return $state;
        }

        $alerts = is_array($event['alerts'] ?? null) ? $event['alerts'] : [];
        $alert = is_array($alerts[$alertKey] ?? null) ? $alerts[$alertKey] : [];
        $alert[$field] = $now->format(\DateTimeInterface::ATOM);
        $alerts[$alertKey] = $alert;
        $event['alerts'] = $alerts;

        return self::withPartialEvent($state, $event);
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    public static function clearRecoveredCloseStop(array $state): array
    {
        if (in_array(self::phase($state), ['submitting', 'inflight', 'suspended', 'filled'], true)) {
            return $state;
        }
        $event = self::event($state);
        if (is_array($event) && self::knownFilledQty($event) > 0.0000001) {
            $requestedQty = max(0.0, (float) ($event['requested_qty'] ?? 0.0));
            $remainingQty = max(0.0, $requestedQty - self::knownFilledQty($event));
            $observedQty = max(0.0, (float) ($state['qty'] ?? 0.0));
            if ($requestedQty > 0.0 && $observedQty > $remainingQty + 0.0000001) {
                // Do not forget known fills while the position endpoint still exposes pre-fill quantity.
                return $state;
            }
        }

        return self::clearCloseStopEvent($state);
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    public static function clearCloseStopEvent(array $state): array
    {
        $payload = is_array($state['payload'] ?? null) ? $state['payload'] : [];
        $eventAction = self::action($state);
        unset($payload[self::EVENT_KEY]);
        $state['payload'] = $payload;
        if (str_starts_with((string) ($state['last_action'] ?? ''), $eventAction . '_')) {
            $state['last_action'] = 'sync_open';
        }

        return $state;
    }

    /** @param array<string, mixed> $state @param array<string, mixed> $decision */
    private static function closeStopFingerprint(array $state, array $decision): string
    {
        $payload = is_array($state['payload'] ?? null) ? $state['payload'] : [];
        $lifecycleId = (string) ($payload['position_lifecycle_id'] ?? $state['opened_at'] ?? '');

        // The live quote is intentionally excluded: quote changes below the same stop are one event.
        return hash('sha256', implode('|', [
            (string) ($decision['action'] ?? ''),
            strtoupper((string) ($state['symbol'] ?? '')),
            $lifecycleId,
            self::decimal((float) ($state['avg_entry_price'] ?? 0.0)),
            self::decimal((float) ($state['stop_price'] ?? 0.0)),
            self::decimal((float) ($decision['qty'] ?? $state['qty'] ?? 0.0)),
        ]));
    }

    /** @param array<string, mixed> $state @param array<string, mixed> $event @return array<string, mixed> */
    private static function withEvent(array $state, array $event): array
    {
        $payload = is_array($state['payload'] ?? null) ? $state['payload'] : [];
        $payload[self::EVENT_KEY] = $event;
        $state['payload'] = $payload;

        return $state;
    }

    /** @param array<string, mixed> $state @param array<string, mixed> $order @return array<string, mixed> */
    private static function recordExternalFill(array $state, array $order, float $filledQty): array
    {
        $event = self::event($state);
        if (!is_array($event)) {
            return $state;
        }
        $key = self::externalFillKey($order);
        if ($key === '') {
            return $state;
        }
        $filledByOrder = is_array($event['external_filled_qty_by_order'] ?? null)
            ? $event['external_filled_qty_by_order']
            : [];
        $filledByOrder[$key] = max((float) ($filledByOrder[$key] ?? 0.0), max(0.0, $filledQty));
        $event['external_filled_qty_by_order'] = $filledByOrder;
        $event['known_filled_qty'] = self::knownFilledQty($event);
        $event['remaining_qty'] = max(0.0, (float) ($event['requested_qty'] ?? 0.0) - (float) $event['known_filled_qty']);
        if ((float) $event['remaining_qty'] <= 0.0000001 && (float) ($event['requested_qty'] ?? 0.0) > 0.0) {
            $event['phase'] = 'filled';
            $state['status'] = 'closing';
            $state['last_action'] = self::action($state) . '_filled';
        }

        return self::withEvent($state, $event);
    }

    /** @param array<string, mixed> $order */
    private static function externalFillKey(array $order): string
    {
        $key = trim((string) ($order['id'] ?? ''));

        return $key !== '' ? $key : trim((string) ($order['client_order_id'] ?? ''));
    }

    /** @param array<string, mixed> $state @param array<string, mixed> $order */
    private static function orderMatchesPartialEvent(array $state, array $order): bool
    {
        $event = self::partialEvent($state);
        if (!is_array($event)) {
            return false;
        }

        $orderId = trim((string) ($order['id'] ?? ''));
        $clientOrderId = trim((string) ($order['client_order_id'] ?? ''));
        $references = [[
            'order_id' => trim((string) ($event['order_id'] ?? '')),
            'client_order_id' => trim((string) ($event['client_order_id'] ?? '')),
        ]];
        foreach (is_array($event['attempt_history'] ?? null) ? $event['attempt_history'] : [] as $attempt) {
            if (is_array($attempt)) {
                $references[] = [
                    'order_id' => trim((string) ($attempt['order_id'] ?? '')),
                    'client_order_id' => trim((string) ($attempt['client_order_id'] ?? '')),
                ];
            }
        }
        foreach ($references as $reference) {
            if (($orderId !== '' && $reference['order_id'] !== '' && hash_equals($reference['order_id'], $orderId))
                || ($clientOrderId !== '' && $reference['client_order_id'] !== '' && hash_equals($reference['client_order_id'], $clientOrderId))
            ) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $event */
    private static function knownFilledQty(array $event): float
    {
        $total = 0.0;
        foreach (['filled_qty_by_attempt', 'external_filled_qty_by_order'] as $key) {
            $rows = is_array($event[$key] ?? null) ? $event[$key] : [];
            foreach ($rows as $qty) {
                $total += max(0.0, (float) $qty);
            }
        }

        return $total;
    }

    /** @param array<string, mixed> $state @param array<string, mixed> $decision */
    private static function partialFingerprint(array $state, array $decision): string
    {
        $payload = is_array($state['payload'] ?? null) ? $state['payload'] : [];
        $lifecycleId = (string) ($payload['position_lifecycle_id'] ?? $state['opened_at'] ?? '');

        return hash('sha256', implode('|', [
            'partial_take_profit',
            strtoupper((string) ($state['symbol'] ?? '')),
            $lifecycleId,
            self::decimal((float) ($state['avg_entry_price'] ?? 0.0)),
            self::decimal((float) ($state['target_price'] ?? 0.0)),
            self::decimal((float) ($decision['qty'] ?? 0.0)),
        ]));
    }

    /** @param array<string, mixed> $state @param array<string, mixed> $event @return array<string, mixed> */
    private static function withPartialEvent(array $state, array $event): array
    {
        $payload = is_array($state['payload'] ?? null) ? $state['payload'] : [];
        $payload[self::PARTIAL_EVENT_KEY] = $event;
        $state['payload'] = $payload;

        return $state;
    }

    private static function partialPhaseBlocksReplacement(string $phase): bool
    {
        return in_array(strtolower($phase), ['blocked_active_sell', 'submitting', 'inflight', 'suspended', 'filled', 'retry_wait'], true);
    }

    /** @param array<string, mixed> $event */
    private static function partialKnownFilledQty(array $event): float
    {
        $attemptTotal = 0.0;
        $rows = is_array($event['filled_qty_by_attempt'] ?? null) ? $event['filled_qty_by_attempt'] : [];
        foreach ($rows as $qty) {
            $attemptTotal += max(0.0, (float) $qty);
        }

        $orderTotal = 0.0;
        $orders = is_array($event['filled_qty_by_order'] ?? null) ? $event['filled_qty_by_order'] : [];
        foreach ($orders as $qty) {
            $orderTotal += max(0.0, (float) $qty);
        }

        return max($attemptTotal, $orderTotal, max(0.0, (float) ($event['position_confirmed_filled_qty'] ?? 0.0)));
    }

    /** @param array<string, mixed> $event @return array<string, mixed> */
    private static function refreshPartialQuantities(array $event): array
    {
        $known = self::partialKnownFilledQty($event);
        $event['known_filled_qty'] = $known;
        $event['remaining_qty'] = max(0.0, (float) ($event['requested_qty'] ?? 0.0) - $known);

        return $event;
    }

    /** @param array<string, mixed> $state */
    private static function partialClientOrderIdForAttempt(array $state, string $eventId, \DateTimeImmutable $firstDetectedAt, int $attempt): string
    {
        $symbol = substr(preg_replace('/[^A-Z0-9]+/', '', strtoupper((string) ($state['symbol'] ?? 'UNK'))) ?: 'UNK', 0, 10);
        $suffix = substr(hash('sha256', $eventId . '|partial|' . $attempt), 0, 8);
        $raw = sprintf('fttmonpt_%s_%s_a%d%s', $symbol, $firstDetectedAt->format('YmdHis'), $attempt, $suffix);

        return substr($raw, 0, 48);
    }

    private static function phaseBlocksReplacement(string $phase): bool
    {
        return in_array(strtolower($phase), ['blocked_active_sell', 'submitting', 'inflight', 'suspended', 'filled', 'retry_wait'], true);
    }

    /** @param array<string, mixed> $event */
    private static function mayExpandUnsubmittedExitEvent(array $event): bool
    {
        return strtolower((string) ($event['phase'] ?? '')) === 'pending'
            && self::knownFilledQty($event) <= 0.0000001
            && max(1, (int) ($event['attempt'] ?? 1)) === 1
            && trim((string) ($event['attempt_started_at'] ?? '')) === ''
            && trim((string) ($event['order_id'] ?? '')) === ''
            && trim((string) ($event['order_status'] ?? '')) === '';
    }

    /** @param array<string, mixed> $order */
    private static function orderStillBlocksQuantity(array $order): bool
    {
        $status = strtolower((string) ($order['status'] ?? ''));
        $qty = (float) ($order['qty'] ?? 0.0);
        $filledQty = (float) ($order['filled_qty'] ?? 0.0);
        if ($status === 'filled' || ($qty > 0.0 && $filledQty + 0.0000001 >= $qty)) {
            return false;
        }
        if (in_array($status, ['canceled', 'cancelled', 'expired', 'rejected', 'replaced'], true)) {
            return false;
        }
        if (in_array($status, ['done_for_day', 'calculated'], true)
            && strtolower((string) ($order['time_in_force'] ?? 'day')) === 'day'
        ) {
            return false;
        }

        return true;
    }

    /** @param array<string, mixed> $state */
    private static function clientOrderIdForAttempt(array $state, string $eventId, \DateTimeImmutable $firstDetectedAt, int $attempt): string
    {
        $symbol = substr(preg_replace('/[^A-Z0-9]+/', '', strtoupper((string) ($state['symbol'] ?? 'UNK'))) ?: 'UNK', 0, 10);
        $suffix = substr(hash('sha256', $eventId . '|' . $attempt), 0, 8);
        $raw = sprintf('fttmonex_%s_%s_a%d%s', $symbol, $firstDetectedAt->format('YmdHis'), $attempt, $suffix);

        return substr($raw, 0, 48);
    }

    private static function timeReached(string $value, \DateTimeImmutable $now): bool
    {
        if ($value === '') {
            return false;
        }
        try {
            return $now->getTimestamp() >= (new \DateTimeImmutable($value))->getTimestamp();
        } catch (\Throwable) {
            return false;
        }
    }

    private static function dateOrNow(string $value, \DateTimeImmutable $now): \DateTimeImmutable
    {
        try {
            return $value !== '' ? new \DateTimeImmutable($value) : $now;
        } catch (\Throwable) {
            return $now;
        }
    }

    private static function decimal(float $value): string
    {
        return number_format($value, 8, '.', '');
    }
}
