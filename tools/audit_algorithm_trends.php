#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Backtest\BlockBootstrapAnalyzer;
use FulltimeTrading\Research\AdaptiveResearchFactory;
use FulltimeTrading\Research\AlgorithmTrendResearch;
use FulltimeTrading\Research\HybridV4Research;
use FulltimeTrading\Research\OfflineHybridDataset;
use FulltimeTrading\Research\ResearchMultiplicityAudit;
use FulltimeTrading\Trading\TacticalImplementationIdentity;

require dirname(__DIR__) . '/bootstrap.php';
$options = getopt('', ['directory:', 'extra-dir:']);
$directory = (string) ($options['directory'] ?? '');
$read = static fn (string $file): array => json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
$completion = $read($directory . '/completion.json');
$protocol = $read($directory . '/protocol.json');
$results = $read($directory . '/results.json');
$selection = $read($directory . '/selection.json');
$curveDirectories = array_fill_keys(array_keys($results), $directory);
$extraCompletion = $extraSelection = null;
$root = dirname(__DIR__);
if (file_exists($directory . '/audit.json')) {
    throw new RuntimeException('Do not overwrite a completed audit.');
}
foreach ($protocol['implementation'] as $path => $hash) {
    if (!hash_equals($hash, hash_file('sha256', $root . '/' . $path))) {
        throw new RuntimeException('Research source drift: ' . $path);
    }
}
if (!hash_equals($completion['results_sha256'], hash_file('sha256', $directory . '/results.json'))
    || !hash_equals($selection['screen_sha256'], hash_file('sha256', $directory . '/train_screen.json'))) {
    throw new RuntimeException('Recorded experiment evidence changed.');
}
if (isset($options['extra-dir'])) {
    $extra = (string) $options['extra-dir'];
    $extraProtocol = $read($extra . '/protocol.json');
    $extraCompletion = $read($extra . '/completion.json');
    $extraSelection = $read($extra . '/selection.json');
    $extraResults = $read($extra . '/results.json');
    if (array_intersect_key($results, $extraResults) !== []
        || !hash_equals($extraCompletion['results_sha256'], hash_file('sha256', $extra . '/results.json'))
        || !hash_equals($extraSelection['screen_sha256'], hash_file('sha256', $extra . '/train_screen.json'))
        || !hash_equals($extraProtocol['parent_protocol_sha256'], hash_file('sha256', $directory . '/protocol.json'))
        || $extraProtocol['data'] !== $protocol['data'] || $extraProtocol['operational_identity'] !== $protocol['operational_identity']) {
        throw new RuntimeException('Extra experiment is not an intact comparable family.');
    }
    foreach ($extraProtocol['implementation'] as $path => $hash) {
        if (!hash_equals($hash, hash_file('sha256', $root . '/' . $path))) { throw new RuntimeException('Extra research source drift.'); }
    }
    $results += $extraResults;
    $protocol['cases'] += $extraProtocol['cases'];
    $curveDirectories += array_fill_keys(array_keys($extraResults), $extra);
}
$dataset = OfflineHybridDataset::load($root, '2026-09-04');
if (json_encode($dataset['provenance']) !== json_encode($protocol['data'])
    || json_encode(TacticalImplementationIdentity::current($root, $dataset['profile'])) !== json_encode($protocol['operational_identity'])) {
    throw new RuntimeException('Data or operational identity changed.');
}
$baseRun = HybridV4Research::backtester($dataset['profile'], [], 30.0)->run($dataset['bars'], '2021-01-04', '2026-09-04');
$researchRun = AdaptiveResearchFactory::make($dataset['profile'], [], 30.0)->run($dataset['bars'], '2021-01-04', '2026-09-04');
if ($baseRun['curve'] !== $researchRun['curve'] || $baseRun['next_targets'] !== $researchRun['next_targets']) {
    throw new RuntimeException('Default research replay no longer matches operational history.');
}
unset($baseRun, $researchRun);
$ranked = $results;
uksort($ranked, static fn (string $a, string $b): int => $results[$b]['costs']['30']['metrics']['full']['cagr'] <=> $results[$a]['costs']['30']['metrics']['full']['cagr'] ?: strcmp($a, $b));
$qualified = array_keys(array_filter($ranked, static fn (array $r): bool => $r['costs']['20']['qualification']['qualifies'] && $r['costs']['30']['qualification']['qualifies']));
$best = array_key_first($ranked);
$newRanked = array_filter($ranked, static fn (array $r): bool => $r['family'] !== 'control');
$bestNew = array_key_first($newRanked);
$focus = array_values(array_unique(array_filter(['baseline', 'previous_best', $selection['train_winner'], $extraSelection['train_winner'] ?? null, $best, $bestNew, $qualified[0] ?? null])));
$report = ['generated_at' => gmdate(DATE_ATOM), 'baseline_exact_operational_parity' => true,
    'completion' => $completion, 'extra_completion' => $extraCompletion, 'train_winner' => $selection['train_winner'],
    'extra_train_winner' => $extraSelection['train_winner'] ?? null,
    'new_variants_total' => $completion['new_variants'] + ($extraCompletion['new_variants'] ?? 0),
    'total_cost_replays' => $completion['candidate_cost_replays'] + ($extraCompletion['candidate_cost_replays'] ?? 0),
    'exact_curve_prefixes_total' => $completion['exact_curve_training_prefixes'] + ($extraCompletion['exact_curve_training_prefixes'] ?? 0),
    'hindsight_full_winner' => $best, 'best_new_variant' => $bestNew, 'pass_20_and_30' => $qualified,
    'focus' => $focus, 'results_sha256' => hash_file('sha256', $directory . '/results.json'),
    'audit_implementation' => ['tools/audit_algorithm_trends.php' => hash_file('sha256', __FILE__),
        'src/Research/ResearchMultiplicityAudit.php' => hash_file('sha256', $root . '/src/Research/ResearchMultiplicityAudit.php')],
    'production_approved' => false, 'deployed' => false,
    'inference_limit' => 'The extra family was chosen after viewing partial first-stage outcomes. Union bootstrap is descriptive and does not correct this adaptive family construction or prior searches.'];
