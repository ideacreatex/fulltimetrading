<?php

declare(strict_types=1);

namespace FulltimeTrading\Trading;

final class TacticalSignalArtifactGuard
{
    /**
     * Validate the entire executable shadow before any notification, broker
     * transition decision, or order reconciliation can consume it.
     *
     * @param array<string,mixed> $artifact
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $paper
     * @param array<string,mixed> $expectedImplementation
     */
    public static function validateArtifact(
        array $artifact,
        array $profile,
        array $paper,
        array $expectedImplementation,
    ): void {
        $declaredDecisionHash = $artifact['decision_sha256'] ?? null;
        if (!is_string($declaredDecisionHash)
            || preg_match('/^[a-f0-9]{64}$/D', $declaredDecisionHash) !== 1
            || !hash_equals(self::decisionSha256($artifact), $declaredDecisionHash)) {
            throw new \RuntimeException('Signal artifact decision hash mismatch.');
        }
        if (($artifact['schema'] ?? null) !== 1
            || ($artifact['validation_selected'] ?? null) !== true
            || ($artifact['production_approved'] ?? null) !== false
            || ($artifact['paper_shadow_enabled'] ?? null) !== true
            || ($artifact['order_submission_enabled'] ?? null) !== false
            || (string) ($artifact['profile'] ?? '') !== (string) ($profile['profile'] ?? '')
            || (string) ($artifact['profile'] ?? '') !== (string) ($paper['profile'] ?? '')
            || (string) ($artifact['causal_contract'] ?? '') !== 'completed close D ranks symbols; target can execute only at open D+1'
            || (string) ($artifact['order_submission_block_reason'] ?? '') !== (string) ($profile['order_submission_block_reason'] ?? '')) {
            throw new \RuntimeException('Signal artifact validation identity is invalid.');
        }
        self::validateImplementationIdentity(
            is_array($artifact['implementation'] ?? null) ? $artifact['implementation'] : [],
            $expectedImplementation,
        );

        $sleeves = $profile['sleeves'] ?? null;
        $targets = $artifact['targets'] ?? null;
        if (!is_array($sleeves) || $sleeves === [] || !is_array($targets)
            || array_keys($targets) !== array_keys($sleeves)) {
            throw new \RuntimeException('Signal artifact sleeve set or order mismatch.');
        }
        $maximumTargetGross = $paper['execution']['maximum_target_gross'] ?? null;
        if (!self::isFiniteNumber($maximumTargetGross) || (float) $maximumTargetGross <= 0.0) {
            throw new \RuntimeException('Signal artifact maximum-target-gross contract is invalid.');
        }
        $maximumTargetGross = (float) $maximumTargetGross;
        $signalDate = null;
        foreach ($targets as $sleeveId => $target) {
            if (!is_string($sleeveId) || !is_array($target)) {
                throw new \RuntimeException('Signal artifact target row is malformed.');
            }
            foreach ([
                'signal_date', 'execution', 'action', 'rebalance_due_next_session',
                'current_symbol', 'current_gross', 'symbol', 'gross', 'ranked_symbol',
                'ranked_gross', 'risk_exit_pending', 'drawdown_rearm_pending',
                'circuit_cooldown_left', 'cooldown_after_next_open_tick', 'shadow_only',
                'allocation', 'initial_equity', 'capital_scope',
                'sizing_reference_close', 'sizing_reference_session',
            ] as $requiredField) {
                if (!array_key_exists($requiredField, $target)) {
                    throw new \RuntimeException('Signal artifact target is missing required field ' . $requiredField . '.');
                }
            }
            $rowDate = (string) $target['signal_date'];
            self::requireCanonicalDate($rowDate, 'Signal artifact target date');
            $signalDate ??= $rowDate;
            if (!hash_equals($signalDate, $rowDate)
                || $target['execution'] !== 'next_session_open'
                || $target['shadow_only'] !== true
                || $target['capital_scope'] !== 'independent_static_sleeve') {
                throw new \RuntimeException('Signal artifact target execution identity is invalid.');
            }

            $definition = $sleeves[$sleeveId] ?? null;
            if (!is_array($definition)) {
                throw new \RuntimeException('Signal artifact references an unknown sleeve.');
            }
            $allocation = $target['allocation'];
            $expectedAllocation = $definition['allocation'] ?? null;
            if (!self::isFiniteNumber($allocation) || !self::isFiniteNumber($expectedAllocation)
                || abs((float) $allocation - (float) $expectedAllocation) > 1.0e-12
                || !self::isFiniteNumber($target['initial_equity'])
                || (float) $target['initial_equity'] <= 0.0) {
                throw new \RuntimeException('Signal artifact sleeve capital identity is invalid.');
            }
            $sleeveConfig = array_replace($profile, (array) ($definition['config'] ?? []));
            $universe = self::canonicalUniverse((array) ($sleeveConfig['universe'] ?? []));
            $targetSymbol = self::targetSymbol($target['symbol'], $universe, 'symbol');
            $currentSymbol = self::targetSymbol($target['current_symbol'], $universe, 'current_symbol');
            $rankedSymbol = self::targetSymbol($target['ranked_symbol'], $universe, 'ranked_symbol');
            $gross = self::boundedGross($target['gross'], $maximumTargetGross, 'gross');
            $rankedGross = self::boundedGross($target['ranked_gross'], $maximumTargetGross, 'ranked_gross');
            $currentGross = self::boundedGross(
                $target['current_gross'],
                $maximumTargetGross,
                'current_gross',
            );
            self::assertSymbolGrossPair($targetSymbol, $gross, 'target');
            self::assertSymbolGrossPair($currentSymbol, $currentGross, 'current');
            self::assertSymbolGrossPair($rankedSymbol, $rankedGross, 'ranked');

            if (!is_bool($target['rebalance_due_next_session'])
                || !is_bool($target['risk_exit_pending'])
                || !is_bool($target['drawdown_rearm_pending'])
                || !is_int($target['circuit_cooldown_left'])
                || !is_int($target['cooldown_after_next_open_tick'])
                || $target['circuit_cooldown_left'] < 0
                || $target['cooldown_after_next_open_tick'] < 0) {
                throw new \RuntimeException('Signal artifact target state fields are invalid.');
            }
            $due = $target['rebalance_due_next_session'];
            $action = (string) $target['action'];
            if ($target['risk_exit_pending'] === true && $due !== true) {
                throw new \RuntimeException('Signal artifact risk exit cannot be non-due.');
            }
            if (!$due) {
                if ($action !== 'hold' || $targetSymbol !== null || abs($gross) > 1.0e-12) {
                    throw new \RuntimeException('Signal artifact non-due target must be an explicit hold.');
                }
            } elseif (in_array($action, ['rebalance', 'resize_or_hold'], true)) {
                if ($targetSymbol === null || $gross <= 0.0
                    || ($action === 'resize_or_hold' && $currentSymbol !== $targetSymbol)
                    || ($action === 'rebalance' && $currentSymbol === $targetSymbol)) {
                    throw new \RuntimeException('Signal artifact invested action is inconsistent with its state.');
                }
            } elseif ($action === 'exit_to_cash') {
                if ($targetSymbol !== null || abs($gross) > 1.0e-12 || $currentSymbol === null) {
                    throw new \RuntimeException('Signal artifact exit-to-cash action is inconsistent with its state.');
                }
            } elseif ($action === 'hold_cash') {
                if ($targetSymbol !== null || abs($gross) > 1.0e-12 || $currentSymbol !== null) {
                    throw new \RuntimeException('Signal artifact hold-cash action is inconsistent with its state.');
                }
            } else {
                throw new \RuntimeException('Signal artifact due action is unknown.');
            }

            $referenceClose = $target['sizing_reference_close'];
            $referenceSession = $target['sizing_reference_session'];
            if ($due && $targetSymbol !== null) {
                if (!self::isFiniteNumber($referenceClose) || (float) $referenceClose <= 0.0
                    || !is_string($referenceSession) || !hash_equals($signalDate, $referenceSession)) {
                    throw new \RuntimeException('Signal artifact executable target lacks its completed-bar sizing reference.');
                }
            } elseif ($referenceClose !== null || $referenceSession !== null) {
                throw new \RuntimeException('Signal artifact non-entry target unexpectedly carries a sizing reference.');
            }
        }
        if ($signalDate === null || (string) ($artifact['as_of'] ?? '') !== $signalDate) {
            throw new \RuntimeException('Signal artifact as-of date mismatch.');
        }
        $intendedSession = (string) ($artifact['intended_session'] ?? '');
        self::requireCanonicalDate($intendedSession, 'Signal artifact intended session');
        if ($intendedSession <= $signalDate) {
            throw new \RuntimeException('Signal artifact intended session must follow its completed signal date.');
        }
        $executionContexts = $artifact['execution_contexts'] ?? null;
        if (!is_array($executionContexts) || array_keys($executionContexts) !== array_keys($sleeves)) {
            throw new \RuntimeException('Signal artifact execution-context sleeve set mismatch.');
        }
        if (!is_array($artifact['data_provenance'] ?? null)) {
            throw new \RuntimeException('Signal artifact market-data provenance is missing.');
        }
        try {
            self::validateDataProvenance(
                $artifact['data_provenance'],
                (array) ($paper['data'] ?? []),
                $signalDate,
                self::profileSymbols($profile),
            );
        } catch (\RuntimeException $e) {
            throw new \RuntimeException(
                'Signal artifact market-data provenance is invalid: ' . $e->getMessage(),
                previous: $e,
            );
        }
    }

