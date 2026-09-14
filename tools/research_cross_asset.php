#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Backtest\TacticalRotationQualification;
use FulltimeTrading\Research\AdaptiveResearchFactory as F;
use FulltimeTrading\Research\AlgorithmTrendResearch as A;
use FulltimeTrading\Research\BreadthVolatilityResearch as B;
use FulltimeTrading\Research\CrossAssetResearch as X;
use FulltimeTrading\Research\DailyDataAudit as D;
use FulltimeTrading\Research\HybridV4Research as H;
use FulltimeTrading\Research\PortfolioCircuitController as C;
use FulltimeTrading\Research\ResearchTradeLedger as L;
use FulltimeTrading\Research\SelectedMaximumResearch as S;
use FulltimeTrading\Trading\TacticalImplementationIdentity;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__);
$out = $root . '/var/reports/cross_asset_research_20260909';
$dataDir = $root . '/var/reports/cross_asset_data_20260909';
$read = static fn ($p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
$mode = $argv[1] ?? 'prepare';
$old = $read($root . '/var/reports/alpaca_stops_from_2023_20260909/protocol.json');
$base = $read($root . '/var/reports/independent_data_20260908/protocol.json');
$profile = require $root . '/config/tactical_rotation.php';
if (TacticalImplementationIdentity::current($root, $profile) !== $old['operational_identity']) { throw new RuntimeException('Operational identity drift.'); }
$files = ['bars' => $root . '/var/reports/alpaca_stops_data_20260909/sip_all.json',
    's5tw' => $root . '/var/reports/breadth_volatility_data_20260909/s5tw.json', 'vvix' => $root . '/var/reports/vvix_data_20260909/cboe_vvix.json',
    'proxies' => $dataDir . '/alpaca_sip_all.json', 'spinoff' => $root . '/var/reports/data_attribution_20260908/split_spinoff.json'];
foreach (['bars', 's5tw', 'vvix'] as $key) { if (!hash_equals($old['input_sha256'][$key], hash_file('sha256', $files[$key]))) { throw new RuntimeException('Original input changed.'); } }
$manifest = $read($dataDir . '/manifest.json');
if (!isset($manifest['completed_at']) || !hash_equals($manifest['alpaca_sha256'], hash_file('sha256', $files['proxies']))) { throw new RuntimeException('Unverified proxy snapshot.'); }
$cboe = [];
foreach ($manifest['cboe'] as $name => $meta) {
    $files[$name] = $dataDir . '/' . $name . '.json';
    if (!hash_equals($meta['sha256'], hash_file('sha256', $files[$name]))) { throw new RuntimeException('Cboe snapshot drift.'); }
    $cboe[$name] = $read($files[$name]);
}
$all = D::decode($read($files['bars']));
$proxies = D::decode($read($files['proxies']));
$calendar = array_keys(D::indexed(['SPY' => $all['SPY']])['SPY']);
$allowedMissing = [];
foreach (['OVX', 'GVZ'] as $name) {
    $allowedMissing[$name] = array_values(array_diff($calendar, array_keys($cboe[$name])));
    foreach ($allowedMissing[$name] as $date) { if ($date >= '2021-01-04') { throw new RuntimeException('Incomplete primary Cboe history: ' . $name . '/' . $date); } }
}
$breadth = B::validateBreadth($read($files['s5tw']), $calendar); $vvix = $read($files['vvix']);
$maps = S::maps($all, $breadth, $vvix);
$anchor = $old['cases'][S::MAXIMUM];
$sha = array_map(static fn ($p) => hash_file('sha256', $p), $files);
$compact = static fn ($curve): array => array_map(static fn ($r): array => array_intersect_key($r,
    array_flip(['date', 'period_start_date', 'start_equity', 'equity', 'equity_low', 'equity_high', 'turnover'])), $curve);
$selectBars = static function ($bars, $universe, $start, $end): array {
    $r = array_intersect_key($bars, array_fill_keys(array_merge($universe, ['SPY', 'QQQ']), true));
    foreach ($r as $s => $series) { $r[$s] = array_values(array_filter($series, static fn ($b): bool => D::session($b) >= $start && D::session($b) <= $end)); }
    return $r;
};
if ($mode === 'prepare') {
    if (file_exists($out)) { throw new RuntimeException('Experiment already frozen.'); }
    $features = X::features($all + $proxies, $cboe, $breadth, $vvix, $allowedMissing);
    $cases = X::definitions();
    foreach ($cases as $d) { F::make($profile, X::changes($anchor['changes'], $d, $features), 30); }
    $p = ['frozen_at' => gmdate(DATE_ATOM), 'cases' => $cases, 'source' => 'Alpaca SIP all equities and 19 ETF proxies; Cboe indices',
        'windows' => ['continuous' => ['2021-01-04', '2026-09-04'], 'fresh2023' => ['2023-01-03', '2026-09-04']],
        'selection' => 'One per new economic family by 2021-2023 train Calmar from the continuous run. Also audit both controls and top three full-period 2023 leaders as labeled hindsight.',
        'independent_claim' => false, 'cost_bps' => 30, 'initial_equity' => 30000, 'orders_enabled' => false, 'allowed_missing_pre2021_cboe' => $allowedMissing,
        'limits' => ['Previously observed history; no untouched future evidence.', 'ETF ratios are proxies, not observed credit spreads or a pure futures curve.',
            'Five-sector breadth is not full-market breadth.', 'Extra risk caps apply at regular strategy resizes, not instantaneous portfolio liquidation.',
            'No macro vintage substitution, future filling, production feed or execution changes.',
            'Four declared pre-2021 dates lack OVX/GVZ; retained as null, with the declared risk cap. Earlier stress is outage-aware; percentiles use prior observed values, no imputation. Primary dates have complete coverage.'],
        'input_sha256' => $sha, 'operational_identity' => $old['operational_identity'], 'code_sha256' => []];
    foreach (array_merge(glob($root . '/src/Research/*.php'), [__FILE__]) as $file) { $p['code_sha256'][substr($file, strlen($root) + 1)] = hash_file('sha256', $file); }
    mkdir($out, 0775, true); A::write($out . '/features.json', $features);
    $p['features_sha256'] = hash_file('sha256', $out . '/features.json');
    A::write($out . '/protocol.json', $p);
    printf("Frozen %d configurations, %d economic feature families, two starting dates each.\n", count($cases), count(array_unique(array_column($cases, 'family'))) - 1);
    exit;
}
$p = $read($out . '/protocol.json');
if ($p['input_sha256'] !== $sha || !hash_equals($p['features_sha256'], hash_file('sha256', $out . '/features.json'))) { throw new RuntimeException('Frozen input/feature mismatch.'); }
foreach ($p['code_sha256'] as $file => $hash) { if (!hash_equals($hash, hash_file('sha256', $root . '/' . $file))) { throw new RuntimeException('Frozen code mismatch: ' . $file); } }
$features = $read($out . '/features.json');
if ($mode === 'select') {
    if (file_exists($out . '/selection.json')) { throw new RuntimeException('Selection already frozen.'); }
    $family = $hindsight = $rows = [];
    foreach ($p['cases'] as $id => $d) {
        foreach (['continuous', 'fresh2023'] as $s) { $rows[$id][$s] = $read($out . '/' . $s . '_' . $id . '.json'); }
        if ($d['family'] !== 'control') {
            $m = $rows[$id]['continuous']['metrics']['train'];
            $family[$d['family']][$id] = $m['cagr'] / max(0.05, abs($m['max_drawdown']));
            $hindsight[$id] = $rows[$id]['fresh2023']['metrics']['full']['cagr'];
        }
    }
    $selected = [];
    foreach ($family as $name => $scores) { arsort($scores); $selected[$name] = array_key_first($scores); }
    arsort($hindsight); $leaders = array_slice(array_keys($hindsight), 0, 3);
    $ids = array_values(array_unique(array_merge(['maximum', 'maximum_stop12'], array_values($selected), $leaders)));
    $jobs = [];
    foreach ($ids as $id) {
        foreach (['cost60_2021', 'cost60_2023', 'expanded', 'earlier', 'spinoff', 'lag1', 'prefix2023'] as $scenario) { $jobs[] = compact('id', 'scenario'); }
    }
    A::write($out . '/primary.json', $rows);
    A::write($out . '/selection.json', ['selected' => $selected, 'hindsight' => $leaders, 'audit_ids' => $ids, 'jobs' => $jobs, 'frozen_at' => gmdate(DATE_ATOM)]);
    printf("Selected %d families; %d audit jobs.\n", count($selected), count($jobs)); exit;
}
if (!in_array($mode, ['worker', 'audit-worker'], true)) { throw new RuntimeException('Unknown mode.'); }
$worker = (int) ($argv[2] ?? 0); $workers = (int) ($argv[3] ?? 4);
if ($workers < 1 || $worker < 0 || $worker >= $workers) { throw new RuntimeException('Invalid worker partition.'); }
$jobs = [];
if ($mode === 'worker') { foreach (array_keys($p['cases']) as $id) { foreach (['continuous', 'fresh2023'] as $scenario) { $jobs[] = compact('id', 'scenario'); } } }
else { $jobs = $read($out . '/selection.json')['jobs']; }
$sources = ['original' => $selectBars($all, $base['original_universe'], '2020-01-01', '2026-09-04')];
$expanded = array_merge($base['original_universe'], $base['additional_universe']);
$availability = D::availableBy($base['original_universe'], $all, '2020-12-31');
if ($mode === 'audit-worker') {
    $sources['expanded'] = $selectBars($all, $expanded, '2020-01-01', '2026-09-04');
    $sources['earlier'] = $selectBars($all, $availability['included'], '2016-01-01', '2020-12-31');
    $sources['spinoff'] = D::decode($read($files['spinoff']));
}
foreach ($jobs as $i => $job) {
    if ($i % $workers !== $worker) { continue; }
    ['id' => $id, 'scenario' => $scenario] = $job; $path = $out . '/' . $scenario . '_' . $id;
    if (file_exists($path . '.json')) {
        if ($read($path . '.json')['protocol_sha256'] !== hash_file('sha256', $out . '/protocol.json') || !file_exists($path . '_curve.json')) { throw new RuntimeException('Invalid resumed result.'); }
        continue;
    }
    $start = in_array($scenario, ['fresh2023', 'cost60_2023'], true) ? '2023-01-03' : ($scenario === 'earlier' ? '2017-01-03' : '2021-01-04');
    $end = $scenario === 'earlier' ? '2020-12-31' : ($scenario === 'prefix2023' ? '2023-12-29' : '2026-09-04');
    $source = in_array($scenario, ['expanded', 'earlier', 'spinoff'], true) ? $scenario : 'original';
    $input = $sources[$source]; $f = $features; $m = $maps;
    if ($scenario === 'prefix2023') {
        $data = H::truncateBars($all + $proxies, $end);
        $b = array_filter($breadth, static fn ($d): bool => $d <= $end, ARRAY_FILTER_USE_KEY);
        $v = array_filter($vvix, static fn ($d): bool => $d <= $end, ARRAY_FILTER_USE_KEY);
        $c = array_map(static fn ($s): array => array_filter($s, static fn ($d): bool => $d <= $end, ARRAY_FILTER_USE_KEY), $cboe);
        $f = X::features($data, $c, $b, $v, $allowedMissing); $m = S::maps($data, $b, $v); $input = H::truncateBars($input, $end);
    }
    $baseline = $anchor['changes']; $baseline['external_daily_scale'] = $m['scale'];
    $changes = X::changes($baseline, $p['cases'][$id], $f);
    $confirmation = $m['confirm'][$anchor['confirmation']];
    if ($scenario === 'lag1') { $changes['external_daily_scale'] = S::lag($changes['external_daily_scale'], 0.0); $confirmation = S::lag($confirmation, false); }
    $cost = str_starts_with($scenario, 'cost60') ? 60 : 30;
    $runProfile = $scenario === 'expanded' ? D::withUniverse($profile, $expanded) : ($scenario === 'earlier' ? D::withUniverse($profile, $availability['included']) : $profile);
    $tester = F::make($runProfile, $changes, $cost); $controller = new C($anchor['circuit'], $confirmation);
    $run = $tester->runControlled($input, $start, $end, $controller, 30000.0);
    $metrics = ['full' => $tester->metrics($run), 'train' => $tester->metrics($run, '2021-01-04', '2024-01-01'),
        'validation' => $tester->metrics($run, '2024-01-01', '2026-01-01'), 'holdout' => $tester->metrics($run, '2026-01-01')];
    $ledger = L::fromSleeveCurves($run['sleeve_curves']); $annual = $qa = []; $factor = 1.0;
    for ($year = (int) substr($start, 0, 4); $year <= (int) substr($end, 0, 4); $year++) {
        $a = $tester->metrics($run, "$year-01-01", ($year + 1) . '-01-01'); $qa[$year] = $a;
        $annual[$year] = ['return' => $a['return'], 'closed_trades' => $ledger['portfolio']['annual_closed'][$year] ?? 0]; $factor *= 1 + $a['return'];
    }
    if (abs($factor - 1 - $metrics['full']['return']) > 1e-8) { throw new RuntimeException('Annual compounding failed.'); }
    $qualified = $start === '2021-01-04' && $end === '2026-09-04' ? (new TacticalRotationQualification($profile['validation']))->evaluate($metrics['train'], $metrics['validation'], $metrics['holdout'], $metrics['full'], $qa) : null;
    $curve = $compact($run['curve']); $prefix = null;
    if ($scenario === 'prefix2023') {
        $prefix = $curve == array_values(array_filter($read($out . '/continuous_' . $id . '_curve.json'), static fn ($r): bool => $r['date'] <= $end));
        if (!$prefix) { throw new RuntimeException('Future-dependent historical prefix.'); }
    }
    $oldMatch = null;
    if ($p['cases'][$id]['family'] === 'control' && in_array($scenario, ['continuous', 'fresh2023'], true)) {
        $oldPath = $root . '/var/reports/selected_maximum_20260909/' . ($scenario === 'continuous' ? 'full2021' : 'primary') . '_' . $id . '_curve.json';
        $oldMatch = $curve == $read($oldPath);
        if (!$oldMatch) { throw new RuntimeException('Previous anchor does not reproduce.'); }
    }
    A::write($path . '_curve.json', $curve);
    A::write($path . '.json', ['id' => $id, 'scenario' => $scenario, 'metrics' => $metrics, 'annual' => $annual,
        'closed_trades' => $ledger['portfolio']['closed_total'], 'qualification' => $qualified, 'prefix_pass' => $prefix, 'old_curve_match' => $oldMatch,
        'protocol_sha256' => hash_file('sha256', $out . '/protocol.json'), 'completed_at' => gmdate(DATE_ATOM)]);
    printf("%d/%d %s %s CAGR %.2f DD %.2f\n", $i + 1, count($jobs), $scenario, $id, $metrics['full']['cagr'] * 100, $metrics['full']['max_drawdown'] * 100);
    unset($run, $tester, $ledger, $curve); gc_collect_cycles();
}
A::write($out . '/' . $mode . '_' . $worker . '_done.json', ['completed_at' => gmdate(DATE_ATOM)]);
