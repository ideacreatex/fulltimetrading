<?php

declare(strict_types=1);

namespace FulltimeTrading\Trading;

final class TacticalRotationPaperPlanner
{
    private const EXECUTION_IDENTITY_SCHEMA = 2;

    /**
     * @param array<string,mixed> $target
     * @param array<string,array<string,mixed>> $positions
     * @return list<array<string,mixed>>
     */
    public function plan(
        string $runId,
        string $sleeveId,
        string $sessionDate,
        array $target,
        float $virtualEquity,
        float $referencePrice,
        array $positions,
        float $maximumGross = 1.20,
    ): array {
        $action = (string) ($target['action'] ?? '');
        $due = ($target['rebalance_due_next_session'] ?? false) === true;
        if (($target['execution'] ?? null) !== 'next_session_open') {
            throw new \RuntimeException('Tactical target execution contract is not next-session open.');
        }
        $positions = $this->normalizePositions($positions);
        if (!$due) {
            if ($action !== 'hold'
                || trim((string) ($target['symbol'] ?? '')) !== ''
                || abs((float) ($target['gross'] ?? 0.0)) > 1.0e-12) {
                throw new \RuntimeException('A non-due tactical target must be an explicit hold.');
            }
            $modelCurrentSymbol = strtoupper(trim((string) ($target['current_symbol'] ?? '')));
            if ($modelCurrentSymbol !== '' && !preg_match('/^[A-Z][A-Z0-9.\-]{0,14}$/D', $modelCurrentSymbol)) {
                throw new \RuntimeException('A hold target has an invalid model current symbol.');
            }
            $stalePositions = array_filter(
                $positions,
                static fn (array $position, string $ownedSymbol): bool =>
                    (float) ($position['qty'] ?? 0.0) > 0.0
                    && ($modelCurrentSymbol === '' || $ownedSymbol !== $modelCurrentSymbol),
                ARRAY_FILTER_USE_BOTH,
            );
            $signalDate = (string) ($target['signal_date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $signalDate)
                || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $sessionDate)
                || $signalDate >= $sessionDate) {
                throw new \InvalidArgumentException('Stale hold and session dates must be canonical.');
            }
            $staleTarget = array_replace($target, [
                'stale_model_mismatch' => true,
                'model_current_symbol' => $modelCurrentSymbol !== '' ? $modelCurrentSymbol : null,
            ]);
            $legs = [];
            foreach ($stalePositions as $ownedSymbol => $position) {
                $legs[] = $this->leg(
                    $runId,
                    $sleeveId,
                    $signalDate,
                    $sessionDate,
                    'exit',
                    $ownedSymbol,
                    'sell',
                    (int) round((float) $position['qty']),
                    $staleTarget,
                );
            }

            // If the whole resize session was missed, the next artifact is a
            // hold in the model's already-resized symbol. Reduce excess paper
            // quantity at the current broker mark, but never buy the missing
            // quantity. This restores risk without chasing a stale entry.
            if ($modelCurrentSymbol !== '' && isset($positions[$modelCurrentSymbol]) && $referencePrice > 0.0) {
                $currentGross = (float) ($target['current_gross'] ?? 0.0);
                if (!is_finite($virtualEquity) || $virtualEquity <= 0.0
                    || !is_finite($currentGross) || $currentGross <= 0.0
                    || $currentGross > $maximumGross + 1.0e-12) {
                    throw new \RuntimeException('A held model position has an invalid recovery sizing state.');
                }
                $ownedQty = (int) floor((float) $positions[$modelCurrentSymbol]['qty'] + 1.0e-9);
                $maximumModelQtyAtRecovery = (int) floor(($virtualEquity * $currentGross) / $referencePrice);
                if ($ownedQty > $maximumModelQtyAtRecovery) {
                    $resizeTarget = array_replace($target, [
                        'stale_model_resize_reduction' => true,
                        'recovery_reference_price' => $referencePrice,
                    ]);
                    $legs[] = $this->leg(
                        $runId,
                        $sleeveId,
                        $signalDate,
                        $sessionDate,
                        'exit',
                        $modelCurrentSymbol,
                        'sell',
                        $ownedQty - $maximumModelQtyAtRecovery,
                        $resizeTarget,
                    );
                }
            }

            return $legs;
        }
        $signalDate = (string) ($target['signal_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $signalDate)
            || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $sessionDate)
            || $signalDate >= $sessionDate) {
            throw new \InvalidArgumentException('Target and session dates must be canonical.');
        }
        if (!is_finite($virtualEquity) || $virtualEquity <= 0.0) {
            throw new \RuntimeException('Sleeve virtual equity is not positive.');
        }
        $symbol = strtoupper(trim((string) ($target['symbol'] ?? '')));
        $gross = (float) ($target['gross'] ?? 0.0);
        if (!is_finite($gross) || $gross < 0.0 || $gross > $maximumGross + 1.0e-12) {
            throw new \RuntimeException('Target gross exceeds the frozen paper envelope.');
        }
        if ($symbol === '' && $gross > 1.0e-12) {
            throw new \RuntimeException('A positive-gross target cannot omit its symbol.');
        }
        if (in_array($action, ['rebalance', 'resize_or_hold'], true)) {
            if ($symbol === '' || $gross <= 0.0) {
                throw new \RuntimeException('An invested rebalance requires a positive-gross symbol.');
            }
        } elseif (in_array($action, ['exit_to_cash', 'hold_cash'], true)) {
            if ($symbol !== '' || $gross > 1.0e-12) {
                throw new \RuntimeException('A cash target must have no symbol and zero gross.');
            }
        } else {
            throw new \RuntimeException('Unknown due tactical target action.');
        }
        if ($symbol !== '' && (!preg_match('/^[A-Z][A-Z0-9.\-]{0,14}$/', $symbol) || $referencePrice <= 0.0)) {
            throw new \RuntimeException('Executable target requires a valid symbol and sizing reference price.');
        }
        $desiredQty = $symbol === '' ? 0 : (int) floor(($virtualEquity * $gross) / $referencePrice);
        $legs = [];
        foreach ($positions as $ownedSymbol => $position) {
            $ownedSymbol = strtoupper((string) $ownedSymbol);
            $ownedQty = (int) floor((float) ($position['qty'] ?? 0.0) + 1.0e-9);
            $keepQty = $ownedSymbol === $symbol ? $desiredQty : 0;
            if ($ownedQty > $keepQty) {
                $legs[] = $this->leg(
                    $runId,
                    $sleeveId,
                    $signalDate,
                    $sessionDate,
                    'exit',
                    $ownedSymbol,
                    'sell',
                    $ownedQty - $keepQty,
                    $target,
                );
            }
        }
        $currentDesiredQty = isset($positions[$symbol]) ? (int) floor((float) $positions[$symbol]['qty'] + 1.0e-9) : 0;
        if ($symbol !== '' && $desiredQty > $currentDesiredQty) {
            $legs[] = $this->leg(
                $runId,
                $sleeveId,
                $signalDate,
                $sessionDate,
                'entry',
                $symbol,
                'buy',
                $desiredQty - $currentDesiredQty,
                $target,
            );
        }

        return $legs;
    }

