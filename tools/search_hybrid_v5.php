#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Backtest\TacticalRotationQualification;
use FulltimeTrading\Research\AdaptiveResearchFactory;
use FulltimeTrading\Research\HybridV4Research;
use FulltimeTrading\Research\OfflineHybridDataset;

require dirname(__DIR__) . '/bootstrap.php';
$options = getopt('', ['output-dir:', 'end:', 'extended-rebound', 'audit-all']);
$root = dirname(__DIR__);
$end = (string) ($options['end'] ?? '2026-09-04');
$out = (string) ($options['output-dir'] ?? $root . '/var/reports/hybrid_structural_20260907');
if (file_exists($out)) {
    throw new RuntimeException('Experiment output must be a new directory.');
}
$dataset = OfflineHybridDataset::load($root, $end);
$profile = $dataset['profile'];
$bars = $dataset['bars'];
$trainBars = HybridV4Research::truncateBars($bars, '2023-12-29');
$cases = AdaptiveResearchFactory::cases(isset($options['extended-rebound']));
mkdir($out, 0775, true);
$paths = ['src/Research/AdaptiveRotationBacktester.php', 'src/Research/AdaptiveRotationEnsembleBacktester.php', 'src/Research/AdaptiveResearchFactory.php', 'src/Research/OfflineHybridDataset.php', 'tools/search_hybrid_v5.php'];
structuralWrite($out . '/protocol.json', [
    'generated_at' => gmdate(DATE_ATOM), 'cases' => $cases,
    'selection' => isset($options['audit-all']) ? 'Audit all predeclared cases; later-period comparisons are exploratory, not a new validation selection.' : 'Freeze up to two train-eligible candidates per family plus global top eight by training CAGR/abs(DD); one diagnostic candidate if a family has no eligible candidate. Reentry triggers form separate families.',
    'periods' => ['train' => ['2021-01-04', '2023-12-29'], 'validation' => ['2024-01-01', '2025-12-31'], 'later_replay' => ['2026-01-01', $end]],
    'data' => $dataset['provenance'], 'files' => $dataset['files'],
    'implementation' => array_combine($paths, array_map(static fn (string $p): string => hash_file('sha256', $root . '/' . $p), $paths)),
    'limitations' => ['Previously examined history, not untouched OOS.', 'Expanded books must not be confused with independent economic trades.', 'Offline only; no execution profile is changed by this tool.'],
    'production_approved' => false,
]);
$screen = [];
foreach ($cases as $id => $case) {
    $tester = AdaptiveResearchFactory::make($profile, $case['changes'], 30.0);
    $result = $tester->run($trainBars, '2021-01-04', '2023-12-29');
    $metrics = $tester->metrics($result['curve']);
    $family = $case['family'];
    if ($family === 'bullish_reentry') {
        $family .= ':' . preg_replace('/_\d+_[\d.]+$/', '', substr($id, strlen('reentry_')));
    }
    $screen[$id] = [
        'family' => $family, 'changes' => $case['changes'], 'train' => $metrics,
        'train_failed_gates' => HybridV4Research::trainFailures($metrics, $profile['validation']),
        'score' => $metrics['cagr'] / max(0.01, abs($metrics['max_drawdown'])),
    ];
    structuralWrite($out . '/train_screen.json', $screen);
    printf("train %3d/%d %-48s CAGR=%7.2f%% DD=%6.2f%% eligible=%s\n", count($screen), count($cases), $id, 100 * $metrics['cagr'], 100 * $metrics['max_drawdown'], $screen[$id]['train_failed_gates'] === [] ? 'yes' : 'no');
    unset($result, $tester);
}
$sorted = $screen;
uksort($sorted, static fn (string $a, string $b): int => $screen[$b]['score'] <=> $screen[$a]['score'] ?: strcmp($a, $b));
$chosen = ['baseline' => true];
$families = [];
foreach ($sorted as $id => $row) {
    $families[$row['family']][$id] = $row;
}
foreach ($families as $rows) {
    $eligible = array_filter($rows, static fn (array $r): bool => $r['train_failed_gates'] === []);
    foreach (array_slice(array_keys($eligible !== [] ? $eligible : $rows), 0, $eligible !== [] ? 2 : 1) as $id) {
        $chosen[$id] = true;
    }
}
$eligible = array_filter($sorted, static fn (array $r): bool => $r['train_failed_gates'] === []);
foreach (array_slice(array_keys($eligible), 0, 8) as $id) {
    $chosen[$id] = true;
}
if (isset($options['audit-all'])) {
    $chosen = array_fill_keys(array_keys($cases), true);
}
structuralWrite($out . '/selection.json', ['selected' => array_keys($chosen), 'screen_sha256' => hash_file('sha256', $out . '/train_screen.json'), 'selected_at' => gmdate(DATE_ATOM)]);
$results = [];
foreach (array_keys($chosen) as $id) {
    foreach ([20.0, 30.0, 40.0] as $cost) {
        $tester = AdaptiveResearchFactory::make($profile, $cases[$id]['changes'], $cost);
        $result = $tester->run($bars, '2021-01-04', $end);
        $curve = $result['curve'];
        $metrics = [];
        foreach (['train' => ['2021-01-04', '2024-01-01'], 'validation' => ['2024-01-01', '2026-01-01'], 'later_2026' => ['2026-01-01', null], 'full' => [null, null], 'post_freeze' => ['2026-07-16', null]] as $period => [$start, $stop]) {
            $metrics[$period] = $tester->metrics($curve, $start, $stop);
        }
        if ($cost === 30.0 && $metrics['train'] !== $screen[$id]['train']) {
            throw new RuntimeException('Training prefix changed after adding later data: ' . $id);
        }
        $annual = [];
        for ($year = 2021; $year <= (int) substr($end, 0, 4); $year++) {
            $annual[$year] = $tester->metrics($curve, $year . '-01-01', ($year + 1) . '-01-01');
        }
        $qualification = (new TacticalRotationQualification($profile['validation']))->evaluate($metrics['train'], $metrics['validation'], $metrics['later_2026'], $metrics['full'], $annual);
        $results[$id]['changes'] = $cases[$id]['changes'];
        $results[$id]['family'] = $screen[$id]['family'];
        $results[$id]['train_eligible'] = $screen[$id]['train_failed_gates'] === [];
        $results[$id]['costs'][(string) $cost] = [
            'metrics' => $metrics, 'annual' => $annual, 'qualification' => $qualification,
            'activity_since_freeze' => HybridV4Research::activity($result['sleeve_curves'], '2026-07-16'),
            'reentry_events' => array_map(static fn (array $s): array => $s['reentry_events'], $result['sleeves']),
            'next_targets' => $result['next_targets'],
        ];
        structuralWrite($out . '/results.json', $results);
        structuralWrite($out . '/' . $id . '_' . (int) $cost . '_curve.json', array_map(static fn (array $r): array => array_intersect_key($r, array_flip(['date', 'start_equity', 'equity', 'equity_low', 'equity_high', 'gross_close', 'gross_bound', 'turnover'])), $curve));
        printf("audit %-48s cost=%2.0f CAGR=%7.2f%% DD=%6.2f%% recent=%7.2f%% gates=%s\n", $id, $cost, 100 * $metrics['full']['cagr'], 100 * $metrics['full']['max_drawdown'], 100 * $metrics['post_freeze']['return'], implode(',', $qualification['failed_gates']));
        unset($result, $tester, $curve);
    }
}
echo "Completed structural search: $out\n";

function structuralWrite(string $file, array $data): void
{
    $temp = $file . '.tmp';
    if (file_put_contents($temp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) === false || !rename($temp, $file)) {
        throw new RuntimeException('Cannot write experiment: ' . $file);
    }
}
