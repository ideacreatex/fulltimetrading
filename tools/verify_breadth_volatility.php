#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Research\AdaptiveResearchFactory;
use FulltimeTrading\Research\AlgorithmTrendResearch;
use FulltimeTrading\Research\BreadthVolatilityGrid;
use FulltimeTrading\Research\BreadthVolatilityResearch as B;
use FulltimeTrading\Research\DailyDataAudit as D;
use FulltimeTrading\Research\HybridV4Research;
use FulltimeTrading\Trading\TacticalImplementationIdentity;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__);
$read = static fn ($path): array => json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$out = $root . '/var/reports/breadth_volatility_verification_20260909';
$resume = in_array('--resume', $argv, true);
if (file_exists($out) && (!$resume || file_exists($out . '/results.json'))) { throw new RuntimeException('Use a new directory, or resume incomplete verification.'); }
$source = $root . '/var/reports/breadth_volatility_20260909';
$protocol = $read($source . '/protocol.json');
$selected = $read($source . '/train_selection.json')['selected'];
$old = $root . '/var/reports/independent_data_20260908';
$base = $read($old . '/protocol.json');
$data = $root . '/var/reports/breadth_volatility_data_20260909';
$svxy = D::decode($read($data . '/yahoo_SVXY.json'))['SVXY'];
$spy = D::decode($read($data . '/yahoo_SPY.json'))['SPY'];
$dates = array_values(array_filter(array_keys(D::indexed(['SPY' => $spy])['SPY']), static fn ($d): bool => $d >= '2016-01-01'));
$breadth = B::validateBreadth($read($data . '/s5tw.json'), $dates);
$vvix = $read($root . '/var/reports/vvix_data_20260909/cboe_vvix.json');
$yahooVvix = D::decode($read($root . '/var/reports/vvix_data_20260909/yahoo_vvix.json'))['^VVIX'];
$altVvix = [];
foreach ($yahooVvix as $bar) { $altVvix[D::session($bar)] = $bar->close; }
$yahoo = [];
foreach (array_merge($base['original_universe'], $base['additional_universe'], ['SPY', 'QQQ']) as $symbol) {
    $bars = D::decode($read($old . '/yahoo_' . $symbol . '.json'))[$symbol];
    $yahoo[$symbol] = array_values(array_filter($bars, static fn ($b): bool => D::session($b) >= '2020-01-01'));
}
$narrow = array_intersect_key($yahoo, array_fill_keys(array_merge($base['original_universe'], ['SPY', 'QQQ']), true));
$early = D::decode($read($old . '/sip_split.json'));
$early = array_intersect_key($early, array_fill_keys(array_merge($base['original_universe'], ['SPY', 'QQQ']), true));
$available = D::availableBy($base['original_universe'], $early, '2020-12-31');
$early = HybridV4Research::truncateBars($early, '2020-12-31');
$earlyProfile = D::withUniverse($base['profile'], $available['included']);
$expandedProfile = D::withUniverse($base['profile'], array_merge($base['original_universe'], $base['additional_universe']));
$report = ['started_at' => gmdate(DATE_ATOM), 'selection' => $selected, 'replays' => 0, 'prefix_checks' => 0,
    'results' => [], 'implementation_sha256' => [], 'assumptions' => ['Expanded universe is retrospective, not point-in-time.',
        'Earlier SIP 2017-2020 period does not include the 2025 WDC spin-off.', 'Extra-session lag delays external information only, not pre-existing rotation signals.']];
