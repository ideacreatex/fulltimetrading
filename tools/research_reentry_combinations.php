#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Backtest\TacticalRotationQualification;
use FulltimeTrading\Research\AdaptiveResearchFactory;
use FulltimeTrading\Research\HybridV4Research;
use FulltimeTrading\Research\OfflineHybridDataset;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__);
$out = $root . '/var/reports/hybrid_reentry_combinations_20260907';
if (file_exists($out)) { throw new RuntimeException('Experiment directory already exists.'); }
$end = '2026-09-04';
$dataset = OfflineHybridDataset::load($root, $end);
$cases = ['baseline' => []];
$triggers = [
    'day2' => ['circuit_reentry_return_1' => 0.02],
    'three3' => ['circuit_reentry_return_3' => 0.03],
    'three5' => ['circuit_reentry_return_3' => 0.05],
    'trough10_8' => ['circuit_reentry_trough_window' => 10, 'circuit_reentry_trough_rebound' => 0.08],
    'confirmed_trough10_5' => ['circuit_reentry_trough_window' => 10, 'circuit_reentry_trough_rebound' => 0.05,
        'circuit_reentry_confirmation_sessions' => 2, 'circuit_reentry_leader_confirmation' => true],
];
foreach ([0.25, 0.35, 0.40, 0.50] as $allocation) {
    $base = ['asset_sma_period' => 50, 'dynamic_allocation' => $allocation];
    $cases['sma50_' . $allocation] = $base;
    foreach ($triggers as $trigger => $settings) {
        foreach ([1.0, 0.5] as $scale) {
            $cases['sma50_' . $allocation . '_' . $trigger . '_' . $scale] = array_replace($base, $settings, [
                'circuit_reentry_mode' => 'rebound', 'circuit_reentry_min_sessions' => 10,
                'circuit_reentry_initial_scale' => $scale, 'circuit_reentry_ramp_sessions' => $scale < 1.0 ? 5 : 0,
            ]);
        }
    }
}
foreach ([0.01, 0.02, 0.025, 0.035, 0.04, 0.045, 0.06] as $buffer) {
    $cases['hysteresis_' . $buffer] = ['rank_hysteresis' => $buffer];
}
mkdir($out, 0775, true);
$write = static function (string $path, array $value): void {
    file_put_contents($path . '.tmp', json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    rename($path . '.tmp', $path);
};
$files = ['src/Research/AdaptiveRotationBacktester.php', 'src/Research/AdaptiveRotationEnsembleBacktester.php',
    'src/Research/AdaptiveResearchFactory.php', 'src/Research/OfflineHybridDataset.php', __FILE__];
$write($out . '/protocol.json', ['generated_at' => gmdate(DATE_ATOM), 'cases' => $cases, 'data' => $dataset['provenance'],
    'selection' => 'All fixed cases are audited. Exploratory interaction and neighborhood analysis after the first structural screen; no untouched OOS claim.',
    'source_sha256' => array_combine($files, array_map(static fn (string $f): string => hash_file('sha256', $f), $files)),
    'production_approved' => false]);
$trainBars = HybridV4Research::truncateBars($dataset['bars'], '2023-12-29');
$results = [];
foreach ($cases as $id => $changes) {
    $trainTester = AdaptiveResearchFactory::make($dataset['profile'], $changes, 30.0);
    $trainRun = $trainTester->run($trainBars, '2021-01-04', '2023-12-29');
    $train = $trainTester->metrics($trainRun['curve']);
    unset($trainTester, $trainRun);
    $results[$id] = ['changes' => $changes];
    foreach ([20.0, 30.0, 40.0] as $cost) {
        $tester = AdaptiveResearchFactory::make($dataset['profile'], $changes, $cost);
        $result = $tester->run($dataset['bars'], '2021-01-04', $end);
        $metrics = [];
        foreach (['train' => ['2021-01-04', '2024-01-01'], 'validation' => ['2024-01-01', '2026-01-01'],
            'later_2026' => ['2026-01-01', null], 'full' => [null, null], 'post_freeze' => ['2026-07-16', null]] as $period => [$from, $to]) {
            $metrics[$period] = $tester->metrics($result['curve'], $from, $to);
        }
        if ($cost === 30.0 && $metrics['train'] !== $train) { throw new RuntimeException('Training prefix changed: ' . $id); }
        $annual = [];
        for ($y = 2021; $y <= 2026; $y++) { $annual[$y] = $tester->metrics($result['curve'], "$y-01-01", ($y + 1) . '-01-01'); }
        $qualification = (new TacticalRotationQualification($dataset['profile']['validation']))->evaluate(
            $metrics['train'], $metrics['validation'], $metrics['later_2026'], $metrics['full'], $annual);
        $results[$id]['costs'][(string) $cost] = ['metrics' => $metrics, 'annual' => $annual, 'qualification' => $qualification,
            'reentry_events' => array_map(static fn (array $s): array => $s['reentry_events'], $result['sleeves'])];
        $write($out . '/' . $id . '_' . (int) $cost . '_curve.json', array_map(static fn (array $r): array =>
            array_intersect_key($r, array_flip(['date', 'start_equity', 'equity', 'equity_low', 'equity_high', 'gross_close', 'gross_bound', 'turnover'])), $result['curve']));
        $write($out . '/results.json', $results);
        unset($result, $tester);
    }
    $row = $results[$id]['costs']['30'];
    printf("%2d/%d %-52s CAGR=%7.2f%% DD=%6.2f%% gates=%s\n", count($results), count($cases), $id,
        100 * $row['metrics']['full']['cagr'], 100 * $row['metrics']['full']['max_drawdown'], implode(',', $row['qualification']['failed_gates']));
}
echo "Completed interaction audit: $out\n";
