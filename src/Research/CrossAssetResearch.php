<?php

declare(strict_types=1);

namespace FulltimeTrading\Research;

/** Independent market-state hypotheses; ratios are ETF proxies, not credit spreads. */
final class CrossAssetResearch
{
    public const RATIOS = ['credit' => ['HYG', 'LQD'], 'credit_treasury' => ['HYG', 'IEF'],
        'small_caps' => ['IWM', 'SPY'], 'equal_weight' => ['RSP', 'SPY'], 'cyclicals' => ['XLY', 'XLP'],
        'growth_defensive' => ['XLK', 'XLU'], 'financials_defensive' => ['XLF', 'XLU'],
        'emerging_relative' => ['EEM', 'EFA'], 'copper_gold' => ['CPER', 'GLD'], 'duration' => ['TLT', 'SHY'],
        'volatility_etf_curve' => ['VIXY', 'VIXM']];

    public static function features(array $bars, array $cboe, array $breadth, array $vvix, array $allowedMissing = []): array
    {
        $index = DailyDataAudit::indexed($bars);
        $dates = array_keys($index['SPY']);
        $needed = array_unique(array_merge(['SPY', 'TLT', 'UUP', 'XLY', 'XLP', 'XLK', 'XLU', 'XLF'], ...array_values(self::RATIOS)));
        $closes = [];
        foreach ($dates as $date) {
            foreach ($needed as $s) {
                if (!isset($index[$s][$date])) { throw new \RuntimeException('Missing proxy date: ' . $s . '/' . $date); }
                $closes[$s][] = $index[$s][$date]->close;
            }
            foreach (['VIX', 'VIX9D', 'OVX', 'GVZ'] as $s) {
                if (!isset($cboe[$s][$date]) && in_array($date, $allowedMissing[$s] ?? [], true) && in_array($s, ['OVX', 'GVZ'], true)) { continue; }
                if (!isset($cboe[$s][$date]) || !is_numeric($cboe[$s][$date]) || $cboe[$s][$date] <= 0) { throw new \RuntimeException('Missing Cboe session: ' . $s . '/' . $date); }
            }
            if (!isset($breadth[$date], $vvix[$date])) { throw new \RuntimeException('Missing existing external indicator.'); }
        }
        $values = [];
        foreach (self::RATIOS as $name => [$a, $b]) { $values[$name] = array_map(static fn ($x, $y): float => $x / $y, $closes[$a], $closes[$b]); }
        $values['dollar'] = $closes['UUP'];
        $features = $returns = $ranges = [];
        foreach ($dates as $i => $date) {
            foreach (['SPY', 'TLT'] as $s) { $returns[$s][] = $i === 0 ? 0.0 : log($closes[$s][$i] / $closes[$s][$i - 1]); }
            foreach ($values as $name => $series) {
                foreach ([20, 60] as $window) {
                    $features[$name . '_sma' . $window][$date] = $i + 1 < $window ? null : $series[$i] / (array_sum(array_slice($series, $i - $window + 1, $window)) / $window) - 1;
                }
            }
            $features['short_vol_term'][$date] = $cboe['VIX9D'][$date] / $cboe['VIX'][$date];
            $realized = $i < 20 ? null : self::stdev(array_slice($returns['SPY'], -20)) * sqrt(252) * 100;
            $features['implied_realized'][$date] = $realized === null || $realized === 0.0 ? null : $cboe['VIX'][$date] / $realized;
            foreach ([20, 60] as $window) {
                $features['stock_bond_corr' . $window][$date] = $i < $window ? null : self::correlation(array_slice($returns['SPY'], -$window), array_slice($returns['TLT'], -$window));
            }
            $bar = $index['SPY'][$date]; $previous = $closes['SPY'][max(0, $i - 1)];
            $ranges[] = max($bar->high - $bar->low, abs($bar->high - $previous), abs($bar->low - $previous)) / $previous;
            $features['atr20'][$date] = $i < 19 ? null : array_sum(array_slice($ranges, -20)) / 20;
            foreach ([20, 50] as $window) {
                $count = 0;
                foreach (['XLY', 'XLP', 'XLK', 'XLU', 'XLF'] as $s) {
                    $count += (int) ($i >= $window - 1 && $closes[$s][$i] > array_sum(array_slice($closes[$s], $i - $window + 1, $window)) / $window);
                }
                $features['five_sector_breadth' . $window][$date] = $i < $window - 1 ? null : $count / 5;
            }
            $features['risk_votes'][$date] = (int) (($features['credit_sma20'][$date] ?? -1) < 0)
                + (int) (($features['small_caps_sma20'][$date] ?? -1) < 0)
                + (int) ($features['short_vol_term'][$date] > 1)
                + (int) ($breadth[$date]['close'] < 30) + (int) ($vvix[$date] >= 110);
        }
        foreach (['OVX' => $cboe['OVX'], 'GVZ' => $cboe['GVZ'], 'atr_percentile' => $features['atr20']] as $name => $series) {
            $history = [];
            foreach ($dates as $date) {
                $value = $series[$date] ?? null;
                $prior = array_slice($history, -252);
                $features[$name][$date] = $value === null || count($prior) < 252 ? null
                    : count(array_filter($prior, static fn ($x): bool => $x <= $value)) / 252;
                if ($value !== null) { $history[] = $value; }
            }
        }
        return $features;
    }

