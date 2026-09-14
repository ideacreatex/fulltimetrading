#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Research\AdaptiveResearchFactory;
use FulltimeTrading\Research\AlgorithmTrendResearch;

require dirname(__DIR__) . '/bootstrap.php';
$options = getopt('', ['directory:', 'extra-dir:', 'output-prefix:']);
$directory = (string) ($options['directory'] ?? 'var/reports/algorithm_trends_20260908');
$extra = (string) ($options['extra-dir'] ?? 'var/reports/algorithm_combinations_20260908');
$output = (string) ($options['output-prefix'] ?? 'docs/research_results/algorithm_trends_20260908');
if (file_exists($output . '.json') || file_exists($output . '.md')) { throw new RuntimeException('Use a new output prefix.'); }
$read = static fn (string $p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
$audit = $read($directory . '/audit.json');
$all = $read($directory . '/results.json') + $read($extra . '/results.json');
$profile = require dirname(__DIR__) . '/config/tactical_rotation.php';
$configHashes = [];
foreach ($all as $id => $row) { $configHashes[$id] = hash('sha256', json_encode(AdaptiveResearchFactory::make($profile, $row['changes'], 30.0)->config())); }
if (count(array_unique($configHashes)) !== 194 || count($all) !== 194
    || !hash_equals($audit['results_sha256'], hash_file('sha256', $directory . '/results.json'))
    || !hash_equals($audit['extra_completion']['results_sha256'], hash_file('sha256', $extra . '/results.json'))) {
    throw new RuntimeException('Count, uniqueness or final audit evidence mismatch.');
}
$report = ['generated_at' => gmdate(DATE_ATOM), 'period' => ['2021-01-04', '2026-09-04'],
    'result_kind' => 'historical model; not broker P/L or a forecast',
    'counts' => ['new_variants' => 192, 'economic_families' => 13, 'first_stage' => 156, 'combinations' => 36,
        'controls' => 2, 'distinct_effective_configs' => count(array_unique($configHashes)),
        'cost_replays' => $audit['total_cost_replays'], 'physical_training_prefixes' => $audit['exact_curve_prefixes_total'],
        'loo_runs' => $audit['loo_runs'], 'distinct_new_paths_at_30bps' => $audit['distinct_new_paths_at_30bps'],
        'baseline_identical_new_variants' => count($audit['baseline_identical_paths'])],
    'train_winner' => $audit['train_winner'], 'extra_train_winner' => $audit['extra_train_winner'],
    'pass_20_and_30' => $audit['pass_20_and_30'], 'families' => $audit['families'],
    'paired_bootstrap' => $audit['paired_bootstrap'], 'multiplicity' => $audit['multiplicity'],
    'audit_sha256' => hash_file('sha256', $directory . '/audit.json'),
    'sources_document' => 'docs/ALGORITHM_TRENDS_SOURCES_2026-09-08.md',
    'limitations' => ['Previously reviewed history and a fixed hand-selected survivor universe.',
        'Full-period winner selected after viewing results; second-stage family is adaptive.',
        'Bootstrap only covers declared candidate paths, not universe and previous search choices.',
        'No demonstrated advantage over previous best after multiplicity or in paired interval.',
        'Fixed bps daily-bar execution, not actual fills or nonlinear market impact.'],
    'production_approved' => false, 'deployed' => false];
foreach ([20, 30, 40] as $cost) {
    $ranked = $all;
    uasort($ranked, static fn (array $a, array $b): int => $b['costs'][$cost]['metrics']['full']['cagr'] <=> $a['costs'][$cost]['metrics']['full']['cagr']);
    $best = array_key_first($ranked);
    $report['max_by_cost'][$cost] = ['id' => $best] + $all[$best]['costs'][$cost];
}
$winner = $report['max_by_cost'][30]['id'];
foreach ($all as $id => $row) {
    $report['variants'][$id] = ['family' => $row['family'], 'changes' => $row['changes'], 'effective_config_sha256' => $configHashes[$id]];
    foreach ($row['costs'] as $cost => $run) {
        $report['variants'][$id]['costs'][$cost] = ['full' => $run['metrics']['full'], 'post_freeze' => $run['metrics']['post_freeze'], 'qualification' => $run['qualification']];
    }
    if ($row['family'] !== 'control') {
        foreach (['baseline', 'previous_best'] as $ref) {
            $report['counts']['higher_cagr_than_' . $ref] = ($report['counts']['higher_cagr_than_' . $ref] ?? 0)
                + (int) ($row['costs'][30]['metrics']['full']['cagr'] > $all[$ref]['costs'][30]['metrics']['full']['cagr']);
        }
    }
}
foreach (['baseline', 'previous_best'] as $ref) {
    $base = $all[$ref]['costs'][30]['metrics']['full'];
    $best = $all[$winner]['costs'][30]['metrics']['full'];
    $report['winner_delta'][$ref] = ['cagr_percentage_points' => 100.0 * ($best['cagr'] - $base['cagr']),
        'total_return_percentage_points' => 100.0 * ($best['return'] - $base['return']),
        'terminal_wealth_relative_change' => (1.0 + $best['return']) / (1.0 + $base['return']) - 1.0,
        'drawdown_percentage_points' => 100.0 * ($best['max_drawdown'] - $base['max_drawdown'])];
}
foreach ($audit['loo'] as $id => $rows) {
    $worst = $rows;
    uasort($worst, static fn (array $a, array $b): int => $a['full']['max_drawdown'] <=> $b['full']['max_drawdown']);
    $symbol = array_key_first($worst);
    $report['loo_summary'][$id] = ['runs' => count($rows), 'worst_drawdown_excluding' => $symbol,
        'worst_drawdown' => $rows[$symbol]['full']['max_drawdown'],
        'minimum_cagr' => min(array_map(static fn (array $r): float => $r['full']['cagr'], $rows))];
}
AlgorithmTrendResearch::write($output . '.json', $report);
$lines = ['# Algorithm variants: complete result ledger', '',
    'Period: 2021-01-04 through 2026-09-04. All figures below are historical model percentages, not account P/L.',
    '192 new variants, two controls, 582 cost replays, 194 exact physical training-prefix checks, 100 leave-one-stock-out replays.',
    'This is an exploratory, adaptive search on previously reviewed history. No candidate was deployed.', '',
    '| Variant | CAGR 30 bps | Total return | Maximum drawdown | PASS 20 and 30 bps |',
    '|---|---:|---:|---:|---|'];
uasort($all, static fn (array $a, array $b): int => $b['costs'][30]['metrics']['full']['cagr'] <=> $a['costs'][30]['metrics']['full']['cagr']);
foreach ($all as $id => $row) {
    $m = $row['costs'][30]['metrics']['full'];
    $lines[] = sprintf('| %s | %.4f%% | %.4f%% | %.4f%% | %s |', $id, 100 * $m['cagr'], 100 * $m['return'], 100 * $m['max_drawdown'],
        in_array($id, $report['pass_20_and_30'], true) ? 'PASS' : 'FAIL');
}
if (file_put_contents($output . '.md', implode("\n", $lines) . "\n") === false) { throw new RuntimeException('Cannot write ledger.'); }
echo json_encode(['winner' => $winner, 'counts' => $report['counts'], 'delta' => $report['winner_delta'], 'output' => $output], JSON_PRETTY_PRINT) . "\n";