    /**
     * A rotation is deliberately two-phase: persist/submit every sell first,
     * then create the replacement DAY buy only after every sell for that
     * sleeve/signal/session is reconciled as fully filled. A cash entry has no
     * sell dependency and remains OPG.
     *
     * @param list<array<string,mixed>> $legs
     * @param list<array<string,mixed>> $historicalIntents
     * @return list<array<string,mixed>>
     */
    public function prepareSequentialExecution(
        array $legs,
        array $historicalIntents,
        array $window = [],
    ): array
    {
        $currentExitSleeves = [];
        foreach ($legs as $leg) {
            if (strtolower((string) ($leg['side'] ?? '')) === 'sell') {
                $currentExitSleeves[(string) ($leg['sleeve_id'] ?? '')] = true;
            }
        }

        $sells = [];
        $buys = [];
        foreach ($legs as $leg) {
            self::assertExecutionIdentity($leg);
            if (strtolower((string) $leg['side']) !== 'buy') {
                $terminalDependencies = [];
                $failedRecoveryExists = false;
                foreach ($historicalIntents as $historical) {
                    if ((string) ($historical['run_id'] ?? '') !== (string) $leg['run_id']
                        || (string) ($historical['sleeve_id'] ?? '') !== (string) $leg['sleeve_id']
                        || (string) ($historical['signal_date'] ?? '') !== (string) $leg['signal_date']
                        || (string) ($historical['scheduled_session'] ?? '') !== (string) $leg['scheduled_session']
                        || strtoupper((string) ($historical['symbol'] ?? '')) !== (string) $leg['symbol']
                        || strtolower((string) ($historical['side'] ?? '')) !== 'sell'
                        || !self::isTerminalIncomplete($historical)) {
                        continue;
                    }
                    if (($historical['payload']['terminal_recovery_sell'] ?? false) === true) {
                        $failedRecoveryExists = true;
                        break;
                    }
                    $terminalDependencies[] = strtolower((string) $historical['decision_id']);
                }
                if ($failedRecoveryExists) {
                    // A failed recovery remains paused for operator review; do
                    // not generate an unbounded chain of market orders.
                    continue;
                }
                if (($window['risk_exit_day_allowed'] ?? false) === true) {
                    if ($terminalDependencies !== []) {
                        sort($terminalDependencies, SORT_STRING);
                        $leg['epoch_key'] = hash(
                            'sha256',
                            'terminal-recovery-v1|' . (string) $leg['epoch_key'] . '|' . implode('|', $terminalDependencies),
                        );
                    }
                    $leg = $this->bindExecutionContract(
                        $leg,
                        'day',
                        [],
                        ($window['late_risk_reduction'] ?? false) === true,
                        $terminalDependencies,
                    );
                }
                $sells[] = $leg;
                continue;
            }

            $sleeveId = (string) $leg['sleeve_id'];
            if (isset($currentExitSleeves[$sleeveId])) {
                // The sell has not even been persisted/reconciled yet.
                continue;
            }

            $matchingExits = array_values(array_filter(
                $historicalIntents,
                static fn (array $intent): bool =>
                    (string) ($intent['run_id'] ?? '') === (string) $leg['run_id']
                    && (string) ($intent['sleeve_id'] ?? '') === $sleeveId
                    && (string) ($intent['signal_date'] ?? '') === (string) $leg['signal_date']
                    && (string) ($intent['scheduled_session'] ?? '') === (string) $leg['scheduled_session']
                    && strtolower((string) ($intent['side'] ?? '')) === 'sell',
            ));
            if ($matchingExits === []) {
                $buys[] = $leg;
                continue;
            }

            $dependencyIds = [];
            $allFullyFilled = true;
            $hasLateRiskReduction = false;
            foreach ($matchingExits as $exit) {
                $decisionId = strtolower(trim((string) ($exit['decision_id'] ?? '')));
                if (!preg_match('/^[a-f0-9]{64}$/', $decisionId)
                    || strtolower((string) ($exit['status'] ?? '')) !== 'filled'
                    || (float) ($exit['cumulative_filled_qty'] ?? 0.0) + 1.0e-9
                        < (float) ($exit['requested_qty'] ?? 0.0)) {
                    $allFullyFilled = false;
                    break;
                }
                $dependencyIds[] = $decisionId;
                $hasLateRiskReduction = $hasLateRiskReduction
                    || ($exit['payload']['late_risk_reduction'] ?? false) === true;
            }
            if (!$allFullyFilled
                || $hasLateRiskReduction
                || ($window['late_risk_reduction'] ?? false) === true) {
                // A partial/terminal/unknown exit can never unlock new risk.
                // A stale risk reduction also never revives a stale entry.
                continue;
            }
            $buys[] = $this->bindExecutionContract($leg, 'day', $dependencyIds);
        }

        // Across sleeves, reductions are always claimed before fresh risk.
        return array_merge($sells, $buys);
    }

