#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Backtest\TacticalRotationQualification;
use FulltimeTrading\Research\AdaptiveResearchFactory;
use FulltimeTrading\Research\AlgorithmTrendResearch;
use FulltimeTrading\Research\BreadthVolatilityGrid;
use FulltimeTrading\Research\BreadthVolatilityResearch as B;
use FulltimeTrading\Research\DailyDataAudit as D;
use FulltimeTrading\Research\ResearchMultiplicityAudit;
use FulltimeTrading\Trading\TacticalImplementationIdentity;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__);
$read = static fn (string $path): array => json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$data = $root . '/var/reports/breadth_volatility_data_20260909';
$old = $root . '/var/reports/independent_data_20260908';
$out = $root . '/var/reports/breadth_volatility_20260909';
$resume = in_array('--resume', $argv, true);
if (file_exists($out) && (!$resume || file_exists($out . '/results.json'))) { throw new RuntimeException('Use a new output directory, or explicitly resume an incomplete run.'); }
$base = $read($old . '/protocol.json');
$profile = $base['profile'];
$identity = TacticalImplementationIdentity::current($root, $profile);
if ($identity !== $base['operational_identity']) { throw new RuntimeException('Operational identity changed.'); }
$hashes = [];
$load = static function (string $file) use ($read, &$hashes): array {
    $hashes[$file] = hash_file('sha256', $file);
    return $read($file);
};
foreach ([$data, $old] as $dir) {
    $manifest = $load($dir . '/manifest.json');
    if (!isset($manifest['finished_at'])) { throw new RuntimeException('Incomplete data collection.'); }
    foreach ($manifest['snapshots'] as $item) {
        if (!hash_equals($item['sha256'], hash_file('sha256', $dir . '/' . $item['file']))) { throw new RuntimeException('Data hash drift.'); }
    }
}
$assetSources = ['yahoo' => [], 'sip_split' => D::decode($load($data . '/sip_split.json')), 'sip_all' => D::decode($load($data . '/sip_all.json'))];
foreach (['SPY', 'QQQ', 'SVXY'] as $symbol) { $assetSources['yahoo'] += D::decode($load($data . '/yahoo_' . $symbol . '.json')); }
$breadthRaw = $load($data . '/s5tw.json');
$calendar = array_keys(D::indexed(['SPY' => $assetSources['yahoo']['SPY']])['SPY']);
$breadthAll = B::validateBreadth($breadthRaw, $calendar);
$breadth = array_filter($breadthAll, static fn ($d): bool => $d >= '2012-01-03', ARRAY_FILTER_USE_KEY);
$vvix = $load($root . '/var/reports/vvix_data_20260909/cboe_vvix.json');
$vvixManifest = $load($root . '/var/reports/vvix_data_20260909/manifest.json');
if (!hash_equals($vvixManifest['canonical_sha256'], hash_file('sha256', $root . '/var/reports/vvix_data_20260909/cboe_vvix.json'))) { throw new RuntimeException('VVIX hash drift.'); }
foreach (array_keys($breadth) as $date) { if ($date >= '2016-01-01' && !isset($vvix[$date])) { throw new RuntimeException('Missing official VVIX: ' . $date); } }
$vvixYahoo = D::decode($load($root . '/var/reports/vvix_data_20260909/yahoo_vvix.json'))['^VVIX'] ?? [];
$vvixDifferences = [];
foreach ($vvixYahoo as $bar) {
    $date = D::session($bar);
    if (isset($vvix[$date])) { $vvixDifferences[] = abs($vvix[$date] - $bar->close); }
}
$stockSources = ['yahoo' => []];
foreach (array_merge($base['original_universe'], ['SPY', 'QQQ']) as $symbol) {
    $all = D::decode($load($old . '/yahoo_' . $symbol . '.json'));
    $stockSources['yahoo'][$symbol] = array_values(array_filter($all[$symbol], static fn ($b): bool => D::session($b) >= '2020-01-01'));
}
$stockSources['sip_spinoff'] = D::decode($load($root . '/var/reports/data_attribution_20260908/split_spinoff.json'));
$stockSources['sip_all'] = D::decode($load($old . '/sip_all.json'));
$definitions = BreadthVolatilityGrid::definitions();
$protocol = ['frozen_before_replay' => gmdate(DATE_ATOM), 'definitions' => $definitions,
    'hybrid_candidate_count' => count($definitions), 'primary_source' => 'yahoo', 'primary_control' => 'new_best',
    'primary_period' => ['2021-01-04', '2026-09-04'], 'train' => ['2021-01-04', '2023-12-31'],
    'validation' => ['2024-01-01', '2025-12-31'], 'later' => ['2026-01-01', '2026-09-04'],
    'selection' => 'Up to two candidates per family, train CAGR / abs(train max drawdown); no validation/full ranking for shortlist.',
    'validation_sources' => ['yahoo', 'sip_spinoff', 'sip_all'], 'validation_costs_bps' => [30, 40, 60],
    'event_grid' => ['events' => B::EVENTS, 'holds' => [5, 10, 20], 'targets' => ['SPY', 'QQQ', 'SVXY'], 'confirmation' => ['none', 'spy200', 'vvix110_svxy20']],
    'event_period' => ['2018-02-28', '2026-09-04'], 'svxy_pre_2018' => 'Separate stress diagnostic; not the current objective.',
    'data_sha256' => $hashes, 'operational_identity' => $identity, 'execution_enabled' => false,
    'limitations' => ['All history has already been seen; chronological splits are not untouched forward evidence.',
        'S5TW has one historical vendor, no archived as-published vintage or independent constituent reconstruction.',
        'Fixed stock universe has survivorship and selection bias; corporate-action accounting remains unresolved.',
        'Close data assumed available before next open. Live ingestion and fail-closed freshness integration not implemented.',
        'Hybrid scale changes act on existing 3-session rebalance cadence; risk exits and existing cooldown are preserved unless explicitly varied.',
        'Event accounts have no borrowing, zero cash interest, proportional transaction costs, no intraday stops or overlapping entries.']];
