#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Research\AlgorithmTrendResearch as A;
use FulltimeTrading\Research\BreadthVolatilityResearch as B;
use FulltimeTrading\Research\DailyDataAudit as D;
use FulltimeTrading\Research\StandardEtfResearch as S;
use FulltimeTrading\Trading\TacticalImplementationIdentity;

require dirname(__DIR__) . '/bootstrap.php';
set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$root = dirname(__DIR__); $out = $root . '/var/reports/standard_etfs_20260914';
$read = static fn (string $p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
$files = ['stocks' => $root . '/var/reports/alpaca_stops_data_20260909/sip_all.json',
    'etfs' => $root . '/var/reports/cross_asset_data_20260909/alpaca_sip_all.json'];
$profile = require $root . '/config/tactical_rotation.php';
foreach (['minimum_train_cagr', 'minimum_validation_cagr'] as $key) {
    if (!isset($profile['validation'][$key]) || !is_numeric($profile['validation'][$key]) || $profile['validation'][$key] <= 0) {
        throw new RuntimeException('Required qualification threshold missing: ' . $key);
    }
}
$prior = $root . '/var/reports/opportunity_20260910';
$candidate = $root . '/var/reports/opportunity_calendar_20260910';
$parent = $read($candidate . '/protocol.json');
if (TacticalImplementationIdentity::current($root, $profile) !== $parent['operational_identity']) { throw new RuntimeException('Operational identity changed.'); }
$scenarios = ['continuous30' => ['2021-01-04', '2026-09-04', 30], 'fresh2023_30' => ['2023-01-03', '2026-09-04', 30],
    'continuous60' => ['2021-01-04', '2026-09-04', 60], 'fresh2023_60' => ['2023-01-03', '2026-09-04', 60],
    'earlier30' => ['2017-01-03', '2020-12-31', 30], 'earlier60' => ['2017-01-03', '2020-12-31', 60]];
$bases = $baseFiles = [];
foreach (['continuous30' => 'continuous', 'fresh2023_30' => 'fresh2023', 'continuous60' => 'cost60_2021', 'fresh2023_60' => 'cost60_2023'] as $scenario => $name) {
    $baseFiles['selected_maximum'][$scenario] = $prior . '/' . $name . '_p0_maximum_curve.json';
    // A frozen 2x30bps switching hurdle equals 1x60bps, not a re-optimized doubled hurdle.
    $strength = str_contains($scenario, '60') ? '1' : '2';
    $baseFiles['candidate'][$scenario] = $candidate . '/' . $name . '_p0_maximum_stop12__cost_band_' . $strength . '_curve.json';
}
$hashes = array_map(static fn ($f): string => hash_file('sha256', $f), $files);
foreach ($baseFiles as $base => $paths) {
    foreach ($paths as $scenario => $path) { $hashes[$base . '/' . $scenario] = hash_file('sha256', $path); $bases[$base][$scenario] = $read($path); }
}
$code = [__FILE__, $root . '/src/Research/StandardEtfResearch.php', $root . '/src/Research/DailyDataAudit.php',
    $root . '/src/Research/BreadthVolatilityResearch.php', $root . '/tests/standard_etf_research.php'];
$protocol = ['cases' => S::definitions(), 'scenarios' => $scenarios, 'satellite_allocations' => [.05, .10, .20],
    'input_sha256' => $hashes, 'code_sha256' => array_combine(array_map(static fn ($f): string => substr($f, strlen($root) + 1), $code),
        array_map(static fn ($f): string => hash_file('sha256', $f), $code)), 'operational_identity' => $parent['operational_identity'],
    'data' => 'Alpaca SIP adjustment=all, frozen September 9. No Yahoo prices. End 2026-09-04 for exact anchor comparability.',
    'selection' => '108 configurations frozen before results. Family leader selected only on 2021-2023 standalone Calmar at 30bps. All variants also screened, no independent holdout claims.',
    'execution' => 'Fractional-share unlevered cash account; close signals, next open, costs paid from capital via exact post-cost rebalance; zero cash yield. Trend/dual: 21-session decision schedule. Vol: 5-session. Donchian/RSI: daily entry/exit and 21-session resizing.',
    'risk' => 'Static initial capital satellite, not added leverage; no cash transfers. Satellite NOT governed by inherited stock portfolio circuit. Summed asset daily extremes are conservative, not simultaneous observations.',
    'limits' => ['Previously viewed history, many prior searches.', 'Adjusted bars are not a full corporate-action cash/share ledger.',
        'No market impact, auction queue or whole-share execution; historical performance is not paper readiness.',
        'ETF baskets are economic choices made today, not a historical point-in-time fund selection study.',
        'Standard algorithms implement transparent variants, not replications of any paper performance claims.'],
    'sources' => ['https://www.aqr.com/Insights/Research/Journal-Article/A-Century-of-Evidence-on-Trend-Following-Investing',
        'https://www.quantconnect.com/docs/v2/writing-algorithms/strategy-library', 'https://arxiv.org/abs/2607.19497',
        'https://arxiv.org/abs/2607.00883', 'https://docs.alpaca.markets/us/docs/paper-trading'], 'orders_submitted' => 0];
if (!is_dir($out)) { mkdir($out, 0775, true); }
if (file_exists($out . '/protocol.json')) {
    if ($read($out . '/protocol.json') != $protocol) { throw new RuntimeException('Frozen protocol drift.'); }
} else { A::write($out . '/protocol.json', $protocol); }
$sha = hash_file('sha256', $out . '/protocol.json');
$universe = ['SPY', 'SHY'];
foreach (S::definitions() as $config) { $universe = array_merge($universe, $config['symbols']); }
$raw = array_replace(array_intersect_key($read($files['stocks']), array_flip(['SPY', 'QQQ'])), $read($files['etfs']));
$bars = D::decode(array_intersect_key($raw, array_flip($universe))); unset($raw);
$engine = new S($bars);
$short = [];
foreach ($bars as $s => $series) { $short[$s] = array_values(array_filter($series, static fn ($b): bool => D::session($b) <= '2023-12-29')); }
$prefixEngine = new S($short); unset($short);
$metrics = static function (array $curve): array {
    $annual = [];
    for ($year = (int) substr($curve[0]['date'], 0, 4); $year <= (int) substr(end($curve)['date'], 0, 4); $year++) {
        $annual[$year] = B::metrics($curve, "$year-01-01", ($year + 1) . '-01-01');
    }
    return ['full' => B::metrics($curve), 'train' => B::metrics($curve, '2021-01-04', '2024-01-01'),
        'validation' => B::metrics($curve, '2024-01-01', '2026-01-01'), 'holdout' => B::metrics($curve, '2026-01-01'), 'annual' => $annual];
};
$baseMetrics = [];
foreach ($bases as $base => $rows) { foreach ($rows as $scenario => $curve) { $baseMetrics[$base][$scenario] = $metrics($curve); } }
$rows = []; $i = 0;
foreach ($protocol['cases'] as $id => $config) {
    $file = $out . '/' . $id . '.json';
    if (file_exists($file)) {
        $rows[$id] = $read($file);
        if ($rows[$id]['protocol_sha256'] !== $sha) { throw new RuntimeException('Resume result drift.'); }
        $i++; continue;
    }
    $row = ['id' => $id, 'family' => $config['family'], 'protocol_sha256' => $sha, 'standalone' => [], 'mixes' => []];
    foreach ($scenarios as $scenario => [$start, $end, $cost]) {
        $run = $engine->run($config, $start, $end, $cost);
        $annualTrades = [];
        foreach ($run['trades'] as $trade) { $year = substr($trade['exit'], 0, 4); $annualTrades[$year] = ($annualTrades[$year] ?? 0) + 1; }
        $row['standalone'][$scenario] = $metrics($run['curve']) + ['closed_trades' => count($run['trades']), 'annual_closed' => $annualTrades, 'simulated_orders' => count($run['orders'])];
        if ($scenario === 'continuous30') {
            $prefix = $prefixEngine->run($config, $start, '2023-12-29', $cost);
            $row['historical_prefix_pass'] = $prefix['curve'] === array_values(array_filter($run['curve'], static fn ($r): bool => $r['date'] <= '2023-12-29'));
            if (!$row['historical_prefix_pass']) { throw new RuntimeException('Historical lookahead: ' . $id); }
        }
        foreach ($bases as $base => $curves) {
            if (!isset($curves[$scenario])) { continue; }
            foreach ($protocol['satellite_allocations'] as $allocation) {
                $mixed = B::mix($curves[$scenario], $run['curve'], $allocation);
                $row['mixes'][$base][(int) round(100 * $allocation)][$scenario] = $metrics($mixed);
            }
        }
    }
    A::write($file, $row); $rows[$id] = $row;
    printf("%d/108 %s CAGR %.2f%% DD %.2f%%\n", ++$i, $id, 100 * $row['standalone']['continuous30']['full']['cagr'], 100 * $row['standalone']['continuous30']['full']['max_drawdown']);
}
$leaders = $winners = $returnWinners = $passesNecessary = [];
$best = $leastDrawdown = [];
foreach ($rows as $id => $row) {
    $m = $row['standalone']['continuous30']['train']; $score = $m['cagr'] / max(.05, abs($m['max_drawdown']));
    if (!isset($leaders[$row['family']]) || $score > $leaders[$row['family']]['train_calmar']) { $leaders[$row['family']] = ['id' => $id, 'train_calmar' => $score, 'standalone' => $row['standalone']]; }
    foreach ($row['mixes'] as $base => $allocations) {
        foreach ($allocations as $allocation => $scenariosMetrics) {
            $allReturn = $allRisk = true;
            foreach ($scenariosMetrics as $scenario => $m) {
                $b = $baseMetrics[$base][$scenario];
                $allReturn = $allReturn && $m['full']['cagr'] > $b['full']['cagr'] + 1e-10;
                $allRisk = $allRisk && $m['full']['max_drawdown'] >= $b['full']['max_drawdown'] - 1e-10;
            }
            $entry = ['id' => $id, 'allocation_pct' => $allocation, 'metrics' => $scenariosMetrics];
            if ($allReturn) { $returnWinners[$base][] = $entry; if ($allRisk) { $winners[$base][] = $entry; } }
            if (!isset($best[$base]) || $scenariosMetrics['fresh2023_30']['full']['cagr'] > $best[$base]['metrics']['fresh2023_30']['full']['cagr']) { $best[$base] = $entry; }
            if (!isset($leastDrawdown[$base]) || $scenariosMetrics['continuous30']['full']['max_drawdown'] > $leastDrawdown[$base]['metrics']['continuous30']['full']['max_drawdown']) { $leastDrawdown[$base] = $entry; }
            $m = $scenariosMetrics['continuous30'];
            if ($m['train']['cagr'] >= $profile['validation']['minimum_train_cagr'] && $m['validation']['cagr'] >= $profile['validation']['minimum_validation_cagr']) { $passesNecessary[$base][] = $entry; }
        }
    }
}
$summary = ['completed_at' => gmdate(DATE_ATOM), 'protocol_sha256' => $sha, 'variants' => count($rows), 'standalone_replays' => count($rows) * count($scenarios),
    'historical_prefix_replays' => count($rows), 'mixed_comparisons' => count($rows) * 2 * 3 * 4,
    'base_metrics' => $baseMetrics, 'train_selected_family_leaders' => $leaders,
    'improve_cagr_both_starts_both_costs' => $returnWinners, 'also_preserve_or_improve_drawdown' => $winners,
    'best_fresh2023_mix_posthoc' => $best, 'least_drawdown_mix_posthoc' => $leastDrawdown,
    'pass_necessary_train_and_validation_cagr_only' => $passesNecessary,
    'full_qualification_evaluated' => false, 'deployment_authorized_by_this_report' => false,
    'orders_submitted' => 0, 'limits' => $protocol['limits']];
A::write($out . '/summary.json', $summary);
echo json_encode(['variants' => count($rows), 'standalone_replays' => $summary['standalone_replays'], 'mixed_comparisons' => $summary['mixed_comparisons'],
    'joint_winners' => array_map('count', $winners), 'necessary_cagr_passes' => array_map('count', $passesNecessary)], JSON_THROW_ON_ERROR), "\n";
