<?php

declare(strict_types=1);

namespace FulltimeTrading\Research;

use FulltimeTrading\Domain\Bar;

/** Price-only research policies, never an execution permission or a fitted model. */
final class AlgorithmSignalPolicy
{
    public const FAMILIES = [
        'relative_strength', 'acceleration', 'efficiency_score', 'volume_score',
        'smoothed_score', 'breadth_risk', 'trend_vote_risk', 'downside_size',
        'tail_size', 'gap_size', 'drawdown_size', 'volofvol_size', 'resize_band',
    ];

    public static function validate(mixed $policy): array
    {
        if ($policy === []) {
            return [];
        }
        if (!is_array($policy) || array_diff(array_keys($policy), ['family', 'window', 'strength', 'mode']) !== []
            || !in_array($policy['family'] ?? '', self::FAMILIES, true)
            || !is_int($policy['window'] ?? null) || $policy['window'] < 2 || $policy['window'] > 252
            || !is_numeric($policy['strength'] ?? null) || !is_finite((float) $policy['strength'])
            || $policy['strength'] <= 0.0 || $policy['strength'] > 3.0
            || !in_array($policy['mode'] ?? 'both', ['both', 'increase_only'], true)) {
            throw new \InvalidArgumentException('Invalid algorithm research policy.');
        }
        if (in_array($policy['family'], ['efficiency_score', 'smoothed_score', 'downside_size', 'trend_vote_risk', 'breadth_risk', 'resize_band'], true)
            && $policy['strength'] > 1.0) {
            throw new \InvalidArgumentException('Research blend/threshold must be at most one.');
        }
        return $policy;
    }

    /** @param list<Bar> $bars @return list<array<string,float|null>> */
    public static function features(array $bars, array $policy, array $factorWeights): array
    {
        if ($policy === [] || $policy['family'] === 'resize_band') {
            return [];
        }
        $family = $policy['family'];
        $window = $policy['window'];
        $closes = array_map(static fn (Bar $b): float => $b->close, $bars);
        $returns = $gaps = $fastVols = $answer = [];
        $ema = null;
        foreach ($bars as $i => $bar) {
            if ($bar->close <= 0.0 || $bar->open <= 0.0) {
                throw new \InvalidArgumentException('Policy needs positive historical prices.');
            }
            $returns[$i] = $i > 0 ? $bar->close / $closes[$i - 1] - 1.0 : 0.0;
            $gaps[$i] = $i > 0 ? $bar->open / $closes[$i - 1] - 1.0 : 0.0;
            $row = [];
            if ($family === 'smoothed_score') {
                $raw = 0.0;
                $valid = true;
                foreach ($factorWeights as $period => $weight) {
                    if ($i < $period) {
                        $valid = false;
                        break;
                    }
                    $raw += $weight * ($bar->close / $closes[$i - $period] - 1.0);
                }
                if ($valid) {
                    $alpha = 2.0 / ($window + 1.0);
                    $ema = $ema === null ? $raw : $alpha * $raw + (1.0 - $alpha) * $ema;
                }
                $row['algo_smoothed_score'] = $ema;
            }
            if ($family === 'volofvol_size') {
                $fastVols[$i] = $i >= 5 ? self::deviation(array_slice($returns, $i - 4, 5)) : 0.0;
            }
            if ($i >= $window) {
                $recent = array_slice($returns, $i - $window + 1, $window);
                $prices = array_slice($closes, $i - $window + 1, $window);
                switch ($family) {
                    case 'relative_strength':
                        $row['algo_return'] = $bar->close / $closes[$i - $window] - 1.0;
                        break;
                    case 'acceleration':
                        $row['algo_acceleration'] = $i >= 2 * $window
                            ? ($bar->close / $closes[$i - $window] - 1.0)
                                - 0.5 * ($bar->close / $closes[$i - 2 * $window] - 1.0) : null;
                        break;
                    case 'efficiency_score':
                        $travel = 0.0;
                        for ($j = $i - $window + 1; $j <= $i; $j++) {
                            $travel += abs($closes[$j] - $closes[$j - 1]);
                        }
                        $row['algo_efficiency'] = $travel > 0.0 ? abs($bar->close - $closes[$i - $window]) / $travel : 0.0;
                        break;
                    case 'volume_score':
                        // The denominator excludes the just-completed signal bar.
                        $average = array_sum(array_map(static fn (Bar $b): float => $b->volume, array_slice($bars, $i - $window, $window))) / $window;
                        $row['algo_volume_ratio'] = $average > 0.0 ? $bar->volume / $average : null;
                        break;
                    case 'breadth_risk':
                        $row['algo_sma'] = array_sum($prices) / $window;
                        break;
                    case 'trend_vote_risk':
                        $votes = 0;
                        foreach ([1, 2, 3, 4] as $multiple) {
                            $period = $window * $multiple;
                            $votes += (int) ($i >= $period && $bar->close > $closes[$i - $period]);
                        }
                        $row['algo_vote'] = $i >= 4 * $window ? $votes / 4.0 : null;
                        break;
                    case 'downside_size':
                        $row['algo_downside'] = sqrt(2.0 * array_sum(array_map(static fn (float $r): float => min(0.0, $r) ** 2, $recent)) / $window * 252.0);
                        break;
                    case 'tail_size':
                        sort($recent, SORT_NUMERIC);
                        $tail = array_slice($recent, 0, max(1, (int) ceil($window * 0.20)));
                        $row['algo_tail'] = max(0.0, -array_sum($tail) / count($tail)) * sqrt(252.0);
                        break;
                    case 'gap_size':
                        $row['algo_gap'] = sqrt(array_sum(array_map(static fn (float $r): float => $r ** 2, array_slice($gaps, $i - $window + 1, $window))) / $window);
                        break;
                    case 'drawdown_size':
                        $row['algo_drawdown'] = max(0.0, 1.0 - $bar->close / max($prices));
                        break;
                    case 'volofvol_size':
                        $sample = array_slice($fastVols, $i - $window + 1, $window);
                        $mean = array_sum($sample) / $window;
                        $row['algo_volofvol'] = $i >= $window + 5 && $mean > 0.0 ? self::deviation($sample) / $mean : null;
                        break;
                }
            }
            $answer[] = $row;
        }
        return $answer;
    }