if (!$resume) {
    mkdir($out, 0775, true);
    AlgorithmTrendResearch::write($out . '/protocol.json', $protocol);
} else {
    $frozen = $read($out . '/protocol.json');
    if ($frozen['definitions'] != $definitions || $frozen['data_sha256'] !== $hashes) { throw new RuntimeException('Resume protocol/data mismatch.'); }
    $protocol = $frozen;
}
$report = ['started_at' => gmdate(DATE_ATOM), 'primary' => [], 'replication' => [], 'event' => [], 'errors' => [],
    'data' => ['s5tw_sessions' => count($breadthAll), 's5tw_extra_non_equity_dates' => array_values(array_diff(array_keys($breadthRaw), $calendar)),
        'vvix_overlap' => count($vvixDifferences), 'vvix_abs_difference_p99' => D::quantile($vvixDifferences, 0.99),
        'vvix_abs_difference_max' => max($vvixDifferences), 'asset_coverage' => []], 'replays' => 0];
foreach ($assetSources as $id => $bars) { $report['data']['asset_coverage'][$id] = D::coverage($bars); }
if ($resume) {
    $report = $read($out . '/progress.json');
    $report['resumes'][] = ['at' => gmdate(DATE_ATOM), 'reason' => 'Record out-of-contract risk cases as rejected; preserve risk guards.',
        'runner_sha256' => hash_file('sha256', __FILE__)];
}
$changesFor = static function (array $definition, string $source) use ($breadth, $vvix, $assetSources): array {
    $input = $source === 'yahoo' ? $assetSources['yahoo']['SVXY'] : $assetSources['sip_split']['SVXY'];
    $first = max('2016-01-01', D::session($input[0]));
    $slice = array_filter($breadth, static fn ($d): bool => $d >= $first, ARRAY_FILTER_USE_KEY);
    return BreadthVolatilityGrid::changes($definition, $slice, $vvix, $input);
};
$curves = $logReturns = [];
$runHybrid = static function (string $id, array $changes, string $source, int $cost, string $stage) use ($profile, $stockSources, $out, &$report, &$curves, &$logReturns): array {
    if (isset($report[$stage][$source][$cost][$id])) {
        if ($stage === 'primary') {
            $cached = json_decode(file_get_contents($out . '/' . $stage . '_' . $source . '_' . $cost . '_' . $id . '.json'), true, 512, JSON_THROW_ON_ERROR);
            $logReturns[$id] = array_map(static fn ($r): float => log($r['equity'] / $r['start_equity']), array_values(array_filter($cached, static fn ($r): bool => $r['date'] >= '2024-01-01')));
            if (str_starts_with($id, 'control_')) { $curves[$id] = $cached; }
        }
        return $report[$stage][$source][$cost][$id];
    }
    $tester = AdaptiveResearchFactory::make($profile, $changes, $cost);
    $run = $tester->run($stockSources[$source], '2021-01-04', '2026-09-04');
    $curve = $run['curve'];
    $metrics = ['full' => $tester->metrics($curve), 'train' => $tester->metrics($curve, '2021-01-04', '2024-01-01'),
        'validation' => $tester->metrics($curve, '2024-01-01', '2026-01-01'), 'later' => $tester->metrics($curve, '2026-01-01')];
    $annual = [];
    for ($year = 2021; $year <= 2026; $year++) { $annual[$year] = $tester->metrics($curve, "$year-01-01", ($year + 1) . '-01-01'); }
    $qualification = (new TacticalRotationQualification($profile['validation']))->evaluate($metrics['train'], $metrics['validation'], $metrics['later'], $metrics['full'], $annual);
    $row = ['metrics' => $metrics, 'annual' => $annual, 'qualification' => $qualification, 'curve_sha256' => hash('sha256', json_encode($curve, JSON_THROW_ON_ERROR))];
    $report[$stage][$source][$cost][$id] = $row;
    if ($stage === 'primary') {
        $logReturns[$id] = array_map(static fn ($r): float => log($r['equity'] / $r['start_equity']), array_values(array_filter($curve, static fn ($r): bool => $r['date'] >= '2024-01-01')));
        if (str_starts_with($id, 'control_')) { $curves[$id] = $curve; }
    }
    $compact = array_map(static fn ($r): array => array_intersect_key($r, array_flip(['date', 'period_start_date', 'start_equity', 'equity', 'equity_low', 'equity_high', 'turnover'])), $curve);
    AlgorithmTrendResearch::write($out . '/' . $stage . '_' . $source . '_' . $cost . '_' . $id . '.json', $compact);
    $report['replays']++;
    printf("%d %s %s %d %s CAGR %.2f DD %.2f train %.2f\n", $report['replays'], $stage, $source, $cost, $id,
        $metrics['full']['cagr'] * 100, $metrics['full']['max_drawdown'] * 100, $metrics['train']['cagr'] * 100);
    return $row;
};
foreach ($base['cases'] as $id => $changes) { $runHybrid('control_' . $id, $changes, 'yahoo', 30, 'primary'); }
foreach ($definitions as $id => $definition) {
    $changes = array_replace($base['cases']['new_best'], $changesFor($definition, 'yahoo'));
    try { AdaptiveResearchFactory::make($profile, $changes, 30); }
    catch (InvalidArgumentException $e) { $report['rejected_configurations'][$id] = $e->getMessage(); continue; }
    $runHybrid($id, $changes, 'yahoo', 30, 'primary');
    if ($report['replays'] % 10 === 0) { AlgorithmTrendResearch::write($out . '/progress.json', $report); }
}
$families = [];
foreach ($definitions as $id => $definition) {
    if (!isset($report['primary']['yahoo'][30][$id])) { continue; }
    $train = $report['primary']['yahoo'][30][$id]['metrics']['train'];
    $families[$definition['family']][$id] = $train['cagr'] / max(0.05, abs($train['max_drawdown']));
}
$selected = [];
foreach ($families as $family => $scores) { arsort($scores); $selected = array_merge($selected, array_slice(array_keys($scores), 0, 2)); }
$report['train_selected'] = $selected;
AlgorithmTrendResearch::write($out . '/train_selection.json', ['selected' => $selected, 'rule' => $protocol['selection'], 'selected_at' => gmdate(DATE_ATOM)]);
$excess = [];
foreach ($definitions as $id => $_) {
    if (isset($logReturns[$id])) { $excess[$id] = array_map(static fn ($a, $b): float => $a - $b, $logReturns[$id], $logReturns['control_new_best']); }
}
$report['multiplicity_validation_and_later'] = ResearchMultiplicityAudit::run($excess, 20, 1000, 20260909);
foreach (['yahoo', 'sip_spinoff', 'sip_all'] as $source) {
    foreach ([30, 40, 60] as $cost) {
        foreach ($base['cases'] as $id => $changes) {
            if ($source === 'yahoo' && $cost === 30) { continue; }
            $runHybrid('control_' . $id, $changes, $source, $cost, 'replication');
        }
        foreach ($selected as $id) {
            if ($source === 'yahoo' && $cost === 30) { continue; }
            $changes = array_replace($base['cases']['new_best'], $changesFor($definitions[$id], $source));
            $runHybrid($id, $changes, $source, $cost, 'replication');
        }
        AlgorithmTrendResearch::write($out . '/progress.json', $report);
    }
}
$events = B::events($breadth);
$report['event_counts'] = [];
foreach ($events as $id => $signals) {
    foreach (['all' => '2012-01-03', 'current_svxy_regime' => '2018-02-28', 'hybrid_period' => '2021-01-04'] as $period => $start) {
        $report['event_counts'][$id][$period] = count(array_filter($signals, static fn ($value, $date): bool => $value && $date >= $start && $date <= '2026-09-04', ARRAY_FILTER_USE_BOTH));
    }
}
$eventDefinitions = [];
foreach (B::EVENTS as $event) {
    foreach ([5, 10, 20] as $hold) {
        foreach (['SPY', 'QQQ', 'SVXY'] as $symbol) {
            foreach (['none', 'spy200', 'vvix110_svxy20'] as $confirmation) {
                $eventDefinitions[$event . '_' . $hold . '_' . $symbol . '_' . $confirmation] = compact('event', 'hold', 'symbol', 'confirmation');
            }
        }
    }
}
foreach (['SPY', 'QQQ', 'SVXY'] as $symbol) { $eventDefinitions['buy_hold_' . $symbol] = ['event' => 'always', 'hold' => 99999, 'symbol' => $symbol, 'confirmation' => 'none']; }
foreach (['yahoo', 'sip_split', 'sip_all'] as $source) {
    $spyTrend = B::trend($assetSources[$source]['SPY'], 200);
    $svxyTrend = B::trend($assetSources[$source]['SVXY'], 20);
    foreach ($eventDefinitions as $id => $definition) {
        $signals = $definition['event'] === 'always' ? array_fill_keys(array_keys($breadth), true) : $events[$definition['event']];
        foreach ($signals as $date => $value) {
            $signals[$date] = $value && match ($definition['confirmation']) {
                'none' => true, 'spy200' => $spyTrend[$date] ?? false,
                'vvix110_svxy20' => ($vvix[$date] ?? INF) < 110 && ($svxyTrend[$date] ?? false),
            };
        }
        foreach ($source === 'yahoo' ? [30, 60] : [30] as $cost) {
            $run = B::eventReplay($assetSources[$source][$definition['symbol']], $signals, $definition['hold'], $cost, '2018-02-28', '2026-09-04');
            $report['event'][$source][$cost][$id] = ['metrics' => B::metrics($run['curve']),
                'train' => B::metrics($run['curve'], null, '2024-01-01'), 'validation' => B::metrics($run['curve'], '2024-01-01', '2026-01-01'),
                'later' => B::metrics($run['curve'], '2026-01-01'), 'closed_trades' => count($run['trades']),
                'win_rate' => count($run['trades']) > 0 ? count(array_filter($run['trades'], static fn ($t): bool => $t['net_return'] > 0)) / count($run['trades']) : null,
                'mean_trade' => count($run['trades']) > 0 ? array_sum(array_column($run['trades'], 'net_return')) / count($run['trades']) : null];
            $report['replays']++;
            if ($source === 'yahoo' && $cost === 30 && $definition['symbol'] === 'SVXY') {
                $r = B::eventReplay($assetSources[$source]['SVXY'], $signals, $definition['hold'], $cost, '2021-01-04', '2026-09-04');
                foreach ([0.05, 0.10] as $allocation) {
                    $mix = B::mix($curves['control_new_best'], $r['curve'], $allocation);
                    $report['static_capital_mix'][$id][(string) $allocation] = ['full' => B::metrics($mix), 'train' => B::metrics($mix, null, '2024-01-01'),
                        'validation' => B::metrics($mix, '2024-01-01', '2026-01-01'), 'later' => B::metrics($mix, '2026-01-01')];
                }
                $report['replays']++;
            }
        }
    }
    printf("Event source %s complete; %d replays\n", $source, $report['replays']);
    AlgorithmTrendResearch::write($out . '/progress.json', $report);
}
foreach (['old_objective' => ['2012-01-03', '2018-02-27'], 'volmageddon' => ['2018-01-02', '2018-02-27'],
    'covid' => ['2020-01-02', '2020-12-31']] as $id => [$start, $end]) {
    $r = B::eventReplay($assetSources['yahoo']['SVXY'], array_fill_keys(array_keys($breadth), true), 99999, 30, $start, $end);
    $report['svxy_stress'][$id] = B::metrics($r['curve']);
    $report['replays']++;
}
$report['completed_at'] = gmdate(DATE_ATOM);
$report['operational_identity_unchanged'] = $identity === TacticalImplementationIdentity::current($root, $profile);
$report['deployment_approved'] = false;
AlgorithmTrendResearch::write($out . '/results.json', $report);
echo "Research complete: ", $report['replays'], " replays. No deployment.\n";
