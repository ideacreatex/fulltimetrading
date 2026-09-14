<?php

declare(strict_types=1);

namespace FulltimeTrading\Research;

use FulltimeTrading\Backtest\CausalTacticalRotationEnsembleBacktester;
use FulltimeTrading\Domain\Bar;

/** Offline experiments only. This class is not an execution profile selector. */
final class HybridV4Research
{
    public static function cases(string $suite = 'core'): array
    {
        if ($suite === 'diversification') {
            return self::diversificationCases();
        }
        if ($suite !== 'core') {
            throw new \InvalidArgumentException('Unknown research suite.');
        }
        $cases = ['baseline' => []];
        foreach ([10, 20, 30, 60] as $sessions) {
            $cases['cooldown_' . $sessions] = ['drawdown_cooldown_sessions' => $sessions];
        }
        foreach ([0.75, 0.85, 0.95] as $scale) {
            $cases['risk_' . (int) round(100 * $scale)] = ['risk_scale' => $scale];
        }
        foreach ([1, 2, 5, 10] as $sessions) {
            $cases['rebalance_' . $sessions] = ['rebalance_sessions' => $sessions];
        }
        foreach ([50, 100, 200] as $period) {
            $cases['asset_sma_' . $period] = ['asset_sma_period' => $period];
        }
        foreach ([0.0, -1.0, -3.0] as $weight) {
            $cases['reversal_' . abs((int) $weight)] = ['short_reversal_weight' => $weight];
        }
        foreach ([0.5, 1.5] as $power) {
            $cases['vol_score_' . str_replace('.', '_', (string) $power)] = ['volatility_score_power' => $power];
        }
        foreach ([10, 20] as $cooldown) {
            foreach ([0.75, 0.85] as $scale) {
                $cases['cooldown_' . $cooldown . '_risk_' . (int) round(100 * $scale)] = [
                    'drawdown_cooldown_sessions' => $cooldown,
                    'risk_scale' => $scale,
                ];
            }
        }
        foreach ([10, 20] as $cooldown) {
            $cases['cooldown_' . $cooldown . '_rebalance_5'] = [
                'drawdown_cooldown_sessions' => $cooldown,
                'rebalance_sessions' => 5,
            ];
        }

        return $cases;
    }

    private static function diversificationCases(): array
    {
        $cases = ['baseline' => []];
        foreach ([0.4, 0.5] as $allocation) {
            $cases['dynamic_weight_' . (int) round(100 * $allocation)] = ['dynamic_allocation' => $allocation];
            $cases['sma50_weight_' . (int) round(100 * $allocation)] = ['asset_sma_period' => 50, 'dynamic_allocation' => $allocation];
        }
        foreach ([20, 50, 100] as $period) {
            $cases['dynamic_sma_' . $period] = ['dynamic_overrides' => ['asset_sma_period' => $period]];
            $cases['defensive_sma_' . $period] = ['defensive_overrides' => ['asset_sma_period' => $period]];
        }
        foreach ([2, 5] as $cadence) {
            $cases['defensive_cadence_' . $cadence] = ['defensive_overrides' => ['rebalance_sessions' => $cadence]];
        }
        foreach ([0.0, -1.0] as $weight) {
            $cases['defensive_reversal_' . abs((int) $weight)] = ['defensive_overrides' => ['short_reversal_weight' => $weight]];
        }
        foreach ([10, 20, 30] as $cooldown) {
            $cases['defensive_cooldown_' . $cooldown] = ['defensive_overrides' => ['drawdown_cooldown_sessions' => $cooldown]];
        }
        return $cases;
    }

