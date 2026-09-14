#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Research\AdaptiveResearchFactory;
use FulltimeTrading\Research\AlgorithmTrendResearch;
use FulltimeTrading\Research\BreadthVolatilityGrid;
use FulltimeTrading\Research\BreadthVolatilityResearch as B;
use FulltimeTrading\Research\DailyDataAudit as D;
use FulltimeTrading\Research\HybridV4Research;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__);
$read = static fn ($p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
$out = $root . '/var/reports/breadth_volatility_final_audit_20260909';
if (file_exists($out)) { throw new RuntimeException('Use a new audit directory.'); }
$mainDir = $root . '/var/reports/breadth_volatility_20260909';
$main = $read($mainDir . '/results.json');
$protocol = $read($mainDir . '/protocol.json');
$refineDir = $root . '/var/reports/breadth_volatility_refinement_20260909';
$refine = $read($refineDir . '/results.json');
$definitions = $read($refineDir . '/protocol.json')['definitions'];
$old = $root . '/var/reports/independent_data_20260908';
$data = $root . '/var/reports/breadth_volatility_data_20260909';
$base = $read($old . '/protocol.json');
$assets = [];
foreach (['SPY', 'QQQ', 'SVXY'] as $symbol) { $assets += D::decode($read($data . '/yahoo_' . $symbol . '.json')); }
$calendar = array_values(array_filter(array_keys(D::indexed(['SPY' => $assets['SPY']])['SPY']), static fn ($d): bool => $d >= '2016-01-01'));
$breadth = B::validateBreadth($read($data . '/s5tw.json'), $calendar);
$vvix = $read($root . '/var/reports/vvix_data_20260909/cboe_vvix.json');
$events = B::events($breadth);
$svxy200 = B::trend($assets['SVXY'], 200);
$svxy20 = B::trend($assets['SVXY'], 20);
$spy200 = B::trend($assets['SPY'], 200);
$control = $read($mainDir . '/primary_yahoo_30_control_new_best.json');
$allYahoo = [];
foreach (array_merge($base['original_universe'], $base['additional_universe'], ['SPY', 'QQQ']) as $symbol) {
    $bars = D::decode($read($old . '/yahoo_' . $symbol . '.json'))[$symbol];
    $allYahoo[$symbol] = array_values(array_filter($bars, static fn ($b): bool => D::session($b) >= '2020-01-01'));
}
$narrow = array_intersect_key($allYahoo, array_fill_keys(array_merge($base['original_universe'], ['SPY', 'QQQ']), true));
$early = D::decode($read($old . '/sip_split.json'));
$availability = D::availableBy($base['original_universe'], $early, '2020-12-31');
$earlyProfile = D::withUniverse($base['profile'], $availability['included']);
$early = HybridV4Research::truncateBars(array_intersect_key($early, array_fill_keys(array_merge($base['original_universe'], ['SPY', 'QQQ']), true)), '2020-12-31');
$primary = $main['primary']['yahoo'][30];
uasort($primary, static fn ($a, $b): int => $b['metrics']['full']['cagr'] <=> $a['metrics']['full']['cagr']);
$hindsightId = array_key_first($primary);
mkdir($out, 0775, true);
$report = ['started_at' => gmdate(DATE_ATOM), 'replays' => 0, 'hindsight_maximum_id' => $hindsightId,
    'caveat' => 'Maximum is selected on full history for diagnostic replication only, never promoted as independent validation.',
    'mix_erratum' => 'Correct drifted sleeve dollar weights in turnover only. Original CAGR, equity and drawdown are asserted unchanged; completed source report preserved.',
    'implementation_sha256' => []];
foreach (['src/Research/BreadthVolatilityResearch.php', 'src/Research/AdaptiveRotationBacktester.php', 'tools/final_audit_breadth_volatility.php'] as $file) {
    $report['implementation_sha256'][$file] = hash_file('sha256', $root . '/' . $file);
}
AlgorithmTrendResearch::write($out . '/protocol.json', $report);
foreach ($main['static_capital_mix'] as $id => $allocations) {
    $signals = array_fill_keys($calendar, true);
    $hold = 99999;
    if ($id !== 'buy_hold_SVXY') {
        if (!preg_match('/^(low_touch|low_close|low_reclaim11|low_reclaim20|high_touch|high_close|high_fade80|high_hold2)_(5|10|20)_SVXY_(none|spy200|vvix110_svxy20)$/', $id, $m)) { throw new RuntimeException('Unknown mixed account.'); }
        $signals = $events[$m[1]];
        $hold = (int) $m[2];
        foreach ($signals as $date => $signal) {
            $signals[$date] = $signal && match ($m[3]) {
                'none' => true, 'spy200' => $spy200[$date], 'vvix110_svxy20' => $vvix[$date] < 110 && $svxy20[$date],
            };
        }
    }
    $r = B::eventReplay($assets['SVXY'], $signals, $hold, 30, '2021-01-04', '2026-09-04');
    $report['replays']++;
    foreach ($allocations as $weight => $previous) {
        $mix = B::mix($control, $r['curve'], (float) $weight);
        $m = ['full' => B::metrics($mix), 'train' => B::metrics($mix, null, '2024-01-01'),
            'validation' => B::metrics($mix, '2024-01-01', '2026-01-01'), 'later' => B::metrics($mix, '2026-01-01')];
        foreach ($m as $period => $metrics) {
            foreach (['cagr', 'return', 'max_drawdown', 'terminal_equity'] as $field) {
                if (abs($metrics[$field] - $previous[$period][$field]) > 1e-8) { throw new RuntimeException('Mix correction changed investment results.'); }
            }
        }
        $report['corrected_static_capital_mix'][$id][$weight] = $m;
    }
}
foreach ($refine['train_selected'] as $id) {
    $d = $definitions[$id];
    $scales = B::windowScale($events['high_touch'], $d['hold'], $d['boost']);
    foreach ($scales as $date => $value) {
        if (!$svxy200[$date]) { $scales[$date] = min($scales[$date], $d['defensive']); }
        if ($vvix[$date] >= 120 && $d['vvixCap'] < 1) { $scales[$date] = min($scales[$date], $d['vvixCap']); }
    }
    $settings = array_replace($base['cases']['new_best'], ['external_daily_scale' => $scales]);
    $tester = AdaptiveResearchFactory::make($earlyProfile, $settings, 30);
    $report['refinement_earlier'][$id] = $tester->metrics($tester->run($early, '2017-01-03', '2020-12-31'));
    $previous = 0.0;
    foreach ($scales as $date => $value) { $scales[$date] = $previous; $previous = $value; }
    $tester = AdaptiveResearchFactory::make($base['profile'], array_replace($settings, ['external_daily_scale' => $scales]), 30);
    $report['refinement_extra_session_lag'][$id] = $tester->metrics($tester->run($narrow, '2021-01-04', '2026-09-04'));
    $report['replays'] += 2;
}
$sipSvxy = D::decode($read($data . '/sip_split.json'))['SVXY'];
foreach (['yahoo' => $narrow, 'sip_spinoff' => D::decode($read($root . '/var/reports/data_attribution_20260908/split_spinoff.json')),
    'sip_all' => D::decode($read($old . '/sip_all.json'))] as $source => $bars) {
    $settings = array_replace($base['cases']['new_best'], BreadthVolatilityGrid::changes($protocol['definitions'][$hindsightId], $breadth, $vvix, $source === 'yahoo' ? $assets['SVXY'] : $sipSvxy));
    foreach ($source === 'yahoo' ? [60] : [30, 60] as $cost) {
        $tester = AdaptiveResearchFactory::make($base['profile'], $settings, $cost);
        $report['hindsight_replication'][$source][$cost] = $tester->metrics($tester->run($bars, '2021-01-04', '2026-09-04'));
        $report['replays']++;
    }
}
$report['completed_at'] = gmdate(DATE_ATOM);
AlgorithmTrendResearch::write($out . '/results.json', $report);
echo "Final audit complete: ", $report['replays'], " replays; mixed-account performance unchanged.\n";
