<?php

declare(strict_types=1);

namespace FulltimeTrading\Trading;

final class PaperPositionLifecycle
{
    private const ENTRY_MATCH_RELATIVE_TOLERANCE = 0.0025;
    private const POSITION_CHANGE_RELATIVE_TOLERANCE = 0.0005;

    /** @param list<array<string, mixed>> $signals @return array<string, array<string, mixed>> */
    public static function indexLatestSignals(array $signals): array
    {
        $result = [];
        foreach ($signals as $signal) {
            if (!is_array($signal)) {
                continue;
            }
            $symbol = strtoupper((string) ($signal['symbol'] ?? ''));
            if ($symbol === '') {
                continue;
            }
            $current = $result[$symbol] ?? null;
            $signalDate = self::signalDate($signal);
            $currentDate = is_array($current) ? self::signalDate($current) : '';
            if (
                !is_array($current)
                || $signalDate > $currentDate
                || ($signalDate === $currentDate && (float) ($signal['score'] ?? 0.0) > (float) ($current['score'] ?? 0.0))
            ) {
                $result[$symbol] = $signal;
            }
        }

        return $result;
    }

    /** @param array<string, mixed>|null $existing */
    public static function isNew(?array $existing, bool $forceNewLifecycle = false): bool
    {
        return $forceNewLifecycle || $existing === null || strtolower((string) ($existing['status'] ?? '')) === 'closed';
    }

    /** @param array<string, mixed> $state */
    public static function requiresRiskSourceReview(array $state, bool $managedByReport): bool
    {
        if (!$managedByReport) {
            return false;
        }

        $payload = is_array($state['payload'] ?? null) ? $state['payload'] : [];
        $valid = array_key_exists('risk_source_valid', $payload)
            ? (bool) $payload['risk_source_valid']
            : (float) ($state['stop_price'] ?? 0.0) > 0.0;

        return !$valid;
    }

    /** @param array<string, mixed>|null $existing */
    public static function reentrySearchAfter(?array $existing): ?string
    {
        if ($existing === null) {
            return null;
        }
        if (strtolower((string) ($existing['status'] ?? '')) === 'closed') {
            $closedAt = trim((string) ($existing['closed_at'] ?? ''));

            return $closedAt !== '' ? $closedAt : null;
        }

        $event = PaperMonitorDecisionGuard::event($existing);
        $phase = strtolower((string) ($event['phase'] ?? ''));
        if (strtolower((string) ($existing['status'] ?? '')) !== 'closing'
            && !in_array($phase, ['submitting', 'inflight', 'suspended', 'filled'], true)
        ) {
            return null;
        }

        $latestValue = '';
        $latestTimestamp = 0;
        foreach ([
            $existing['opened_at'] ?? null,
            $event['first_detected_at'] ?? null,
            $event['attempt_started_at'] ?? null,
            $event['accepted_at'] ?? null,
            $event['filled_at'] ?? null,
        ] as $value) {
            $value = trim((string) ($value ?? ''));
            $timestamp = self::timestamp($value);
            if ($timestamp > $latestTimestamp) {
                $latestTimestamp = $timestamp;
                $latestValue = $value;
            }
        }

        return $latestValue !== '' ? $latestValue : null;
    }

    /** @param array<string, mixed> $existing @param array<string, mixed> $position @param array<string, mixed>|null $entryOrder */
    public static function shouldResetForReentry(array $existing, array $position, ?array $entryOrder): bool
    {
        if ($entryOrder === null || self::reentrySearchAfter($existing) === null) {
            return false;
        }

        $phase = strtolower((string) (PaperMonitorDecisionGuard::event($existing)['phase'] ?? ''));
        if (strtolower((string) ($existing['status'] ?? '')) !== 'closed' && $phase !== 'filled') {
            return false;
        }
        if (self::entryOrderHasFillEvidence($entryOrder)) {
            return true;
        }

        $oldAverage = (float) ($existing['avg_entry_price'] ?? 0.0);
        $newAverage = (float) ($position['avg_entry_price'] ?? 0.0);
        $averageChanged = self::materiallyDifferent($oldAverage, $newAverage, self::POSITION_CHANGE_RELATIVE_TOLERANCE, 0.01);
        $oldQty = abs((float) ($existing['qty'] ?? 0.0));
        $newQty = abs((float) ($position['qty'] ?? 0.0));
        $qtyChanged = self::materiallyDifferent($oldQty, $newQty, self::POSITION_CHANGE_RELATIVE_TOLERANCE, 0.000001);

        return $averageChanged || $qtyChanged;
    }

