<?php

declare(strict_types=1);

namespace FulltimeTrading\Trading;

use FulltimeTrading\Domain\Signal;
use FulltimeTrading\Strategy\MarketRegime;

final class PositionSizingPolicy
{
    /** @param array<string, mixed> $strategy @param array<string, mixed> $risk */
    public static function positionPct(array $strategy, array $risk, ?MarketRegime $regime, Signal $signal): float
    {
        $maxPositionPct = max(0.0, (float) ($risk['max_position_pct'] ?? 1.0));
        $clubRules = is_array($strategy['club_rules'] ?? null) ? $strategy['club_rules'] : [];
        $basePct = $maxPositionPct;
        if (($clubRules['enabled'] ?? false) === true) {
            $stablePct = (float) ($clubRules['stable_market_position_pct'] ?? $maxPositionPct);
            $unstablePct = (float) ($clubRules['unstable_market_position_pct'] ?? 0.05);
            $isUnstable = $regime !== null && (
                $regime->score < (float) ($clubRules['stable_market_score_threshold'] ?? 2.5)
                || count($regime->warnings) >= (int) ($clubRules['unstable_warning_count'] ?? 3)
            );
            $basePct = min($maxPositionPct, $isUnstable ? $unstablePct : $stablePct);
        }

        $layerConfig = is_array($strategy['layered_positions'] ?? null) ? $strategy['layered_positions'] : [];
        $sizing = is_array($layerConfig['support_hierarchy_sizing'] ?? null)
            ? $layerConfig['support_hierarchy_sizing']
            : [];
        if (($sizing['enabled'] ?? false) !== true) {
            return max(0.0, min($maxPositionPct, $basePct));
        }

        $timeframe = strtoupper((string) ($signal->metadata['timeframe'] ?? 'D'));
        $period = (int) ($signal->metadata['ma_period'] ?? 0);
        $multiplier = self::hierarchyMultiplier($sizing, $timeframe, $period);
        $hierarchyCap = max($maxPositionPct, (float) ($sizing['max_position_pct'] ?? $maxPositionPct));

        return max(0.0, min($hierarchyCap, $basePct * $multiplier));
    }

    /** @param array<string, mixed> $sizing */
    private static function hierarchyMultiplier(array $sizing, string $timeframe, int $period): float
    {
        if ($period <= 0) {
            return 1.0;
        }
        $multipliers = is_array($sizing['multipliers'] ?? null) ? $sizing['multipliers'] : [];
        if (isset($multipliers[$timeframe]) && is_array($multipliers[$timeframe])) {
            $value = $multipliers[$timeframe][$period] ?? $multipliers[$timeframe][(string) $period] ?? null;
            if ($value !== null) {
                return max(0.0, (float) $value);
            }
        }
        $flatKey = $timeframe . ':' . $period;

        return isset($multipliers[$flatKey]) ? max(0.0, (float) $multipliers[$flatKey]) : 1.0;
    }
}
