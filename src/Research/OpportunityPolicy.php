<?php

declare(strict_types=1);

namespace FulltimeTrading\Research;

/** Additive, research-only stock-selection and matured-outcome policies. */
final class OpportunityPolicy
{
    public const FACTORS = ['trend_r2', 'residual_momentum', 'intraday_momentum', 'overnight_momentum', 'close_pressure', 'volume_confirmation'];

    public static function validate(array $p): array
    {
        if ($p === []) { return []; }
        if (!in_array($p['family'] ?? '', array_merge(self::FACTORS, ['cost_band', 'conditional_return']), true)
            || !in_array($p['window'] ?? null, [20, 60, 252, 504], true)
            || !is_numeric($p['strength'] ?? null) || !is_finite((float) $p['strength']) || $p['strength'] < 0 || $p['strength'] > 4
            || array_diff(array_keys($p), ['family', 'window', 'strength', 'horizon', 'threshold']) !== []) {
            throw new \InvalidArgumentException('Invalid opportunity policy.');
        }
        if (in_array($p['family'], self::FACTORS, true) && (!in_array($p['window'], [20, 60], true) || $p['strength'] > 1)) {
            throw new \InvalidArgumentException('Invalid factor scale.');
        }
        if ($p['family'] === 'conditional_return' && (!in_array($p['window'], [252, 504], true)
            || !in_array($p['horizon'] ?? null, [3, 10], true) || !is_numeric($p['threshold'] ?? null)
            || !in_array((float) $p['threshold'], [0.0, 0.005], true))) {
            throw new \InvalidArgumentException('Invalid conditional model.');
        }
        return $p;
    }

    public static function definitions(): array
    {
        $cases = [];
        foreach (['maximum', 'maximum_stop12'] as $anchor) {
            $cases[$anchor] = ['anchor' => $anchor, 'family' => 'control', 'policy' => []];
            foreach (self::FACTORS as $family) {
                foreach ([20, 60] as $window) {
                    foreach ([0.25, 0.5] as $strength) {
                        $id = $anchor . '__' . $family . '_' . $window . '_' . str_replace('.', 'p', (string) $strength);
                        $cases[$id] = ['anchor' => $anchor, 'family' => $family, 'policy' => compact('family', 'window', 'strength')];
                    }
                }
            }
            foreach ([0.5, 1.0, 2.0, 4.0] as $strength) {
                $id = $anchor . '__cost_band_' . str_replace('.', 'p', (string) $strength);
                $cases[$id] = ['anchor' => $anchor, 'family' => 'cost_band', 'policy' => ['family' => 'cost_band', 'window' => 20, 'strength' => $strength]];
            }
            foreach ([252, 504] as $window) {
                foreach ([3, 10] as $horizon) {
                    foreach ([0.0, 0.005] as $threshold) {
                        $id = $anchor . '__conditional_' . $window . '_' . $horizon . '_' . str_replace('.', 'p', (string) $threshold);
                        $cases[$id] = ['anchor' => $anchor, 'family' => 'conditional_return', 'policy' => ['family' => 'conditional_return',
                            'window' => $window, 'horizon' => $horizon, 'threshold' => $threshold, 'strength' => 0.5]];
                    }
                }
            }
        }
        return $cases;
    }

    public static function score(float $base, array $row, array $p): ?float
    {
        if ($p === [] || !in_array($p['family'], self::FACTORS, true)) { return $base; }
        $v = $row[$p['family'] . '_' . $p['window']] ?? null;
        if (!is_numeric($v) || !is_finite((float) $v)) { return null; }
        return match ($p['family']) {
            'trend_r2' => $base * (1 - $p['strength'] + $p['strength'] * $v),
            'residual_momentum', 'intraday_momentum', 'overnight_momentum' => $base + $p['strength'] * $v,
            default => $base * (1 + $p['strength'] * max(-1.0, min(1.0, $v))),
        };
    }

    public static function choose(string $leader, ?string $incumbent, array $scores, array $features, string $date, array $p, float $cost): string
    {
        if (($p['family'] ?? '') !== 'cost_band' || $incumbent === null || !isset($scores[$incumbent]) || $incumbent === $leader) { return $leader; }
        $a = $features[$leader][$date]['drift'] ?? null; $b = $features[$incumbent][$date]['drift'] ?? null;
        if ($a === null || $b === null) { throw new \RuntimeException('Missing historical drift proxy.'); }
        // A momentum-implied drift proxy, not a calibrated expected-return forecast.
        return 3.0 * ($a - $b) <= 2.0 * $cost / 10000 * $p['strength'] ? $incumbent : $leader;
    }

    public static function scale(array $row, array $p): float
    {
        if (($p['family'] ?? '') !== 'conditional_return') { return 1.0; }
        $v = $row['conditional_' . $p['window'] . '_' . $p['horizon']] ?? null;
        // Insufficient matured training evidence leaves the unchanged anchor in place.
        return $v !== null && $v < $p['threshold'] ? 0.5 : 1.0;
    }

