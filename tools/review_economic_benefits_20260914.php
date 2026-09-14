#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Research\AlgorithmTrendResearch as A;
use FulltimeTrading\Research\BreadthVolatilityResearch as B;
use FulltimeTrading\Research\EconomicBenefitReview as R;

require dirname(__DIR__) . '/bootstrap.php';
set_error_handler(static function (int $level, string $message): never { throw new RuntimeException($message); });
$root = dirname(__DIR__);
$dir = $root . '/var/reports/standard_etfs_20260914';
$out = $root . '/var/reports/paper_clarity_20260914';
$read = static fn ($file): array => json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
$p = $read($dir . '/protocol.json');
foreach ($p['code_sha256'] as $file => $sha) {
    if (hash_file('sha256', $root . '/' . $file) !== $sha) { throw new RuntimeException('Historical engine changed: ' . $file); }
}
$metrics = $contracts = $hashes = [];
foreach (['continuous30' => 'continuous', 'fresh2023_30' => 'fresh2023', 'continuous60' => 'cost60_2021', 'fresh2023_60' => 'cost60_2023'] as $scenario => $name) {
    $paths = ['selected_maximum' => '/var/reports/opportunity_20260910/' . $name . '_p0_maximum_curve.json',
        'candidate' => '/var/reports/opportunity_calendar_20260910/' . $name . '_p0_maximum_stop12__cost_band_' . (str_contains($scenario, '60') ? '1' : '2') . '_curve.json'];
    foreach ($paths as $base => $relative) {
        $path = $root . $relative;
        $sha = hash_file('sha256', $path);
        if ($sha !== $p['input_sha256'][$base . '/' . $scenario]) { throw new RuntimeException('Frozen anchor changed.'); }
        $curve = $read($path);
        $metrics[$base][$scenario] = B::metrics($curve);
        [$start, $end, $cost] = $p['scenarios'][$scenario];
        $contracts[$base][$scenario] = ['start' => $start, 'end' => $end, 'initial_equity' => (float) $curve[0]['start_equity'],
            'data_contract' => 'alpaca/sip/all; fixed-switching-hurdle-at-60bps', 'cost_bps' => $cost,
            'calendar_sha256' => hash('sha256', json_encode(array_column($curve, 'date'), JSON_THROW_ON_ERROR))];
        $hashes[$base . '/' . $scenario] = $sha;
    }
}
$review = ['created_at' => gmdate(DATE_ATOM), 'policy' => R::VERSION,
    'thresholds' => ['terminal_capital_gain_pct' => 10, 'drawdown_reduction_pp' => 5],
    'policy_origin' => 'Engineering triage thresholds set after user accepted about 6pp drawdown improvement; not pre-registered, not statistical validation or paper admission.',
    'parent_protocol_sha256' => hash_file('sha256', $dir . '/protocol.json'), 'anchor_curve_sha256' => $hashes,
    'execution_authorized' => false, 'live_authorized' => false, 'manual_orders_submitted' => 0,
    'unchanged' => ['historical_qualification', 'validation_selected', 'monthly_live_review', 'runtime_identity', 'broker_guards'],
    'candidate_vs_selected_maximum' => []];