    public static function definitions(): array
    {
        $cases = [];
        foreach (['maximum', 'maximum_stop12'] as $anchor) {
            $cases[$anchor] = ['anchor' => $anchor, 'family' => 'control'];
            $add = static function (string $family, string $feature, string $operator, float $threshold, array $extra = []) use (&$cases, $anchor): void {
                foreach ([0.0, 0.5, 0.75] as $cap) {
                    $d = compact('anchor', 'family', 'feature', 'operator', 'threshold', 'cap') + $extra;
                    $id = $anchor . '__' . $family . '__' . substr(SelectedMaximumResearch::hash($d), 0, 12);
                    if (isset($cases[$id])) { throw new \RuntimeException('Duplicate cross-asset recipe.'); }
                    $cases[$id] = $d;
                }
            };
            foreach (array_merge(array_keys(self::RATIOS), ['dollar']) as $name) {
                foreach ([20, 60] as $window) { $add($name, $name . '_sma' . $window, in_array($name, ['dollar', 'volatility_etf_curve'], true) ? 'gt' : 'lt', 0); }
            }
            foreach ([0.9, 1.0, 1.1] as $threshold) { $add('short_vol_term', 'short_vol_term', 'gt', $threshold); }
            foreach ([0.85, 1.0, 1.25] as $threshold) {
                foreach (['gt', 'lt'] as $operator) { $add('implied_realized', 'implied_realized', $operator, $threshold); }
            }
            foreach ([20, 60] as $window) {
                foreach ([0.0, 0.3, 0.6] as $threshold) { $add('stock_bond_correlation', 'stock_bond_corr' . $window, 'gt', $threshold); }
            }
            foreach (['OVX', 'GVZ', 'atr_percentile'] as $name) {
                foreach ([0.75, 0.9] as $threshold) { $add($name, $name, 'gt', $threshold); }
            }
            foreach ([20, 50] as $window) {
                foreach ([0.4, 0.6, 0.8] as $threshold) { $add('sector_participation', 'five_sector_breadth' . $window, 'lt', $threshold); }
            }
            foreach ([2.0, 3.0, 4.0] as $threshold) { $add('cross_asset_votes', 'risk_votes', 'ge', $threshold); }
        }
        return $cases;
    }

    public static function changes(array $baseline, array $d, array $features): array
    {
        if ($d['anchor'] === 'maximum_stop12') { $baseline = SelectedMaximumResearch::merge($baseline, ['standing_stop_pct' => 0.12, 'standing_stop_trailing' => true, 'position_exit_cooldown_sessions' => 1]); }
        if ($d['family'] === 'control') { return $baseline; }
        foreach ($baseline['external_daily_scale'] as $date => $scale) {
            if (!array_key_exists($date, $features[$d['feature']])) { throw new \RuntimeException('Missing causal feature date.'); }
            $value = $features[$d['feature']][$date];
            $risky = $value === null || match ($d['operator']) {
                'gt' => $value > $d['threshold'], 'lt' => $value < $d['threshold'], 'ge' => $value >= $d['threshold'],
                default => throw new \RuntimeException('Unknown feature operator.'),
            };
            if ($risky) { $baseline['external_daily_scale'][$date] = min($scale, $d['cap']); }
        }
        return $baseline;
    }

    private static function stdev(array $values): float
    {
        $mean = array_sum($values) / count($values);
        return sqrt(array_sum(array_map(static fn ($x): float => ($x - $mean) ** 2, $values)) / max(1, count($values) - 1));
    }

    private static function correlation(array $a, array $b): float
    {
        $ma = array_sum($a) / count($a); $mb = array_sum($b) / count($b);
        $xy = $xx = $yy = 0.0;
        foreach ($a as $i => $x) { $dx = $x - $ma; $dy = $b[$i] - $mb; $xy += $dx * $dy; $xx += $dx ** 2; $yy += $dy ** 2; }
        return $xx * $yy > 0 ? max(-1.0, min(1.0, $xy / sqrt($xx * $yy))) : 0.0;
    }
}
