<?php

declare(strict_types=1);

namespace FulltimeTrading\Trading;

/**
 * Research-only portfolio load relief. This class creates no broker orders and
 * deliberately has no price-target or routine take-profit input.
 */
final class PortfolioEmergencyDeRiskPolicy
{
    private const EPSILON = 1.0e-9;

    /**
     * @param list<array<string, mixed>> $positions
     * @return array<string, mixed>
     */
    public function plan(
        float $equity,
        array $positions,
        float $triggerRatio,
        float $targetRatio,
        float $maxReductionFractionPerPosition,
        float $minimumRemainingQuantity,
    ): array {
        $base = $this->basePlan($triggerRatio, $targetRatio);
        if (!$this->positiveFinite($equity)) {
            return array_merge($base, [
                'reason' => 'invalid_equity',
                'validation_failure' => 'equity_must_be_finite_and_positive',
            ]);
        }
        if (
            !$this->positiveFinite($triggerRatio)
            || !$this->nonNegativeFinite($targetRatio)
            || $targetRatio >= $triggerRatio
            || !$this->nonNegativeFinite($maxReductionFractionPerPosition)
            || $maxReductionFractionPerPosition > 1.0
            || !$this->nonNegativeFinite($minimumRemainingQuantity)
        ) {
            return array_merge($base, [
                'reason' => 'invalid_policy_parameters',
                'validation_failure' => 'require 0 <= target < trigger, 0 <= max_fraction <= 1, and minimum_quantity >= 0',
            ]);
        }

        $normalized = [];
        $ignoredNonLong = 0;
        foreach ($positions as $index => $position) {
            if (!is_array($position)) {
                return $this->invalidPositionPlan($base, $index, 'position_must_be_an_object');
            }

            $side = strtolower(trim((string) ($position['side'] ?? $position['direction'] ?? '')));
            if ($side === 'short') {
                $ignoredNonLong++;
                continue;
            }
            if ($side !== 'long') {
                return $this->invalidPositionPlan($base, $index, 'side_must_explicitly_be_long_or_short');
            }

            $symbol = strtoupper(trim((string) ($position['symbol'] ?? '')));
            if (!preg_match('/^[A-Z][A-Z0-9.\-]{0,12}$/', $symbol)) {
                return $this->invalidPositionPlan($base, $index, 'invalid_symbol');
            }
            if (isset($normalized[$symbol])) {
                return $this->invalidPositionPlan($base, $index, 'duplicate_symbol_' . $symbol);
            }

            $quantityRaw = $position['qty'] ?? $position['quantity'] ?? null;
            $priceRaw = $position['price'] ?? $position['current_price'] ?? $position['market_price'] ?? null;
            if (!$this->numericFinite($quantityRaw) || (float) $quantityRaw <= 0.0) {
                return $this->invalidPositionPlan($base, $index, 'quantity_must_be_finite_and_positive');
            }
            if (!$this->numericFinite($priceRaw) || (float) $priceRaw <= 0.0) {
                return $this->invalidPositionPlan($base, $index, 'price_must_be_finite_and_positive');
            }

            $quantity = (float) $quantityRaw;
            $price = (float) $priceRaw;
            $notional = $quantity * $price;
            if (!$this->positiveFinite($notional)) {
                return $this->invalidPositionPlan($base, $index, 'position_notional_must_be_finite_and_positive');
            }

            $capacityQuantity = min(
                $quantity * $maxReductionFractionPerPosition,
                max(0.0, $quantity - $minimumRemainingQuantity),
            );
            $normalized[$symbol] = [
                'symbol' => $symbol,
                'quantity' => $quantity,
                'price' => $price,
                'notional' => $notional,
                'capacity_quantity' => $capacityQuantity,
                'capacity_notional' => $capacityQuantity * $price,
            ];
        }
        ksort($normalized, SORT_STRING);

        $grossExposure = array_sum(array_column($normalized, 'notional'));
        $currentRatio = $grossExposure / $equity;
        $targetGrossExposure = $equity * $targetRatio;
        if (!is_finite($grossExposure) || !is_finite($currentRatio) || !is_finite($targetGrossExposure)) {
            return array_merge($base, [
                'reason' => 'invalid_computed_exposure',
                'validation_failure' => 'gross_exposure_and_target_notional_must_remain_finite',
            ]);
        }
        $base = array_merge($base, [
            'current_gross_ratio' => $currentRatio,
            'gross_exposure_notional' => $grossExposure,
            'ignored_non_long_positions' => $ignoredNonLong,
        ]);
        if ($currentRatio < $triggerRatio) {
            return array_merge($base, [
                'reason' => 'gross_exposure_below_emergency_trigger',
                'remaining_gross_ratio' => $currentRatio,
                'remaining_gross_exposure_notional' => $grossExposure,
            ]);
        }

        $requiredReduction = max(0.0, $grossExposure - $targetGrossExposure);
        $allocations = $this->allocateProportionally($normalized, $requiredReduction);
        $reductions = [];
        $plannedReduction = 0.0;
        foreach ($normalized as $symbol => $position) {
            $reductionNotional = min(
                (float) ($allocations[$symbol] ?? 0.0),
                (float) $position['capacity_notional'],
            );
            if ($reductionNotional <= self::EPSILON) {
                continue;
            }

            $reductionQuantity = $reductionNotional / (float) $position['price'];
            $remainingQuantity = (float) $position['quantity'] - $reductionQuantity;
            if ($remainingQuantity < $minimumRemainingQuantity) {
                $remainingQuantity = $minimumRemainingQuantity;
                $reductionQuantity = max(0.0, (float) $position['quantity'] - $remainingQuantity);
                $reductionNotional = $reductionQuantity * (float) $position['price'];
            }
            if ($reductionQuantity <= self::EPSILON) {
                continue;
            }

            $plannedReduction += $reductionNotional;
            $reductions[] = [
                'symbol' => $symbol,
                'side' => 'sell',
                'emergency' => true,
                'reason' => 'portfolio_gross_exposure_emergency_de_risk',
                'qty' => $reductionQuantity,
                'current_qty' => (float) $position['quantity'],
                'remaining_qty' => $remainingQuantity,
                'minimum_remaining_qty' => $minimumRemainingQuantity,
                'price' => (float) $position['price'],
                'reduction_fraction' => $reductionQuantity / (float) $position['quantity'],
                'reduction_notional' => $reductionNotional,
            ];
        }

        $remainingGross = max(0.0, $grossExposure - $plannedReduction);
        $capacityLimited = $plannedReduction + max(self::EPSILON, $requiredReduction * 1.0e-12) < $requiredReduction;

        return array_merge($base, [
            'emergency' => true,
            'reason' => $capacityLimited
                ? 'emergency_de_risk_capacity_limited'
                : 'emergency_de_risk_to_target',
            'required_reduction_notional' => $requiredReduction,
            'reduction_notional' => $plannedReduction,
            'reduction_shortfall_notional' => max(0.0, $requiredReduction - $plannedReduction),
            'remaining_gross_exposure_notional' => $remainingGross,
            'remaining_gross_ratio' => $remainingGross / $equity,
            'capacity_limited' => $capacityLimited,
            'reductions' => $reductions,
        ]);
    }