foreach ($results as $id => $row) {
    if ($row['changes'] !== $protocol['cases'][$id]['changes'] || count($row['costs']) !== 3) {
        throw new RuntimeException('Incomplete or changed candidate: ' . $id);
    }
    if ($row['family'] === 'control') { continue; }
    $family = $row['family'];
    $report['families'][$family]['cases'] = 1 + ($report['families'][$family]['cases'] ?? 0);
    $m = $row['costs']['30']['metrics']['full'];
    $report['families'][$family]['higher_cagr_than_baseline'] = ($report['families'][$family]['higher_cagr_than_baseline'] ?? 0) + (int) ($m['cagr'] > $results['baseline']['costs']['30']['metrics']['full']['cagr']);
    $familyBest = $report['families'][$family]['best_id'] ?? null;
    if ($familyBest === null || $m['cagr'] > $results[$familyBest]['costs']['30']['metrics']['full']['cagr']) {
        $report['families'][$family]['best_id'] = $id;
    }
}
$curves = [];
foreach ($results as $id => $row) {
    $curves[$id] = $read($curveDirectories[$id] . '/' . $id . '_30_curve.json');
    if (array_column($curves[$id], 'date') !== array_column($curves['baseline'], 'date')) {
        throw new RuntimeException('Candidate sessions are not aligned.');
    }
}
$pathHashes = [];
$noops = [];
foreach ($newRanked as $id => $row) {
    $pathHashes[$id] = hash('sha256', json_encode($curves[$id]));
    if ($curves[$id] === $curves['baseline']) { $noops[] = $id; }
}
$report['distinct_new_paths_at_30bps'] = count(array_unique($pathHashes));
$report['baseline_identical_paths'] = $noops;
foreach (['baseline', 'previous_best'] as $reference) {
    foreach (['full' => '2021-01-05', 'after_train' => '2024-01-01'] as $period => $start) {
        $matrix = [];
        foreach ($newRanked as $id => $row) {
            foreach ($curves[$id] as $i => $point) {
                if ($point['date'] < $start) { continue; }
                $base = $curves[$reference][$i];
                $matrix[$id][] = log($point['equity'] / $point['start_equity']) - log($base['equity'] / $base['start_equity']);
            }
        }
        foreach ([20, 60] as $block) {
            $report['multiplicity'][$reference][$period][$block] = ResearchMultiplicityAudit::run($matrix, $block, 1000);
        }
        printf("multiple-testing audit %s/%s completed\n", $reference, $period);
    }
}
foreach ($focus as $id) {
    $report['candidates'][$id] = $results[$id];
    if ($id !== 'baseline') {
        foreach (['baseline', 'previous_best'] as $reference) {
            if ($id === $reference) { continue; }
            $ratio = [];
            foreach ($curves[$id] as $i => $point) {
                $ratio[] = ['date' => $point['date'], 'equity' => $point['equity'] / $curves[$reference][$i]['equity']];
            }
            foreach ([5, 20, 60] as $block) {
                $report['paired_bootstrap'][$id][$reference][$block] = (new BlockBootstrapAnalyzer())->analyze($ratio, 2000, $block, 20260908);
            }
        }
    }
    foreach ($dataset['profile']['universe'] as $symbol) {
        $changes = array_replace($results[$id]['changes'], ['exclude_symbols' => [$symbol]]);
        $tester = AdaptiveResearchFactory::make($dataset['profile'], $changes, 30.0);
        $run = $tester->run($dataset['bars'], '2021-01-04', '2026-09-04');
        $report['loo'][$id][$symbol] = [
            'full' => $tester->metrics($run['curve']),
            'validation' => $tester->metrics($run['curve'], '2024-01-01', '2026-01-01'),
            'later_2026' => $tester->metrics($run['curve'], '2026-01-01'),
        ];
        AlgorithmTrendResearch::write($directory . '/audit_progress.json', $report);
        unset($tester, $run);
    }
    printf("LOO %s: 20 exclusions completed\n", $id);
}
$report['loo_runs'] = count($focus) * count($dataset['profile']['universe']);
$report['finished_at'] = gmdate(DATE_ATOM);
AlgorithmTrendResearch::write($directory . '/audit.json', $report);
echo "Verified full algorithm audit: $directory/audit.json\n";
