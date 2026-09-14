#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Research\AdaptiveResearchFactory;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__);
$read = static fn (string $p): array => json_decode((string) file_get_contents($root . '/' . $p), true, 512, JSON_THROW_ON_ERROR);
$profile = require $root . '/config/tactical_rotation.php';
$structural = 'var/reports/hybrid_rebound_20260907_v2';
$combined = 'var/reports/hybrid_reentry_combinations_20260907';
$batches = [
    'structural' => $read($structural . '/results.json'),
    'combined' => $read($combined . '/results.json'),
    'sensitivity' => $read('var/reports/hybrid_reentry_sensitivity_20260907.json')['results'],
];
$unique = [];
$canonical = function (mixed $value) use (&$canonical): mixed {
    if (!is_array($value)) { return $value; }
    if (!array_is_list($value)) { ksort($value, SORT_STRING); }
    return array_map($canonical, $value);
};
$audits = 0;
foreach ($batches as $batch => $rows) {
    foreach ($rows as $id => $row) {
        if (array_keys($row['costs']) !== [20, 30, 40]) { throw new RuntimeException('Incomplete candidate: ' . $id); }
        $effective = AdaptiveResearchFactory::make($profile, $row['changes'], 30.0)->config();
        $hash = hash('sha256', json_encode($canonical($effective), JSON_THROW_ON_ERROR));
        $unique[$hash]['aliases'][] = $batch . ':' . $id;
        $unique[$hash]['costs'] = $row['costs'];
        $audits += 3;
    }
}
$reentries = array_filter($batches['structural'], static fn (array $r): bool =>
    $r['family'] === 'trough_reentry' || str_starts_with($r['family'], 'bullish_reentry:'));
$events = static fn (array $r): array => array_merge(...array_values($r['costs'][30]['reentry_events']));
$baseline = $batches['structural']['baseline']['costs'][30]['metrics']['full'];
$candidates = [];
foreach ([['structural', 'baseline'], ['structural', 'fixed_pause_1'], ['structural', 'reentry_trend_5_0.5'],
    ['structural', 'hysteresis_3'], ['combined', 'sma50_0.25_day2_1'], ['combined', 'sma50_0.4_day2_1'], ['combined', 'sma50_0.5_day2_1'],
    ['sensitivity', 'day_0.02_pause_5_scale_1']] as [$batch, $id]) {
    $r = $batches[$batch][$id];
    $candidates[$id] = ['changes' => $r['changes'], 'costs' => $r['costs'],
        'unique_reentry_signal_dates_30bps' => array_values(array_unique(array_column($events($r), 'signal_date')))];
}
$report = [
    'generated_at' => gmdate(DATE_ATOM), 'period' => ['2021-01-04', '2026-09-04'],
    'data_sha256' => $read($structural . '/protocol.json')['data']['merged']['canonical_sha256'],
    'batches' => array_map('count', $batches), 'candidate_cost_replays_including_repeats' => $audits,
    'distinct_effective_configs_including_baseline' => count($unique),
    'distinct_configs_passing_20_and_30_bps' => count(array_filter($unique, static fn (array $r): bool =>
        $r['costs'][20]['qualification']['qualifies'] && $r['costs'][30]['qualification']['qualifies'])),
    'standalone_reentry' => ['cases' => count($reentries),
        'zero_release_cases_at_30bps' => count(array_filter($reentries, static fn (array $r): bool => $events($r) === [])),
        'higher_full_cagr_than_baseline_at_30bps' => count(array_filter($reentries, static fn (array $r): bool =>
            $r['costs'][30]['metrics']['full']['cagr'] > $baseline['cagr'] + 1.0e-12))],
    'candidates' => $candidates,
    'robustness' => [
        'structural' => array_diff_key($read($structural . '/structural_robustness.json'), ['loo' => true]),
        'combined' => array_diff_key($read($combined . '/structural_robustness.json'), ['loo' => true]),
        'finalist_pause5' => $read($combined . '/finalist_pause5_loo.json'),
    ],
    'bootstrap_combined_050_vs_baseline' => $read($combined . '/bootstrap_day2_050.json'),
    'bootstrap_combined_050_vs_matched_no_reentry' => $read($combined . '/bootstrap_day2_050_matched.json'),
    'bootstrap_finalist_pause5_vs_baseline' => $read($combined . '/bootstrap_finalist_pause5.json'),
    'limits' => ['Known history and many comparisons, not untouched OOS.',
        'Four sleeve releases on one date are one market event, not four independent confirmations.',
        'Surviving, hand-selected stock universe; no point-in-time delisted universe has been established.',
        'Daily-bar next-open fills and fixed cost stresses are models, not real execution evidence.',
        'Paper model NAV is not the broker fill ledger.'],
    'production_approved' => false, 'adaptive_rules_deployed' => false,
];
$destination = $root . '/docs/research_results/hybrid_v4_rebound_20260907.json';
file_put_contents($destination, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
echo json_encode(array_diff_key($report, array_flip(['candidates', 'robustness', 'bootstrap_combined_050_vs_baseline', 'bootstrap_combined_050_vs_matched_no_reentry', 'bootstrap_finalist_pause5_vs_baseline'])), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
