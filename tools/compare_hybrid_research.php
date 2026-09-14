#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Backtest\BlockBootstrapAnalyzer;

require dirname(__DIR__) . '/bootstrap.php';

$options = getopt('', ['baseline:', 'candidate:']);
foreach (['baseline', 'candidate'] as $key) {
    if (!isset($options[$key]) || !is_file($options[$key])) {
        throw new InvalidArgumentException('Provide --baseline and --candidate curve JSON files.');
    }
}
$baseline = json_decode(file_get_contents($options['baseline']), true, 512, JSON_THROW_ON_ERROR);
$candidate = json_decode(file_get_contents($options['candidate']), true, 512, JSON_THROW_ON_ERROR);
if ($baseline === [] || array_column($baseline, 'date') !== array_column($candidate, 'date')) {
    throw new InvalidArgumentException('Paired comparison requires identical nonempty ordered sessions.');
}
$report = [
    'method' => 'paired circular block bootstrap of candidate / baseline relative wealth',
    'seed' => 20260907,
    'iterations' => 2000,
    'interpretation' => 'q05 to q95 is a 90% resampling interval, not a multiple-testing-corrected confidence claim.',
    'baseline_sha256' => hash_file('sha256', $options['baseline']),
    'candidate_sha256' => hash_file('sha256', $options['candidate']),
];
foreach (['full' => '2021-01-04', 'validation' => '2024-01-01', 'later_2026' => '2026-01-01', 'post_freeze' => '2026-07-16'] as $period => $start) {
    $ratio = [];
    $previousDate = null;
    foreach ($baseline as $index => $row) {
        $other = $candidate[$index];
        if ($row['date'] >= $start && ($period !== 'validation' || $row['date'] < '2026-01-01')) {
            if ($ratio === []) {
                $ratio[] = [
                    'date' => $previousDate ?? (new DateTimeImmutable($row['date']))->modify('-1 day')->format('Y-m-d'),
                    'equity' => $other['start_equity'] / $row['start_equity'],
                ];
            }
            if ($row['equity'] <= 0.0 || $other['equity'] <= 0.0) {
                throw new InvalidArgumentException('Relative wealth requires positive equity.');
            }
            $ratio[] = ['date' => $row['date'], 'equity' => $other['equity'] / $row['equity']];
        }
        $previousDate = $row['date'];
    }
    foreach ([5, 20, 60] as $block) {
        $report['periods'][$period]['blocks'][(string) $block] = (new BlockBootstrapAnalyzer())->analyze($ratio, 2000, $block, 20260907);
    }
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
