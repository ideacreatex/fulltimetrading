#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Research\AlgorithmTrendResearch as A;
use FulltimeTrading\Research\ResearchMultiplicityAudit as M;
use FulltimeTrading\Trading\TacticalImplementationIdentity;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__); $read = static fn ($f): array => json_decode(file_get_contents($f), true, 512, JSON_THROW_ON_ERROR);
$out = $root . '/var/reports/opportunity_20260910';
if (file_exists($out . '/final_review.json')) { throw new RuntimeException('Final review already frozen.'); }
$matrix = []; $summaries = []; $integrity = 0;
foreach (['opportunity_20260910', 'opportunity_calendar_20260910'] as $name) {
    $dir = $root . '/var/reports/' . $name; $p = $read($dir . '/protocol.json'); $summaries[$name] = $read($dir . '/results.json');
    foreach ($p['code_sha256'] as $file => $hash) {
        if (hash_file('sha256', $root . '/' . $file) !== $hash) { throw new RuntimeException('Frozen code drift.'); } $integrity++;
    }
    if (TacticalImplementationIdentity::current($root, require $root . '/config/tactical_rotation.php') !== $p['operational_identity']) { throw new RuntimeException('Operational code changed.'); }
    foreach ($p['phases'] as $phase) {
        $ref = []; foreach ($read($dir . '/continuous_p' . $phase . '_maximum_curve.json') as $r) {
            if ($r['date'] >= '2024-01-01') { $ref[$r['date']] = log($r['equity'] / $r['start_equity']); }
        }
        foreach ($p['cases'] as $id => $d) {
            if ($id === 'maximum') { continue; } $v = [];
            foreach ($read($dir . '/continuous_p' . $phase . '_' . $id . '_curve.json') as $r) {
                if (isset($ref[$r['date']])) { $v[$r['date']] = log($r['equity'] / $r['start_equity']) - $ref[$r['date']]; }
            }
            if (array_keys($ref) !== array_keys($v)) { throw new RuntimeException('Unaligned joint comparison.'); }
            $matrix[$name . '/' . $id . '/phase' . $phase] = array_values($v);
        }
    }
}
$extra = []; $code = 0; exec('php ' . escapeshellarg($root . '/tests/opportunity_cost_stress.php') . ' 2>&1', $extra, $code);
if ($code !== 0) { throw new RuntimeException(implode("\n", $extra)); }
$pool = $summaries['opportunity_calendar_20260910']; $main = $summaries['opportunity_20260910'];
$id = 'maximum_stop12__cost_band_2'; $audit = $pool['audit'][$id];
$fixed = $pool['audit']['maximum_stop12__cost_band_1'];
$review = ['completed_at' => gmdate(DATE_ATOM), 'script_sha256' => hash_file('sha256', __FILE__),
    'replays' => $main['primary_replays'] + $main['audit_replays'] + $pool['primary_replays'] + $pool['audit_replays'],
    'new_alternatives' => 80, 'control_configurations' => 4, 'regression_tests' => count($main['tests']) + 1,
    'extra_test' => ['exit_code' => $code, 'output' => implode("\n", $extra), 'sha256' => hash_file('sha256', $root . '/tests/opportunity_cost_stress.php')],
    'prefix_passes' => $main['prefix_passes'] + $pool['prefix_passes'], 'old_curve_matches' => $main['old_curve_matches'] + $pool['old_curve_matches'],
    'frozen_code_checks' => $integrity, 'joint_multiplicity' => M::run($matrix, 20, 1000, 20260910),
    'multiplicity_limit' => 'Joint correction covers 228 current matched-context comparisons. Does not remove prior searches, adaptive-family selection, retrospective universe or publication bias. Subset p=0.043 is not independent confirmation.',
    'candidate' => ['id' => $id, 'status' => 'research_candidate_not_approved', 'base' => 'balanced__global_confirmed_f6156ed86a11',
        'recipe' => ['standing_stop_pct' => 0.12, 'position_exit_cooldown_sessions' => 1, 'phase_count' => 3,
            'opportunity_policy' => ['family' => 'cost_band', 'window' => 20, 'strength' => 2.0],
            'inherit' => 'All other chosen-maximum parameters including global DD18 confirmed reentry, dynamic downside policy, S5TW/SVXY sizing and VVIX confirmation. Equal initial capital across phases; no periodic cross-sleeve capital transfer.'],
        'results' => $pool['summary'][$id], 'qualification' => $pool['summary'][$id]['continuous']['phase0']['qualification'],
        'stress' => $audit, 'fixed_threshold_cost60' => ['explanation' => 'At 60bps, multiplier 1 exactly preserves the nominal 30bps x2 switching threshold. This isolates higher charged execution costs without increasing that threshold; holdings still respond causally to costs and risk state.',
            '2021' => $fixed['cost60_2021'][0], '2023' => $fixed['cost60_2023'][0]],
        'not_done' => ['Independent post-selection forward observation', 'Full point-in-time universe with delistings',
            'Corporate-action cash/share ledger', 'Candidate-specific minute/NBBO execution and paper parity qualification']],
    'operational_identity_unchanged' => true, 'orders_submitted' => 0, 'paper_activation' => false, 'live_activation' => false];
A::write($out . '/final_review.json', $review); A::write($root . '/docs/research_results/opportunity_final_20260910.json', $review);
echo json_encode(array_diff_key($review, ['candidate' => true]), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
