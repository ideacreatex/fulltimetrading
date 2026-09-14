#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Research\AlgorithmTrendResearch;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__);
$read = static fn ($p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
$dir = $root . '/var/reports/alpaca_portfolio_stops_20260909';
$result = $read($dir . '/results.json');
$protocol = $read($dir . '/protocol.json');
$readiness = $read($root . '/var/reports/alpaca_stop_readiness_20260909/readiness.json');
$rows = $result['primary']['all'][30]['stop_level'];
$sorted = $rows;
uasort($sorted, static fn ($a, $b): int => $b['metrics']['full']['cagr'] <=> $a['metrics']['full']['cagr']);
$maximum = array_key_first($sorted);
$summary = ['generated_at' => gmdate(DATE_ATOM), 'primary_source' => $protocol['primary'], 'dates' => $protocol['primary_dates'],
    'new_parameter_hypotheses' => count($protocol['cases']), 'replays' => $result['replays'],
    'replay_breakdown' => ['primary_with_controls' => count($rows), 'robustness' => 85, 'adverse_stop_fill' => 6, 'actual_prefix' => $result['prefix_checks']],
    'full_period_maximum_hindsight_only' => $maximum, 'primary_qualified' => $result['primary_qualified'],
    'train_selected' => $result['train_selected'], 'multiplicity' => $result['multiplicity'],
    'primary' => [], 'train_selected_robustness' => [], 'ready_for_order_enabled_demo' => $readiness['ready_for_order_enabled_demo'],
    'tests' => [$readiness['tests_passed'], $readiness['tests_total']], 'broker' => $readiness['broker'], 'daemons' => $readiness['daemons'],
    'minute_audit' => $readiness['minute_audit'], 'blockers' => $readiness['blockers']];
foreach ($rows as $id => $row) {
    $annual = [];
    $compounded = 1.0;
    foreach ($row['annual'] as $year => $metrics) {
        $annual[$year] = ['return' => $metrics['return'], 'max_drawdown' => $metrics['max_drawdown'], 'points' => $metrics['points']];
        $compounded *= 1.0 + $metrics['return'];
    }
    if (abs($compounded - (1.0 + $row['metrics']['full']['return'])) > max(1.0e-9, abs($compounded) * 1.0e-10)) {
        throw new RuntimeException('Annual returns do not compound to the full-period result: ' . $id);
    }
    $summary['primary'][$id] = ['definition' => $protocol['cases'][$id] ?? ['control' => true],
        'full' => $row['metrics']['full'], 'train' => $row['metrics']['train'], 'validation' => $row['metrics']['validation'],
        'later' => $row['metrics']['later'], 'annual' => $annual, 'qualification' => $row['qualification'], 'sleeve_stop_events' => count($row['stops']),
        'global_events' => count($row['global_events']), 'global_blocked_sessions' => $row['global_blocked_sessions']];
}
$summary['annual_compounding_checks'] = count($rows);
$summary['annual_return_convention'] = 'Actual simulated return within each calendar year, not annualized CAGR. 2026 ends on 2026-09-04. No equity reset between years.';
$summary['deployed_profile_comparison_id'] = 'control_original';
$summary['deployed_profile_comparison_note'] = 'Historical replay of the deployed profile on the common SIP all / 30bps research data; not broker P/L and not the operational SIP/IEX split-adjusted report.';
foreach ($result['replication'] as $source => $costs) {
    foreach ($costs as $cost => $fills) {
        foreach ($fills as $fill => $cases) {
            foreach ($cases as $id => $row) { $summary['train_selected_robustness'][$source][$cost][$fill][$id] = $row['metrics']['full']; }
        }
    }
}
$expanded = $result['replication']['expanded'][30]['stop_level'];
$values = array_map(static fn ($id): float => $expanded[$id]['metrics']['full']['cagr'], $result['train_selected']);
$summary['expanded_train_selected_positive'] = count(array_filter($values, static fn ($v): bool => $v > 0));
$summary['expanded_train_selected_cagr_range'] = [min($values), max($values)];
$summary['maximum_vs_balanced_cagr_percentage_points'] = ($rows[$maximum]['metrics']['full']['cagr'] - $rows['control_balanced']['metrics']['full']['cagr']) * 100;
$summary['operational_identity_unchanged'] = $readiness['operational_identity_unchanged'];
$summary['research_code_unchanged'] = $readiness['research_code_unchanged'];
AlgorithmTrendResearch::write($root . '/docs/research_results/alpaca_stops_20260909.json', $summary);
echo json_encode(array_intersect_key($summary, array_flip(['new_parameter_hypotheses', 'replays', 'full_period_maximum_hindsight_only',
    'maximum_vs_balanced_cagr_percentage_points', 'expanded_train_selected_positive', 'expanded_train_selected_cagr_range', 'tests', 'ready_for_order_enabled_demo'])), JSON_PRETTY_PRINT), "\n";