    /**
     * @param array<string, mixed> $position
     * @param ?array<string, mixed> $existing
     * @param ?array<string, mixed> $model
     * @param ?array<string, mixed> $signal
     * @param ?array<string, mixed> $entryOrder
     * @return array<string, mixed>
     */
    public static function stateFromPosition(
        array $position,
        ?array $existing,
        ?array $model,
        ?array $signal,
        ?array $entryOrder,
        float $breakEvenPct,
        \DateTimeImmutable $now,
        bool $forceNewLifecycle = false,
    ): array {
        $symbol = strtoupper((string) ($position['symbol'] ?? ''));
        $qty = abs((float) ($position['qty'] ?? 0.0));
        $avgEntry = (float) ($position['avg_entry_price'] ?? 0.0);
        $marketPrice = (float) ($position['current_price'] ?? $position['market_price'] ?? 0.0);
        $newLifecycle = self::isNew($existing, $forceNewLifecycle);
        $existingPayload = is_array($existing['payload'] ?? null) ? $existing['payload'] : [];
        $riskValidationRequired = ($newLifecycle && $existing !== null)
            || (!$newLifecycle && !empty($existingPayload['risk_source_validation_required']));
        $riskSourceBoundary = $newLifecycle && $existing !== null
            ? self::reentrySearchAfter($existing)
            : (isset($existingPayload['risk_source_boundary_at']) ? (string) $existingPayload['risk_source_boundary_at'] : null);
        $orderPayload = is_array($entryOrder['payload'] ?? null) ? $entryOrder['payload'] : [];
        $entryOrderBody = is_array($orderPayload['order'] ?? null) ? $orderPayload['order'] : [];
        $entryMetadata = is_array($entryOrderBody['metadata'] ?? null) ? $entryOrderBody['metadata'] : [];
        $riskModel = $model;
        $riskSignal = $signal;
        $rejectedRiskSources = [];
        if ($riskValidationRequired) {
            $riskModel = self::qualifiedRiskSource($model, $riskSourceBoundary, $avgEntry, true);
            $riskSignal = self::qualifiedRiskSource($signal, $riskSourceBoundary, $avgEntry, false);
            if ($model !== null && $riskModel === null) {
                $rejectedRiskSources[] = 'model';
            }
            if ($signal !== null && $riskSignal === null) {
                $rejectedRiskSources[] = 'signal';
            }
        }
        $entry = $newLifecycle
            ? ($avgEntry > 0.0 ? $avgEntry : (float) ($riskSignal['entry'] ?? $riskModel['entry'] ?? 0.0))
            : (float) ($existing['entry_price'] ?? $riskModel['entry'] ?? $riskSignal['entry'] ?? $avgEntry);
        $initialStop = $newLifecycle
            ? self::firstPositive([$entryMetadata['stop'] ?? null, $entryOrder['stop_price'] ?? null, $riskSignal['stop'] ?? null, $riskModel['initial_stop'] ?? null, $riskModel['stop'] ?? null])
            : self::firstPositive([$existing['initial_stop_price'] ?? null, $riskModel['initial_stop'] ?? null, $riskSignal['stop'] ?? null]);
        $existingStop = self::firstPositive([$existing['stop_price'] ?? null, $initialStop]);
        $stop = $newLifecycle ? $initialStop : $existingStop;
        $modelTrailAccepted = false;
        if (
            !$newLifecycle
            && (bool) ($existing['partial_done'] ?? false)
            && (bool) ($riskModel['took_partial'] ?? false)
            && self::modelMatchesLifecycle($existing, $riskModel, $entry)
        ) {
            $modelStop = (float) ($riskModel['stop'] ?? 0.0);
            if ($modelStop > 0.0) {
                $short = strtolower((string) ($position['side'] ?? $riskModel['direction'] ?? 'long')) === 'short';
                $stop = $existingStop > 0.0
                    ? ($short ? min($existingStop, $modelStop) : max($existingStop, $modelStop))
                    : $modelStop;
                $modelTrailAccepted = abs($stop - $existingStop) > 0.0000001;
            }
        }
        $target = $newLifecycle
            ? self::firstPositive([$entryMetadata['target'] ?? null, $riskSignal['target'] ?? null, $riskModel['target'] ?? null])
            : self::firstPositive([$existing['target_price'] ?? null, $riskSignal['target'] ?? null, $riskModel['target'] ?? null]);
        if ($target <= 0.0 && $entry > 0.0 && $initialStop > 0.0) {
            $target = $entry + max(0.0, ($entry - $initialStop) * 2.0);
        }
        $breakEvenTrigger = $newLifecycle
            ? ($entry > 0.0
                ? $entry * (1.0 + $breakEvenPct)
                : self::firstPositive([$entryMetadata['break_even_trigger'] ?? null, $riskSignal['break_even_trigger'] ?? null, $riskModel['break_even_trigger_price'] ?? null]))
            : self::firstPositive([$existing['break_even_trigger_price'] ?? null, $riskSignal['break_even_trigger'] ?? null]);
        if ($breakEvenTrigger <= 0.0 && $entry > 0.0) {
            $breakEvenTrigger = $entry * (1.0 + $breakEvenPct);
        }

        $payload = !$newLifecycle ? $existingPayload : [];
        if ($newLifecycle) {
            $entryClientOrderId = (string) ($entryOrder['client_order_id'] ?? '');
            $payload['position_lifecycle_id'] = $entryClientOrderId !== ''
                ? $entryClientOrderId
                : hash('sha256', $symbol . '|' . $avgEntry . '|' . $now->format(\DateTimeInterface::ATOM));
            $payload['entry_order'] = $entryOrder !== null ? [
                'client_order_id' => $entryClientOrderId,
                'order_id' => $entryOrder['order_id'] ?? null,
                'submitted_at' => $entryOrder['submitted_at'] ?? null,
                'metadata' => $entryMetadata,
            ] : null;
        }
        if ($riskValidationRequired) {
            $payload['risk_source_validation_required'] = true;
            $payload['risk_source_boundary_at'] = $riskSourceBoundary;
        }
        $payload['model_position'] = $riskModel;
        $payload['signal'] = $riskSignal;
        $payload['risk_source_valid'] = $initialStop > 0.0;
        if ($modelTrailAccepted) {
            $payload['model_trailing_stop_applied_at'] = $now->format(\DateTimeInterface::ATOM);
            $payload['model_trailing_stop'] = $stop;
        }
        $riskSourceName = self::riskSourceName($entryMetadata, $entryOrder, $riskSignal, $riskModel);
        if (!$newLifecycle && $initialStop > 0.0) {
            $previousRiskSource = trim((string) ($existingPayload['risk_source'] ?? ''));
            if ($previousRiskSource === 'entry_order') {
                $riskSourceName = 'entry_order';
            } elseif ($riskSourceName === null) {
                $riskSourceName = $previousRiskSource !== '' ? $previousRiskSource : 'existing';
            }
        }
        $payload['risk_source'] = $riskSourceName;
        if ($riskValidationRequired && $rejectedRiskSources !== []) {
            $payload['rejected_risk_sources'] = $rejectedRiskSources;
        } elseif ($riskValidationRequired) {
            unset($payload['rejected_risk_sources']);
        }

        $entryFilledAt = trim((string) ($orderPayload['alpaca_order']['filled_at'] ?? ''));
        $openedAt = $newLifecycle
            ? ($entryFilledAt !== '' ? $entryFilledAt : $now->format(\DateTimeInterface::ATOM))
            : ($existing['opened_at'] ?? $riskModel['entry_date'] ?? $now->format(\DateTimeInterface::ATOM));
        $existingEventPhase = PaperMonitorDecisionGuard::phase(['payload' => $payload]);
        $status = !$newLifecycle && in_array($existingEventPhase, ['submitting', 'inflight', 'suspended', 'filled'], true) ? 'closing' : 'open';

        return [
            'symbol' => $symbol,
            'status' => $status,
            'qty' => $qty,
            'avg_entry_price' => $avgEntry,
            'market_price' => $marketPrice,
            'entry_price' => $entry,
            'stop_price' => $stop,
            'initial_stop_price' => $initialStop,
            'break_even_trigger_price' => $breakEvenTrigger,
            'target_price' => $target,
            'break_even_armed' => $newLifecycle ? false : (bool) ($existing['break_even_armed'] ?? $riskModel['break_even_armed'] ?? false),
            'partial_done' => $newLifecycle ? false : (bool) ($existing['partial_done'] ?? $riskModel['took_partial'] ?? false),
            'strategy' => $newLifecycle
                ? (string) ($riskSignal['strategy'] ?? $riskModel['strategy'] ?? 'unknown')
                : (string) ($riskModel['strategy'] ?? $riskSignal['strategy'] ?? $existing['strategy'] ?? 'unknown'),
            'setup_key' => $newLifecycle
                ? (string) ($riskSignal['key'] ?? $riskSignal['metadata']['setup_key'] ?? $riskModel['key'] ?? $riskModel['metadata']['setup_key'] ?? $entryOrder['client_order_id'] ?? '')
                : (string) ($riskModel['key'] ?? $riskModel['metadata']['setup_key'] ?? $existing['setup_key'] ?? ''),
            'opened_at' => $openedAt,
            'closed_at' => null,
            'last_event_at' => $now->format(\DateTimeInterface::ATOM),
            'last_action' => $newLifecycle ? 'sync_open' : ($existing['last_action'] ?? 'sync_open'),
            'client_order_id' => $newLifecycle ? ($entryOrder['client_order_id'] ?? null) : ($existing['client_order_id'] ?? null),
            'payload' => $payload,
        ];
    }

