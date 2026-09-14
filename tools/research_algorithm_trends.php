#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Backtest\TacticalRotationQualification;
use FulltimeTrading\Research\AdaptiveResearchFactory;
use FulltimeTrading\Research\AlgorithmTrendResearch;
use FulltimeTrading\Research\HybridV4Research;
use FulltimeTrading\Research\OfflineHybridDataset;
use FulltimeTrading\Trading\TacticalImplementationIdentity;

require dirname(__DIR__) . '/bootstrap.php';
$options = getopt('', ['output-dir:']);
$root = dirname(__DIR__);
$out = (string) ($options['output-dir'] ?? $root . '/var/reports/algorithm_trends_20260908');
if (file_exists($out)) {
    throw new RuntimeException('Use a new output directory; completed protocols are immutable.');
}
$end = '2026-09-04';
$dataset = OfflineHybridDataset::load($root, $end);
$profile = $dataset['profile'];
$bars = $dataset['bars'];
$trainBars = HybridV4Research::truncateBars($bars, '2023-12-29');
$cases = AlgorithmTrendResearch::cases();
$paths = ['src/Research/AlgorithmSignalPolicy.php', 'src/Research/AlgorithmTrendResearch.php',
    'src/Research/AdaptiveRotationBacktester.php', 'src/Research/AdaptiveRotationEnsembleBacktester.php',
    'src/Research/AdaptiveResearchFactory.php', 'src/Research/HybridV4Research.php',
    'src/Research/OfflineHybridDataset.php', 'src/Backtest/TacticalRotationQualification.php',
    'src/Indicators/IndicatorCalculator.php', 'tools/research_algorithm_trends.php'];
$identity = TacticalImplementationIdentity::current($root, $profile);
mkdir($out, 0775, true);
$implementation = [];
foreach ($paths as $path) {
    $implementation[$path] = hash_file('sha256', $root . '/' . $path);
    $snapshot = $out . '/source/' . $path;
    if (!is_dir(dirname($snapshot))) {
        mkdir(dirname($snapshot), 0775, true);
    }
    if (file_put_contents($snapshot, file_get_contents($root . '/' . $path)) === false) {
        throw new RuntimeException('Cannot snapshot research implementation.');
    }
}
AlgorithmTrendResearch::write($out . '/protocol.json', [
    'created_at' => gmdate(DATE_ATOM), 'cases' => $cases, 'implementation' => $implementation,
    'operational_identity' => $identity, 'data' => $dataset['provenance'], 'files' => $dataset['files'],
    'periods' => ['train' => ['2021-01-04', '2023-12-29'], 'validation' => ['2024-01-01', '2025-12-31'], 'later_replay' => ['2026-01-01', $end]],
    'costs_bps' => [20, 30, 40], 'new_variants' => 156, 'economic_families' => 13,
    'selection' => 'Select by training CAGR/abs(DD) among original train-gate passers. Audit every case afterwards; full-period maximum is explicitly hindsight-selected.',
    'limitations' => ['Known, previously examined history is not untouched OOS.', 'Fixed hand-selected 20-stock universe; no survivorship-free historical universe.',
        'Daily close signals / next-open modeled fills, fixed bps plus margin interest; no measured liquidity or market-impact evidence.',
        'Parameter variants in the same family are correlated, not 156 independent economic discoveries.',
        'Price-only mechanisms inspired by recent research; no claim to replicate DRL or pretrained foundation models.'],
    'production_approved' => false, 'order_submission_enabled' => false,
]);
$screen = [];
foreach ($cases as $id => $case) {
    $tester = AdaptiveResearchFactory::make($profile, $case['changes'], 30.0);
    $run = $tester->run($trainBars, '2021-01-04', '2023-12-29');
    $metrics = $tester->metrics($run['curve']);
    $screen[$id] = $case + ['train' => $metrics,
        'train_failed_gates' => HybridV4Research::trainFailures($metrics, $profile['validation']),
        'prefix_sha256' => hash('sha256', serialize($run['curve'])),
        'score' => $metrics['cagr'] / max(0.01, abs($metrics['max_drawdown']))];
    AlgorithmTrendResearch::write($out . '/train_screen.json', $screen);
    printf("train %3d/%d %-45s CAGR=%7.2f%% DD=%6.2f%%\n", count($screen), count($cases), $id, 100 * $metrics['cagr'], 100 * $metrics['max_drawdown']);
    unset($run, $tester);
}
$eligible = array_filter($screen, static fn (array $r): bool => $r['family'] !== 'control' && $r['train_failed_gates'] === []);
uksort($eligible, static fn (string $a, string $b): int => $eligible[$b]['score'] <=> $eligible[$a]['score'] ?: strcmp($a, $b));
AlgorithmTrendResearch::write($out . '/selection.json', ['selected_at' => gmdate(DATE_ATOM),
    'train_winner' => array_key_first($eligible), 'top_five_train_eligible' => array_slice(array_keys($eligible), 0, 5),
    'screen_sha256' => hash_file('sha256', $out . '/train_screen.json')]);