    /**
     * Hash the complete shadow decision (including implementation and market
     * data provenance) while excluding only the hash field itself. Object-map
     * keys are sorted recursively; list order remains semantically significant.
     *
     * @param array<string,mixed> $artifact
     */
    public static function decisionSha256(array $artifact): string
    {
        unset($artifact['decision_sha256']);
        $canonical = self::canonicalDecisionValue($artifact);

        return hash(
            'sha256',
            json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }

    /** @param array<string,mixed> $provenance @param array<string,mixed> $contract */
    public static function validateDataProvenance(
        array $provenance,
        array $contract,
        string $signalDate,
        array $expectedSymbols,
    ): void
    {
        $boundary = (array) ($provenance['boundary'] ?? []);
        $segments = (array) ($provenance['segments'] ?? []);
        $frozen = (array) ($segments['frozen_sip'] ?? []);
        $fresh = (array) ($segments['recent_iex'] ?? []);
        $checks = [
            [(string) ($provenance['mode'] ?? ''), 'frozen_alpaca_sip_plus_completed_alpaca_iex'],
            [(string) ($boundary['frozen_sip_cutoff_inclusive'] ?? ''), (string) ($contract['historical_cutoff'] ?? '')],
            [(string) ($boundary['overlap_policy'] ?? ''), (string) ($contract['overlap_policy'] ?? '')],
            [(string) ($frozen['feed'] ?? ''), (string) ($contract['historical_feed'] ?? '')],
            [(string) ($frozen['expected_sha256'] ?? ''), (string) ($contract['historical_snapshot_sha256'] ?? '')],
            [(string) ($frozen['sha256'] ?? ''), (string) ($contract['historical_snapshot_sha256'] ?? '')],
            [(string) ($frozen['namespace'] ?? ''), (string) ($contract['cache_namespace'] ?? '')],
            [(string) ($fresh['feed'] ?? ''), (string) ($contract['fresh_feed'] ?? '')],
            [(string) ($fresh['namespace'] ?? ''), (string) ($contract['fresh_cache_namespace'] ?? '')],
        ];
        foreach ($checks as [$actual, $expected]) {
            if ($expected === '' || !hash_equals($expected, $actual)) {
                throw new \RuntimeException('Signal market-data provenance differs from the frozen paper contract.');
            }
        }
        if ((int) ($boundary['overlap_sessions'] ?? -1) !== (int) ($contract['overlap_sessions'] ?? -2)) {
            throw new \RuntimeException('Signal market-data overlap contract mismatch.');
        }
        $effective = (string) ($provenance['merged']['effective_completed_session'] ?? '');
        $requested = (string) ($provenance['request']['end'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $signalDate)
            || !hash_equals($signalDate, $requested)
            || !hash_equals($signalDate, $effective)) {
            throw new \RuntimeException('Signal market-data terminal session mismatch.');
        }
        $symbols = array_values(array_unique(array_map(
            static fn (mixed $symbol): string => strtoupper(trim((string) $symbol)),
            (array) ($provenance['request']['symbols'] ?? []),
        )));
        $expectedSymbols = array_values(array_unique(array_map(
            static fn (mixed $symbol): string => strtoupper(trim((string) $symbol)),
            $expectedSymbols,
        )));
        sort($symbols, SORT_STRING);
        sort($expectedSymbols, SORT_STRING);
        if ($symbols !== $expectedSymbols || $symbols === []) {
            throw new \RuntimeException('Signal market-data universe mismatch.');
        }
        $freshStart = (new \DateTimeImmutable((string) $contract['historical_cutoff']))
            ->modify('+1 day')
            ->format('Y-m-d');
        if ((string) ($boundary['recent_iex_start_inclusive'] ?? '') !== $freshStart) {
            throw new \RuntimeException('Signal market-data fresh boundary mismatch.');
        }
        $recentRequired = $signalDate > (string) $contract['historical_cutoff'];
        if (($fresh['used'] ?? null) !== $recentRequired) {
            throw new \RuntimeException('Signal market-data recent feed usage mismatch.');
        }
        if ($recentRequired) {
            if ((string) ($fresh['request']['start'] ?? '') !== $freshStart
                || (string) ($fresh['request']['end'] ?? '') !== $signalDate) {
                throw new \RuntimeException('Signal recent-IEX request boundary mismatch.');
            }
            $coverage = (array) ($fresh['coverage'] ?? []);
            $coverageSymbols = array_keys($coverage);
            sort($coverageSymbols, SORT_STRING);
            if ($coverageSymbols !== $expectedSymbols) {
                throw new \RuntimeException('Signal recent-IEX coverage universe mismatch.');
            }
            foreach ($coverage as $row) {
                if (!is_array($row) || (string) ($row['last_session'] ?? '') !== $signalDate) {
                    throw new \RuntimeException('Signal recent-IEX coverage is stale.');
                }
            }
        }
        self::validateCrossFeedAudit(
            (array) ($provenance['cross_feed_audit'] ?? []),
            (array) ($contract['cross_feed_audit'] ?? []),
            (string) $contract['historical_cutoff'],
            $signalDate,
            $expectedSymbols,
            (string) ($contract['fresh_cache_namespace'] ?? ''),
        );
        foreach ([
            $frozen['snapshot_canonical_sha256'] ?? null,
            $provenance['merged']['canonical_sha256'] ?? null,
            $recentRequired ? ($fresh['canonical_sha256'] ?? null) : str_repeat('0', 64),
        ] as $hash) {
            if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) {
                throw new \RuntimeException('Signal market-data canonical hash is missing or invalid.');
            }
        }
    }

