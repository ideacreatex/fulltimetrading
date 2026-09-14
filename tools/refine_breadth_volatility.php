#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Backtest\TacticalRotationQualification;
use FulltimeTrading\Research\AdaptiveResearchFactory;
use FulltimeTrading\Research\AlgorithmTrendResearch;
use FulltimeTrading\Research\BreadthVolatilityResearch as B;
use FulltimeTrading\Research\DailyDataAudit as D;
use FulltimeTrading\Research\HybridV4Research;
use FulltimeTrading\Research\ResearchMultiplicityAudit;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__);
$read = static fn ($p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
$out = $root . '/var/reports/breadth_volatility_refinement_20260909';
if (file_exists($out)) { throw new RuntimeException('Use a new output directory.'); }
$data = $root . '/var/reports/breadth_volatility_data_20260909';
$old = $root . '/var/reports/independent_data_20260908';
$base = $read($old . '/protocol.json');
$definitions = [];
foreach ([0.0, 0.5, 0.75] as $defensive) {
    foreach ([5, 10] as $hold) {
        foreach ([1.1, 1.25] as $boost) {
            foreach ([0.75, 1.0] as $vvixCap) {
                $definitions["svxy200_{$defensive}_high80_{$hold}_{$boost}_vvix120_{$vvixCap}"] = compact('defensive', 'hold', 'boost', 'vvixCap');
            }
        }
    }
}
mkdir($out, 0775, true);
AlgorithmTrendResearch::write($out . '/protocol.json', ['frozen_before_replay' => gmdate(DATE_ATOM), 'definitions' => $definitions,
    'selection' => 'Top three by train CAGR / abs(train drawdown), 2021-2023 only.',
    'caveat' => 'Adaptive exploratory refinement after first-batch outcomes; not an independent holdout.', 'execution_enabled' => false]);
$allYahoo = [];
foreach (array_merge($base['original_universe'], $base['additional_universe'], ['SPY', 'QQQ']) as $symbol) {
    $bars = D::decode($read($old . '/yahoo_' . $symbol . '.json'))[$symbol];
    $allYahoo[$symbol] = array_values(array_filter($bars, static fn ($b): bool => D::session($b) >= '2020-01-01'));
}
$sources = ['yahoo' => array_intersect_key($allYahoo, array_fill_keys(array_merge($base['original_universe'], ['SPY', 'QQQ']), true)),
    'sip_spinoff' => D::decode($read($root . '/var/reports/data_attribution_20260908/split_spinoff.json')),
    'sip_all' => D::decode($read($old . '/sip_all.json'))];
$spy = D::decode($read($data . '/yahoo_SPY.json'))['SPY'];
$calendar = array_values(array_filter(array_keys(D::indexed(['SPY' => $spy])['SPY']), static fn ($d): bool => $d >= '2016-01-01'));
$breadth = B::validateBreadth($read($data . '/s5tw.json'), $calendar);
$vvix = $read($root . '/var/reports/vvix_data_20260909/cboe_vvix.json');
$svxy = ['yahoo' => D::decode($read($data . '/yahoo_SVXY.json'))['SVXY'], 'sip' => D::decode($read($data . '/sip_split.json'))['SVXY']];
$events = B::events($breadth)['high_touch'];
$changes = static function (array $d, string $source) use ($base, $svxy, $breadth, $events, $vvix): array {
    $trend = B::trend($svxy[$source === 'yahoo' ? 'yahoo' : 'sip'], 200);
    $scales = B::windowScale($events, $d['hold'], $d['boost']);
    foreach ($scales as $date => $value) {
        if (!array_key_exists($date, $trend)) { throw new RuntimeException('Missing refinement SVXY date.'); }
        if (!$trend[$date]) { $scales[$date] = min($scales[$date], $d['defensive']); }
        if ($vvix[$date] >= 120 && $d['vvixCap'] < 1) { $scales[$date] = min($scales[$date], $d['vvixCap']); }
    }
    return array_replace($base['cases']['new_best'], ['external_daily_scale' => $scales]);
};
$report = ['started_at' => gmdate(DATE_ATOM), 'replays' => 0, 'primary' => [], 'replication' => [], 'prefix_checks' => 0];
$runCase = static function (string $id, array $settings, string $source, int $cost, string $stage) use ($base, $sources, $out, &$report): array {
    $tester = AdaptiveResearchFactory::make($base['profile'], $settings, $cost);
    $run = $tester->run($sources[$source], '2021-01-04', '2026-09-04');
    $curve = $run['curve'];
    $m = ['full' => $tester->metrics($curve), 'train' => $tester->metrics($curve, null, '2024-01-01'),
        'validation' => $tester->metrics($curve, '2024-01-01', '2026-01-01'), 'later' => $tester->metrics($curve, '2026-01-01')];
    $annual = [];
    for ($year = 2021; $year <= 2026; $year++) { $annual[$year] = $tester->metrics($curve, "$year-01-01", ($year + 1) . '-01-01'); }
    $q = (new TacticalRotationQualification($base['profile']['validation']))->evaluate($m['train'], $m['validation'], $m['later'], $m['full'], $annual);
    $report[$stage][$source][$cost][$id] = ['metrics' => $m, 'qualification' => $q];
    AlgorithmTrendResearch::write($out . '/' . $stage . '_' . $source . '_' . $cost . '_' . $id . '.json',
        array_map(static fn ($r): array => array_intersect_key($r, array_flip(['date', 'period_start_date', 'start_equity', 'equity', 'equity_low', 'equity_high', 'turnover'])), $curve));
    $report['replays']++;
    printf("%d %s %d %s CAGR %.2f DD %.2f\n", $report['replays'], $source, $cost, $id, $m['full']['cagr'] * 100, $m['full']['max_drawdown'] * 100);
    return $m;
};
$scores = [];
foreach ($definitions as $id => $definition) {
    $m = $runCase($id, $changes($definition, 'yahoo'), 'yahoo', 30, 'primary');
    $scores[$id] = $m['train']['cagr'] / max(0.05, abs($m['train']['max_drawdown']));
    AlgorithmTrendResearch::write($out . '/progress.json', $report);
}
arsort($scores);
$selected = array_slice(array_keys($scores), 0, 3);
$report['train_selected'] = $selected;
AlgorithmTrendResearch::write($out . '/train_selection.json', ['selected' => $selected]);
foreach ($selected as $id) {
    foreach (['yahoo', 'sip_spinoff', 'sip_all'] as $source) {
        foreach ([30, 60] as $cost) {
            if ($source === 'yahoo' && $cost === 30) { continue; }
            $runCase($id, $changes($definitions[$id], $source), $source, $cost, 'replication');
        }
    }
    $settings = $changes($definitions[$id], 'yahoo');
    $expandedProfile = D::withUniverse($base['profile'], array_merge($base['original_universe'], $base['additional_universe']));
    $tester = AdaptiveResearchFactory::make($expandedProfile, $settings, 30);
    $report['expanded'][$id] = $tester->metrics($tester->run($allYahoo, '2021-01-04', '2026-09-04'));
    $report['replays']++;
    $full = $read($out . '/primary_yahoo_30_' . $id . '.json');
    foreach (['2023-12-29', '2025-12-31'] as $end) {
        $r = AdaptiveResearchFactory::make($base['profile'], $settings, 30)->run(HybridV4Research::truncateBars($sources['yahoo'], $end), '2021-01-04', $end);
        $prefix = array_map(static fn ($r): array => array_intersect_key($r, array_flip(['date', 'period_start_date', 'start_equity', 'equity', 'equity_low', 'equity_high', 'turnover'])), $r['curve']);
        if ($prefix != array_values(array_filter($full, static fn ($r): bool => $r['date'] <= $end))) { throw new RuntimeException('Refinement prefix drift.'); }
        $report['replays']++;
        $report['prefix_checks']++;
    }
    AlgorithmTrendResearch::write($out . '/progress.json', $report);
}
$primaryDir = $root . '/var/reports/breadth_volatility_20260909';
$baseline = $read($primaryDir . '/primary_yahoo_30_control_new_best.json');
$logs = static fn ($c): array => array_map(static fn ($r): float => log($r['equity'] / $r['start_equity']), array_values(array_filter($c, static fn ($r): bool => $r['date'] >= '2024-01-01')));
$control = $logs($baseline);
$matrix = [];
foreach ([$primaryDir, $out] as $dir) {
    foreach (glob($dir . '/primary_yahoo_30_*.json') as $file) {
        if (str_contains(basename($file), 'control_')) { continue; }
        $matrix[basename($file)] = array_map(static fn ($a, $b): float => $a - $b, $logs($read($file)), $control);
    }
}
$report['pooled_multiplicity'] = ResearchMultiplicityAudit::run($matrix, 20, 1000, 20260909);
$report['completed_at'] = gmdate(DATE_ATOM);
$report['deployment_approved'] = false;
AlgorithmTrendResearch::write($out . '/results.json', $report);
echo "Refinement complete.\n";
