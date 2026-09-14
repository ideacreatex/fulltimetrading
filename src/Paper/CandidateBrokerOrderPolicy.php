<?php

declare(strict_types=1);

namespace FulltimeTrading\Paper;

/** Alpaca's account-wide simple-order wash-trade rule also applies to paper accounts. */
final class CandidateBrokerOrderPolicy
{
    public static function conflicts(array $intent, array $active): array
    {
        CandidateOrder::assert($intent); $conflicts = [];
        foreach ($active as $other) {
            if ($other['decision_id'] === $intent['decision_id'] || $other['symbol'] !== $intent['symbol']
                || $other['side'] === $intent['side'] || in_array($other['status'], CandidateOrder::TERMINAL, true)) { continue; }
            // Even planned opposite orders are reserved locally. A DELETE request is not confirmation.
            $conflicts[] = $other['decision_id'];
        }
        return $conflicts;
    }

    public static function assertCompatible(array $intent, array $active): void
    {
        if (self::conflicts($intent, $active) !== []) {
            throw new \RuntimeException('candidate_opposite_side_order_conflict:' . $intent['symbol']);
        }
    }
}
