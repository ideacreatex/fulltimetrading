<?php

declare(strict_types=1);

namespace FulltimeTrading\Research;

final class AdaptiveResearchFactory
{
    public static function make(array $profile, array $changes, float $cost): AdaptiveRotationEnsembleBacktester
    {
        $definitions = HybridV4Research::backtester($profile, [], $cost)->config();
        $books = [];
        $defensiveIndex = 0;
        foreach ($definitions as $name => $definition) {
            $overrides = array_replace(
                array_diff_key($changes, array_flip(['basket_size', 'phase_count', 'stagger_defensive', 'dynamic_allocation', 'dynamic_overrides', 'defensive_overrides', 'exclude_symbols'])),
                $changes[$name === 'dynamic_loo10' ? 'dynamic_overrides' : 'defensive_overrides'] ?? [],
            );
            $config = array_replace($definition['config'], $overrides, ['cost_bps' => $cost]);
            $config['universe'] = array_values(array_diff($config['universe'], $changes['exclude_symbols'] ?? []));
            $allocation = isset($changes['dynamic_allocation'])
                ? ($name === 'dynamic_loo10' ? $changes['dynamic_allocation'] : (1.0 - $changes['dynamic_allocation']) / 3.0)
                : $definition['allocation'];
            $ranks = (int) ($changes['basket_size'] ?? 1);
            $phases = (int) ($changes['phase_count'] ?? 1);
            if ($ranks < 1 || $ranks > 5 || $phases < 1 || $phases > $config['rebalance_sessions']) {
                throw new \InvalidArgumentException('Invalid rank/phase diversification.');
            }
            for ($rank = 0; $rank < $ranks; $rank++) {
                for ($phase = 0; $phase < $phases; $phase++) {
                    $child = $config;
                    if ($ranks > 1) {
                        $child['rank_index'] = $rank;
                    }
                    if ($phases > 1) {
                        $child['rebalance_phase'] = $phase;
                    } elseif (($changes['stagger_defensive'] ?? false) && $name !== 'dynamic_loo10') {
                        $child['rebalance_phase'] = $defensiveIndex % $child['rebalance_sessions'];
                    }
                    $id = $ranks * $phases === 1 ? $name : $name . '_rank' . $rank . '_phase' . $phase;
                    $books[$id] = ['allocation' => $allocation / ($ranks * $phases), 'config' => $child];
                }
            }
            if ($name !== 'dynamic_loo10') {
                $defensiveIndex++;
            }
        }
        return new AdaptiveRotationEnsembleBacktester($books);
    }