    /**
     * Reserve sell capacity across every sleeve before any broker submission.
     * Alpaca may allow shorting, so validating each leg independently is not
     * sufficient: two valid 100-share sleeve exits must not both submit when
     * the broker owns only 150 shares in aggregate.
     *
     * @param list<array<string,mixed>> $legs
     * @param array<string,float|int> $actualBrokerPositions
     * @param array<string,array<string,array<string,mixed>>> $ledgerPositionsBySleeve
     * @return list<array<string,mixed>>
     */
    public function capAggregateSellReservations(
        array $legs,
        array $actualBrokerPositions,
        array $ledgerPositionsBySleeve,
        float $tolerance = 0.000001,
    ): array {
        if (!is_finite($tolerance) || $tolerance < 0.0) {
            throw new \InvalidArgumentException('Aggregate sell tolerance must be finite and non-negative.');
        }
        $actual = [];
        foreach ($actualBrokerPositions as $symbol => $qty) {
            $symbol = strtoupper(trim((string) $symbol));
            $qty = (float) $qty;
            if (!preg_match('/^[A-Z][A-Z0-9.\-]{0,14}$/D', $symbol)
                || !is_finite($qty) || $qty < 0.0 || isset($actual[$symbol])) {
                throw new \RuntimeException('Aggregate broker sell capacity is malformed.');
            }
            $actual[$symbol] = $qty;
        }

        $ledgerTotals = [];
        $sleeveOwned = [];
        foreach ($ledgerPositionsBySleeve as $sleeveId => $positions) {
            if (!is_string($sleeveId) || !is_array($positions)) {
                throw new \RuntimeException('Aggregate tactical ledger is malformed.');
            }
            foreach ($positions as $symbol => $position) {
                $symbol = strtoupper(trim((string) $symbol));
                $qty = (float) ($position['qty'] ?? 0.0);
                if (!preg_match('/^[A-Z][A-Z0-9.\-]{0,14}$/D', $symbol)
                    || !is_finite($qty) || $qty < 0.0
                    || abs($qty - round($qty)) > $tolerance
                    || isset($sleeveOwned[$sleeveId][$symbol])) {
                    throw new \RuntimeException('Aggregate tactical sleeve quantity is malformed.');
                }
                $sleeveOwned[$sleeveId][$symbol] = $qty;
                $ledgerTotals[$symbol] = (float) ($ledgerTotals[$symbol] ?? 0.0) + $qty;
            }
        }

        $sells = [];
        $buys = [];
        $seenSellEpochs = [];
        foreach ($legs as $leg) {
            self::assertExecutionIdentity($leg);
            if (strtolower((string) $leg['side']) !== 'sell') {
                $buys[] = $leg;
                continue;
            }
            $epochKey = (string) $leg['epoch_key'];
            if (isset($seenSellEpochs[$epochKey])) {
                throw new \RuntimeException('Duplicate sell epoch in aggregate reservation set.');
            }
            $seenSellEpochs[$epochKey] = true;
            $symbol = strtoupper((string) $leg['symbol']);
            $sleeveId = (string) $leg['sleeve_id'];
            if ((float) $leg['requested_qty'] > (float) ($sleeveOwned[$sleeveId][$symbol] ?? 0.0) + $tolerance) {
                throw new \RuntimeException('Sell leg exceeds its sleeve-owned tactical quantity.');
            }
            $sells[] = $leg;
        }
        usort($sells, static function (array $left, array $right): int {
            $leftKey = [
                strtoupper((string) $left['symbol']),
                ($left['payload']['terminal_recovery_sell'] ?? false) === true ? 0 : 1,
                (string) $left['sleeve_id'],
                (string) $left['decision_id'],
            ];
            $rightKey = [
                strtoupper((string) $right['symbol']),
                ($right['payload']['terminal_recovery_sell'] ?? false) === true ? 0 : 1,
                (string) $right['sleeve_id'],
                (string) $right['decision_id'],
            ];

            return $leftKey <=> $rightKey;
        });

        $remaining = [];
        foreach (array_unique(array_merge(array_keys($actual), array_keys($ledgerTotals))) as $symbol) {
            $remaining[$symbol] = max(0, (int) floor(min(
                (float) ($actual[$symbol] ?? 0.0),
                (float) ($ledgerTotals[$symbol] ?? 0.0),
            ) + $tolerance));
        }
        $reserved = [];
        foreach ($sells as $leg) {
            $symbol = strtoupper((string) $leg['symbol']);
            $requested = (int) round((float) $leg['requested_qty']);
            $available = (int) ($remaining[$symbol] ?? 0);
            $quantity = min($requested, $available);
            if ($quantity <= 0) {
                continue;
            }
            if ($quantity < $requested
                && ($leg['payload']['terminal_recovery_sell'] ?? false) === true) {
                // A terminal recovery is causally bound to its exact remainder;
                // a smaller body would need a new reviewed recovery contract.
                continue;
            }
            $remaining[$symbol] = $available - $quantity;
            $payload = (array) $leg['payload'];
            $payload['aggregate_sell_reservation'] = [
                'contract' => 'min_broker_long_tactical_ledger',
                'broker_long_snapshot_qty' => (float) ($actual[$symbol] ?? 0.0),
                'tactical_ledger_qty' => (float) ($ledgerTotals[$symbol] ?? 0.0),
                'original_requested_qty' => $requested,
                'reserved_qty' => $quantity,
            ];
            $leg['payload'] = $payload;
            $leg['requested_qty'] = $quantity;
            $leg = $this->bindExecutionContract(
                $leg,
                strtolower((string) $payload['time_in_force']),
                (array) ($payload['required_exit_decision_ids'] ?? []),
                ($payload['late_risk_reduction'] ?? false) === true,
                (array) ($payload['required_terminal_decision_ids'] ?? []),
            );
            $reserved[] = $leg;
        }

        return array_merge($reserved, $buys);
    }