$results = [];
$old = json_decode(file_get_contents($root . '/docs/research_results/hybrid_v4_rebound_20260907.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($cases as $id => $case) {
    foreach ([20.0, 30.0, 40.0] as $cost) {
        $tester = AdaptiveResearchFactory::make($profile, $case['changes'], $cost);
        $run = $tester->run($bars, '2021-01-04', $end);
        $curve = $run['curve'];
        $metrics = [];
        foreach (['train' => ['2021-01-04', '2024-01-01'], 'validation' => ['2024-01-01', '2026-01-01'],
            'later_2026' => ['2026-01-01', null], 'full' => [null, null], 'post_freeze' => ['2026-07-16', null]] as $period => [$start, $stop]) {
            $metrics[$period] = $tester->metrics($curve, $start, $stop);
        }
        if ($cost === 30.0) {
            $prefix = array_values(array_filter($curve, static fn (array $r): bool => $r['date'] <= '2023-12-29'));
            if ($metrics['train'] !== $screen[$id]['train'] || hash('sha256', serialize($prefix)) !== $screen[$id]['prefix_sha256']) {
                throw new RuntimeException('Physical training-prefix equality failed: ' . $id);
            }
        }
        if (in_array($id, ['baseline', 'previous_best'], true)) {
            $oldId = $id === 'baseline' ? 'baseline' : 'day_0.02_pause_5_scale_1';
            foreach (['return', 'cagr', 'max_drawdown'] as $key) {
                if (abs($metrics['full'][$key] - $old['candidates'][$oldId]['costs'][(string) $cost]['metrics']['full'][$key]) > 1.0e-10) {
                    throw new RuntimeException('Prior control changed: ' . $id . '/' . $key);
                }
            }
        }
        $annual = [];
        for ($year = 2021; $year <= 2026; $year++) {
            $annual[$year] = $tester->metrics($curve, $year . '-01-01', ($year + 1) . '-01-01');
        }
        $qualification = (new TacticalRotationQualification($profile['validation']))->evaluate($metrics['train'], $metrics['validation'], $metrics['later_2026'], $metrics['full'], $annual);
        $results[$id] = ($results[$id] ?? $case);
        $results[$id]['costs'][(string) $cost] = ['metrics' => $metrics, 'annual' => $annual, 'qualification' => $qualification,
            'activity_since_freeze' => HybridV4Research::activity($run['sleeve_curves'], '2026-07-16'),
            'path_sha256' => hash('sha256', serialize($curve))];
        AlgorithmTrendResearch::write($out . '/' . $id . '_' . (int) $cost . '_curve.json', array_map(static fn (array $r): array => array_intersect_key($r, array_flip(['date', 'start_equity', 'equity', 'equity_low', 'equity_high', 'gross_close', 'gross_bound', 'turnover'])), $curve));
        AlgorithmTrendResearch::write($out . '/results.json', $results);
        printf("audit %-45s cost=%2.0f CAGR=%7.2f%% DD=%6.2f%% gates=%s\n", $id, $cost, 100 * $metrics['full']['cagr'], 100 * $metrics['full']['max_drawdown'], implode(',', $qualification['failed_gates']));
        unset($tester, $run, $curve, $prefix);
    }
}
if ($identity !== TacticalImplementationIdentity::current($root, $profile)) {
    throw new RuntimeException('Operational identity changed during research.');
}
AlgorithmTrendResearch::write($out . '/completion.json', ['completed_at' => gmdate(DATE_ATOM), 'new_variants' => 156,
    'total_cases' => count($cases), 'candidate_cost_replays' => count($cases) * 3,
    'exact_curve_training_prefixes' => count($cases), 'prior_controls_reproduced' => true,
    'operational_identity_unchanged' => true, 'results_sha256' => hash_file('sha256', $out . '/results.json')]);
echo "Completed algorithm research: $out\n";