    /** @param array<string,mixed> $actual @param array<string,mixed> $expected */
    private static function validateImplementationIdentity(array $actual, array $expected): void
    {
        if (array_keys($actual) !== array_keys($expected)
            || ($actual['schema'] ?? null) !== 1
            || ($expected['schema'] ?? null) !== 1
            || !is_array($actual['files_sha256'] ?? null)
            || !is_array($expected['files_sha256'] ?? null)
            || array_keys($actual['files_sha256']) !== array_keys($expected['files_sha256'])) {
            throw new \RuntimeException('Signal artifact implementation identity mismatch.');
        }
        foreach ($expected['files_sha256'] as $path => $expectedHash) {
            $actualHash = $actual['files_sha256'][$path] ?? null;
            if (!is_string($expectedHash) || !is_string($actualHash)
                || preg_match('/^[a-f0-9]{64}$/D', $actualHash) !== 1
                || !hash_equals($expectedHash, $actualHash)) {
                throw new \RuntimeException('Signal artifact implementation identity mismatch.');
            }
        }
        foreach (['profile_sha256', 'combined_sha256'] as $field) {
            $expectedHash = $expected[$field] ?? null;
            $actualHash = $actual[$field] ?? null;
            if (!is_string($expectedHash) || !is_string($actualHash)
                || preg_match('/^[a-f0-9]{64}$/D', $actualHash) !== 1
                || !hash_equals($expectedHash, $actualHash)) {
                throw new \RuntimeException('Signal artifact implementation identity mismatch.');
            }
        }
    }

