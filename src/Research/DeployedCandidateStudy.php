<?php
declare(strict_types=1);

namespace FulltimeTrading\Research;

/** Offline sensitivities only. Never consumed by the deployed paper release. */
final class DeployedCandidateStudy
{
    public static function cases(): array
    {
        $rows = ['deployed' => ['family' => 'control', 'changes' => []]];
        $add = static function (string $id, string $family, array $spec) use (&$rows): void {
            if (isset($rows[$id])) { throw new \RuntimeException('Duplicate hypothesis.'); }
            $rows[$id] = ['family' => $family] + $spec;
        };
        foreach ([.08, .10, .12, .14, .16] as $stop) {
            foreach ([1, 3, 5] as $pause) {
                if ($stop === .12 && $pause === 1) { continue; }
                $add("stop_{$stop}_pause_{$pause}", 'position_stop', ['changes' => ['standing_stop_pct' => $stop, 'position_exit_cooldown_sessions' => $pause]]);
            }
        }
        foreach ([.12, .15, .18, .21] as $dd) {
            foreach ([3, 5, 10] as $pause) {
                if ($dd === .18 && $pause === 5) { continue; }
                $add("circuit_{$dd}_pause_{$pause}", 'portfolio_circuit', ['circuit' => ['drawdown' => $dd, 'minimum_pause' => $pause]]);
            }
        }
        foreach ([.35, .40, .45, .55, .60, .65] as $weight) { $add("dynamic_weight_{$weight}", 'allocation', ['changes' => ['dynamic_allocation' => $weight]]); }
        foreach ([.5, 1., 1.5, 2.5, 3., 3.5, 4.] as $strength) { $add("switch_hurdle_{$strength}", 'turnover', ['cost_band' => $strength]); }
        foreach ([[3, 1], [3, 2], [5, 3], [5, 5], [10, 3]] as [$period, $phases]) {
            $add("cadence_{$period}_phases_{$phases}", 'cadence', ['changes' => ['rebalance_sessions' => $period, 'phase_count' => $phases]]);
        }
        foreach ([.60, .75, .90, 1.10] as $risk) { $add("risk_scale_{$risk}", 'risk_size', ['risk_scale' => $risk]); }
        foreach ([90, 100, 110, 120] as $threshold) {
            foreach ([.5, .75] as $cap) { $add("vvix_cap_{$threshold}_{$cap}", 'vvix_risk', ['vvix_cap' => [$threshold, $cap]]); }
        }
        foreach ([85, 90, 95, 105, 110, 120] as $threshold) { $add("vvix_confirmation_{$threshold}", 'vvix_reentry', ['vvix_confirmation' => $threshold]); }
        foreach ([100, 150, 250] as $window) { $add("svxy_trend_{$window}", 'svxy_risk', ['svxy_window' => $window]); }
        foreach ([.25, .75] as $cap) { $add("svxy_cap_{$cap}", 'svxy_risk', ['svxy_cap' => $cap]); }
        foreach (['high_touch', 'high_close', 'high_hold2'] as $event) {
            foreach ([5, 10, 20] as $window) {
                foreach ([1.10, 1.25] as $boost) {
                    if ($event === 'high_touch' && $window === 10 && $boost === 1.25) { continue; }
                    $add("s5tw_{$event}_{$window}_{$boost}", 's5tw_high', ['high_event' => $event, 'high_window' => $window, 'high_boost' => $boost]);
                }
            }
        }
        foreach ([75., 85.] as $threshold) { $add("s5tw_high_level_{$threshold}", 's5tw_high', ['high_threshold' => $threshold]); }
        foreach (['low_touch', 'low_reclaim11', 'low_reclaim20'] as $event) {
            foreach ([3, 5, 10] as $window) {
                foreach ([.5, 1.25] as $scale) {
                    $add("s5tw_{$event}_{$window}_{$scale}", 's5tw_low', ['low_overlay' => [$event, $window, $scale]]);
                }
            }
        }
        foreach ([.10, .25, .75] as $strength) {
            $add("downside_strength_{$strength}", 'downside_size', ['changes' => ['dynamic_overrides' => ['algorithm_policy' => ['family' => 'downside_size', 'window' => 20, 'strength' => $strength]]]]);
        }
        foreach ([20, 100, 150] as $period) { $add("asset_sma_{$period}", 'asset_trend', ['changes' => ['asset_sma_period' => $period]]); }
        foreach ([1.5, 2.] as $ratio) {
            foreach ([.5, .75] as $size) { $add("vol_shock_{$ratio}_{$size}", 'volatility_shock', ['changes' => ['volatility_shock_ratio' => $ratio, 'volatility_shock_multiplier' => $size]]); }
        }
        foreach ([.05, .10, .20] as $buffer) { $add("rank_buffer_{$buffer}", 'rank_stability', ['changes' => ['rank_hysteresis' => $buffer]]); }
        foreach ([2, 3] as $ranks) { $add("rank_basket_{$ranks}", 'rank_diversification', ['changes' => ['basket_size' => $ranks]]); }
        foreach (['s5tw', 'svxy', 'vvix'] as $source) {
            foreach ([1, 2] as $lag) { $add("publication_lag_{$source}_{$lag}", 'publication_sensitivity', ['lag_source' => $source, 'lag_sessions' => $lag]); }
        }
        return $rows;
    }

