#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Backtest\TacticalRotationQualification;
use FulltimeTrading\Research\AdaptiveResearchFactory as F;
use FulltimeTrading\Research\AlgorithmTrendResearch as A;
use FulltimeTrading\Research\BreadthVolatilityResearch as B;
use FulltimeTrading\Research\DailyDataAudit as D;
use FulltimeTrading\Research\HybridV4Research as H;
use FulltimeTrading\Research\PortfolioCircuitController as C;
use FulltimeTrading\Research\ResearchMultiplicityAudit;
use FulltimeTrading\Research\ResearchTradeLedger;
use FulltimeTrading\Research\SelectedMaximumResearch as S;
use FulltimeTrading\Trading\TacticalImplementationIdentity;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__);
$out = $root . '/var/reports/selected_maximum_20260909';
$read = static fn (string $p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
$mode = $argv[1] ?? 'prepare';
$oldDir = $root . '/var/reports/alpaca_stops_from_2023_20260909';
$old = $read($oldDir . '/protocol.json');
$base = $read($root . '/var/reports/independent_data_20260908/protocol.json');
$profile = require $root . '/config/tactical_rotation.php';
if (TacticalImplementationIdentity::current($root, $profile) !== $old['operational_identity']) { throw new RuntimeException('Operational identity drift.'); }
$files = ['bars' => $root . '/var/reports/alpaca_stops_data_20260909/sip_all.json',
    's5tw' => $root . '/var/reports/breadth_volatility_data_20260909/s5tw.json',
    'vvix' => $root . '/var/reports/vvix_data_20260909/cboe_vvix.json',
    'spinoff' => $root . '/var/reports/data_attribution_20260908/split_spinoff.json'];
foreach (['bars', 's5tw', 'vvix'] as $key) {
    if (!hash_equals($old['input_sha256'][$key], hash_file('sha256', $files[$key]))) { throw new RuntimeException('Frozen data mismatch.'); }
}
$anchors = ['control_original' => $old['cases']['control_original'], 'control_balanced' => $old['cases']['control_balanced'],
    'maximum' => $old['cases'][S::MAXIMUM], 'old_stop12' => $old['cases'][S::OLD_STOP12]];
$all = D::decode($read($files['bars']));
$calendar = array_keys(D::indexed(['SPY' => $all['SPY']])['SPY']);
$breadth = B::validateBreadth($read($files['s5tw']), $calendar);
$vvix = $read($files['vvix']);
$maps = S::maps($all, $breadth, $vvix);
if ($maps['scale'] != $anchors['maximum']['changes']['external_daily_scale']) { throw new RuntimeException('Selected baseline scale did not reproduce.'); }
$selectBars = static function (array $bars, array $universe, string $warmup, string $end): array {
    $input = array_intersect_key($bars, array_fill_keys(array_merge($universe, ['SPY', 'QQQ']), true));
    foreach ($input as $symbol => $series) { $input[$symbol] = array_values(array_filter($series, static fn ($b): bool => D::session($b) >= $warmup && D::session($b) <= $end)); }
    return $input;
};
$compact = static fn ($curve): array => array_map(static fn ($r): array => array_intersect_key($r,
    array_flip(['date', 'period_start_date', 'start_equity', 'equity', 'equity_low', 'equity_high', 'turnover'])), $curve);

if ($mode === 'prepare') {
    if (file_exists($out)) { throw new RuntimeException('Refusing to overwrite frozen experiment.'); }
    $sources = ['refinement' => $root . '/var/reports/breadth_volatility_refinement_20260909/protocol.json',
        'combinations' => $root . '/var/reports/algorithm_combinations_20260908/protocol.json',
        'reentry' => $root . '/var/reports/hybrid_reentry_combinations_20260907/protocol.json'];
    $catalogue = S::catalogue($read($sources['refinement'])['definitions'], $read($sources['combinations'])['cases'], $read($sources['reentry'])['cases']);
    $cases = $duplicates = $rejected = $seen = [];
    foreach ($catalogue as $id => $recipe) {
        $d = S::materialize($recipe, $anchors, $all, $breadth, $vvix, $maps);
        if (($d['changes']['max_gross'] ?? 0) > 1.25) { $rejected[$id] = 'Existing research max_gross <=1.25, not relaxed.'; continue; }
        $tester = F::make($profile, $d['changes'], 30);
        $controller = new C($d['circuit'], $maps['confirm'][$d['confirmation']]);
        $hash = S::hash([$tester->config(), $controller->report()['config'], $d['confirmation']]);
        if (isset($seen[$hash])) { $duplicates[$id] = $seen[$hash]; continue; }
        $seen[$hash] = $id;
        $cases[$id] = $recipe + ['effective_config_sha256' => $hash];
    }
    $protocol = ['frozen_at' => gmdate(DATE_ATOM), 'selected_research_baseline' => S::MAXIMUM,
        'primary_dates' => ['2023-01-03', '2026-09-04'], 'initial_equity' => 30000, 'cost_bps' => 30,
        'source' => 'Alpaca SIP adjustment=all; S5TW historical OHLC; Cboe VVIX',
        'selection' => 'One per declared family by 2023-2024 CAGR/abs(drawdown), then inspect 2025-2026. Also audit controls and three full-period return leaders explicitly labeled hindsight.',
        'prior_observation_warning' => 'All historical periods already observed in prior searches. Chronological split is not untouched out-of-sample evidence.',
        'rebase_semantics' => 'Two anchors: selected maximum, and same maximum plus trailing close-ratcheted standing stop12/cooldown1. Local stops inherit portfolio controller; global controller recipes explicitly replace it. External components replaced or capped, not double multiplied; inverse VVIX multiplier is explicit.',
        'requested_recipes' => count($catalogue), 'cases' => $cases, 'duplicates' => $duplicates, 'rejected' => $rejected,
        'source_protocol_sha256' => array_map(static fn ($p) => hash_file('sha256', $p), $sources),
        'input_sha256' => array_map(static fn ($p) => hash_file('sha256', $p), $files),
        'operational_identity' => $old['operational_identity'], 'implementation_sha256' => [],
        'orders_enabled' => false, 'operational_deployment_changed' => false];
    foreach (array_merge(glob($root . '/src/Research/*.php'), [__FILE__]) as $file) { $protocol['implementation_sha256'][substr($file, strlen($root) + 1)] = hash_file('sha256', $file); }
    mkdir($out, 0775, true);
    A::write($out . '/protocol.json', $protocol);
    printf("Frozen %d recipes, %d unique configs, %d duplicates, %d rejected.\n", count($catalogue), count($cases), count($duplicates), count($rejected));
    exit;
}
$p = $read($out . '/protocol.json');
foreach ($p['implementation_sha256'] as $file => $sha) { if (!hash_equals($sha, hash_file('sha256', $root . '/' . $file))) { throw new RuntimeException('Frozen code changed: ' . $file); } }
foreach ($p['input_sha256'] as $key => $sha) { if (!hash_equals($sha, hash_file('sha256', $files[$key]))) { throw new RuntimeException('Input drift.'); } }

if ($mode === 'select') {
    if (file_exists($out . '/selection.json')) { throw new RuntimeException('Selection already frozen.'); }
    $rows = $families = $fullScores = [];
    foreach ($p['cases'] as $id => $recipe) {
        $r = $read($out . '/primary_' . $id . '.json');
        $rows[$id] = $r;
        if ($recipe['family'] !== 'control') {
            $m = $r['metrics']['selection_train'];
            $families[$recipe['family']][$id] = $m['cagr'] / max(0.05, abs($m['max_drawdown']));
            $fullScores[$id] = $r['metrics']['full']['return'];
        }
    }
    $selected = [];
    foreach ($families as $family => $scores) { arsort($scores); $selected[$family] = array_key_first($scores); }
    arsort($fullScores);
    $hindsight = array_slice(array_keys($fullScores), 0, 3);
    $controls = array_keys(array_filter($p['cases'], static fn ($d): bool => $d['family'] === 'control'));
    $audit = array_values(array_unique(array_merge($controls, array_values($selected), $hindsight)));
    $jobs = [];
    foreach ($audit as $id) {
        foreach (['cost60', 'full2021', 'spinoff30', 'spinoff60', 'expanded', 'earlier', 'lag1', 'prefix2024'] as $scenario) { $jobs[] = compact('id', 'scenario'); }
        $d = S::materialize($p['cases'][$id], $anchors, $all, $breadth, $vvix, $maps);
        if (($d['changes']['standing_stop_pct'] ?? 0) > 0) { $jobs[] = ['id' => $id, 'scenario' => 'adverse_stop']; }
    }
    A::write($out . '/primary_summary.json', $rows);
    A::write($out . '/selection.json', ['frozen_at' => gmdate(DATE_ATOM), 'train_selected' => $selected, 'hindsight_only' => $hindsight, 'controls' => $controls, 'audit_ids' => $audit, 'jobs' => $jobs]);
    printf("Primary complete: %d unique configs, %d family leaders, %d audit jobs.\n", count($rows), count($selected), count($jobs));
    exit;
}

if (!in_array($mode, ['worker', 'audit-worker'], true)) { throw new RuntimeException('Unknown mode.'); }
$worker = (int) ($argv[2] ?? 0); $workers = (int) ($argv[3] ?? 4);
if ($workers < 1 || $worker < 0 || $worker >= $workers) { throw new RuntimeException('Invalid worker partition.'); }
$jobs = $mode === 'worker' ? array_map(static fn ($id): array => ['id' => $id, 'scenario' => 'primary'], array_keys($p['cases'])) : $read($out . '/selection.json')['jobs'];
$availability = D::availableBy($base['original_universe'], $all, '2020-12-31');
$universe = array_merge($base['original_universe'], $base['additional_universe']);
$sourceBars = ['primary' => $selectBars($all, $base['original_universe'], '2020-01-01', '2026-09-04')];
if ($mode === 'audit-worker') {
    $sourceBars['spinoff'] = D::decode($read($files['spinoff']));
    $sourceBars['expanded'] = $selectBars($all, $universe, '2020-01-01', '2026-09-04');
    $sourceBars['earlier'] = $selectBars($all, $availability['included'], '2016-01-01', '2020-12-31');
}
$count = 0;
foreach ($jobs as $index => $job) {
    if ($index % $workers !== $worker) { continue; }
    ['id' => $id, 'scenario' => $scenario] = $job;
    $path = $out . '/' . $scenario . '_' . $id;
    if (file_exists($path . '.json')) {
        $cached = $read($path . '.json');
        if (($cached['protocol_sha256'] ?? '') !== hash_file('sha256', $out . '/protocol.json') || !file_exists($path . '_curve.json')) { throw new RuntimeException('Invalid cached replay.'); }
        continue;
    }
    $data = $all; $b = $breadth; $v = $vvix; $m = $maps;
    $start = $scenario === 'full2021' ? '2021-01-04' : ($scenario === 'earlier' ? '2017-01-03' : '2023-01-03');
    $end = $scenario === 'prefix2024' ? '2024-12-31' : ($scenario === 'earlier' ? '2020-12-31' : '2026-09-04');
    if ($scenario === 'prefix2024') {
        $data = H::truncateBars($all, $end);
        $b = array_filter($breadth, static fn ($d): bool => $d <= $end, ARRAY_FILTER_USE_KEY);
        $v = array_filter($vvix, static fn ($d): bool => $d <= $end, ARRAY_FILTER_USE_KEY);
        $m = S::maps($data, $b, $v);
    }
    $d = S::materialize($p['cases'][$id], $anchors, $data, $b, $v, $m);
    $confirm = $m['confirm'][$d['confirmation']];
    if ($scenario === 'lag1') {
        if (isset($d['changes']['external_daily_scale'])) { $d['changes']['external_daily_scale'] = S::lag($d['changes']['external_daily_scale'], 0.0); }
        $confirm = S::lag($confirm, false);
    }
    if ($scenario === 'adverse_stop') { $d['changes']['standing_stop_fill'] = 'daily_low'; }
    $cost = in_array($scenario, ['cost60', 'spinoff60'], true) ? 60 : 30;
    $runProfile = $scenario === 'expanded' ? D::withUniverse($profile, $universe)
        : ($scenario === 'earlier' ? D::withUniverse($profile, $availability['included']) : $profile);
    $input = $sourceBars[match ($scenario) { 'expanded', 'earlier' => $scenario, 'spinoff30', 'spinoff60' => 'spinoff', default => 'primary' }];
    if ($scenario === 'prefix2024') { $input = H::truncateBars($input, $end); }
    $tester = F::make($runProfile, $d['changes'], $cost);
    $controller = new C($d['circuit'], $confirm);
    $run = $tester->runControlled($input, $start, $end, $controller, 30000.0);
    $metrics = ['full' => $tester->metrics($run), 'selection_train' => $tester->metrics($run, '2023-01-03', '2025-01-01'),
        'selection_later' => $tester->metrics($run, '2025-01-01'), 'gate_train' => $tester->metrics($run, '2021-01-04', '2024-01-01'),
        'gate_validation' => $tester->metrics($run, '2024-01-01', '2026-01-01'), 'gate_holdout' => $tester->metrics($run, '2026-01-01')];
    $ledger = ResearchTradeLedger::fromSleeveCurves($run['sleeve_curves']);
    $annual = $gateAnnual = []; $factor = 1.0;
    for ($year = (int) substr($start, 0, 4); $year <= (int) substr($end, 0, 4); $year++) {
        $a = $tester->metrics($run, "$year-01-01", ($year + 1) . '-01-01');
        $gateAnnual[$year] = $a;
        $annual[$year] = ['return' => $a['return'], 'closed_trades' => $ledger['portfolio']['annual_closed'][$year] ?? 0];
        $factor *= 1 + $a['return'];
    }
    if (abs($factor - 1 - $metrics['full']['return']) > 1e-8) { throw new RuntimeException('Annual compounding mismatch.'); }
    $qualification = $scenario === 'full2021' ? (new TacticalRotationQualification($profile['validation']))->evaluate(
        $metrics['gate_train'], $metrics['gate_validation'], $metrics['gate_holdout'], $metrics['full'], $gateAnnual) : null;
    $curve = $compact($run['curve']);
    $prefixPass = null;
    if ($scenario === 'prefix2024') {
        $expected = array_values(array_filter($read($out . '/primary_' . $id . '_curve.json'), static fn ($r): bool => $r['date'] <= $end));
        $prefixPass = $curve == $expected;
        if (!$prefixPass) { throw new RuntimeException('Future input changed prefix: ' . $id); }
    }
    $stops = [];
    foreach ($run['sleeves'] as $name => $sleeve) { foreach ($sleeve['standing_stop_events'] ?? [] as $event) { $stops[] = $event + ['sleeve' => $name]; } }
    $row = ['id' => $id, 'scenario' => $scenario, 'metrics' => $metrics, 'annual' => $annual,
        'closed_trades' => $ledger['portfolio']['closed_total'], 'open_at_end' => $ledger['portfolio']['open_at_end'],
        'stops' => $stops, 'global_events' => $controller->report()['events'], 'qualification' => $qualification,
        'prefix_pass' => $prefixPass, 'completed_at' => gmdate(DATE_ATOM), 'protocol_sha256' => hash_file('sha256', $out . '/protocol.json')];
    if ($scenario === 'primary' && in_array($id, ['control_original', 'control_balanced', 'maximum', 'old_stop12'], true)) {
        $oldId = ['maximum' => S::MAXIMUM, 'old_stop12' => S::OLD_STOP12][$id] ?? $id;
        if ($curve != $read($oldDir . '/' . $oldId . '_curve.json')) { throw new RuntimeException('Old baseline failed exact reproduction: ' . $id); }
        $row['old_curve_exact_match'] = true;
    }
    A::write($path . '_curve.json', $curve);
    A::write($path . '.json', $row);
    $count++;
    printf("%d/%d %s %s CAGR %.2f DD %.2f trades %d\n", $index + 1, count($jobs), $scenario, $id,
        100 * $metrics['full']['cagr'], 100 * $metrics['full']['max_drawdown'], $row['closed_trades']);
}
if (TacticalImplementationIdentity::current($root, $profile) !== $p['operational_identity']) { throw new RuntimeException('Operational identity changed during replay.'); }
A::write($out . '/' . $mode . '_' . $worker . '_done.json', ['completed_at' => gmdate(DATE_ATOM), 'new_replays' => $count, 'operational_identity_unchanged' => true]);
