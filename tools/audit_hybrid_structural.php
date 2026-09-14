#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Research\AdaptiveResearchFactory;
use FulltimeTrading\Research\HybridV4Research;
use FulltimeTrading\Research\OfflineHybridDataset;

require dirname(__DIR__) . '/bootstrap.php';
$options = getopt('', ['directory:', 'candidates:']);
$directory = (string) ($options['directory'] ?? '');
$ids = array_values(array_unique(array_merge(['baseline'], explode(',', (string) ($options['candidates'] ?? '')))));
$ids = array_values(array_filter($ids));
$read = static fn (string $file): array => json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
$protocol = $read($directory . '/protocol.json');
$screen = is_file($directory . '/train_screen.json') ? $read($directory . '/train_screen.json') : null;
$selection = $screen !== null ? $read($directory . '/selection.json') : null;
$results = $read($directory . '/results.json');
if ($screen !== null && !hash_equals($selection['screen_sha256'], hash_file('sha256', $directory . '/train_screen.json'))) {
    throw new RuntimeException('Training selection evidence changed.');
}
$root = dirname(__DIR__);
foreach (($protocol['implementation'] ?? $protocol['source_sha256']) as $path => $hash) {
    if (!hash_equals($hash, hash_file('sha256', str_starts_with($path, '/') ? $path : $root . '/' . $path))) {
        throw new RuntimeException('Research implementation changed since trial: ' . $path);
    }
}
$audits = 0;
foreach ($results as $id => $row) {
    if ($row['changes'] !== ($protocol['cases'][$id]['changes'] ?? $protocol['cases'][$id])
        || ($screen !== null && ($row['changes'] !== $screen[$id]['changes']
        || $row['costs']['30']['metrics']['train'] !== $screen[$id]['train']))) {
        throw new RuntimeException('Candidate or exact causal training prefix changed: ' . $id);
    }
    $audits += count($row['costs']);
}
$end = $protocol['periods']['later_replay'][1] ?? '2026-09-04';
$dataset = OfflineHybridDataset::load($root, $end);
// JSON persistence normalizes whole-valued floats (150.0 -> 150).
if (json_encode($dataset['provenance'], JSON_THROW_ON_ERROR) !== json_encode($protocol['data'], JSON_THROW_ON_ERROR)
    || (isset($protocol['files']) && json_encode($dataset['files'], JSON_THROW_ON_ERROR) !== json_encode($protocol['files'], JSON_THROW_ON_ERROR))) {
    throw new RuntimeException('Market data differs from the experiment.');
}
if ($screen === null) {
    $trainBars = HybridV4Research::truncateBars($dataset['bars'], '2023-12-29');
    foreach ($results as $id => $row) {
        $tester = AdaptiveResearchFactory::make($dataset['profile'], $row['changes'], 30.0);
        $prefix = $tester->run($trainBars, '2021-01-04', '2023-12-29');
        // Compare JSON values after persistence, including integer/float normalization.
        if (json_encode($tester->metrics($prefix['curve']), JSON_THROW_ON_ERROR)
            !== json_encode($row['costs']['30']['metrics']['train'], JSON_THROW_ON_ERROR)) {
            throw new RuntimeException('Independent training prefix failed: ' . $id);
        }
        unset($prefix, $tester);
    }
    unset($trainBars);
}
$parityOriginal = HybridV4Research::backtester($dataset['profile'], [], 30.0)->run($dataset['bars'], '2021-01-04', $end);
$parityAdaptive = AdaptiveResearchFactory::make($dataset['profile'], [], 30.0)->run($dataset['bars'], '2021-01-04', $end);
if ($parityOriginal['curve'] !== $parityAdaptive['curve'] || $parityOriginal['next_targets'] !== $parityAdaptive['next_targets']) {
    throw new RuntimeException('Adaptive default no longer reproduces operational historical baseline.');
}
unset($parityOriginal, $parityAdaptive);
$symbols = [];
foreach (HybridV4Research::backtester($dataset['profile'], [], 30.0)->config() as $book) {
    $symbols = array_merge($symbols, $book['config']['universe']);
}
$symbols = array_values(array_unique($symbols));
sort($symbols);
$output = $directory . '/structural_robustness.json';
if (file_exists($output)) {
    throw new RuntimeException('Do not overwrite a completed robustness audit.');
}
$report = ['generated_at' => gmdate(DATE_ATOM), 'baseline_exact_parity' => true,
    'unique_cases' => count($protocol['cases']), 'exact_training_prefixes' => count($results),
    'candidate_cost_audits' => $audits, 'loo_cost_bps' => 30, 'loo_candidates' => $ids,
    'results_sha256' => hash_file('sha256', $directory . '/results.json'),
    'limitations' => ['LOO is a perturbation of previously examined history, not an untouched OOS test.',
        'Candidates named for this diagnostic audit may have been chosen after viewing their full-period outcomes.']];
foreach ($ids as $id) {
    if (!isset($results[$id])) { throw new InvalidArgumentException('Unknown candidate: ' . $id); }
    foreach ($symbols as $symbol) {
        $changes = array_replace($results[$id]['changes'], ['exclude_symbols' => [$symbol]]);
        $tester = AdaptiveResearchFactory::make($dataset['profile'], $changes, 30.0);
        $result = $tester->run($dataset['bars'], '2021-01-04', $end);
        $report['loo'][$id][$symbol] = [
            'full' => $tester->metrics($result['curve']),
            'validation' => $tester->metrics($result['curve'], '2024-01-01', '2026-01-01'),
            'later_2026' => $tester->metrics($result['curve'], '2026-01-01'),
            'post_freeze' => $tester->metrics($result['curve'], '2026-07-16'),
        ];
        file_put_contents($output . '.tmp', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        rename($output . '.tmp', $output);
        unset($tester, $result);
    }
    printf("LOO %-48s %d symbols completed\n", $id, count($symbols));
}
echo "Verified and wrote $output\n";