foreach ($metrics['candidate'] as $scenario => $metric) {
    $review['candidate_vs_selected_maximum'][$scenario] = R::compare(
        $contracts['selected_maximum'][$scenario] + $metrics['selected_maximum'][$scenario],
        $contracts['candidate'][$scenario] + $metric);
}
$diagnostic = $read($dir . '/rth_wick_sensitivity/results.json');
$review['diagnostic_results_sha256'] = hash_file('sha256', $dir . '/rth_wick_sensitivity/results.json');
$families = $counts = $mixed = [];
foreach (['selected_maximum', 'candidate'] as $base) {
    $counts[$base] = ['total' => 0, 'risk_not_worse_all_contexts' => 0, 'material_benefit_all_contexts' => 0];
}
foreach ($p['cases'] as $id => $config) {
    $row = $read($dir . '/' . $id . '.json');
    if ($row['protocol_sha256'] !== $review['parent_protocol_sha256'] || $row['historical_prefix_pass'] !== true) {
        throw new RuntimeException('Unverified mixed result: ' . $id);
    }
    $review['mixed_input_sha256'][$id] = hash_file('sha256', $dir . '/' . $id . '.json');
    foreach ($row['mixes'] as $base => $allocations) {
        foreach ($allocations as $allocation => $scenarios) {
            $key = $base . '/' . $id . '/' . $allocation;
            $entry = ['base' => $base, 'id' => $id, 'allocation_pct' => $allocation, 'scenarios' => []];
            $allRisk = $allUseful = true;
            $worstMoneySacrifice = 0.; $minimumRiskGain = INF;
            foreach ($scenarios as $scenario => $m) {
                $raw = R::compare($contracts[$base][$scenario] + $metrics[$base][$scenario], $contracts[$base][$scenario] + $m['full']);
                $sensitivity = R::compare($contracts[$base][$scenario] + $metrics[$base][$scenario],
                    $contracts[$base][$scenario] + $diagnostic[$id]['mixes'][$base][$allocation][$scenario]['full']);
                $entry['scenarios'][$scenario] = ['raw' => $raw, 'rth_wick_diagnostic' => $sensitivity];
                // Require both raw and diagnostic agreement, without calling the latter a vendor correction.
                foreach ([$raw, $sensitivity] as $r) {
                    $allRisk = $allRisk && $r['drawdown_reduction_pp'] >= -1e-9;
                    $allUseful = $allUseful && $r['economically_interesting'];
                    $worstMoneySacrifice = min($worstMoneySacrifice, $r['capital_delta_pct']);
                    $minimumRiskGain = min($minimumRiskGain, $r['drawdown_reduction_pp']);
                }
            }
            $entry['risk_not_worse_all_contexts'] = $allRisk;
            $entry['material_benefit_all_contexts'] = $allUseful;
            $entry['worst_terminal_capital_delta_pct'] = $worstMoneySacrifice;
            $entry['minimum_drawdown_reduction_pp'] = $minimumRiskGain;
            $mixed[$key] = $entry;
            $counts[$base]['total'] = ($counts[$base]['total'] ?? 0) + 1;
            if ($allRisk) { $counts[$base]['risk_not_worse_all_contexts'] = ($counts[$base]['risk_not_worse_all_contexts'] ?? 0) + 1; }
            if ($allUseful) { $counts[$base]['material_benefit_all_contexts'] = ($counts[$base]['material_benefit_all_contexts'] ?? 0) + 1; }
        }
    }
}
$ordered = array_keys($mixed);
usort($ordered, static fn ($a, $b): int => $mixed[$b]['minimum_drawdown_reduction_pp'] <=> $mixed[$a]['minimum_drawdown_reduction_pp']);
$review['mix_counts'] = $counts;
$review['mixes_with_largest_minimum_risk_reduction_posthoc'] = array_map(static fn ($key) => $mixed[$key], array_slice($ordered, 0, 6));
$review['limits'] = ['Reanalysis of existing replays, not new hypotheses or independent holdout.',
    'Raw SPY wick and RTH diagnostic both retained; diagnostic is not a production price source.',
    'Whole-share nominal-price replay, broker stop lifecycle and twelve-sleeve runtime integration remain separate technical work.',
    'Passing preference review does not set validation_selected or permit any order.'];
if (!is_dir($out)) { mkdir($out, 0775, true); }
A::write($out . '/economic_mix_reviews.json', $mixed);
A::write($out . '/economic_review.json', $review);
echo json_encode(['candidate' => array_map(static fn ($r) => array_intersect_key($r, array_flip([
    'capital_delta_dollars', 'capital_delta_pct', 'drawdown_reduction_pp', 'cagr_delta_pp', 'economically_interesting', 'tradeoff'])),
    $review['candidate_vs_selected_maximum']), 'mix_counts' => $counts], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
