#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Research\AdaptiveResearchFactory as F;
use FulltimeTrading\Research\AlgorithmTrendResearch as A;
use FulltimeTrading\Research\BreadthVolatilityResearch as B;
use FulltimeTrading\Research\CrossAssetResearch as X;
use FulltimeTrading\Research\DailyDataAudit as D;
use FulltimeTrading\Research\HybridV4Research as H;
use FulltimeTrading\Research\PortfolioCircuitController as C;
use FulltimeTrading\Research\SelectedMaximumResearch as S;
use FulltimeTrading\Trading\TacticalImplementationIdentity;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__); $parent = $root . '/var/reports/cross_asset_research_20260909'; $out = $parent . '/followup';
$read = static fn ($f): array => json_decode(file_get_contents($f), true, 512, JSON_THROW_ON_ERROR);
if (file_exists($out)) { throw new RuntimeException('Follow-up already frozen.'); }
$report = $read($parent . '/results.json'); $p = $report['protocol'];
$ids = array_values(array_unique(array_merge($report['improves_both_primary_starts'], [$report['top2021_hindsight'][0]])));
$files = ['bars' => $root . '/var/reports/alpaca_stops_data_20260909/sip_all.json',
    's5tw' => $root . '/var/reports/breadth_volatility_data_20260909/s5tw.json', 'vvix' => $root . '/var/reports/vvix_data_20260909/cboe_vvix.json',
    'proxies' => $root . '/var/reports/cross_asset_data_20260909/alpaca_sip_all.json',
    'spinoff' => $root . '/var/reports/data_attribution_20260908/split_spinoff.json'];
foreach (['VIX', 'VIX9D', 'OVX', 'GVZ'] as $name) { $files[$name] = $root . '/var/reports/cross_asset_data_20260909/' . $name . '.json'; }
foreach ($files as $key => $file) { if (!hash_equals($p['input_sha256'][$key], hash_file('sha256', $file))) { throw new RuntimeException('Input drift.'); } }
foreach ($p['code_sha256'] as $file => $sha) { if (!hash_equals($sha, hash_file('sha256', $root . '/' . $file))) { throw new RuntimeException('Frozen code drift.'); } }
$profile = require $root . '/config/tactical_rotation.php';
if (TacticalImplementationIdentity::current($root, $profile) !== $p['operational_identity']) { throw new RuntimeException('Operational drift.'); }
$base = $read($root . '/var/reports/independent_data_20260908/protocol.json');
$old = $read($root . '/var/reports/alpaca_stops_from_2023_20260909/protocol.json'); $anchor = $old['cases'][S::MAXIMUM];
$all = D::decode($read($files['bars'])); $proxies = D::decode($read($files['proxies']));
$calendar = array_keys(D::indexed(['SPY' => $all['SPY']])['SPY']);
$breadth = B::validateBreadth($read($files['s5tw']), $calendar); $vvix = $read($files['vvix']);
$cboe = [];
foreach (['VIX', 'VIX9D', 'OVX', 'GVZ'] as $name) { $cboe[$name] = $read($files[$name]); }
$maps = S::maps($all, $breadth, $vvix); $features = $read($parent . '/features.json');
if (!hash_equals($p['features_sha256'], hash_file('sha256', $parent . '/features.json'))) { throw new RuntimeException('Feature drift.'); }
$select = static function ($bars, $universe, $warmup, $end): array {
    $a = array_intersect_key($bars, array_fill_keys(array_merge($universe, ['SPY', 'QQQ']), true));
    foreach ($a as $symbol => $series) { $a[$symbol] = array_values(array_filter($series, static fn ($b): bool => D::session($b) >= $warmup && D::session($b) <= $end)); }
    return $a;
};
$expanded = array_merge($base['original_universe'], $base['additional_universe']);
$available = D::availableBy($base['original_universe'], $all, '2020-12-31')['included'];
$sources = ['original' => $select($all, $base['original_universe'], '2020-01-01', '2026-09-04'),
    'expanded' => $select($all, $expanded, '2020-01-01', '2026-09-04'),
    'earlier' => $select($all, $available, '2016-01-01', '2020-12-31'), 'spinoff' => D::decode($read($files['spinoff']))];