    public static function maps(array $spec, array $all, array $breadth, array $vvix): array
    {
        $events = BreadthVolatilityResearch::events($breadth, 11., (float) ($spec['high_threshold'] ?? 80.));
        $high = BreadthVolatilityResearch::windowScale($events[$spec['high_event'] ?? 'high_touch'], $spec['high_window'] ?? 10, $spec['high_boost'] ?? 1.25);
        $low = isset($spec['low_overlay']) ? BreadthVolatilityResearch::windowScale($events[$spec['low_overlay'][0]], $spec['low_overlay'][1], $spec['low_overlay'][2]) : [];
        $risk = array_map(static fn ($v): float => $v ? 1. : (float) ($spec['svxy_cap'] ?? .5), BreadthVolatilityResearch::trend($all['SVXY'], $spec['svxy_window'] ?? 200));
        $svxy20 = BreadthVolatilityResearch::trend($all['SVXY'], 20);
        for ($i = 0; $i < ($spec['lag_sessions'] ?? 0); ++$i) {
            switch ($spec['lag_source']) {
                case 's5tw': $high = SelectedMaximumResearch::lag($high, 1.); if ($low !== []) { $low = SelectedMaximumResearch::lag($low, 1.); } break;
                case 'svxy': $risk = SelectedMaximumResearch::lag($risk, .5); $svxy20 = SelectedMaximumResearch::lag($svxy20, false); break;
                case 'vvix': $vvix = SelectedMaximumResearch::lag($vvix, 1000.); break;
                default: throw new \RuntimeException('Unknown lag source.');
            }
        }
        foreach ($high as $date => $value) {
            if (($low[$date] ?? 1.) < 1.) { $high[$date] = min($value, $low[$date]); }
            elseif (($low[$date] ?? 1.) > 1.) { $high[$date] = max($value, $low[$date]); }
        }
        $scale = SelectedMaximumResearch::cap($high, $risk);
        $spy = BreadthVolatilityResearch::trend($all['SPY'], 20); $qqq = BreadthVolatilityResearch::trend($all['QQQ'], 20); $confirmation = [];
        foreach ($breadth as $date => $_) {
            if (!isset($vvix[$date], $svxy20[$date], $spy[$date], $qqq[$date], $scale[$date])) { throw new \RuntimeException('Missing study source date.'); }
            if (isset($spec['vvix_cap']) && $vvix[$date] >= $spec['vvix_cap'][0]) { $scale[$date] = min($scale[$date], $spec['vvix_cap'][1]); }
            $scale[$date] = min(2., $scale[$date] * ($spec['risk_scale'] ?? 1.));
            $confirmation[$date] = $vvix[$date] < ($spec['vvix_confirmation'] ?? 100) && $svxy20[$date] && $spy[$date] && $qqq[$date];
        }
        return ['scale' => $scale, 'confirmation' => $confirmation];
    }

    public static function books(array $spec, array $profile, array $scale, array $features, array $nominal, float $cost): array
    {
        $changes = ['asset_sma_period' => 50, 'dynamic_allocation' => .5,
            'circuit_reentry_mode' => 'rebound', 'circuit_reentry_return_1' => .02, 'circuit_reentry_min_sessions' => 5,
            'dynamic_overrides' => ['algorithm_policy' => ['family' => 'downside_size', 'window' => 20, 'strength' => .5]],
            'external_daily_scale' => $scale, 'phase_count' => 3, 'standing_stop_pct' => .12, 'standing_stop_trailing' => true,
            'position_exit_cooldown_sessions' => 1];
        $books = AdaptiveResearchFactory::make($profile, SelectedMaximumResearch::merge($changes, $spec['changes'] ?? []), $cost)->config();
        foreach ($books as &$book) {
            $strength = $spec['cost_band'] ?? 2;
            $book['config']['opportunity_policy'] = ['family' => 'cost_band', 'window' => 20, 'strength' => $cost === 30. ? $strength : $strength * 30 / $cost];
            $book['config']['opportunity_features'] = $features;
            $book['config']['whole_share_execution'] = true; $book['config']['nominal_prices'] = $nominal;
        }
        unset($book);
        return (new PaperExecutionRotationEnsembleBacktester($books))->config();
    }
}
