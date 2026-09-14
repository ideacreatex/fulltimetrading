#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Research\AlgorithmTrendResearch as A;
use FulltimeTrading\Research\BreadthVolatilityResearch as B;
use FulltimeTrading\Research\DailyDataAudit as D;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__);
$out = $root . '/var/reports/selected_maximum_20260909';
$read = static fn ($p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
if (file_exists($out . '/satellites.json')) { throw new RuntimeException('Satellite batch already complete.'); }
$p = $read($out . '/protocol.json');
$file = $root . '/var/reports/alpaca_stops_data_20260909/sip_all.json';
if (!hash_equals($p['input_sha256']['bars'], hash_file('sha256', $file))) { throw new RuntimeException('Data drift.'); }
$all = D::decode($read($file));
$breadthFile = $root . '/var/reports/breadth_volatility_data_20260909/s5tw.json';
$vvixFile = $root . '/var/reports/vvix_data_20260909/cboe_vvix.json';
foreach (['s5tw' => $breadthFile, 'vvix' => $vvixFile] as $key => $path) {
    if (!hash_equals($p['input_sha256'][$key], hash_file('sha256', $path))) { throw new RuntimeException('Indicator drift.'); }
}
$calendar = array_keys(D::indexed(['SPY' => $all['SPY']])['SPY']);
$breadth = B::validateBreadth($read($breadthFile), $calendar);
$vvix = $read($vvixFile);
$events = B::events($breadth);
$spyTrend = B::trend($all['SPY'], 200);
$svxyTrend = B::trend($all['SVXY'], 20);
$cases = [];
foreach (B::EVENTS as $event) {
    foreach ([5, 10, 20] as $hold) {
        foreach (['SPY', 'QQQ', 'SVXY'] as $symbol) {
            foreach (['none', 'spy200', 'vvix110_svxy20'] as $confirmation) {
                $id = implode('_', [$symbol, $event, $hold, $confirmation]);
                $cases[$id] = compact('symbol', 'event', 'hold', 'confirmation');
            }
        }
    }
}
foreach (['SPY', 'QQQ', 'SVXY'] as $symbol) { $cases[$symbol . '_buy_hold'] = ['symbol' => $symbol, 'event' => 'always', 'hold' => 100000, 'confirmation' => 'none']; }
$protocol = ['frozen_at' => gmdate(DATE_ATOM), 'dates' => $p['primary_dates'], 'definitions' => $cases,
    'anchors' => ['maximum', 'maximum_stop12'], 'static_initial_allocations' => [0.025, 0.05, 0.1, 0.2],
    'selection' => 'Standalone and mix train Calmar on 2023-2024, later periods descriptive; all SVXY mixes are also cost-stressed.',
    'limits' => 'SVXY satellite is independent static capital, not subject to the main book portfolio circuit. No cash transfers. Its own buy/hold loss is not bounded by the main stop12. Not an executable integration.',
    'script_sha256' => hash_file('sha256', __FILE__), 'primary_protocol_sha256' => hash_file('sha256', $out . '/protocol.json'), 'orders_enabled' => false];
if (file_exists($out . '/satellites_protocol.json')) { throw new RuntimeException('Unfinished satellite batch needs a new output, not silent replacement.'); }
A::write($out . '/satellites_protocol.json', $protocol);
$result = ['standalone' => [], 'mixes' => [], 'replays' => 0, 'mix_evaluations' => 0, 'orders_submitted' => 0];
$metrics = static function ($curve): array {
    $annual = [];
    foreach ([2023, 2024, 2025, 2026] as $year) { $annual[$year] = B::metrics($curve, "$year-01-01", ($year + 1) . '-01-01')['return']; }
    return ['full' => B::metrics($curve), 'selection_train' => B::metrics($curve, null, '2025-01-01'), 'selection_later' => B::metrics($curve, '2025-01-01'), 'annual' => $annual];
};
foreach ([30, 60] as $cost) {
    foreach ($cases as $id => $d) {
        $signals = $d['event'] === 'always' ? array_fill_keys(array_keys($breadth), true) : $events[$d['event']];
        foreach ($signals as $date => $signal) {
            $signals[$date] = $signal && match ($d['confirmation']) {
                'none' => true, 'spy200' => $spyTrend[$date], 'vvix110_svxy20' => $vvix[$date] < 110 && $svxyTrend[$date],
            };
        }
        $run = B::eventReplay($all[$d['symbol']], $signals, $d['hold'], $cost, ...$p['primary_dates']);
        $result['standalone'][$cost][$id] = $metrics($run['curve']) + ['closed_trades' => count($run['trades']), 'open_trade' => $run['open_trade']];
        $result['replays']++;
        if ($d['symbol'] !== 'SVXY') { continue; }
        foreach ($protocol['anchors'] as $anchor) {
            $baseCurve = $read($out . '/' . ($cost === 30 ? 'primary' : 'cost60') . '_' . $anchor . '_curve.json');
            foreach ($protocol['static_initial_allocations'] as $allocation) {
                $key = $anchor . '__svxy_' . str_replace('.', 'p', (string) $allocation) . '__' . $id;
                $curve = B::mix($baseCurve, $run['curve'], $allocation);
                $result['mixes'][$cost][$key] = $metrics($curve) + ['anchor' => $anchor, 'allocation' => $allocation, 'satellite' => $id,
                    'satellite_closed_trades' => count($run['trades']), 'satellite_open_trade' => $run['open_trade']];
                if ($cost === 30) { A::write($out . '/mix_' . $key . '_curve.json', $curve); }
                $result['mix_evaluations']++;
            }
        }
    }
}
$scores = [];
foreach ($result['mixes'][30] as $id => $r) { $scores[$id] = $r['selection_train']['cagr'] / max(0.05, abs($r['selection_train']['max_drawdown'])); }
arsort($scores);
$result['train_selected_mix'] = array_key_first($scores);
$result['completed_at'] = gmdate(DATE_ATOM);
A::write($out . '/satellites.json', $result);
printf("ETF event replays %d, static SVXY mix evaluations %d. No execution integration.\n", $result['replays'], $result['mix_evaluations']);
