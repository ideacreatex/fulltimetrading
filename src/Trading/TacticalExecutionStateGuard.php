<?php

declare(strict_types=1);

namespace FulltimeTrading\Trading;

/**
 * A broker/ledger match is necessary but not sufficient after an execution
 * failure: a partial terminal fill can match the ledger while still differing
 * from the replay target. This guard latches that divergence from immutable
 * intent history and blocks every later entry until an operator resolves it.
 */
final class TacticalExecutionStateGuard
{
    private const TERMINAL = ['filled', 'canceled', 'cancelled', 'expired', 'rejected', 'ambiguous_missed'];

    private const INFLIGHT = [
        'pending_new',
        'accepted',
        'new',
        'partially_filled',
        'done_for_day',
    ];

    private const KNOWN = [
        'planned',
        'submitting',
        'ambiguous',
        'pending_new',
        'accepted',
        'new',
        'partially_filled',
        'done_for_day',
        'filled',
        'canceled',
        'cancelled',
        'expired',
        'rejected',
        'ambiguous_missed',
    ];

    /**
     * @param list<array<string,mixed>> $intents complete immutable run history
     * @param array<string,float|int> $expectedBrokerPositions fill-ledger totals
     * @param array<string,float|int> $actualBrokerPositions current Alpaca totals
     * @param list<array<string,mixed>> $foreignOpenOrders
     * @return array{
     *   execution_state:string,
     *   entries_allowed:bool,
     *   reason_codes:list<string>,
     *   details:array<string,mixed>
     * }
     */
    public static function assess(
        array $intents,
        array $expectedBrokerPositions,
        array $actualBrokerPositions,
        array $foreignOpenOrders,
        \DateTimeImmutable $now,
        float $positionTolerance = 0.000001,
        string $opgCutoff = '09:27',
        string $dayCutoff = '09:32',
    ): array {
        if (!is_finite($positionTolerance) || $positionTolerance < 0.0) {
            throw new \InvalidArgumentException('Execution-state position tolerance must be finite and non-negative.');
        }
        self::assertClock($opgCutoff, 'OPG cutoff');
        self::assertClock($dayCutoff, 'DAY cutoff');
        $expected = self::positionMap($expectedBrokerPositions);
        $actual = self::positionMap($actualBrokerPositions);
        $positionDrift = [];
        foreach (array_unique(array_merge(array_keys($expected), array_keys($actual))) as $symbol) {
            $expectedQty = (float) ($expected[$symbol] ?? 0.0);
            $actualQty = (float) ($actual[$symbol] ?? 0.0);
            if (abs($expectedQty - $actualQty) > $positionTolerance) {
                $positionDrift[$symbol] = ['expected' => $expectedQty, 'actual' => $actualQty];
            }
        }
        ksort($positionDrift, SORT_STRING);

        $terminalIncomplete = [];
        $missed = [];
        $ambiguous = [];
        $inflight = [];
        $unknown = [];
        $seen = [];
        foreach ($intents as $intent) {
            if (!is_array($intent)) {
                throw new \InvalidArgumentException('Execution-state intent history is malformed.');
            }
            $decisionId = trim((string) ($intent['decision_id'] ?? ''));
            if ($decisionId === '' || isset($seen[$decisionId])) {
                throw new \InvalidArgumentException('Execution-state intent identity is missing or duplicated.');
            }
            $seen[$decisionId] = true;
            $requested = $intent['requested_qty'] ?? null;
            $filled = $intent['cumulative_filled_qty'] ?? null;
            if (!self::numericValue($requested) || !self::numericValue($filled)) {
                throw new \InvalidArgumentException('Execution-state intent quantities are malformed.');
            }
            $requested = (float) $requested;
            $filled = (float) $filled;
            if (!is_finite($requested) || !is_finite($filled)
                || $requested <= 0.0 || abs($requested - round($requested)) > 1.0e-6
                || $filled < 0.0 || $filled > $requested + $positionTolerance) {
                throw new \InvalidArgumentException('Execution-state intent quantities violate the whole-share contract.');
            }
            $status = strtolower(trim((string) ($intent['status'] ?? '')));
            $row = [
                'decision_id_short' => substr($decisionId, 0, 12),
                'sleeve_id' => (string) ($intent['sleeve_id'] ?? ''),
                'symbol' => strtoupper(trim((string) ($intent['symbol'] ?? ''))),
                'side' => strtolower(trim((string) ($intent['side'] ?? ''))),
                'status' => $status,
                'scheduled_session' => (string) ($intent['scheduled_session'] ?? ''),
                'requested_qty' => $requested,
                'filled_qty' => $filled,
                'remaining_qty' => max(0.0, $requested - $filled),
            ];
            if (!in_array($status, self::KNOWN, true)) {
                $unknown[] = $row;
                continue;
            }
            $complete = $filled + $positionTolerance >= $requested;
            if (in_array($status, self::TERMINAL, true)) {
                if (!$complete) {
                    $terminalIncomplete[] = $row;
                }
                continue;
            }
            if (in_array($status, ['submitting', 'ambiguous'], true)) {
                $ambiguous[] = $row;
                continue;
            }
            if (in_array($status, self::INFLIGHT, true)) {
                if (!$complete) {
                    $inflight[] = $row;
                }
                continue;
            }
            if ($status === 'planned' && self::entryWindowClosed(
                (string) ($intent['scheduled_session'] ?? ''),
                strtolower((string) ($intent['payload']['time_in_force'] ?? 'opg')),
                $now,
                $opgCutoff,
                $dayCutoff,
            )) {
                $missed[] = $row;
            }
        }

        $reasonCodes = [];
        $diverged = false;
        if ($foreignOpenOrders !== []) {
            $reasonCodes[] = 'foreign_open_order';
        }
        if ($positionDrift !== []) {
            $reasonCodes[] = 'position_ledger_drift';
            $diverged = true;
        }
        if ($ambiguous !== []) {
            $reasonCodes[] = 'ambiguous_order_intent';
        }
        if ($inflight !== []) {
            $reasonCodes[] = 'incomplete_order_inflight';
        }
        if ($terminalIncomplete !== []) {
            $reasonCodes[] = 'terminal_incomplete_order';
            $diverged = true;
        }
        if ($missed !== []) {
            $reasonCodes[] = 'missed_execution_window';
            $diverged = true;
        }
        if ($unknown !== []) {
            $reasonCodes[] = 'unknown_intent_status';
            $diverged = true;
        }

        return [
            'execution_state' => $diverged ? 'diverged' : ($reasonCodes === [] ? 'reconciled' : 'reconcile_only'),
            'entries_allowed' => $reasonCodes === [],
            'reason_codes' => $reasonCodes,
            'details' => [
                'position_drift' => $positionDrift,
                'terminal_incomplete_intents' => $terminalIncomplete,
                'missed_intents' => $missed,
                'ambiguous_intents' => $ambiguous,
                'inflight_intents' => $inflight,
                'unknown_status_intents' => $unknown,
                'foreign_open_order_count' => count($foreignOpenOrders),
            ],
        ];
    }

