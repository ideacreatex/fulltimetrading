<?php

declare(strict_types=1);

namespace FulltimeTrading\Paper;

use FulltimeTrading\Research\AdaptiveResearchFactory;
use FulltimeTrading\Research\PaperExecutionRotationEnsembleBacktester;

final class CandidateDefinition
{
    public const PROFILE = 'maximum-stop12-costband2-whole-bull5-v1';
    public const INDICATOR_RECIPE = 'bull_v110_ma50_boost105';
    public const CIRCUIT = ['drawdown' => .18, 'release' => 'confirmed', 'minimum_pause' => 5, 'initial_scale' => 1];

    public static function indicatorMaps(array $bars, array $breadth, array $vvix): array
    {
        $case = \FulltimeTrading\Research\CandidateBullRiskStudy::cases()[self::INDICATOR_RECIPE];
        $maps = \FulltimeTrading\Research\CandidateBullRiskStudy::maps($case, $bars, $breadth, $vvix);
        return ['scale' => $maps['scale'], 'confirm' => ['volatility_calm' => $maps['confirmation']], 'bullish' => $maps['bullish']];
    }

    public static function books(array $baseProfile, array $scale, array $features, array $nominal): array
    {
        $changes = ['asset_sma_period' => 50, 'dynamic_allocation' => .5,
            'circuit_reentry_mode' => 'rebound', 'circuit_reentry_return_1' => .02, 'circuit_reentry_min_sessions' => 5,
            'dynamic_overrides' => ['algorithm_policy' => ['family' => 'downside_size', 'window' => 20, 'strength' => .5]],
            'external_daily_scale' => $scale, 'phase_count' => 3, 'standing_stop_pct' => .12,
            'standing_stop_trailing' => true, 'position_exit_cooldown_sessions' => 1];
        $books = AdaptiveResearchFactory::make($baseProfile, $changes, 30.)->config();
        foreach ($books as &$book) {
            $book['config']['opportunity_policy'] = ['family' => 'cost_band', 'window' => 20, 'strength' => 2];
            $book['config']['opportunity_features'] = $features;
            $book['config']['whole_share_execution'] = true; $book['config']['nominal_prices'] = $nominal;
        }
        unset($book);
        return (new PaperExecutionRotationEnsembleBacktester($books))->config();
    }
}
