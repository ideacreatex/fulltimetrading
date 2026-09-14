#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Backtest\TacticalRotationQualification;
use FulltimeTrading\Data\FrozenSipIexDailyBarsProvider;
use FulltimeTrading\Data\MarketDataProvider;
use FulltimeTrading\Data\VerifiedCacheSnapshotMarketDataProvider;
use FulltimeTrading\Research\HybridV4Research;

require dirname(__DIR__) . '/bootstrap.php';

$options = getopt('', ['end:', 'output-dir:', 'suite:', 'audit-cases:', 'robustness']);
$root = dirname(__DIR__);
$profile = require $root . '/config/tactical_rotation.php';
$paper = require $root . '/config/tactical_paper.php';
$data = $paper['data'];
$end = (string) ($options['end'] ?? '2026-09-04');
$out = (string) ($options['output-dir'] ?? $root . '/var/reports/hybrid_research_20260907');
if (file_exists($out)) {
    throw new RuntimeException('Use a new output directory so a recorded experiment is never overwritten.');
}
$suite = (string) ($options['suite'] ?? 'core');
$cases = HybridV4Research::cases($suite);
$supplementary = isset($options['audit-cases']) ? explode(',', (string) $options['audit-cases']) : [];
foreach ($supplementary as $id) {
    if (!array_key_exists($id, $cases)) {
        throw new InvalidArgumentException('Supplementary audit must use a predefined case: ' . $id);
    }
}
$symbols = array_values(array_unique(array_merge($profile['universe'], ['SPY', 'QQQ'])));
sort($symbols, SORT_STRING);
$offline = new class($root . '/var/cache', $data['fresh_cache_namespace']) implements MarketDataProvider {
    public array $manifest = [];
    public function __construct(private string $cache, private string $namespace) {}
    public function getBars(array $symbols, string $timeframe, string $start, string $end): array
    {
        sort($symbols, SORT_STRING);
        $file = $this->cache . '/' . sha1($this->namespace . '|' . implode(',', $symbols) . '|' . $timeframe . '|' . $start . '|' . $end) . '.json';
        if (!is_file($file)) {
            throw new RuntimeException('Required offline cache is missing: ' . basename($file));
        }
        $hash = hash_file('sha256', $file);
        $provider = new VerifiedCacheSnapshotMarketDataProvider($this->cache, $this->namespace, $hash, 'Alpaca', 'iex', 'split');
        $bars = $provider->getBars($symbols, $timeframe, $start, $end);
        $this->manifest[] = $provider->provenance();
        return $bars;
    }
};
$provider = new FrozenSipIexDailyBarsProvider(
    new VerifiedCacheSnapshotMarketDataProvider($root . '/var/cache', $data['cache_namespace'], $data['historical_snapshot_sha256'], 'Alpaca', 'sip', 'split'),
    $offline, $data['historical_cutoff'], $data['fresh_cache_namespace'], $data['cross_feed_audit'],
);
$bars = $provider->getBars($symbols, '1Day', '2020-01-01', $end);
if (!mkdir($out, 0775, true)) {
    throw new RuntimeException('Cannot create research output directory.');
}
$protocol = [
    'created_at' => gmdate(DATE_ATOM),
    'suite' => $suite,
    'supplementary_audits' => $supplementary,
    'supplementary_selection_status' => 'exploratory sensitivity checks; do not change the training shortlist',
    'purpose' => 'offline_research_no_execution',
    'order_submission_enabled' => false,
    'train' => ['2021-01-04', '2023-12-29'],
    'selection' => 'top three training CAGR / absolute drawdown at 30 bps, subject to unchanged training gates',
    'validation' => ['2024-01-01', '2025-12-31'],
    'later_replay' => ['2026-01-01', $end],
    'limitations' => [
        'The fixed universe and baseline were previously researched; no period is claimed untouched.',
        '2026 and post-freeze performance are audits, never inputs to this training shortlist.',
        'Research uses daily-open target weights, not broker-confirmed whole-share fills.',
        'SIP historical data switch to IEX after 2026-07-15; split adjusted, no dividend reinvestment.',
        'A historical winner does not authorize changing a paper run or passing its forward gate.',
    ],
    'cases' => $cases,
    'strategy_sha256' => hash_file('sha256', $root . '/config/tactical_rotation.php'),
    'implementation_sha256' => array_combine(
        ['single', 'ensemble', 'research', 'runner'],
        array_map(static fn (string $path): string => hash_file('sha256', $root . '/' . $path), [
            'src/Backtest/CausalTacticalRotationBacktester.php', 'src/Backtest/CausalTacticalRotationEnsembleBacktester.php',
            'src/Research/HybridV4Research.php', 'tools/research_hybrid_v4.php',
        ]),
    ),
    'data_provenance' => $provider->provenance(),
    'iex_files' => $offline->manifest,
];
researchWrite($out . '/protocol.json', $protocol);
$trainBars = HybridV4Research::truncateBars($bars, '2023-12-29');
$screen = [];
foreach ($cases as $id => $changes) {
    $tester = HybridV4Research::backtester($profile, $changes, 30.0);
    $run = $tester->run($trainBars, '2021-01-04', '2023-12-29');
    $metrics = $tester->metrics($run['curve']);
    $screen[$id] = ['changes' => $changes, 'train' => $metrics, 'train_failed_gates' => HybridV4Research::trainFailures($metrics, $profile['validation'])];
    researchWrite($out . '/train_screen.json', $screen);
    printf("train %-32s CAGR %7.2f%% DD %7.2f%% gates=%s\n", $id, 100 * $metrics['cagr'], 100 * $metrics['max_drawdown'], implode(',', $screen[$id]['train_failed_gates']));
    unset($run, $tester);
}
$selected = HybridV4Research::shortlist($screen);
researchWrite($out . '/selection.json', ['selected_before_later_period_evaluation' => $selected, 'train_screen_sha256' => hash_file('sha256', $out . '/train_screen.json')]);
$auditIds = array_values(array_unique(array_merge($suite === 'core' ? ['baseline', 'cooldown_20'] : ['baseline'], $selected, $supplementary)));
$results = [];
foreach ($auditIds as $id) {
    foreach ([20.0, 30.0, 40.0] as $cost) {
        $tester = HybridV4Research::backtester($profile, $cases[$id], $cost);
        $run = $tester->run($bars, '2021-01-04', $end);
        $curve = $run['curve'];
        $metrics = [];
        foreach ([
            'train' => ['2021-01-04', '2024-01-01'], 'validation' => ['2024-01-01', '2026-01-01'],
            'later_2026' => ['2026-01-01', null], 'full' => [null, null],
            'post_freeze' => ['2026-07-16', null], 'since_activation' => ['2026-08-18', null],
        ] as $period => [$start, $stop]) {
            $metrics[$period] = $tester->metrics($curve, $start, $stop);
        }
        $annual = [];
        for ($year = 2021; $year <= (int) substr($end, 0, 4); $year++) {
            $annual[$year] = $tester->metrics($curve, $year . '-01-01', ($year + 1) . '-01-01');
        }
        $qualification = (new TacticalRotationQualification($profile['validation']))->evaluate($metrics['train'], $metrics['validation'], $metrics['later_2026'], $metrics['full'], $annual);
        $results[$id]['costs'][(string) $cost] = [
            'metrics' => $metrics, 'annual' => $annual, 'qualification' => $qualification,
            'activity_since_freeze' => HybridV4Research::activity($run['sleeve_curves'], '2026-07-16'),
            'activity_since_activation' => HybridV4Research::activity($run['sleeve_curves'], '2026-08-18'),
            'next_targets' => $run['next_targets'],
        ];
        $results[$id]['changes'] = $cases[$id];
        $results[$id]['train_selected'] = in_array($id, $selected, true);
        $compactCurve = array_map(static fn (array $row): array => array_intersect_key($row, array_flip([
            'date', 'start_equity', 'equity', 'equity_low', 'equity_high', 'gross_close', 'gross_bound', 'turnover',
        ])), $curve);
        researchWrite($out . '/' . $id . '_' . (int) $cost . '_curve.json', $compactCurve);
        researchWrite($out . '/results.json', $results);
        printf("audit %-32s cost=%2.0f full=%7.2f%% DD=%7.2f%% post-freeze=%7.2f%% gates=%s\n", $id, $cost,
            100 * $metrics['full']['cagr'], 100 * $metrics['full']['max_drawdown'], 100 * $metrics['post_freeze']['return'], implode(',', $qualification['failed_gates']));
        unset($run, $tester, $curve);
    }
}
if (isset($options['robustness'])) {
    $robustness = [];
    foreach (array_values(array_unique(array_merge(['baseline'], $selected, $supplementary))) as $id) {
        foreach ($profile['universe'] as $symbol) {
            $tester = HybridV4Research::backtester($profile, array_merge($cases[$id], ['exclude_symbols' => [$symbol]]), 30.0);
            $run = $tester->run($bars, '2021-01-04', $end);
            $robustness[$id][$symbol] = [
                'train' => $tester->metrics($run['curve'], null, '2024-01-01'),
                'validation' => $tester->metrics($run['curve'], '2024-01-01', '2026-01-01'),
                'later_2026' => $tester->metrics($run['curve'], '2026-01-01'),
                'full' => $tester->metrics($run['curve']),
                'post_freeze' => $tester->metrics($run['curve'], '2026-07-16'),
            ];
            researchWrite($out . '/leave_one_out.json', $robustness);
            printf("robustness %-32s excluded=%s\n", $id, $symbol);
            unset($run, $tester);
        }
    }
}
echo 'Research artifacts: ' . $out . "\n";

function researchWrite(string $file, array $data): void
{
    $temp = $file . '.tmp';
    if (file_put_contents($temp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)) === false || !rename($temp, $file)) {
        throw new RuntimeException('Cannot write research artifact: ' . $file);
    }
}