    /**
     * @param list<array<string,mixed>> $dependencyIntents
     */
    public function isAllowedInWindow(array $intent, array $window, array $dependencyIntents = []): bool
    {
        self::assertExecutionIdentity($intent);
        $timeInForce = strtolower((string) $intent['payload']['time_in_force']);
        if ($timeInForce === 'opg') {
            return ($window['opg_submit_allowed'] ?? false) === true;
        }
        if (strtolower((string) $intent['side']) === 'sell') {
            return ($intent['payload']['risk_exit_day_fallback'] ?? false) === true
                && ($window['risk_exit_day_allowed'] ?? false) === true
                && (($intent['payload']['late_risk_reduction'] ?? false) === true)
                    === (($window['late_risk_reduction'] ?? false) === true);
        }
        if (($window['rotation_reentry_allowed'] ?? false) !== true
            || ($intent['payload']['late_risk_reduction'] ?? false) === true) {
            return false;
        }

        $expected = (array) ($intent['payload']['required_exit_decision_ids'] ?? []);
        $actual = [];
        foreach ($dependencyIntents as $dependency) {
            $decisionId = strtolower(trim((string) ($dependency['decision_id'] ?? '')));
            if ($decisionId === '' || isset($actual[$decisionId])) {
                return false;
            }
            $actual[$decisionId] = $dependency;
        }
        if (count($expected) !== count($actual)) {
            return false;
        }
        foreach ($expected as $decisionId) {
            $dependency = $actual[$decisionId] ?? null;
            if (!is_array($dependency)
                || (string) ($dependency['run_id'] ?? '') !== (string) $intent['run_id']
                || (string) ($dependency['sleeve_id'] ?? '') !== (string) $intent['sleeve_id']
                || (string) ($dependency['signal_date'] ?? '') !== (string) $intent['signal_date']
                || (string) ($dependency['scheduled_session'] ?? '') !== (string) $intent['scheduled_session']
                || strtolower((string) ($dependency['side'] ?? '')) !== 'sell'
                || strtolower((string) ($dependency['status'] ?? '')) !== 'filled'
                || (float) ($dependency['cumulative_filled_qty'] ?? 0.0) + 1.0e-9
                    < (float) ($dependency['requested_qty'] ?? 0.0)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Bind the actual broker body and every sell dependency into both the
     * decision identity and deterministic Alpaca client_order_id.
     *
     * @param list<string> $requiredExitDecisionIds
     * @return array<string,mixed>
     */
    public function bindExecutionContract(
        array $leg,
        string $timeInForce,
        array $requiredExitDecisionIds = [],
        bool $lateRiskReduction = false,
        array $terminalRecoveryDecisionIds = [],
    ): array {
        $timeInForce = strtolower(trim($timeInForce));
        $dependencies = self::normalizeDependencies($requiredExitDecisionIds);
        $terminalDependencies = self::normalizeDependencies($terminalRecoveryDecisionIds);
        if ($timeInForce === 'opg') {
            if ($dependencies !== [] || $terminalDependencies !== [] || $lateRiskReduction) {
                throw new \InvalidArgumentException('An OPG intent cannot carry recovery dependencies.');
            }
        } elseif ($timeInForce === 'day') {
            $side = strtolower((string) ($leg['side'] ?? ''));
            $kind = (string) ($leg['leg'] ?? '');
            $validRotationEntry = $side === 'buy' && $kind === 'entry' && $dependencies !== [] && !$lateRiskReduction;
            $validRiskExit = $side === 'sell' && $kind === 'exit' && $dependencies === [];
            if (!$validRotationEntry && !$validRiskExit) {
                throw new \InvalidArgumentException('A DAY intent must be a dependency entry or bounded risk exit.');
            }
        } else {
            throw new \InvalidArgumentException('Unsupported tactical time-in-force.');
        }

        $payload = (array) ($leg['payload'] ?? []);
        $payload['time_in_force'] = $timeInForce;
        if ($timeInForce === 'day') {
            if (strtolower((string) $leg['side']) === 'buy') {
                $payload['deferred_rotation_reentry'] = true;
                $payload['required_exit_decision_ids'] = $dependencies;
                unset($payload['risk_exit_day_fallback']);
                $payload['late_risk_reduction'] = false;
            } else {
                $payload['risk_exit_day_fallback'] = true;
                $payload['late_risk_reduction'] = $lateRiskReduction;
                $payload['terminal_recovery_sell'] = $terminalDependencies !== [];
                $payload['required_terminal_decision_ids'] = $terminalDependencies;
                unset(
                    $payload['deferred_rotation_reentry'],
                    $payload['required_exit_decision_id'],
                    $payload['required_exit_decision_ids'],
                );
            }
        } else {
            unset(
                $payload['deferred_rotation_reentry'],
                $payload['required_exit_decision_id'],
                $payload['required_exit_decision_ids'],
                $payload['risk_exit_day_fallback'],
                $payload['late_risk_reduction'],
                $payload['terminal_recovery_sell'],
                $payload['required_terminal_decision_ids'],
            );
        }
        $leg['payload'] = $payload;
        $canonical = self::executionIdentityPayload($leg);
        $identityJson = json_encode(
            $canonical,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        $leg['decision_id'] = hash('sha256', 'decision-v2|' . $identityJson);
        $leg['client_order_id'] = 'ftr4-' . substr(hash('sha256', 'alpaca-client-v2|' . $identityJson), 0, 32);
        $leg['payload']['execution_identity_sha256'] = hash('sha256', $identityJson);

        return $leg;
    }

    /** @param array<string,mixed> $intent */
    public static function assertExecutionIdentity(array $intent): void
    {
        $canonical = self::executionIdentityPayload($intent);
        $identityJson = json_encode(
            $canonical,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        $expectedDecision = hash('sha256', 'decision-v2|' . $identityJson);
        $expectedClient = 'ftr4-' . substr(hash('sha256', 'alpaca-client-v2|' . $identityJson), 0, 32);
        $expectedContract = hash('sha256', $identityJson);
        $epochIdentity = implode('|', [
            (string) ($intent['run_id'] ?? ''),
            (string) ($intent['sleeve_id'] ?? ''),
            (string) ($intent['signal_date'] ?? ''),
            (string) ($intent['scheduled_session'] ?? ''),
            (string) ($intent['leg'] ?? ''),
            (string) ($intent['leg'] ?? '') === 'exit' ? strtoupper((string) ($intent['symbol'] ?? '')) : 'target',
        ]);
        $expectedEpoch = hash('sha256', $epochIdentity);
        if (($intent['payload']['terminal_recovery_sell'] ?? false) === true) {
            $expectedEpoch = hash(
                'sha256',
                'terminal-recovery-v1|' . $expectedEpoch . '|' . implode('|', $canonical['required_terminal_decision_ids']),
            );
        }
        if (!hash_equals($expectedDecision, strtolower((string) ($intent['decision_id'] ?? '')))
            || !hash_equals($expectedClient, (string) ($intent['client_order_id'] ?? ''))
            || !hash_equals($expectedContract, strtolower((string) ($intent['payload']['execution_identity_sha256'] ?? '')))
            || !hash_equals($expectedEpoch, strtolower((string) ($intent['epoch_key'] ?? '')))) {
            throw new \RuntimeException('Tactical intent execution identity drift.');
        }
    }

    /** @param array<string,mixed> $target @return array<string,mixed> */
    private function leg(
        string $runId,
        string $sleeveId,
        string $signalDate,
        string $sessionDate,
        string $leg,
        string $symbol,
        string $side,
        int $qty,
        array $target,
    ): array {
        $epochIdentity = implode('|', [
            $runId,
            $sleeveId,
            $signalDate,
            $sessionDate,
            $leg,
            $leg === 'exit' ? $symbol : 'target',
        ]);
        $epochKey = hash('sha256', $epochIdentity);
        return $this->bindExecutionContract([
            'epoch_key' => $epochKey,
            'run_id' => $runId,
            'sleeve_id' => $sleeveId,
            'signal_date' => $signalDate,
            'scheduled_session' => $sessionDate,
            'leg' => $leg,
            'symbol' => $symbol,
            'side' => $side,
            'requested_qty' => $qty,
            'payload' => [
                'target_action' => $target['action'] ?? null,
                'target_gross' => $target['gross'] ?? null,
                'reference_price_contract' => ($target['stale_model_resize_reduction'] ?? false) === true
                    ? 'broker_current_mark_risk_reduction_only'
                    : 'completed_signal_bar_close',
                'stale_model_mismatch' => ($target['stale_model_mismatch'] ?? false) === true,
                'stale_model_resize_reduction' => ($target['stale_model_resize_reduction'] ?? false) === true,
                'recovery_reference_price' => $target['recovery_reference_price'] ?? null,
                'model_current_symbol' => $target['model_current_symbol'] ?? null,
            ],
        ], 'opg');
    }

    /** @param array<string,mixed> $intent @return array<string,mixed> */
    private static function executionIdentityPayload(array $intent): array
    {
        $timeInForce = strtolower(trim((string) ($intent['payload']['time_in_force'] ?? '')));
        $dependencies = self::normalizeDependencies((array) ($intent['payload']['required_exit_decision_ids'] ?? []));
        $terminalDependencies = self::normalizeDependencies((array) ($intent['payload']['required_terminal_decision_ids'] ?? []));
        $side = strtolower((string) ($intent['side'] ?? ''));
        $kind = (string) ($intent['leg'] ?? '');
        $lateRiskReduction = ($intent['payload']['late_risk_reduction'] ?? false) === true;
        $staleModelMismatch = ($intent['payload']['stale_model_mismatch'] ?? false) === true;
        $staleModelResizeReduction = ($intent['payload']['stale_model_resize_reduction'] ?? false) === true;
        $recoveryReferencePrice = $intent['payload']['recovery_reference_price'] ?? null;
        $modelCurrentSymbol = strtoupper(trim((string) ($intent['payload']['model_current_symbol'] ?? '')));
        if ($staleModelMismatch
            && ($side !== 'sell'
                || $kind !== 'exit'
                || ($modelCurrentSymbol !== '' && !preg_match('/^[A-Z][A-Z0-9.\-]{0,14}$/D', $modelCurrentSymbol)))) {
            throw new \RuntimeException('Invalid stale-model de-risk contract.');
        }
        if ($staleModelResizeReduction
            && ($staleModelMismatch
                || $side !== 'sell'
                || $kind !== 'exit'
                || (!is_int($recoveryReferencePrice) && !is_float($recoveryReferencePrice))
                || !is_finite((float) $recoveryReferencePrice)
                || (float) $recoveryReferencePrice <= 0.0)) {
            throw new \RuntimeException('Invalid stale-model resize-reduction contract.');
        }
        if (!$staleModelResizeReduction && $recoveryReferencePrice !== null) {
            throw new \RuntimeException('Unexpected recovery reference price outside resize reduction.');
        }
        $validDayBuy = $timeInForce === 'day'
            && $side === 'buy'
            && $kind === 'entry'
            && $dependencies !== []
            && $terminalDependencies === []
            && ($intent['payload']['deferred_rotation_reentry'] ?? false) === true
            && !$lateRiskReduction;
        $validDaySell = $timeInForce === 'day'
            && $side === 'sell'
            && $kind === 'exit'
            && $dependencies === []
            && ($intent['payload']['risk_exit_day_fallback'] ?? false) === true
            && (($intent['payload']['terminal_recovery_sell'] ?? false) === true) === ($terminalDependencies !== []);
        if (!in_array($timeInForce, ['opg', 'day'], true)
            || ($timeInForce === 'opg' && ($dependencies !== [] || $terminalDependencies !== [] || $lateRiskReduction))
            || ($timeInForce === 'day' && !$validDayBuy && !$validDaySell)) {
            throw new \RuntimeException('Invalid tactical execution contract.');
        }
        $qty = (float) ($intent['requested_qty'] ?? 0.0);
        if ($qty <= 0.0 || abs($qty - round($qty)) > 1.0e-9) {
            throw new \RuntimeException('Tactical execution identity requires positive whole-share quantity.');
        }

        return [
            'schema' => self::EXECUTION_IDENTITY_SCHEMA,
            'run_id' => (string) ($intent['run_id'] ?? ''),
            'sleeve_id' => (string) ($intent['sleeve_id'] ?? ''),
            'signal_date' => (string) ($intent['signal_date'] ?? ''),
            'scheduled_session' => (string) ($intent['scheduled_session'] ?? ''),
            'leg' => (string) ($intent['leg'] ?? ''),
            'order' => [
                'symbol' => strtoupper((string) ($intent['symbol'] ?? '')),
                'qty' => (string) (int) round($qty),
                'side' => strtolower((string) ($intent['side'] ?? '')),
                'type' => 'market',
                'time_in_force' => $timeInForce,
                'extended_hours' => false,
            ],
            'required_exit_decision_ids' => $dependencies,
            'required_terminal_decision_ids' => $terminalDependencies,
            'late_risk_reduction' => $lateRiskReduction,
            'stale_model_mismatch' => $staleModelMismatch,
            'stale_model_resize_reduction' => $staleModelResizeReduction,
            'recovery_reference_price' => $staleModelResizeReduction
                ? sprintf('%.8F', (float) $recoveryReferencePrice)
                : null,
            'model_current_symbol' => $staleModelMismatch
                ? $modelCurrentSymbol
                : null,
        ];
    }

    /** @param array<string,array<string,mixed>> $positions @return array<string,array<string,mixed>> */
    private function normalizePositions(array $positions): array
    {
        $normalized = [];
        foreach ($positions as $ownedSymbol => $position) {
            $canonical = strtoupper(trim((string) $ownedSymbol));
            $qty = (float) ($position['qty'] ?? 0.0);
            if (!preg_match('/^[A-Z][A-Z0-9.\-]{0,14}$/', $canonical)
                || !is_finite($qty) || $qty < 0.0 || abs($qty - round($qty)) > 1.0e-6
                || isset($normalized[$canonical])) {
                throw new \RuntimeException('Sleeve positions must be unique canonical whole-share longs.');
            }
            $position['qty'] = round($qty);
            $normalized[$canonical] = $position;
        }

        return $normalized;
    }

    /** @param array<string,mixed> $intent */
    private static function isTerminalIncomplete(array $intent): bool
    {
        return in_array(strtolower((string) ($intent['status'] ?? '')), [
            'filled', 'canceled', 'cancelled', 'expired', 'rejected', 'done_for_day', 'ambiguous_missed',
        ], true)
            && (float) ($intent['cumulative_filled_qty'] ?? 0.0) + 1.0e-9
                < (float) ($intent['requested_qty'] ?? 0.0);
    }

    /** @param list<string> $decisionIds @return list<string> */
    private static function normalizeDependencies(array $decisionIds): array
    {
        $normalized = array_values(array_unique(array_map(
            static fn (mixed $id): string => strtolower(trim((string) $id)),
            $decisionIds,
        )));
        foreach ($normalized as $id) {
            if (!preg_match('/^[a-f0-9]{64}$/', $id)) {
                throw new \InvalidArgumentException('Rotation dependency must be a SHA-256 decision id.');
            }
        }
        sort($normalized, SORT_STRING);

        return $normalized;
    }
}