    public static function features(array $bars, array $vvix, array $trainingUniverse): array
    {
        $indexed = DailyDataAudit::indexed($bars);
        if (!isset($indexed['SPY'])) { throw new \InvalidArgumentException('SPY calendar required.'); }
        $dates = array_keys($indexed['SPY']); $answer = [];
        foreach ($bars as $symbol => $series) {
            $closes = $logs = $market = $intraday = $overnight = $volChange = $pressure = $volume = [];
            foreach ($dates as $i => $date) {
                $b = $indexed[$symbol][$date] ?? null; $prev = $i > 0 ? ($indexed[$symbol][$dates[$i - 1]] ?? null) : null;
                if ($b === null || $prev === null) { continue; }
                $closes[$i] = $b->close;
                $logs[$i] = log($b->close / $prev->close);
                $market[$i] = log($indexed['SPY'][$date]->close / $indexed['SPY'][$dates[$i - 1]]->close);
                $intraday[$i] = log($b->close / $b->open); $overnight[$i] = log($b->open / $prev->close);
                $volChange[$i] = log(max(1, $b->volume) / max(1, $prev->volume));
                $volume[$i] = $b->volume;
                $pressure[$i] = $b->high > $b->low ? (2 * $b->close - $b->high - $b->low) / ($b->high - $b->low) : 0.0;
                $row = [];
                foreach ([20, 60] as $w) {
                    if ($i < $w || !isset($closes[$i - $w + 1]) || count(array_intersect_key($closes, array_flip(range($i - $w + 1, $i)))) !== $w) { continue; }
                    $get = static fn ($a): array => array_values(array_intersect_key($a, array_flip(range($i - $w + 1, $i))));
                    $c = array_map('log', $get($closes)); $x = $get($logs); $m = $get($market);
                    $beta = self::covariance($x, $m) / max(1e-12, self::covariance($m, $m));
                    $row['trend_r2_' . $w] = self::correlation(range(1, $w), $c) ** 2;
                    $row['residual_momentum_' . $w] = exp(max(-5.0, min(5.0, array_sum($x) - $beta * array_sum($m)))) - 1;
                    $row['intraday_momentum_' . $w] = exp(array_sum($get($intraday))) - 1;
                    $row['overnight_momentum_' . $w] = exp(array_sum($get($overnight))) - 1;
                    $v = $get($volume); $q = $get($pressure);
                    $row['close_pressure_' . $w] = array_sum(array_map(static fn ($a, $b) => $a * $b, $v, $q)) / max(1, array_sum($v));
                    $row['volume_confirmation_' . $w] = self::correlation($x, $get($volChange));
                }
                if (isset($closes[$i - 120])) {
                    $drift = $raw = 0.0;
                    foreach ([5 => -2, 20 => 1, 60 => 1, 90 => 2, 120 => -1] as $w => $weight) {
                        if (!isset($closes[$i - $w])) { continue 2; }
                        $r = $b->close / $closes[$i - $w] - 1; $raw += $weight * $r; $drift += $weight * $r / $w;
                    }
                    $row['drift'] = $drift;
                    $recent = array_values(array_intersect_key($closes, array_flip(range($i - 49, $i))));
                    $row['eligible'] = $raw > 0 && count($recent) === 50 && $b->close > array_sum($recent) / 50 && isset($closes[$i - 252]);
                    $row['bin'] = (int) (($row['trend_r2_60'] ?? 0) >= 0.5) + 2 * (int) ($drift >= 0.002) + 4 * (int) (($vvix[$date] ?? 110) >= 110);
                }
                $answer[$symbol][$date] = $row;
            }
        }
        foreach ([3, 10] as $h) {
            foreach ([252, 504] as $window) {
                $queue = []; $sums = $counts = array_fill(0, 8, 0); $sum = 0.0; $n = 0;
                foreach ($dates as $i => $date) {
                    $origin = $i - $h - 1;
                    if ($origin >= 0 && $origin % $h === 0) {
                        foreach ($trainingUniverse as $symbol) {
                            $r = $answer[$symbol][$dates[$origin]] ?? [];
                            $entry = $indexed[$symbol][$dates[$origin + 1]] ?? null; $exit = $indexed[$symbol][$date] ?? null;
                            if (!($r['eligible'] ?? false) || $entry === null || $exit === null) { continue; }
                            $y = max(-0.5, min(0.5, $exit->open / $entry->open - 1)) - 0.006;
                            $bin = $r['bin']; $queue[] = [$origin, $bin, $y]; $counts[$bin]++; $sums[$bin] += $y; $sum += $y; $n++;
                        }
                    }
                    while ($queue !== [] && $queue[0][0] < $i - $window) {
                        [, $bin, $y] = array_shift($queue); $counts[$bin]--; $sums[$bin] -= $y; $n--; $sum -= $y;
                    }
                    foreach ($answer as $symbol => &$rows) {
                        if (!isset($rows[$date]['bin'])) { continue; }
                        $bin = $rows[$date]['bin'];
                        $rows[$date]['conditional_' . $window . '_' . $h] = $n >= 200 && $counts[$bin] >= 30
                            ? ($sums[$bin] + 20.0 * $sum / $n) / ($counts[$bin] + 20.0) : null;
                    }
                    unset($rows);
                }
            }
        }
        return $answer;
    }

    private static function covariance(array $a, array $b): float
    {
        $n = count($a); $ma = array_sum($a) / $n; $mb = array_sum($b) / $n; $s = 0.0;
        foreach ($a as $i => $v) { $s += ($v - $ma) * ($b[$i] - $mb); }
        return $s / $n;
    }

    private static function correlation(array $a, array $b): float
    {
        return max(-1.0, min(1.0, self::covariance($a, $b) / sqrt(max(1e-24, self::covariance($a, $a) * self::covariance($b, $b)))));
    }
}