    /** @param array<string, mixed> $signal */
    private static function signalDate(array $signal): string
    {
        foreach (['date', 'entry_date', 'created_at', 'time'] as $key) {
            $value = trim((string) ($signal[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /** @param list<mixed> $values */
    private static function firstPositive(array $values): float
    {
        foreach ($values as $value) {
            $number = (float) ($value ?? 0.0);
            if ($number > 0.0) {
                return $number;
            }
        }

        return 0.0;
    }

    /** @param array<string, mixed>|null $source @return array<string, mixed>|null */
    private static function qualifiedRiskSource(?array $source, ?string $boundaryValue, float $actualAverage, bool $model): ?array
    {
        if ($source === null || $boundaryValue === null || trim($boundaryValue) === '') {
            return null;
        }

        try {
            $boundary = new \DateTimeImmutable($boundaryValue);
        } catch (\Throwable) {
            return null;
        }

        $sourceDate = $model
            ? self::firstText([$source['entry_date'] ?? null, $source['date'] ?? null, $source['created_at'] ?? null, $source['time'] ?? null])
            : self::signalDate($source);
        if ($sourceDate === '') {
            return null;
        }
        try {
            $sourceAt = new \DateTimeImmutable($sourceDate, $boundary->getTimezone());
        } catch (\Throwable) {
            return null;
        }

        $sourceDay = $sourceAt->format('Y-m-d');
        $boundaryDay = $boundary->format('Y-m-d');
        $hasExplicitTime = preg_match('/(?:T|\s)\d{2}:\d{2}/', $sourceDate) === 1;
        if (($hasExplicitTime && $sourceAt->getTimestamp() > $boundary->getTimestamp()) || $sourceDay > $boundaryDay) {
            return $source;
        }

        $sourceEntry = (float) ($source['entry'] ?? $source['avg_entry_price'] ?? 0.0);
        if ($sourceDay === $boundaryDay && self::entryApproximatelyMatches($sourceEntry, $actualAverage)) {
            return $source;
        }

        return null;
    }

    /** @param array<string, mixed> $entryOrder */
    private static function entryOrderHasFillEvidence(array $entryOrder): bool
    {
        $payload = is_array($entryOrder['payload'] ?? null) ? $entryOrder['payload'] : [];
        $alpacaOrder = is_array($payload['alpaca_order'] ?? null) ? $payload['alpaca_order'] : [];
        $statuses = [
            strtolower((string) ($entryOrder['status'] ?? '')),
            strtolower((string) ($alpacaOrder['status'] ?? '')),
        ];
        if (in_array('filled', $statuses, true)) {
            return true;
        }

        $filledAt = self::firstText([$entryOrder['filled_at'] ?? null, $alpacaOrder['filled_at'] ?? null]);
        $filledQty = max(
            (float) ($entryOrder['filled_qty'] ?? 0.0),
            (float) ($alpacaOrder['filled_qty'] ?? 0.0),
        );

        return $filledAt !== '' || $filledQty > 0.0000001;
    }

    private static function entryApproximatelyMatches(float $expected, float $actual): bool
    {
        if ($expected <= 0.0 || $actual <= 0.0) {
            return false;
        }

        return abs($expected - $actual) <= max(0.02, abs($actual) * self::ENTRY_MATCH_RELATIVE_TOLERANCE);
    }

    /** @param array<string, mixed> $existing @param array<string, mixed>|null $model */
    private static function modelMatchesLifecycle(array $existing, ?array $model, float $entry): bool
    {
        if ($model === null) {
            return false;
        }
        $existingKey = trim((string) ($existing['setup_key'] ?? ''));
        $modelKey = trim((string) ($model['key'] ?? $model['metadata']['setup_key'] ?? ''));
        if ($existingKey !== '' && $modelKey !== '') {
            return hash_equals($existingKey, $modelKey);
        }

        return self::entryApproximatelyMatches((float) ($model['entry'] ?? 0.0), $entry);
    }

    private static function materiallyDifferent(float $old, float $new, float $relativeTolerance, float $absoluteTolerance): bool
    {
        if ($old < 0.0 || $new < 0.0) {
            return false;
        }

        return abs($new - $old) > max($absoluteTolerance, abs($old) * $relativeTolerance);
    }

    /** @param list<mixed> $values */
    private static function firstText(array $values): string
    {
        foreach ($values as $value) {
            $text = trim((string) ($value ?? ''));
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $entryMetadata
     * @param array<string, mixed>|null $entryOrder
     * @param array<string, mixed>|null $signal
     * @param array<string, mixed>|null $model
     */
    private static function riskSourceName(array $entryMetadata, ?array $entryOrder, ?array $signal, ?array $model): ?string
    {
        if (self::firstPositive([$entryMetadata['stop'] ?? null, $entryOrder['stop_price'] ?? null]) > 0.0) {
            return 'entry_order';
        }
        if (self::firstPositive([$signal['stop'] ?? null]) > 0.0) {
            return 'signal';
        }
        if (self::firstPositive([$model['initial_stop'] ?? null, $model['stop'] ?? null]) > 0.0) {
            return 'model';
        }

        return null;
    }

    private static function timestamp(string $value): int
    {
        if ($value === '') {
            return 0;
        }
        try {
            return (new \DateTimeImmutable($value))->getTimestamp();
        } catch (\Throwable) {
            return 0;
        }
    }
}