    /**
     * Water-filling allocation: every uncapped position sheds the same fraction
     * of notional; positions constrained by the per-position cap or minimum
     * remaining quantity are frozen and the remainder is redistributed.
     *
     * @param array<string, array<string, float|string>> $positions
     * @return array<string, float>
     */
    private function allocateProportionally(array $positions, float $requiredReduction): array
    {
        $allocations = array_fill_keys(array_keys($positions), 0.0);
        $active = [];
        foreach ($positions as $symbol => $position) {
            if ((float) $position['capacity_notional'] > self::EPSILON) {
                $active[$symbol] = true;
            }
        }

        $remaining = $requiredReduction;
        while ($remaining > self::EPSILON && $active !== []) {
            $weight = 0.0;
            foreach (array_keys($active) as $symbol) {
                $weight += (float) $positions[$symbol]['notional'];
            }
            if ($weight <= self::EPSILON) {
                break;
            }

            $capped = [];
            foreach (array_keys($active) as $symbol) {
                $available = (float) $positions[$symbol]['capacity_notional'] - $allocations[$symbol];
                $proportional = $remaining * (float) $positions[$symbol]['notional'] / $weight;
                if ($proportional >= $available - self::EPSILON) {
                    $capped[$symbol] = max(0.0, $available);
                }
            }

            if ($capped !== []) {
                foreach ($capped as $symbol => $amount) {
                    $allocations[$symbol] += $amount;
                    $remaining = max(0.0, $remaining - $amount);
                    unset($active[$symbol]);
                }
                continue;
            }

            $activeSymbols = array_keys($active);
            $allocatedThisRound = 0.0;
            $lastIndex = count($activeSymbols) - 1;
            foreach ($activeSymbols as $index => $symbol) {
                $amount = $index === $lastIndex
                    ? max(0.0, $remaining - $allocatedThisRound)
                    : $remaining * (float) $positions[$symbol]['notional'] / $weight;
                $available = (float) $positions[$symbol]['capacity_notional'] - $allocations[$symbol];
                $amount = min(max(0.0, $amount), max(0.0, $available));
                $allocations[$symbol] += $amount;
                $allocatedThisRound += $amount;
            }
            $remaining = max(0.0, $remaining - $allocatedThisRound);
        }

        return $allocations;
    }

    /** @return array<string, mixed> */
    private function basePlan(float $triggerRatio, float $targetRatio): array
    {
        return [
            'research_only' => true,
            'execution_authorized' => false,
            'mode' => 'emergency_portfolio_load_only',
            'routine_target_partial' => false,
            'emergency' => false,
            'reason' => 'not_evaluated',
            'trigger_ratio' => $this->finiteOrNull($triggerRatio),
            'current_gross_ratio' => null,
            'target_ratio' => $this->finiteOrNull($targetRatio),
            'gross_exposure_notional' => null,
            'required_reduction_notional' => 0.0,
            'reduction_notional' => 0.0,
            'reduction_shortfall_notional' => 0.0,
            'remaining_gross_exposure_notional' => null,
            'remaining_gross_ratio' => null,
            'capacity_limited' => false,
            'ignored_non_long_positions' => 0,
            'validation_failure' => null,
            'reductions' => [],
        ];
    }

    /** @param array<string, mixed> $base @return array<string, mixed> */
    private function invalidPositionPlan(array $base, int|string $index, string $failure): array
    {
        return array_merge($base, [
            'reason' => 'invalid_position_data',
            'validation_failure' => 'positions[' . $index . ']:' . $failure,
        ]);
    }

    private function numericFinite(mixed $value): bool
    {
        return is_numeric($value) && is_finite((float) $value);
    }

    private function positiveFinite(float $value): bool
    {
        return is_finite($value) && $value > 0.0;
    }

    private function nonNegativeFinite(float $value): bool
    {
        return is_finite($value) && $value >= 0.0;
    }

    private function finiteOrNull(float $value): ?float
    {
        return is_finite($value) ? $value : null;
    }
}
