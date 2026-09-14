#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Backtest\TacticalRotationQualification;
use FulltimeTrading\Research\AdaptiveResearchFactory as F;
use FulltimeTrading\Research\AlgorithmTrendResearch as A;
use FulltimeTrading\Research\BreadthVolatilityResearch as B;
use FulltimeTrading\Research\DailyDataAudit as D;
use FulltimeTrading\Research\HybridV4Research as H;
use FulltimeTrading\Research\OpportunityPolicy as O;
use FulltimeTrading\Research\OpportunityRotationEnsembleBacktester as E;
use FulltimeTrading\Research\PortfolioCircuitController as C;
use FulltimeTrading\Research\ResearchTradeLedger as L;
use FulltimeTrading\Research\SelectedMaximumResearch as S;
use FulltimeTrading\Trading\TacticalImplementationIdentity;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__); $out = $root . '/var/reports/opportunity_calendar_20260910';
$read = static fn ($p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
$mode = $argv[1] ?? 'prepare';
$old = $read($root . '/var/reports/alpaca_stops_from_2023_20260909/protocol.json');
$base = $read($root . '/var/reports/independent_data_20260908/protocol.json');
$profile = require $root . '/config/tactical_rotation.php';
if (TacticalImplementationIdentity::current($root, $profile) !== $old['operational_identity']) { throw new RuntimeException('Operational identity drift.'); }
$files = ['bars' => $root . '/var/reports/alpaca_stops_data_20260909/sip_all.json',
    's5tw' => $root . '/var/reports/breadth_volatility_data_20260909/s5tw.json', 'vvix' => $root . '/var/reports/vvix_data_20260909/cboe_vvix.json',
    'spinoff' => $root . '/var/reports/data_attribution_20260908/split_spinoff.json'];
foreach (['bars', 's5tw', 'vvix'] as $key) { if (!hash_equals($old['input_sha256'][$key], hash_file('sha256', $files[$key]))) { throw new RuntimeException('Original input changed.'); } }
$sha = array_map(static fn ($f) => hash_file('sha256', $f), $files);
$all = D::decode($read($files['bars'])); $vvix = $read($files['vvix']);
$calendar = array_keys(D::indexed(['SPY' => $all['SPY']])['SPY']);
$breadth = B::validateBreadth($read($files['s5tw']), $calendar); $maps = S::maps($all, $breadth, $vvix);
$anchor = $old['cases'][S::MAXIMUM];
$compact = static fn ($curve): array => array_map(static fn ($r): array => array_intersect_key($r,
    array_flip(['date', 'period_start_date', 'start_equity', 'equity', 'equity_low', 'equity_high', 'turnover'])), $curve);
$selectBars = static function ($bars, $universe, $start, $end): array {
    $r = array_intersect_key($bars, array_fill_keys(array_merge($universe, ['SPY', 'QQQ']), true));
    foreach ($r as $s => $series) { $r[$s] = array_values(array_filter($series, static fn ($b): bool => D::session($b) >= $start && D::session($b) <= $end)); }
    return $r;
};
if ($mode === 'prepare') {
    if (file_exists($out)) { throw new RuntimeException('Experiment already frozen.'); }
    $cases = array_filter(O::definitions(), static fn ($d) => in_array($d['family'], ['control', 'cost_band'], true)); foreach ($cases as $d) { O::validate($d['policy']); }
    $features = $read($root . '/var/reports/opportunity_20260910/features.json');
    $p = ['frozen_at' => gmdate(DATE_ATOM), 'cases' => $cases, 'cost_bps' => 30, 'initial_equity' => 30000,
        'starts' => ['2021-01-04', '2023-01-03'], 'end' => '2026-09-04', 'phases' => [0], 'phase_count' => 3, 'adaptive_followup' => true,
        'selection' => 'Explicit post-hoc structural follow-up prompted by primary turnover findings: entire eight-variant cost-band family plus two controls, equal initial capital across all three phases. Audit every configuration, no subset selected.',
        'conditional_model' => ['training' => 'Only original-universe positive fixed-momentum candidates above SMA50 with 253-session history. Pooled 8 observable bins; outcomes mature before inference.',
            'label' => 'next open to open h sessions later; clip raw return at +/-50%, subtract 60bps roundtrip. Non-overlapping origin calendar per horizon.',
            'fit' => 'Rolling 252/504 sessions; bin mean shrunk by 20 pseudo-observations to pooled mean; minimum pooled 200/bin 30. Low prediction halves size, insufficient history leaves anchor unchanged.',
            'limitations' => 'Candidate-return model, not labels of actual strategy round trips. Cross-asset samples are correlated. Fixed 30bps model-label costs remain frozen in cost60 execution stress.'],
        'input_sha256' => $sha, 'operational_identity' => $old['operational_identity'], 'orders_enabled' => false,
        'independent_holdout' => false, 'code_sha256' => [],
        'limits' => ['Already-seen history; not independent future evidence.', 'Retrospective universe and adjusted-bar corporate action limitations remain.',
            'All added factors supplement, not replace, inherited downside policy, S5TW/SVXY and portfolio stops.',
            'Momentum drift in cost-band is a heuristic, not calibrated expected return.', 'Original research engine files remain unchanged; isolated derivatives checked against original curves.'],
        'sources' => ['HKUDS/Vibe-Trading' => 'f9cb061b3f3b983a62780985008044ceb90c2f81',
            'ml4t/skills' => '5213a9a8dbfb3da5caab3104c304f259e62c4f26', 'alpacahq/alpaca-skills' => '39111abee6b60af7c11d40b5fc892dfc4fd791a4']];
    foreach (array_merge(glob($root . '/src/Research/*.php'), [__FILE__, $root . '/tests/opportunity_research.php']) as $f) { $p['code_sha256'][substr($f, strlen($root) + 1)] = hash_file('sha256', $f); }
    mkdir($out, 0775, true); A::write($out . '/features.json', $features); $p['features_sha256'] = hash_file('sha256', $out . '/features.json');
    A::write($out . '/protocol.json', $p); printf("Frozen %d cases / %d primary runs.\n", count($cases), count($cases) * 2); exit;
}
$p = $read($out . '/protocol.json');
if ($p['input_sha256'] !== $sha || $p['features_sha256'] !== hash_file('sha256', $out . '/features.json')) { throw new RuntimeException('Frozen data drift.'); }
foreach ($p['code_sha256'] as $f => $hash) { if ($hash !== hash_file('sha256', $root . '/' . $f)) { throw new RuntimeException('Frozen code drift: ' . $f); } }
if ($mode === 'select') {
    if (file_exists($out . '/selection.json')) { throw new RuntimeException('Selection already frozen.'); }
    $rows = $families = [];
    foreach ($p['cases'] as $id => $d) {
        $scores = [];
        foreach (['continuous', 'fresh2023'] as $s) {
            foreach ([0] as $phase) {
                $r = $read($out . '/' . $s . '_p' . $phase . '_' . $id . '.json'); $rows[$id][$s][$phase] = $r;
                if ($s === 'continuous') { $m = $r['metrics']['train']; $scores[] = $m['cagr'] / max(0.05, abs($m['max_drawdown'])); }
            }
        }
        if ($d['family'] !== 'control') { $families[$d['family']][$id] = min($scores); }
    }
    $selected = [];
    foreach ($families as $family => $scores) { arsort($scores); $selected[$family] = array_key_first($scores); }
    $ids = array_keys($p['cases']); $jobs = [];
    foreach ($ids as $id) {
        foreach (['cost60_2021', 'cost60_2023', 'expanded'] as $scenario) { foreach ([0] as $phase) { $jobs[] = compact('id', 'scenario', 'phase'); } }
        foreach (['earlier', 'spinoff', 'prefix2023'] as $scenario) { $phase = 0; $jobs[] = compact('id', 'scenario', 'phase'); }
    }
    A::write($out . '/primary.json', $rows); A::write($out . '/selection.json', ['selected' => $selected, 'ids' => $ids, 'jobs' => $jobs, 'frozen_at' => gmdate(DATE_ATOM)]);
    printf("Selected %d family leaders, %d audit jobs.\n", count($selected), count($jobs)); exit;
}
if (!in_array($mode, ['worker', 'audit-worker'], true)) { throw new RuntimeException('Unknown mode.'); }
$worker = (int) ($argv[2] ?? 0); $workers = (int) ($argv[3] ?? 4);
if ($workers < 1 || $worker < 0 || $worker >= $workers) { throw new RuntimeException('Invalid partition.'); }
$jobs = [];
if ($mode === 'worker') { foreach (array_keys($p['cases']) as $id) { foreach (['continuous', 'fresh2023'] as $scenario) { foreach ([0] as $phase) { $jobs[] = compact('id', 'scenario', 'phase'); } } } }
else { $jobs = $read($out . '/selection.json')['jobs']; }
$featureSets = ['original' => $read($out . '/features.json')];
$sources = ['original' => $selectBars($all, $base['original_universe'], '2020-01-01', $p['end'])];
$expanded = array_merge($base['original_universe'], $base['additional_universe']);
$availability = D::availableBy($base['original_universe'], $all, '2020-12-31');
foreach ($jobs as $i => $job) {
    if ($i % $workers !== $worker) { continue; }
    ['id' => $id, 'scenario' => $scenario, 'phase' => $phase] = $job; $path = $out . '/' . $scenario . '_p' . $phase . '_' . $id;
    if (file_exists($path . '.json')) {
        if ($read($path . '.json')['protocol_sha256'] !== hash_file('sha256', $out . '/protocol.json') || !file_exists($path . '_curve.json')) { throw new RuntimeException('Invalid resume.'); }
        continue;
    }
    $start = in_array($scenario, ['fresh2023', 'cost60_2023'], true) ? '2023-01-03' : ($scenario === 'earlier' ? '2017-01-03' : '2021-01-04');
    $end = $scenario === 'earlier' ? '2020-12-31' : ($scenario === 'prefix2023' ? '2023-12-29' : $p['end']);
    $source = in_array($scenario, ['expanded', 'earlier', 'spinoff', 'prefix2023'], true) ? $scenario : 'original';
    $universe = $scenario === 'expanded' ? $expanded : ($scenario === 'earlier' ? $availability['included'] : $base['original_universe']);
    if (!isset($sources[$source])) {
        $data = $scenario === 'spinoff' ? D::decode($read($files['spinoff'])) : H::truncateBars($all, $end);
        $sources[$source] = $selectBars($data, $universe, $scenario === 'earlier' ? '2016-01-01' : '2020-01-01', $end);
        $featureSets[$source] = O::features($data, array_filter($vvix, static fn ($d) => $d <= $end, ARRAY_FILTER_USE_KEY), $universe);
        unset($data);
    }
    $changes = $anchor['changes']; $m = $maps;
    if ($scenario === 'prefix2023') {
        $m = S::maps(H::truncateBars($all, $end), array_filter($breadth, static fn ($d) => $d <= $end, ARRAY_FILTER_USE_KEY), array_filter($vvix, static fn ($d) => $d <= $end, ARRAY_FILTER_USE_KEY));
    }
    $changes['external_daily_scale'] = $m['scale'];
    $changes = S::merge($changes, ['phase_count' => 3]);
    if ($p['cases'][$id]['anchor'] === 'maximum_stop12') { $changes = S::merge($changes, ['standing_stop_pct' => 0.12, 'standing_stop_trailing' => true, 'position_exit_cooldown_sessions' => 1]); }
    $cost = str_starts_with($scenario, 'cost60') ? 60 : 30; $runProfile = D::withUniverse($profile, $universe);
    $books = F::make($runProfile, $changes, $cost)->config();
    foreach ($books as &$book) { $book['config']['opportunity_policy'] = $p['cases'][$id]['policy']; $book['config']['opportunity_features'] = $featureSets[$source]; } unset($book);
    $tester = new E($books); $run = $tester->runControlled($sources[$source], $start, $end, new C($anchor['circuit'], $m['confirm'][$anchor['confirmation']]), 30000);
    $metrics = ['full' => $tester->metrics($run), 'train' => $tester->metrics($run, '2021-01-04', '2024-01-01'),
        'validation' => $tester->metrics($run, '2024-01-01', '2026-01-01'), 'holdout' => $tester->metrics($run, '2026-01-01')];
    $ledger = L::fromSleeveCurves($run['sleeve_curves']); $annual = $qa = []; $factor = 1.0;
    for ($year = (int) substr($start, 0, 4); $year <= (int) substr($end, 0, 4); $year++) {
        $a = $tester->metrics($run, "$year-01-01", ($year + 1) . '-01-01'); $qa[$year] = $a;
        $annual[$year] = ['return' => $a['return'], 'closed_trades' => $ledger['portfolio']['annual_closed'][$year] ?? 0]; $factor *= 1 + $a['return'];
    }
    if (abs($factor - 1 - $metrics['full']['return']) > 1e-8 || array_sum(array_column($annual, 'closed_trades')) !== $ledger['portfolio']['closed_total']) { throw new RuntimeException('Annual reconciliation failed.'); }
    $qualified = $start === '2021-01-04' && $end === $p['end'] ? (new TacticalRotationQualification($profile['validation']))->evaluate($metrics['train'], $metrics['validation'], $metrics['holdout'], $metrics['full'], $qa) : null;
    $curve = $compact($run['curve']); $prefix = $oldMatch = null;
    if ($scenario === 'prefix2023') {
        $prefix = $curve == array_values(array_filter($read($out . '/continuous_p0_' . $id . '_curve.json'), static fn ($r) => $r['date'] <= $end));
        if (!$prefix) { throw new RuntimeException('Future-dependent prefix.'); }
    }
    if ($id === 'maximum' && in_array($scenario, ['continuous', 'fresh2023'], true)) {
        $oldMatch = $curve == $read($root . '/var/reports/start_dependence_20260909/maximum_' . $start . '_three_phases_curve.json');
        if (!$oldMatch) { throw new RuntimeException('Original three-phase ensemble does not reproduce.'); }
    }
    A::write($path . '_curve.json', $curve); A::write($path . '.json', ['id' => $id, 'scenario' => $scenario, 'phase' => $phase, 'metrics' => $metrics,
        'annual' => $annual, 'closed_trades' => $ledger['portfolio']['closed_total'], 'qualification' => $qualified, 'prefix_pass' => $prefix, 'old_curve_match' => $oldMatch,
        'protocol_sha256' => hash_file('sha256', $out . '/protocol.json'), 'completed_at' => gmdate(DATE_ATOM)]);
    printf("%d/%d %s phase%d %s CAGR %.2f DD %.2f\n", $i + 1, count($jobs), $scenario, $phase, $id, $metrics['full']['cagr'] * 100, $metrics['full']['max_drawdown'] * 100);
    unset($run, $tester, $ledger, $curve, $books); gc_collect_cycles();
}
A::write($out . '/' . $mode . '_' . $worker . '_done.json', ['completed_at' => gmdate(DATE_ATOM)]);