    /** @param array<string,mixed> $profile @return list<string> */
    private static function profileSymbols(array $profile): array
    {
        $symbols = [];
        foreach ((array) ($profile['sleeves'] ?? []) as $definition) {
            if (!is_array($definition)) {
                throw new \RuntimeException('Signal artifact profile sleeve definition is malformed.');
            }
            $config = array_replace($profile, (array) ($definition['config'] ?? []));
            $symbols = array_merge(
                $symbols,
                [(string) ($config['benchmark'] ?? '')],
                [(string) ($config['market_context']['symbol'] ?? '')],
                [(string) ($config['signal_market_filter']['symbol'] ?? '')],
                (array) ($config['universe'] ?? []),
            );
        }

        return self::canonicalUniverse($symbols);
    }

    /** @param array<mixed> $symbols @return list<string> */
    private static function canonicalUniverse(array $symbols): array
    {
        $result = [];
        foreach ($symbols as $symbol) {
            if (!is_string($symbol)) {
                throw new \RuntimeException('Signal artifact frozen universe is malformed.');
            }
            $symbol = strtoupper(trim($symbol));
            if (preg_match('/^[A-Z][A-Z0-9.\-]{0,14}$/D', $symbol) !== 1) {
                throw new \RuntimeException('Signal artifact frozen universe contains an invalid symbol.');
            }
            $result[$symbol] = true;
        }
        if ($result === []) {
            throw new \RuntimeException('Signal artifact frozen universe is empty.');
        }
        $symbols = array_keys($result);
        sort($symbols, SORT_STRING);

        return $symbols;
    }