    public static function backtester(array $profile, array $changes, float $cost): CausalTacticalRotationEnsembleBacktester
    {
        if (($profile['production_approved'] ?? null) !== false
            || ($profile['order_submission_enabled'] ?? null) !== false) {
            throw new \InvalidArgumentException('Research requires a disabled execution profile.');
        }
        $base = array_diff_key($profile, array_flip([
            'sleeves', 'validation', 'profile', 'status', 'production_approved',
            'paper_shadow_enabled', 'order_submission_enabled', 'order_submission_block_reason',
        ]));
        $sleeves = [];
        foreach ($profile['sleeves'] as $name => $definition) {
            $config = array_replace($base, $definition['config']);
            $specific = $name === 'dynamic_loo10' ? 'dynamic_overrides' : 'defensive_overrides';
            $overrides = array_replace(
                array_diff_key($changes, array_flip(['dynamic_allocation', 'dynamic_overrides', 'defensive_overrides'])),
                $changes[$specific] ?? [],
            );
            foreach ($overrides as $key => $value) {
                if ($key === 'risk_scale') {
                    foreach (['volatility_target', 'benchmark_volatility_target', 'max_gross'] as $riskKey) {
                        $config[$riskKey] *= $value;
                    }
                } elseif ($key === 'short_reversal_weight') {
                    $config['factor_weights'][5] = $value;
                } elseif ($key === 'exclude_symbols') {
                    $config['universe'] = array_values(array_diff($config['universe'], $value));
                } else {
                    if (!array_key_exists($key, $config)) {
                        throw new \InvalidArgumentException('Unknown research parameter: ' . $key);
                    }
                    $config[$key] = $value;
                }
            }
            $config['cost_bps'] = $cost;
            $allocation = $definition['allocation'];
            if (isset($changes['dynamic_allocation'])) {
                $allocation = $name === 'dynamic_loo10' ? $changes['dynamic_allocation'] : (1.0 - $changes['dynamic_allocation']) / 3.0;
            }
            $sleeves[$name] = ['allocation' => $allocation, 'config' => $config];
        }

        return new CausalTacticalRotationEnsembleBacktester($sleeves);
    }

    public static function truncateBars(array $bars, string $end): array
    {
        $timezone = new \DateTimeZone('America/New_York');
        return array_map(static fn (array $series): array => array_values(array_filter(
            $series,
            static fn (Bar $bar): bool => $bar->time->setTimezone($timezone)->format('Y-m-d') <= $end,
        )), $bars);
    }

    public static function trainFailures(array $metrics, array $gate): array
    {
        $checks = [
            'cagr' => $metrics['cagr'] >= $gate['minimum_train_cagr'],
            'drawdown' => $metrics['max_drawdown'] >= -$gate['maximum_drawdown'],
            'gross' => $metrics['max_gross_bound'] <= $gate['maximum_gross_bound'],
            'ex_top5_days_cagr' => $metrics['ex_top5_days_cagr'] >= $gate['minimum_train_ex_top5_days_cagr'],
            'episodes' => $metrics['positive_holding_episodes'] >= $gate['minimum_train_positive_holding_episodes'],
            'episode_concentration' => $metrics['top1_positive_episode_share'] <= $gate['maximum_train_top1_positive_episode_share'],
            'symbols' => $metrics['return_symbols'] >= $gate['minimum_train_return_symbols'],
        ];
        return array_keys(array_filter($checks, static fn (bool $pass): bool => !$pass));
    }

    /** Rank only training results. Later-period fields cannot affect selection. */
    public static function shortlist(array $screen, int $limit = 3): array
    {
        $eligible = array_filter($screen, static fn (array $row, string $id): bool =>
            $id !== 'baseline' && $row['train_failed_gates'] === [], ARRAY_FILTER_USE_BOTH);
        uksort($eligible, static function (string $a, string $b) use ($eligible): int {
            $score = static fn (array $row): float => $row['train']['cagr'] / max(0.01, abs($row['train']['max_drawdown']));
            return $score($eligible[$b]) <=> $score($eligible[$a]) ?: strcmp($a, $b);
        });
        return array_slice(array_keys($eligible), 0, $limit);
    }

    public static function activity(array $sleeveCurves, ?string $start = null, ?string $end = null): array
    {
        $answer = [];
        foreach ($sleeveCurves as $id => $curve) {
            $last = null;
            $entries = $exits = $resizeDays = $invested = $cash = $streak = $longest = 0;
            $events = [];
            foreach ($curve as $row) {
                $holding = $row['holding'];
                if (($start === null || $row['date'] >= $start) && ($end === null || $row['date'] < $end)) {
                    $entries += (int) ($holding !== null && $holding !== $last);
                    $exits += (int) ($last !== null && $last !== $holding);
                    $resizeDays += (int) ($holding !== null && $holding === $last && $row['turnover'] > 1.0e-10);
                    $invested += (int) ($holding !== null);
                    $cash += (int) ($holding === null);
                    $streak = $holding === null ? $streak + 1 : 0;
                    $longest = max($longest, $streak);
                    if (($row['risk_signal'] ?? null) !== null) {
                        $events[] = ['date' => $row['date'], 'reason' => $row['risk_signal'], 'cooldown' => $row['circuit_cooldown_left']];
                    }
                }
                $last = $holding;
            }
            $answer[$id] = [
                'entries' => $entries, 'exits' => $exits, 'resize_days' => $resizeDays,
                'invested_sessions' => $invested, 'cash_sessions' => $cash,
                'longest_cash_streak' => $longest, 'risk_events' => $events,
            ];
        }
        return $answer;
    }
}