    /**
     * A divergence may still be de-risked. This helper never permits a buy,
     * never permits selling beyond either broker or sleeve ownership, and
     * refuses to race any open/ambiguous/foreign order.
     *
     * @param array<string,mixed> $leg
     * @param array<string,float|int> $actualBrokerPositions
     * @param array<string,array<string,array<string,mixed>>> $ledgerPositionsBySleeve
     * @param list<array<string,mixed>> $brokerOpenOrders
     * @param array<string,mixed> $assessment result of assess()
     */
    public static function riskReducingSellAllowed(
        array $leg,
        array $actualBrokerPositions,
        array $ledgerPositionsBySleeve,
        array $brokerOpenOrders,
        array $assessment,
        float $positionTolerance = 0.000001,
    ): bool {
        try {
            if (!is_finite($positionTolerance) || $positionTolerance < 0.0
                || strtolower((string) ($leg['side'] ?? '')) !== 'sell'
                || strtolower((string) ($leg['leg'] ?? '')) !== 'exit'
                || $brokerOpenOrders !== []
                || (int) ($assessment['details']['foreign_open_order_count'] ?? 0) !== 0
                || (array) ($assessment['details']['ambiguous_intents'] ?? []) !== []
                || (array) ($assessment['details']['inflight_intents'] ?? []) !== []) {
                return false;
            }
            $symbol = strtoupper(trim((string) ($leg['symbol'] ?? '')));
            $sleeveId = (string) ($leg['sleeve_id'] ?? '');
            $qty = $leg['requested_qty'] ?? null;
            if (!preg_match('/^[A-Z][A-Z0-9.\-]{0,14}$/D', $symbol)
                || $sleeveId === ''
                || !self::numericValue($qty)) {
                return false;
            }
            $qty = (float) $qty;
            if (!is_finite($qty) || $qty <= 0.0 || abs($qty - round($qty)) > 1.0e-6) {
                return false;
            }
            $actual = self::positionMap($actualBrokerPositions);
            $sleevePosition = $ledgerPositionsBySleeve[$sleeveId][$symbol] ?? null;
            if (!is_array($sleevePosition)) {
                return false;
            }
            $sleeveQty = $sleevePosition['qty'] ?? null;
            if (!self::numericValue($sleeveQty)) {
                return false;
            }
            $sleeveQty = (float) $sleeveQty;

            return $qty <= (float) ($actual[$symbol] ?? 0.0) + $positionTolerance
                && $qty <= $sleeveQty + $positionTolerance;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<string,float|int> $positions @return array<string,float> */
    private static function positionMap(array $positions): array
    {
        $normalized = [];
        foreach ($positions as $symbol => $qty) {
            $symbol = strtoupper(trim((string) $symbol));
            if (!preg_match('/^[A-Z][A-Z0-9.\-]{0,14}$/D', $symbol)
                || !self::numericValue($qty)) {
                throw new \InvalidArgumentException('Execution-state position map is malformed.');
            }
            $qty = (float) $qty;
            if (!is_finite($qty) || $qty < 0.0 || isset($normalized[$symbol])) {
                throw new \InvalidArgumentException('Execution-state position quantity is invalid.');
            }
            if ($qty > 0.0) {
                $normalized[$symbol] = $qty;
            }
        }
        ksort($normalized, SORT_STRING);

        return $normalized;
    }

    private static function entryWindowClosed(
        string $session,
        string $timeInForce,
        \DateTimeImmutable $now,
        string $opgCutoff,
        string $dayCutoff,
    ): bool {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $session) !== 1) {
            throw new \InvalidArgumentException('Execution-state scheduled session is malformed.');
        }
        if (!in_array($timeInForce, ['opg', 'day'], true)) {
            throw new \InvalidArgumentException('Execution-state intent time-in-force is unsupported.');
        }
        $timezone = new \DateTimeZone('America/New_York');
        $now = $now->setTimezone($timezone);
        $today = $now->format('Y-m-d');
        if ($session < $today) {
            return true;
        }
        if ($session > $today) {
            return false;
        }
        $cutoff = new \DateTimeImmutable(
            $session . ' ' . ($timeInForce === 'day' ? $dayCutoff : $opgCutoff) . ':00',
            $timezone,
        );

        return $now >= $cutoff;
    }

    private static function assertClock(string $value, string $label): void
    {
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $value) !== 1) {
            throw new \InvalidArgumentException($label . ' must use HH:MM.');
        }
    }

    private static function numericValue(mixed $value): bool
    {
        return is_int($value)
            || is_float($value)
            || (is_string($value) && trim($value) !== '' && is_numeric($value));
    }
}