mkdir($out, 0775, true);
A::write($out . '/protocol.json', ['frozen_at' => gmdate(DATE_ATOM), 'cases' => array_intersect_key($p['cases'], array_fill_keys($ids, true)),
    'selection' => 'Explicit post-hoc diagnostic: all candidates improving both primary starts plus the continuous-period leader. No independence claim.',
    'additional_structural_hypothesis' => 'Equal initial capital across all three rebalance phases, no free capital transfers.',
    'parent_protocol_sha256' => hash_file('sha256', $parent . '/protocol.json'), 'script_sha256' => hash_file('sha256', __FILE__), 'orders_enabled' => false]);
$answer = ['cases' => [], 'replays' => 0, 'prefix_checks' => 0, 'orders_submitted' => 0];
$compact = static fn ($curve): array => array_map(static fn ($r): array => array_intersect_key($r, array_flip(['date', 'period_start_date', 'start_equity', 'equity', 'equity_low', 'equity_high', 'turnover'])), $curve);
foreach ($ids as $id) {
    foreach (['cost60_2021', 'cost60_2023', 'expanded', 'earlier', 'spinoff', 'lag1', 'prefix2023', 'three_phases_2021', 'three_phases_2023'] as $scenario) {
        $start = in_array($scenario, ['cost60_2023', 'three_phases_2023'], true) ? '2023-01-03' : ($scenario === 'earlier' ? '2017-01-03' : '2021-01-04');
        $end = $scenario === 'earlier' ? '2020-12-31' : ($scenario === 'prefix2023' ? '2023-12-29' : '2026-09-04');
        $cost = str_starts_with($scenario, 'cost60') ? 60 : 30;
        $source = in_array($scenario, ['expanded', 'earlier', 'spinoff'], true) ? $scenario : 'original';
        $input = $sources[$source]; $f = $features; $m = $maps;
        if ($scenario === 'prefix2023') {
            $data = H::truncateBars($all + $proxies, $end);
            $truncate = static fn ($v): array => array_filter($v, static fn ($d): bool => $d <= $end, ARRAY_FILTER_USE_KEY);
            $f = X::features($data, array_map($truncate, $cboe), $truncate($breadth), $truncate($vvix), $p['allowed_missing_pre2021_cboe']);
            $m = S::maps($data, $truncate($breadth), $truncate($vvix)); $input = H::truncateBars($input, $end);
        }
        $changes = $anchor['changes']; $changes['external_daily_scale'] = $m['scale'];
        $changes = X::changes($changes, $p['cases'][$id], $f); $confirmation = $m['confirm'][$anchor['confirmation']];
        if ($scenario === 'lag1') { $changes['external_daily_scale'] = S::lag($changes['external_daily_scale'], 0.0); $confirmation = S::lag($confirmation, false); }
        if (str_starts_with($scenario, 'three_phases')) { $changes['phase_count'] = 3; }
        $rp = $scenario === 'expanded' ? D::withUniverse($profile, $expanded) : ($scenario === 'earlier' ? D::withUniverse($profile, $available) : $profile);
        $tester = F::make($rp, $changes, $cost);
        $r = $tester->runControlled($input, $start, $end, new C($anchor['circuit'], $confirmation));
        $metrics = $tester->metrics($r); $curve = $compact($r['curve']);
        if ($scenario === 'prefix2023') {
            if ($curve != array_values(array_filter($read($parent . '/continuous_' . $id . '_curve.json'), static fn ($row): bool => $row['date'] <= $end))) { throw new RuntimeException('Future-dependent prefix.'); }
            $answer['prefix_checks']++;
        }
        $answer['cases'][$id][$scenario] = $metrics;
        $answer['replays']++;
        A::write($out . '/' . $scenario . '_' . $id . '_curve.json', $curve);
        printf("%s %s CAGR %.2f DD %.2f\n", $id, $scenario, $metrics['cagr'] * 100, $metrics['max_drawdown'] * 100);
        unset($r, $curve, $tester); gc_collect_cycles();
    }
}
$answer['completed_at'] = gmdate(DATE_ATOM);
$answer['operational_identity_unchanged'] = TacticalImplementationIdentity::current($root, $profile) === $p['operational_identity'];
if (!$answer['operational_identity_unchanged']) { throw new RuntimeException('Operational identity changed.'); }
A::write($out . '/results.json', $answer);
