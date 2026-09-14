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
$options = getopt('', ['output-dir:', 'parent:']);
$root = dirname(__DIR__);
$out = (string) ($options['output-dir'] ?? $root . '/var/reports/algorithm_combinations_20260908');
$parent = (string) ($options['parent'] ?? $root . '/var/reports/algorithm_trends_20260908');
if (file_exists($out)) { throw new RuntimeException('Use a new output directory.'); }
$dataset = OfflineHybridDataset::load($root, '2026-09-04');
$train = HybridV4Research::truncateBars($dataset['bars'], '2023-12-29');
$baseCases = AlgorithmTrendResearch::cases();
$bases = ['prior_best' => $baseCases['previous_best']['changes'], 'sma50_weight25' => ['asset_sma_period' => 50, 'dynamic_allocation' => 0.25]];
$cases = [];
foreach (['downside_size' => [0.25, 0.5, 1.0], 'drawdown_size' => [0.05, 0.10, 0.15], 'resize_band' => [0.02, 0.05, 0.10]] as $family => $strengths) {
    foreach ($strengths as $strength) {
        foreach (['all', 'dynamic'] as $scope) {
            foreach ($bases as $base => $settings) {
                $policy = ['family' => $family, 'window' => 20, 'strength' => $strength];
                if ($family === 'resize_band') { $policy['mode'] = 'both'; }
                $changes = ['algorithm_policy' => $policy];
                if ($scope === 'dynamic') { $changes = ['dynamic_overrides' => $changes]; }
                $id = 'combo_' . $base . '_' . $family . '_' . str_replace('.', 'p', (string) $strength) . '_' . $scope;
                $cases[$id] = ['family' => 'combined_' . $family, 'scope' => $scope, 'changes' => array_replace($settings, $changes)];
            }
        }
    }
}
$hashes = [];
foreach ($cases as $case) { $hashes[] = hash('sha256', json_encode(AdaptiveResearchFactory::make($dataset['profile'], $case['changes'], 30.0)->config())); }
if (count($cases) !== 36 || count(array_unique($hashes)) !== 36) { throw new RuntimeException('Combination grid is not distinct.'); }
$parentProtocol = json_decode(file_get_contents($parent . '/protocol.json'), true, 512, JSON_THROW_ON_ERROR);
$identity = TacticalImplementationIdentity::current($root, $dataset['profile']);
mkdir($out, 0775, true);
$implementation = $parentProtocol['implementation'];
$implementation['tools/research_algorithm_combinations.php'] = hash_file('sha256', __FILE__);
AlgorithmTrendResearch::write($out . '/protocol.json', ['created_at' => gmdate(DATE_ATOM), 'cases' => $cases,
    'implementation' => $implementation, 'operational_identity' => $identity,
    'data' => $dataset['provenance'], 'files' => $dataset['files'], 'periods' => $parentProtocol['periods'],
    'parent_protocol_sha256' => hash_file('sha256', $parent . '/protocol.json'),
    'selection' => 'Exploratory second stage after partial full-history first-stage results were inspected. Grid frozen before these combination runs; not untouched OOS.',
    'new_variants' => 36, 'costs_bps' => [20, 30, 40], 'production_approved' => false]);
