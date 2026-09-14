#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Research\AlgorithmTrendResearch;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__);
$read = static fn ($p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
$path = $root . '/var/reports/';
$files = ['main' => 'breadth_volatility_20260909/results.json', 'refinement' => 'breadth_volatility_refinement_20260909/results.json',
    'verification' => 'breadth_volatility_verification_20260909/results.json', 'final_audit' => 'breadth_volatility_final_audit_20260909/results.json',
    'readiness' => 'breadth_volatility_demo_readiness_20260909/readiness.json'];
$data = $sources = [];
foreach ($files as $id => $file) { $data[$id] = $read($path . $file); $sources[$id] = ['file' => 'var/reports/' . $file, 'sha256' => hash_file('sha256', $path . $file)]; }
$main = $data['main'];
$refine = $data['refinement'];
$candidate = 'svxy200_0.5_high80_10_1.25_vvix120_1';
$primary = $main['primary']['yahoo'][30];
$summary = ['generated_at' => gmdate(DATE_ATOM), 'status' => 'RESEARCH_IMPROVEMENT_FOUND_DEPLOYMENT_NOT_APPROVED',
    'replays' => $main['replays'] + $refine['replays'] + $data['verification']['replays'] + $data['final_audit']['replays'],
    'tested_parameter_configurations' => ['hybrid' => count($primary) - 3, 'adaptive_refinement' => count($refine['primary']['yahoo'][30]),
        'standalone_event' => count($main['event']['yahoo'][30]) - 3, 'rejected_before_replay' => count($main['rejected_configurations']),
        'static_initial_capital_mixes' => array_sum(array_map('count', $data['final_audit']['corrected_static_capital_mix']))],
    'periods' => ['hybrid' => ['2021-01-04', '2026-09-04'], 'earlier' => ['2017-01-03', '2020-12-31'], 'event' => ['2018-02-28', '2026-09-04']],
    'metrics_yahoo_30bps' => ['baseline' => $primary['control_baseline']['metrics']['full'],
        'prior_research_best' => $primary['control_new_best']['metrics']['full'],
        'hindsight_maximum' => $primary[$data['final_audit']['hindsight_maximum_id']]['metrics']['full'],
        'balanced_candidate' => $refine['primary']['yahoo'][30][$candidate]['metrics']['full']],
    'balanced_candidate_id' => $candidate,
    'balanced_candidate_rules' => ['Initial research model is prior new_best, not the deployed paper strategy.',
        'First daily high >=80 after prior high <80 raises the desired size multiplier to 1.25 for 10 signal sessions; existing gross cap still applies.',
        'SVXY close at or below its 200-session SMA caps that multiplier at 0.5.',
        'VVIX cap disabled in this candidate; adding a high-VVIX cap did not improve this grid.',
        'Next-open execution on existing cadence; no operational gate override or manual order.'],
    'balanced_candidate_qualification' => $refine['primary']['yahoo'][30][$candidate]['qualification'],
    'balanced_candidate_replication' => [], 'balanced_candidate_expanded' => $refine['expanded'][$candidate],
    'balanced_candidate_earlier' => $data['final_audit']['refinement_earlier'][$candidate],
    'balanced_candidate_extra_session_lag' => $data['final_audit']['refinement_extra_session_lag'][$candidate],
    'multiple_testing' => $refine['pooled_multiplicity'], 'event_counts' => $main['event_counts'], 'svxy_stress' => $main['svxy_stress'],
    'prefix_checks' => ['synthetic_external_grid' => 285, 'actual_hybrid_selected' => $data['verification']['prefix_checks'] + $refine['prefix_checks']],
    'readiness' => array_intersect_key($data['readiness'], array_flip(['tests_passed', 'tests_total', 'ready_for_order_enabled_demo', 'broker', 'runtime_run',
        'daemons', 'status_export_agent', 'intents', 'fill_audits', 'telegram', 'blockers', 'operational_identity_unchanged'])), 'sources' => $sources];
foreach ($refine['replication'] as $source => $costs) { foreach ($costs as $cost => $cases) { $summary['balanced_candidate_replication'][$source][$cost] = $cases[$candidate]; } }
AlgorithmTrendResearch::write($root . '/docs/research_results/breadth_volatility_20260909.json', $summary);
echo 'Summary: ', $summary['replays'], " replays, no demo activation.\n";