    /** @param list<string> $universe */
    private static function targetSymbol(mixed $value, array $universe, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || $value === '' || strtoupper($value) !== $value
            || !in_array($value, $universe, true)) {
            throw new \RuntimeException('Signal artifact ' . $field . ' is outside its frozen sleeve universe.');
        }

        return $value;
    }

    private static function boundedGross(mixed $value, float $maximum, string $field): float
    {
        if (!self::isFiniteNumber($value) || (float) $value < 0.0 || (float) $value > $maximum + 1.0e-12) {
            throw new \RuntimeException('Signal artifact ' . $field . ' exceeds the frozen paper envelope.');
        }

        return (float) $value;
    }

    private static function assertSymbolGrossPair(?string $symbol, float $gross, string $field): void
    {
        if (($symbol === null && abs($gross) > 1.0e-12) || ($symbol !== null && $gross <= 0.0)) {
            throw new \RuntimeException('Signal artifact ' . $field . ' symbol/gross pair is inconsistent.');
        }
    }

    private static function isFiniteNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value);
    }

    private static function canonicalDecisionValue(mixed $value): mixed
    {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return array_map(self::canonicalDecisionValue(...), $value);
            }
            ksort($value, SORT_STRING);
            foreach ($value as $key => $item) {
                if (!is_string($key)) {
                    throw new \RuntimeException('Signal artifact decision map contains a non-string key.');
                }
                $value[$key] = self::canonicalDecisionValue($item);
            }

            return $value;
        }
        if (is_float($value) && !is_finite($value)) {
            throw new \RuntimeException('Signal artifact decision contains a non-finite number.');
        }
        if ($value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }

        throw new \RuntimeException('Signal artifact decision contains an unsupported value.');
    }

    private static function requireCanonicalDate(string $value, string $label): void
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            throw new \RuntimeException($label . ' is malformed.');
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('America/New_York'));
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \RuntimeException($label . ' is invalid.');
        }
    }

    /**
     * The overlap is a veto-only historical audit. It must end at the frozen
     * cutoff, disclose explicit tolerances, and prove it was never merged into
     * the bars that generated the signal.
     *
     * @param array<string,mixed> $audit
     * @param array<string,mixed> $expected
     * @param list<string> $expectedSymbols
     */
    private static function validateCrossFeedAudit(
        array $audit,
        array $expected,
        string $cutoff,
        string $signalDate,
        array $expectedSymbols,
        string $expectedCacheNamespace,
    ): void {
        if (($expected['enabled'] ?? null) !== true
            || ($expected['mode'] ?? null) !== 'audit_only_cutoff_overlap_v1'
            || ($expected['require_all_symbols'] ?? null) !== true) {
            throw new \RuntimeException('Frozen paper contract is missing a fail-closed cross-feed audit.');
        }
        $sessionsRequired = $expected['sessions'] ?? null;
        $priceTolerance = $expected['price_tolerance_bps'] ?? null;
        $minimumVolume = $expected['minimum_iex_to_sip_volume_ratio'] ?? null;
        $maximumVolume = $expected['maximum_iex_to_sip_volume_ratio'] ?? null;
        if (!is_int($sessionsRequired) || $sessionsRequired < 2 || $sessionsRequired > 20
            || !is_array($priceTolerance)
            || array_keys($priceTolerance) !== ['open', 'high', 'low', 'close']
            || (!is_int($minimumVolume) && !is_float($minimumVolume))
            || (!is_int($maximumVolume) && !is_float($maximumVolume))
            || !is_finite((float) $minimumVolume)
            || !is_finite((float) $maximumVolume)
            || (float) $minimumVolume <= 0.0
            || (float) $maximumVolume <= (float) $minimumVolume
            || (float) $maximumVolume > 2.0) {
            throw new \RuntimeException('Frozen paper cross-feed tolerances are malformed.');
        }
        foreach ($priceTolerance as $value) {
            if ((!is_int($value) && !is_float($value))
                || !is_finite((float) $value)
                || (float) $value <= 0.0
                || (float) $value > 1000.0) {
                throw new \RuntimeException('Frozen paper cross-feed tolerances are malformed.');
            }
        }

        $auditRequired = $signalDate >= $cutoff;
        if (!$auditRequired) {
            if (($audit['used'] ?? null) !== false) {
                throw new \RuntimeException('A later cross-feed audit must not gate an earlier historical signal.');
            }

            return;
        }

        if (($audit['mode'] ?? null) !== 'audit_only_cutoff_overlap_v1'
            || ($audit['enabled'] ?? null) !== true
            || ($audit['used'] ?? null) !== true
            || ($audit['passed'] ?? null) !== true
            || ($audit['role'] ?? null) !== 'audit_only_not_decision_data'
            || ($audit['decision_data_usage'] ?? null) !== 'none'
            || ($audit['used_for_merged_bars'] ?? null) !== false
            || (int) ($audit['violations'] ?? -1) !== 0) {
            throw new \RuntimeException('Signal cross-feed audit is absent, failed, or entered decision data.');
        }
        $actualContract = (array) ($audit['contract'] ?? []);
        foreach (['mode', 'enabled', 'sessions', 'require_all_symbols'] as $field) {
            if (($actualContract[$field] ?? null) !== ($expected[$field] ?? null)) {
                throw new \RuntimeException('Signal cross-feed audit contract mismatch.');
            }
        }
        foreach (['open', 'high', 'low', 'close'] as $field) {
            $configured = $priceTolerance[$field] ?? null;
            $declared = $actualContract['price_tolerance_bps'][$field] ?? null;
            if ((!is_int($configured) && !is_float($configured))
                || (!is_int($declared) && !is_float($declared))
                || abs((float) $configured - (float) $declared) > 1.0e-12) {
                throw new \RuntimeException('Signal cross-feed price tolerance mismatch.');
            }
        }
        foreach (['minimum_iex_to_sip_volume_ratio', 'maximum_iex_to_sip_volume_ratio'] as $field) {
            $declared = $actualContract[$field] ?? null;
            if ((!is_int($declared) && !is_float($declared))
                || abs((float) $expected[$field] - (float) $declared) > 1.0e-12) {
                throw new \RuntimeException('Signal cross-feed volume tolerance mismatch.');
            }
        }

        if (($audit['feeds']['reference'] ?? null) !== 'sip'
            || ($audit['feeds']['candidate'] ?? null) !== 'iex'
            || $expectedCacheNamespace === ''
            || ($audit['cache_namespace'] ?? null) !== $expectedCacheNamespace) {
            throw new \RuntimeException('Signal cross-feed audit feed identity mismatch.');
        }
        $request = (array) ($audit['request'] ?? []);
        $window = (array) ($audit['window'] ?? []);
        $sessions = (array) ($window['sessions'] ?? []);
        if (($request['timeframe'] ?? null) !== '1Day'
            || ($request['end'] ?? null) !== $cutoff
            || ($window['end'] ?? null) !== $cutoff
            || ($request['start'] ?? null) !== ($window['start'] ?? null)
            || count($sessions) !== $sessionsRequired
            || ($sessions[array_key_last($sessions)] ?? null) !== $cutoff
            || ($sessions[0] ?? null) !== ($window['start'] ?? null)) {
            throw new \RuntimeException('Signal cross-feed audit window mismatch.');
        }
        $previous = null;
        foreach ($sessions as $session) {
            if (!is_string($session) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $session) !== 1
                || ($previous !== null && $session <= $previous) || $session > $cutoff) {
                throw new \RuntimeException('Signal cross-feed audit sessions are not causal and monotonic.');
            }
            $previous = $session;
        }

        $requestSymbols = self::canonicalSymbols((array) ($request['symbols'] ?? []));
        $comparedSymbols = self::canonicalSymbols((array) ($audit['compared_symbols'] ?? []));
        if ($requestSymbols !== $expectedSymbols || $comparedSymbols !== $expectedSymbols
            || (int) ($audit['compared_sessions'] ?? 0) !== $sessionsRequired
            || (int) ($audit['compared_bars'] ?? 0) !== $sessionsRequired * count($expectedSymbols)) {
            throw new \RuntimeException('Signal cross-feed audit coverage mismatch.');
        }

        $observed = (array) ($audit['observed'] ?? []);
        $observedPrice = (array) ($observed['maximum_price_deviation_bps'] ?? []);
        foreach (['open', 'high', 'low', 'close'] as $field) {
            $value = $observedPrice[$field] ?? null;
            if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)
                || (float) $value < 0.0
                || (float) $value > (float) $priceTolerance[$field] + 1.0e-9) {
                throw new \RuntimeException('Signal cross-feed observed price deviation exceeds its contract.');
            }
        }
        $observedMinimumVolume = $observed['minimum_iex_to_sip_volume_ratio'] ?? null;
        $observedMaximumVolume = $observed['maximum_iex_to_sip_volume_ratio'] ?? null;
        if ((!is_int($observedMinimumVolume) && !is_float($observedMinimumVolume))
            || (!is_int($observedMaximumVolume) && !is_float($observedMaximumVolume))
            || !is_finite((float) $observedMinimumVolume)
            || !is_finite((float) $observedMaximumVolume)
            || (float) $observedMinimumVolume + 1.0e-12 < (float) $minimumVolume
            || (float) $observedMaximumVolume > (float) $maximumVolume + 1.0e-12
            || (float) $observedMinimumVolume > (float) $observedMaximumVolume) {
            throw new \RuntimeException('Signal cross-feed observed volume ratio exceeds its contract.');
        }
        foreach (['frozen_sip', 'audit_iex'] as $source) {
            $hash = $audit['canonical_sha256'][$source] ?? null;
            if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1) {
                throw new \RuntimeException('Signal cross-feed audit canonical hash is invalid.');
            }
        }
    }

    /** @param array<mixed> $symbols @return list<string> */
    private static function canonicalSymbols(array $symbols): array
    {
        $symbols = array_values(array_unique(array_map(
            static fn (mixed $symbol): string => strtoupper(trim((string) $symbol)),
            $symbols,
        )));
        sort($symbols, SORT_STRING);

        return $symbols;
    }
}
