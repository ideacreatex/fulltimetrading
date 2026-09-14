#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Research\HybridV4Research;

require dirname(__DIR__) . '/bootstrap.php';

$directories = array_slice($argv, 1);
if ($directories === []) {
    throw new InvalidArgumentException('Provide one or more completed experiment directories.');
}
$read = static fn (string $path): array => json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$hash = null;
$cases = $audits = $loo = [];
$prefixCount = 0;
foreach ($directories as $directory) {
    $protocol = $read($directory . '/protocol.json');
    $train = $read($directory . '/train_screen.json');
    $selection = $read($directory . '/selection.json');
    $results = $read($directory . '/results.json');
    $dataHash = $protocol['data_provenance']['merged']['canonical_sha256'];
    if ($hash !== null && $dataHash !== $hash) {
        throw new RuntimeException('Experiments did not use the same market data.');
    }
    $hash = $dataHash;
    if (!hash_equals($selection['train_screen_sha256'], hash_file('sha256', $directory . '/train_screen.json'))
        || $selection['selected_before_later_period_evaluation'] !== HybridV4Research::shortlist($train)) {
        throw new RuntimeException('Training selection evidence does not match: ' . $directory);
    }
    foreach ($train as $id => $row) {
        if ($row['changes'] !== $protocol['cases'][$id]) {
            throw new RuntimeException('A candidate was changed after the protocol: ' . $id);
        }
        $cases[$id] = true;
    }
    foreach ($results as $id => $row) {
        if ($train[$id]['train'] !== $row['costs']['30']['metrics']['train']) {
            throw new RuntimeException('Adding later bars changed the training prefix: ' . $id);
        }
        $prefixCount++;
        foreach ($row['costs'] as $cost => $result) {
            $key = $id . ':' . $cost;
            if (isset($audits[$key]) && $audits[$key] !== $result) {
                throw new RuntimeException('A repeated replay was not deterministic: ' . $key);
            }
            $audits[$key] = $result;
        }
    }
    if (is_file($directory . '/leave_one_out.json')) {
        foreach ($read($directory . '/leave_one_out.json') as $id => $rows) {
            foreach ($rows as $symbol => $row) {
                $key = $id . ':' . $symbol;
                if (isset($loo[$key]) && $loo[$key] !== $row) {
                    throw new RuntimeException('Repeated leave-one-out result changed: ' . $key);
                }
                $loo[$key] = $row;
            }
        }
    }
}
$source = $read(dirname(__DIR__) . '/var/reports/tactical_rotation/latest.json');
if (($source['periods']['end'] ?? null) === $protocol['later_replay'][1]) {
    foreach (['20', '30'] as $cost) {
        foreach (['cagr', 'return', 'max_drawdown', 'ex_top5_days_cagr', 'annualized_turnover'] as $metric) {
            if (abs($source['cost_stress'][$cost]['full'][$metric] - $audits['baseline:' . $cost]['metrics']['full'][$metric]) > 1.0e-9) {
                throw new RuntimeException('Research does not reproduce the operational baseline: ' . $metric);
            }
        }
    }
    $baselineCheck = 'matches current operational replay at 20 and 30 bps';
} else {
    $baselineCheck = 'skipped: operational report has advanced to another date';
}
echo json_encode([
    'verified' => true,
    'data_sha256' => $hash,
    'unique_hypotheses_excluding_baseline' => count($cases) - 1,
    'unique_candidate_cost_audits' => count($audits),
    'unique_leave_one_out_audits' => count($loo),
    'exact_training_prefix_comparisons' => $prefixCount,
    'baseline' => $baselineCheck,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