    public static function score(float $base, array $row, array $market, array $policy): ?float
    {
        if ($policy === []) {
            return $base;
        }
        $s = (float) $policy['strength'];
        $field = match ($policy['family']) {
            'relative_strength' => 'algo_return', 'acceleration' => 'algo_acceleration',
            'efficiency_score' => 'algo_efficiency', 'volume_score' => 'algo_volume_ratio',
            'smoothed_score' => 'algo_smoothed_score', default => null,
        };
        if ($field !== null && !is_float($row[$field] ?? null)) {
            return null;
        }
        return match ($policy['family']) {
            'relative_strength' => is_float($market['algo_return'] ?? null) ? $base + $s * ($row[$field] - $market['algo_return']) : null,
            'acceleration' => $base + $s * $row[$field],
            'efficiency_score' => $base * (1.0 - $s + $s * $row[$field]),
            'volume_score' => $base * max(0.25, min(4.0, $row[$field])) ** $s,
            'smoothed_score' => (1.0 - $s) * $base + $s * $row[$field],
            default => $base,
        };
    }

    public static function riskScale(array $row, array $features, array $universe, string $date, int $minimumHistory, array $policy): float
    {
        if ($policy === []) {
            return 1.0;
        }
        $s = (float) $policy['strength'];
        if ($policy['family'] === 'breadth_risk') {
            $n = $above = 0;
            foreach ($universe as $symbol) {
                $other = $features[$symbol][$date] ?? [];
                if (is_float($other['algo_sma'] ?? null) && ($other['history_sessions'] ?? 0) >= $minimumHistory) {
                    $n++;
                    $above += (int) ($other['close'] > $other['algo_sma']);
                }
            }
            return $n > 0 && $above / $n >= $s ? 1.0 : 0.5;
        }
        $vol = max(0.05, (float) ($row['volatility'] ?? 0.0));
        return match ($policy['family']) {
            'trend_vote_risk' => ($row['algo_vote'] ?? 0.0) >= 0.75 ? 1.0 : 1.0 - $s,
            'downside_size' => isset($row['algo_downside']) ? $vol / max(0.05, (1.0 - $s) * $vol + $s * $row['algo_downside']) : 0.0,
            'tail_size' => isset($row['algo_tail']) ? min(1.0, $vol / max(0.05, $row['algo_tail'] / $s)) : 0.0,
            'gap_size' => isset($row['algo_gap']) ? min(1.0, $s / max(0.000001, $row['algo_gap'])) : 0.0,
            'drawdown_size' => isset($row['algo_drawdown']) ? min(1.0, $s / max(0.000001, $row['algo_drawdown'])) : 0.0,
            'volofvol_size' => isset($row['algo_volofvol']) ? 1.0 / (1.0 + $s * $row['algo_volofvol']) : 0.0,
            default => 1.0,
        };
    }

    public static function executionTarget(array $current, array $target, float $maxGross, array $policy): array
    {
        if (($policy['family'] ?? '') !== 'resize_band' || count($current) !== 1 || count($target) !== 1
            || array_key_first($current) !== array_key_first($target)) {
            return $target;
        }
        $held = (float) reset($current);
        $wanted = (float) reset($target);
        if ($held > $maxGross || (($policy['mode'] ?? 'both') === 'increase_only' && $wanted < $held)) {
            return $target;
        }
        return abs($wanted - $held) < $policy['strength'] ? $current : $target;
    }

    private static function deviation(array $values): float
    {
        $mean = array_sum($values) / count($values);
        return sqrt(array_sum(array_map(static fn (float $x): float => ($x - $mean) ** 2, $values)) / count($values));
    }
}
