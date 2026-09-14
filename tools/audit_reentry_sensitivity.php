#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Backtest\TacticalRotationQualification;
use FulltimeTrading\Research\AdaptiveResearchFactory;
use FulltimeTrading\Research\OfflineHybridDataset;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__);
$out = $root . '/var/reports/hybrid_reentry_sensitivity_20260907.json';
if (file_exists($out)) { throw new RuntimeException('Sensitivity audit already exists.'); }
$data = OfflineHybridDataset::load($root, '2026-09-04');
$report = ['generated_at' => gmdate(DATE_ATOM), 'method' => 'Exploratory local sensitivity, not another independent validation.',
    'thresholds' => [0.015, 0.02, 0.025], 'minimum_pauses' => [5, 10, 15], 'entry_scales' => [1.0, 0.5],
    'data_sha256' => $data['provenance']['merged']['canonical_sha256'], 'production_approved' => false];
file_put_contents($out . '.protocol', json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
foreach ($report['thresholds'] as $threshold) {
    foreach ($report['minimum_pauses'] as $minimum) {
        foreach ($report['entry_scales'] as $scale) {
            $id = "day_{$threshold}_pause_{$minimum}_scale_{$scale}";
            $changes = ['asset_sma_period' => 50, 'dynamic_allocation' => 0.5,
                'circuit_reentry_mode' => 'rebound', 'circuit_reentry_return_1' => $threshold,
                'circuit_reentry_min_sessions' => $minimum, 'circuit_reentry_initial_scale' => $scale,
                'circuit_reentry_ramp_sessions' => $scale < 1.0 ? 5 : 0];
            foreach ([20.0, 30.0, 40.0] as $cost) {
                $tester = AdaptiveResearchFactory::make($data['profile'], $changes, $cost);
                $r = $tester->run($data['bars'], '2021-01-04', '2026-09-04');
                $m = [];
                foreach (['train' => ['2021-01-04', '2024-01-01'], 'validation' => ['2024-01-01', '2026-01-01'],
                    'later_2026' => ['2026-01-01', null], 'full' => [null, null], 'post_freeze' => ['2026-07-16', null]] as $name => [$start, $end]) {
                    $m[$name] = $tester->metrics($r['curve'], $start, $end);
                }
                $annual = [];
                for ($year = 2021; $year <= 2026; $year++) { $annual[$year] = $tester->metrics($r['curve'], "$year-01-01", ($year + 1) . '-01-01'); }
                $q = (new TacticalRotationQualification($data['profile']['validation']))->evaluate($m['train'], $m['validation'], $m['later_2026'], $m['full'], $annual);
                $events = array_map(static fn (array $s): array => $s['reentry_events'], $r['sleeves']);
                $report['results'][$id]['changes'] = $changes;
                $report['results'][$id]['costs'][(string) $cost] = ['metrics' => $m, 'qualification' => $q, 'reentry_events' => $events];
                unset($r, $tester);
            }
            file_put_contents($out . '.tmp', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            rename($out . '.tmp', $out);
            printf("%s: CAGR %.2f%% DD %.2f%%\n", $id, $m['full']['cagr'] * 100, $m['full']['max_drawdown'] * 100);
        }
    }
}
echo "Wrote $out (console metrics use the final 40 bps stress; all costs are in JSON)\n";