foreach (['src/Research/AdaptiveRotationBacktester.php', 'src/Research/AdaptiveRotationEnsembleBacktester.php',
    'src/Research/BreadthVolatilityGrid.php', 'src/Research/BreadthVolatilityResearch.php', 'tools/research_breadth_volatility.php', 'tools/verify_breadth_volatility.php'] as $file) {
    $report['implementation_sha256'][$file] = hash_file('sha256', $root . '/' . $file);
}
if (!$resume) {
    mkdir($out, 0775, true);
    AlgorithmTrendResearch::write($out . '/protocol.json', $report);
} else {
    $report = $read($out . '/progress.json');
    $report['resumes'][] = ['at' => gmdate(DATE_ATOM), 'reason' => 'Record incomplete Yahoo VVIX as rejected independent dataset, without filling gaps.'];
}
$completed = $resume ? array_keys($report['results']['expanded']) : [];
$cases = [];
foreach ($base['cases'] as $id => $changes) { $cases['control_' . $id] = $changes; }
foreach ($selected as $id) {
    $cases[$id] = array_replace($base['cases']['new_best'], BreadthVolatilityGrid::changes($protocol['definitions'][$id], $breadth, $vvix, $svxy));
}
foreach ($cases as $id => $changes) {
    if (in_array($id, $completed, true)) { continue; }
    foreach (['expanded' => [$expandedProfile, $yahoo, '2021-01-04', '2026-09-04'],
        'earlier' => [$earlyProfile, $early, '2017-01-03', '2020-12-31']] as $kind => [$profile, $bars, $start, $end]) {
        $tester = AdaptiveResearchFactory::make($profile, $changes, 30);
        $run = $tester->run($bars, $start, $end);
        $report['results'][$kind][$id] = $tester->metrics($run);
        $report['replays']++;
        printf("%s %s CAGR %.2f DD %.2f\n", $kind, $id, $report['results'][$kind][$id]['cagr'] * 100, $report['results'][$kind][$id]['max_drawdown'] * 100);
    }
    if (str_starts_with($id, 'control_')) { continue; }
    $full = $read($source . '/primary_yahoo_30_' . $id . '.json');
    foreach (['2023-12-29', '2025-12-31'] as $end) {
        $run = AdaptiveResearchFactory::make($base['profile'], $changes, 30)->run(HybridV4Research::truncateBars($narrow, $end), '2021-01-04', $end);
        $prefix = array_map(static fn ($r): array => array_intersect_key($r, array_flip(['date', 'period_start_date', 'start_equity', 'equity', 'equity_low', 'equity_high', 'turnover'])), $run['curve']);
        $expected = array_values(array_filter($full, static fn ($r): bool => $r['date'] <= $end));
        // JSON round-tripping can turn integral floats into ints; compare numeric values, not PHP storage types.
        if ($prefix != $expected) { throw new RuntimeException('Actual-data prefix drift: ' . $id . '/' . $end); }
        $report['prefix_checks']++;
        $report['replays']++;
    }
    if (isset($changes['external_daily_scale'])) {
        $delayed = $changes;
        $previous = 0.0;
        foreach ($delayed['external_daily_scale'] as $date => $value) { $delayed['external_daily_scale'][$date] = $previous; $previous = $value; }
        $tester = AdaptiveResearchFactory::make($base['profile'], $delayed, 30);
        $report['results']['extra_session_lag'][$id] = $tester->metrics($tester->run($narrow, '2021-01-04', '2026-09-04'));
        $report['replays']++;
    }
    if (in_array($protocol['definitions'][$id]['family'], ['vvix', 'combined'], true)) {
        $missing = array_values(array_diff(array_keys($breadth), array_keys($altVvix)));
        if ($missing !== []) {
            $report['rejected_independent_vvix'][$id] = ['missing_sessions' => $missing, 'action' => 'No replay; no implicit Cboe fallback or forward fill.'];
        } else {
            $alternative = array_replace($base['cases']['new_best'], BreadthVolatilityGrid::changes($protocol['definitions'][$id], $breadth, $altVvix, $svxy));
            $tester = AdaptiveResearchFactory::make($base['profile'], $alternative, 30);
            $report['results']['yahoo_vvix'][$id] = $tester->metrics($tester->run($narrow, '2021-01-04', '2026-09-04'));
            $report['replays']++;
        }
    }
    AlgorithmTrendResearch::write($out . '/progress.json', $report);
}
$report['operational_identity_unchanged'] = $protocol['operational_identity'] === TacticalImplementationIdentity::current($root, $base['profile']);
$report['completed_at'] = gmdate(DATE_ATOM);
AlgorithmTrendResearch::write($out . '/results.json', $report);
echo "Verification complete: ", $report['replays'], " replays, ", $report['prefix_checks'], " actual-data prefix checks\n";
