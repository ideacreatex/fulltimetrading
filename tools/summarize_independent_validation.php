#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Research\AlgorithmTrendResearch;
use FulltimeTrading\Trading\TacticalImplementationIdentity;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__);
$read = static fn (string $file): array => json_decode(file_get_contents($root . '/' . $file), true, 512, JSON_THROW_ON_ERROR);
$paths = ['main' => 'var/reports/independent_audit_20260908/results.json',
    'earlier' => 'var/reports/independent_earlier_20260908/results.json',
    'attribution' => 'var/reports/data_attribution_20260908/results.json',
    'opening' => 'var/reports/opening_audit_20260908/results.json'];
$reports = array_map($read, $paths);
$manifest = $read('var/reports/independent_data_20260908/manifest.json');
$protocol = $read('var/reports/independent_data_20260908/protocol.json');
foreach ($reports as $report) {
    if (!isset($report['finished_at']) || $report['operational_identity_unchanged'] !== true) {
        throw new RuntimeException('Incomplete or operationally unsafe audit.');
    }
}
if (TacticalImplementationIdentity::current($root, $protocol['profile']) !== $protocol['operational_identity']) {
    throw new RuntimeException('Current operational identity changed.');
}
$attributionRuns = array_sum(array_map('count', $reports['attribution']['replays']));
$openingRuns = $openingValid = 0;
foreach ($reports['opening']['replays'] as $source) {
    foreach ($source as $rows) {
        $openingRuns += count($rows);
        $openingValid += count(array_filter($rows, static fn (array $r): bool => $r['valid_for_sample']));
    }
}
$summary = ['generated_at' => gmdate(DATE_ATOM), 'status' => 'INDEPENDENT_VALIDATION_FAILED',
    'new_parameter_search' => false, 'models' => array_keys($protocol['cases']),
    'completed_replays' => $reports['main']['completed_replays'] + $reports['earlier']['completed_replays'] + $attributionRuns + $openingRuns,
    'replay_counts' => ['source_and_transfer' => $reports['main']['completed_replays'], 'earlier' => $reports['earlier']['completed_replays'],
        'attribution' => $attributionRuns, 'opening' => $openingRuns, 'opening_valid_for_sample' => $openingValid],
    'additional_stock_universe_size' => count($protocol['additional_universe']),
    'expanded_stock_universe_size' => count(array_unique(array_merge($protocol['original_universe'], $protocol['additional_universe']))),
    'downloaded_daily_bars' => array_sum(array_column($manifest['snapshots'], 'bars')),
    'minute_sample_sessions' => count($reports['attribution']['minute_plan']['sessions']),
    'results_30bps' => [], 'earlier_30bps' => [], 'attribution_30bps' => [],
    'source_hashes' => [], 'deployed' => false, 'operational_identity_unchanged' => true,
    'unperformed' => ['Survivorship-free point-in-time membership and delisting returns.',
        'Truly unseen future completed sessions after the frozen candidate selection.',
        'A full corporate-action cash/share ledger and resolution of differing WDC adjustment factors.',
        'Full-period intraday executions and market impact.'],
    'limitations' => ['Independent distributors still describe the same previously studied market path.',
        'Earlier and expanded tests use retrospective fixed universes, not survivorship-free data.',
        'All-adjusted bars are a sensitivity replay, not proof of executable total return.']];
foreach ($paths as $id => $path) { $summary['source_hashes'][$path] = hash_file('sha256', $root . '/' . $path); }
$metric = static fn (array $m): array => ['total_return_pct' => 100 * $m['return'], 'cagr_pct' => 100 * $m['cagr'],
    'max_drawdown_pct' => 100 * $m['max_drawdown']];
foreach ($reports['main']['scenarios'] as $id => $scenario) {
    foreach ($scenario['cases'] as $case => $costs) {
        $summary['results_30bps'][$id][$case] = $metric($costs[30]['metrics']['full']) +
            ['qualification' => $costs[30]['historical_qualification'] ?? null];
    }
}
foreach ($reports['earlier']['scenarios'] as $id => $scenario) {
    foreach ($scenario['cases'] as $case => $costs) {
        $summary['earlier_30bps'][$id][$case] = $metric($costs[30]['metrics']['full']);
    }
}
foreach ($reports['attribution']['replays'] as $id => $cases) {
    foreach ($cases as $case => $row) { $summary['attribution_30bps'][$id][$case] = $metric($row['metrics']['full']); }
}
$summary['opening'] = $reports['opening']['replays'];
$summary['quotes'] = ['windows' => 0, 'valid_quotes' => 0, 'invalid_quotes' => 0, 'truncated_days' => [], 'maximum_symbol_day_p95_full_spread_bps' => 0];
foreach ($reports['opening']['quotes'] as $date => $day) {
    if ($day['truncated']) { $summary['quotes']['truncated_days'][] = $date; }
    foreach ($day['symbols'] as $row) {
        $summary['quotes']['windows']++;
        $summary['quotes']['valid_quotes'] += $row['valid_quotes'];
        $summary['quotes']['invalid_quotes'] += $row['invalid_quotes'];
        $summary['quotes']['maximum_symbol_day_p95_full_spread_bps'] = max(
            $summary['quotes']['maximum_symbol_day_p95_full_spread_bps'], $row['full_spread_bps_p95'] ?? 0);
    }
}
foreach (glob($root . '/var/reports/opening_audit_20260908/quotes_*.json') as $file) {
    $summary['source_hashes'][substr($file, strlen($root) + 1)] = hash_file('sha256', $file);
}
$prior = $read('var/reports/algorithm_trends_20260908/results.json');
$priorCombo = $read('var/reports/algorithm_combinations_20260908/results.json');
foreach (['baseline' => $prior['baseline'], 'previous_best' => $prior['previous_best'],
    'new_best' => $priorCombo['combo_prior_best_downside_size_0p5_dynamic']] as $case => $old) {
    foreach ([20, 30, 40] as $cost) {
        $new = $reports['main']['scenarios']['original_2021_2026']['cases'][$case][$cost]['metrics']['full'];
        foreach (['cagr', 'return', 'max_drawdown'] as $key) {
            if (abs($new[$key] - $old['costs'][$cost]['metrics']['full'][$key]) > 1.0e-9) {
                throw new RuntimeException('Original result failed to reproduce: ' . $case . '/' . $cost . '/' . $key);
            }
        }
    }
}
$summary['original_results_reproduced_all_three_costs'] = true;
$summary['new_best_independent_gates'] = [
    'yahoo' => $reports['main']['scenarios']['yahoo_2021_2026']['cases']['new_best'][30]['historical_qualification'],
    'alpaca_all_adjusted' => $reports['main']['scenarios']['sip_all_2021_2026']['cases']['new_best'][30]['historical_qualification']];
if ($summary['new_best_independent_gates']['yahoo']['qualifies'] || $summary['new_best_independent_gates']['alpaca_all_adjusted']['qualifies']) {
    throw new RuntimeException('The negative summary no longer matches the underlying gates.');
}
$output = $root . '/docs/research_results/independent_validation_20260908.json';
if (file_exists($output)) { throw new RuntimeException('Do not overwrite completed validation evidence.'); }
AlgorithmTrendResearch::write($output, $summary);
echo json_encode(['output' => $output, 'status' => $summary['status'], 'replays' => $summary['completed_replays'],
    'daily_bars' => $summary['downloaded_daily_bars'], 'quotes' => $summary['quotes']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