$screen = [];
foreach ($cases as $id => $case) {
    $tester = AdaptiveResearchFactory::make($dataset['profile'], $case['changes'], 30.0);
    $run = $tester->run($train, '2021-01-04', '2023-12-29');
    $metrics = $tester->metrics($run['curve']);
    $screen[$id] = $case + ['train' => $metrics, 'train_failed_gates' => HybridV4Research::trainFailures($metrics, $dataset['profile']['validation']),
        'score' => $metrics['cagr'] / max(0.01, abs($metrics['max_drawdown'])), 'prefix_sha256' => hash('sha256', serialize($run['curve']))];
    AlgorithmTrendResearch::write($out . '/train_screen.json', $screen);
    printf("combo train %2d/36 %s\n", count($screen), $id);
    unset($run, $tester);
}
$eligible = array_filter($screen, static fn (array $r): bool => $r['train_failed_gates'] === []);
uksort($eligible, static fn (string $a, string $b): int => $eligible[$b]['score'] <=> $eligible[$a]['score'] ?: strcmp($a, $b));
AlgorithmTrendResearch::write($out . '/selection.json', ['selected_at' => gmdate(DATE_ATOM), 'train_winner' => array_key_first($eligible),
    'top_five_train_eligible' => array_slice(array_keys($eligible), 0, 5), 'screen_sha256' => hash_file('sha256', $out . '/train_screen.json')]);
$results = [];
foreach ($cases as $id => $case) {
    foreach ([20.0, 30.0, 40.0] as $cost) {
        $tester = AdaptiveResearchFactory::make($dataset['profile'], $case['changes'], $cost);
        $run = $tester->run($dataset['bars'], '2021-01-04', '2026-09-04');
        $metrics = [];
        foreach (['train' => ['2021-01-04', '2024-01-01'], 'validation' => ['2024-01-01', '2026-01-01'],
            'later_2026' => ['2026-01-01', null], 'full' => [null, null], 'post_freeze' => ['2026-07-16', null]] as $period => [$start, $stop]) {
            $metrics[$period] = $tester->metrics($run['curve'], $start, $stop);
        }
        if ($cost === 30.0) {
            $prefix = array_values(array_filter($run['curve'], static fn (array $r): bool => $r['date'] <= '2023-12-29'));
            if ($metrics['train'] !== $screen[$id]['train'] || hash('sha256', serialize($prefix)) !== $screen[$id]['prefix_sha256']) {
                throw new RuntimeException('Combination prefix mismatch: ' . $id);
            }
        }
        $annual = [];
        for ($year = 2021; $year <= 2026; $year++) { $annual[$year] = $tester->metrics($run['curve'], "$year-01-01", ($year + 1) . '-01-01'); }
        $qualification = (new TacticalRotationQualification($dataset['profile']['validation']))->evaluate($metrics['train'], $metrics['validation'], $metrics['later_2026'], $metrics['full'], $annual);
        $results[$id] = $results[$id] ?? $case;
        $results[$id]['costs'][(string) $cost] = ['metrics' => $metrics, 'annual' => $annual, 'qualification' => $qualification,
            'activity_since_freeze' => HybridV4Research::activity($run['sleeve_curves'], '2026-07-16'), 'path_sha256' => hash('sha256', serialize($run['curve']))];
        AlgorithmTrendResearch::write($out . '/' . $id . '_' . (int) $cost . '_curve.json', array_map(static fn (array $r): array => array_intersect_key($r, array_flip(['date', 'start_equity', 'equity', 'equity_low', 'equity_high', 'gross_close', 'gross_bound', 'turnover'])), $run['curve']));
        AlgorithmTrendResearch::write($out . '/results.json', $results);
        printf("combo audit %s cost=%2.0f CAGR=%.2f%% DD=%.2f%% gates=%s\n", $id, $cost, 100 * $metrics['full']['cagr'], 100 * $metrics['full']['max_drawdown'], implode(',', $qualification['failed_gates']));
        unset($run, $tester, $prefix);
    }
}
if ($identity !== TacticalImplementationIdentity::current($root, $dataset['profile'])) { throw new RuntimeException('Operational identity changed.'); }
AlgorithmTrendResearch::write($out . '/completion.json', ['completed_at' => gmdate(DATE_ATOM), 'new_variants' => 36, 'total_cases' => 36,
    'candidate_cost_replays' => 108, 'exact_curve_training_prefixes' => 36, 'operational_identity_unchanged' => true,
    'results_sha256' => hash_file('sha256', $out . '/results.json')]);
echo "Completed combinations: $out\n";
