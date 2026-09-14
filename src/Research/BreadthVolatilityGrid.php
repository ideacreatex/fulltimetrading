<?php

declare(strict_types=1);

namespace FulltimeTrading\Research;

final class BreadthVolatilityGrid
{
    public static function definitions(): array
    {
        $cases = [];
        $add = static function (array $row) use (&$cases): void {
            $id = $row['family'] . '_' . implode('_', array_map(static fn ($x): string => is_array($x) ? substr(hash('sha256', json_encode($x)), 0, 12) : (string) $x, array_slice($row, 1)));
            if (isset($cases[$id])) { throw new \RuntimeException('Duplicate research case.'); }
            $cases[$id] = $row;
        };
        foreach (BreadthVolatilityResearch::EVENTS as $event) {
            foreach ([5, 10, 20] as $hold) {
                foreach ([0.0, 0.5, 0.75, 1.25] as $scale) {
                    $add(['family' => 's5tw', 'event' => $event, 'low' => 11, 'high' => 80, 'hold' => $hold, 'scale' => $scale]);
                }
            }
            foreach (str_starts_with($event, 'low') ? [5, 8, 15] : [75, 85, 90] as $level) {
                foreach ([0.5, 1.25] as $scale) {
                    $add(['family' => 's5tw_sensitivity', 'event' => $event,
                        'low' => str_starts_with($event, 'low') ? $level : 11,
                        'high' => str_starts_with($event, 'high') ? $level : 80, 'hold' => 10, 'scale' => $scale]);
                }
            }
        }
        foreach ([20, 50, 200] as $window) {
            foreach ([0.0, 0.5, 0.75] as $scale) { $add(['family' => 'svxy', 'mode' => 'sma', 'window' => $window, 'scale' => $scale]); }
        }
        foreach ([5, 20] as $window) {
            foreach ([0.5, 0.75] as $scale) { $add(['family' => 'svxy', 'mode' => 'return', 'window' => $window, 'scale' => $scale]); }
        }
        foreach ([90, 100, 110, 120, 130] as $threshold) {
            foreach ([0.0, 0.5, 0.75] as $scale) { $add(['family' => 'vvix', 'mode' => 'level', 'threshold' => $threshold, 'scale' => $scale]); }
        }
        foreach ([10, 20, 50] as $window) {
            foreach ([1.05, 1.15] as $threshold) { $add(['family' => 'vvix', 'mode' => 'sma_ratio', 'threshold' => $threshold, 'scale' => 0.5, 'window' => $window]); }
        }
        foreach ([0.1, 0.2, 0.3] as $threshold) {
            foreach ([0.0, 0.5] as $scale) { $add(['family' => 'vvix', 'mode' => 'rise5', 'threshold' => $threshold, 'scale' => $scale]); }
        }
        foreach ([0.8, 0.9] as $threshold) { $add(['family' => 'vvix', 'mode' => 'percentile252', 'threshold' => $threshold, 'scale' => 0.5]); }
        foreach ([80, 100, 120] as $anchor) { $add(['family' => 'vvix', 'mode' => 'inverse', 'threshold' => $anchor, 'scale' => 1.25]); }
        foreach ([0.75, 1.0, 1.25, 1.5] as $gross) {
            foreach ([0.5, 0.75, 1.25] as $multiplier) { $add(['family' => 'risk', 'changes' => ['max_gross' => $gross, 'volatility_target' => 0.45 * $multiplier]]); }
        }
        foreach ([0.25, 0.4, 0.6, 0.75] as $allocation) { $add(['family' => 'allocation', 'changes' => ['dynamic_allocation' => $allocation]]); }
        foreach ([0.1, 0.15, 0.2] as $kill) {
            foreach ([10, 20, 40, 60] as $pause) { $add(['family' => 'circuit', 'changes' => ['drawdown_kill_pct' => $kill, 'drawdown_cooldown_sessions' => $pause]]); }
        }
        foreach (['low_touch', 'low_reclaim11', 'high_close', 'high_fade80'] as $event) {
            foreach ([5, 20] as $hold) {
                foreach ([0.5, 1.25] as $scale) { $add(['family' => 'combined', 'event' => $event, 'low' => 11, 'high' => 80,
                    'hold' => $hold, 'scale' => $scale, 'vvix_limit' => 110, 'svxy_sma' => 20]); }
            }
        }
        return $cases;
    }

    public static function changes(array $definition, array $breadth, array $vvix, array $svxy): array
    {
        if (isset($definition['changes'])) { return $definition['changes']; }
        $dates = array_keys($breadth);
        $scale = array_fill_keys($dates, 1.0);
        if (isset($definition['event'])) {
            $events = BreadthVolatilityResearch::events($breadth, $definition['low'], $definition['high']);
            $scale = BreadthVolatilityResearch::windowScale($events[$definition['event']], $definition['hold'], $definition['scale']);
        }
        if ($definition['family'] === 'svxy' || $definition['family'] === 'combined') {
            $trend = BreadthVolatilityResearch::trend($svxy, $definition['window'] ?? $definition['svxy_sma'], $definition['mode'] ?? 'sma');
            foreach ($dates as $date) {
                if (!array_key_exists($date, $trend)) { throw new \RuntimeException('Missing SVXY signal session: ' . $date); }
                if (!$trend[$date]) { $scale[$date] = min($scale[$date], $definition['family'] === 'combined' ? 0.5 : $definition['scale']); }
            }
        }
        if ($definition['family'] === 'vvix' || $definition['family'] === 'combined') {
            $values = [];
            foreach ($dates as $date) {
                $v = $vvix[$date] ?? throw new \RuntimeException('Missing VVIX session: ' . $date);
                $i = count($values);
                $mode = $definition['mode'] ?? 'level';
                $threshold = $definition['threshold'] ?? $definition['vvix_limit'];
                $window = $definition['window'] ?? 20;
                $risky = match ($mode) {
                    'level' => $v >= $threshold,
                    'sma_ratio' => $i >= $window && $v > (array_sum(array_slice($values, -$window)) / $window) * $threshold,
                    'rise5' => $i >= 5 && $v / $values[$i - 5] - 1 >= $threshold,
                    'percentile252' => $i >= 252 && $v >= DailyDataAudit::quantile(array_slice($values, -252), $threshold),
                    'inverse' => false,
                    default => throw new \RuntimeException('Unknown VVIX mode.'),
                };
                if ($mode === 'inverse') { $scale[$date] = max(0.5, min(1.25, $threshold / $v)); }
                elseif ($risky) { $scale[$date] = min($scale[$date], $definition['family'] === 'combined' ? 0.5 : $definition['scale']); }
                $values[] = $v;
            }
        }
        return ['external_daily_scale' => $scale];
    }
}