    public static function cases(bool $extendedRebound = false): array
    {
        $cases = ['baseline' => ['family' => 'control', 'changes' => []]];
        $add = static function (string $id, string $family, array $changes) use (&$cases): void {
            $cases[$id] = ['family' => $family, 'changes' => $changes];
        };
        foreach ([0.03, 0.05, 0.10, 0.20, 0.30] as $buffer) {
            $n = (int) round(100 * $buffer);
            $add('hysteresis_' . $n, 'hysteresis', ['rank_hysteresis' => $buffer]);
            $add('dynamic_hysteresis_' . $n, 'hysteresis', ['dynamic_overrides' => ['rank_hysteresis' => $buffer]]);
        }
        foreach ([1, 2] as $phase) {
            $add('phase_' . $phase, 'cadence', ['rebalance_phase' => $phase]);
        }
        $add('stagger_defensive', 'cadence', ['stagger_defensive' => true]);
        $add('three_phases', 'cadence', ['phase_count' => 3]);
        foreach ([2, 3, 5] as $size) {
            $add('basket_' . $size, 'basket', ['basket_size' => $size]);
            $add('basket_' . $size . '_sma50', 'basket', ['basket_size' => $size, 'asset_sma_period' => 50]);
        }
        foreach ([1.2, 1.5, 2.0] as $ratio) {
            foreach ([0.5, 0.75] as $scale) {
                $add('shock_' . $ratio . '_' . $scale, 'volatility_shock', ['volatility_shock_ratio' => $ratio, 'volatility_shock_multiplier' => $scale]);
            }
        }
        foreach ([20, 50, 100] as $sma) {
            foreach ([0.10, 0.20, 0.30] as $extension) {
                $add('extension_' . $sma . '_' . $extension, 'extension', ['asset_sma_period' => $sma, 'maximum_asset_sma_extension' => $extension]);
            }
        }
        foreach ([0.10, 0.15, 0.18] as $trail) {
            foreach ([1, 3, 5] as $pause) {
                $add('trail_' . $trail . '_pause_' . $pause, 'position_exit', ['position_trailing_close_pct' => $trail, 'position_exit_cooldown_sessions' => $pause]);
            }
        }
        $triggers = [
            'trend' => [],
            'calm_trend' => ['circuit_reentry_calm_required' => true],
            'day_2pct' => ['circuit_reentry_mode' => 'rebound', 'circuit_reentry_return_1' => 0.02],
            'three_day_3pct' => ['circuit_reentry_mode' => 'rebound', 'circuit_reentry_return_3' => 0.03],
            'three_day_5pct' => ['circuit_reentry_mode' => 'rebound', 'circuit_reentry_return_3' => 0.05],
            'five_day_4pct' => ['circuit_reentry_mode' => 'rebound', 'circuit_reentry_return_5' => 0.04],
            'five_day_6pct' => ['circuit_reentry_mode' => 'rebound', 'circuit_reentry_return_5' => 0.06],
            'broad_reclaim' => ['circuit_reentry_mode' => 'rebound', 'circuit_reentry_require_sma20' => true, 'circuit_reentry_minimum_breadth' => 0.60],
            'strong_breadth' => ['circuit_reentry_mode' => 'rebound', 'circuit_reentry_require_sma20' => true, 'circuit_reentry_minimum_breadth' => 0.75, 'circuit_reentry_return_3' => 0.03],
        ];
        foreach ($triggers as $trigger => $settings) {
            foreach ([3, 5, 10] as $minimum) {
                foreach ([1.0, 0.5] as $scale) {
                    $add('reentry_' . $trigger . '_' . $minimum . '_' . $scale, 'bullish_reentry', array_merge($settings, [
                        'circuit_reentry_min_sessions' => $minimum,
                        'circuit_reentry_initial_scale' => $scale,
                        'circuit_reentry_ramp_sessions' => $scale < 1.0 ? 5 : 0,
                    ]));
                }
            }
        }
        foreach ([1, 3, 5, 10, 20] as $pause) {
            $add('fixed_pause_' . $pause, 'fixed_pause', ['drawdown_cooldown_sessions' => $pause]);
        }
        foreach ([0.25, 0.35, 0.45, 0.50] as $weight) {
            foreach ([0.0, 0.05, 0.10] as $buffer) {
                $add('sma50_weight_' . $weight . '_buffer_' . $buffer, 'combined', ['asset_sma_period' => 50, 'dynamic_allocation' => $weight, 'rank_hysteresis' => $buffer]);
            }
        }
        foreach ([0.05, 0.10, 0.20] as $buffer) {
            $add('stagger_buffer_' . $buffer, 'combined', ['stagger_defensive' => true, 'rank_hysteresis' => $buffer]);
            $add('reentry_calm_buffer_' . $buffer, 'combined', ['circuit_reentry_min_sessions' => 5, 'circuit_reentry_calm_required' => true, 'rank_hysteresis' => $buffer]);
        }
        if ($extendedRebound) {
            foreach ([5 => [0.02, 0.04, 0.06], 10 => [0.03, 0.05, 0.08], 20 => [0.05, 0.08, 0.12]] as $window => $thresholds) {
                foreach ($thresholds as $threshold) {
                    foreach ([0, 2, 3] as $confirmation) {
                        foreach ([1.0, 0.5] as $scale) {
                            $add('trough_' . $window . '_' . $threshold . '_confirm_' . $confirmation . '_scale_' . $scale, 'trough_reentry', [
                                'circuit_reentry_mode' => 'rebound', 'circuit_reentry_min_sessions' => 5,
                                'circuit_reentry_trough_window' => $window, 'circuit_reentry_trough_rebound' => $threshold,
                                'circuit_reentry_confirmation_sessions' => $confirmation,
                                'circuit_reentry_leader_confirmation' => $confirmation > 0,
                                'circuit_reentry_initial_scale' => $scale,
                                'circuit_reentry_ramp_sessions' => $scale < 1.0 ? 5 : 0,
                            ]);
                        }
                    }
                }
            }
        }
        return $cases;
    }
}
