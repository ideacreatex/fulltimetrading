<?php

declare(strict_types=1);

namespace FulltimeTrading\Research;

final class AlgorithmTrendResearch
{
    public static function cases(): array
    {
        $cases = ['baseline' => ['family' => 'control', 'changes' => []],
            'previous_best' => ['family' => 'control', 'changes' => [
                'asset_sma_period' => 50, 'dynamic_allocation' => 0.5,
                'circuit_reentry_mode' => 'rebound', 'circuit_reentry_return_1' => 0.02,
                'circuit_reentry_min_sessions' => 5,
            ]]];
        $grid = [
            'relative_strength' => [[20, 60], [0.5, 1.0, 2.0]],
            'acceleration' => [[10, 20], [0.5, 1.0, 2.0]],
            'efficiency_score' => [[20, 60], [0.25, 0.5, 1.0]],
            'volume_score' => [[20, 60], [0.25, 0.5, 1.0]],
            'smoothed_score' => [[5, 10], [0.25, 0.5, 1.0]],
            'breadth_risk' => [[20, 50], [0.4, 0.6, 0.8]],
            'trend_vote_risk' => [[20, 50], [0.25, 0.5, 0.75]],
            'downside_size' => [[20, 60], [0.25, 0.5, 1.0]],
            'tail_size' => [[20, 60], [1.5, 2.0, 3.0]],
            'gap_size' => [[5, 20], [0.005, 0.01, 0.02]],
            'drawdown_size' => [[20, 60], [0.05, 0.10, 0.15]],
            'volofvol_size' => [[20, 60], [0.25, 0.5, 0.75]],
            'resize_band' => [['both', 'increase_only'], [0.02, 0.05, 0.10]],
        ];
        foreach ($grid as $family => [$windows, $strengths]) {
            foreach ($windows as $window) {
                foreach ($strengths as $strength) {
                    foreach (['all', 'dynamic'] as $scope) {
                        $policy = ['family' => $family, 'window' => is_int($window) ? $window : 20, 'strength' => $strength];
                        if (is_string($window)) {
                            $policy['mode'] = $window;
                        }
                        $changes = ['algorithm_policy' => $policy];
                        if ($scope === 'dynamic') {
                            $changes = ['dynamic_overrides' => $changes];
                        }
                        $id = $family . '_' . $window . '_' . str_replace('.', 'p', (string) $strength) . '_' . $scope;
                        $cases[$id] = ['family' => $family, 'scope' => $scope, 'changes' => $changes];
                    }
                }
            }
        }
        return $cases;
    }

    public static function write(string $file, array $data): void
    {
        $temporary = $file . '.tmp';
        if (file_put_contents($temporary, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) === false
            || !rename($temporary, $file)) {
            throw new \RuntimeException('Cannot persist research artifact: ' . $file);
        }
    }
}
