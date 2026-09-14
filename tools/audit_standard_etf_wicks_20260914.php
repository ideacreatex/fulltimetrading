#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Research\AlgorithmTrendResearch as A;
use FulltimeTrading\Research\BreadthVolatilityResearch as B;
use FulltimeTrading\Research\DailyDataAudit as D;
use FulltimeTrading\Research\StandardEtfResearch as S;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__); $parentDir = $root . '/var/reports/standard_etfs_20260914'; $out = $parentDir . '/rth_wick_sensitivity';
$read = static fn ($p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
$parent = $read($parentDir . '/protocol.json');
foreach ($parent['code_sha256'] as $file => $sha) { if (hash_file('sha256', $root . '/' . $file) !== $sha) { throw new RuntimeException('Frozen engine changed.'); } }
$auditDir = $root . '/var/reports/etf_data_audit_20260914'; $audit = $read($auditDir . '/results.json');
$stockFile = $root . '/var/reports/alpaca_stops_data_20260909/sip_all.json'; $etfFile = $root . '/var/reports/cross_asset_data_20260909/alpaca_sip_all.json';
if (hash_file('sha256', $stockFile) !== $parent['input_sha256']['stocks'] || hash_file('sha256', $etfFile) !== $parent['input_sha256']['etfs']) { throw new RuntimeException('Data changed.'); }
$raw = array_replace(array_intersect_key($read($stockFile), array_flip(['SPY', 'QQQ'])), $read($etfFile));
$replacements = [];
foreach ($audit['flags'] as $flag) {
    $s = $flag['symbol']; $d = $flag['date']; $snapFile = $auditDir . '/' . $s . '_' . $d . '.json';
    if (hash_file('sha256', $snapFile) !== $flag['snapshot_sha256']) { throw new RuntimeException('Minute evidence changed.'); }
    $snapshot = $read($snapFile); $session = $snapshot['calendar']; $zone = new DateTimeZone('America/New_York');
    $start = new DateTimeImmutable($d . ' ' . $session['open'], $zone); $end = new DateTimeImmutable($d . ' ' . $session['close'], $zone);
    $rth = array_values(array_filter(D::decode($snapshot['minutes'])[$s], static fn ($b): bool => $b->time >= $start && $b->time < $end));
    if (count($rth) !== intdiv($end->getTimestamp() - $start->getTimestamp(), 60)) { throw new RuntimeException('Incomplete minute session.'); }
    foreach ($rth as $i => $b) { if ($b->time->getTimestamp() !== $start->getTimestamp() + $i * 60) { throw new RuntimeException('Noncontiguous minute session.'); } }
    $low = min(array_map(static fn ($b): float => $b->low, $rth)); $high = max(array_map(static fn ($b): float => $b->high, $rth));
    foreach ($raw[$s] as &$bar) {
        if (substr($bar['t'], 0, 10) !== $d) { continue; }
        if ($bar != $flag['frozen_bar'] || $low > min($bar['o'], $bar['c']) || $high < max($bar['o'], $bar['c'])) { throw new RuntimeException('Daily/minute alignment failed.'); }
        $before = $bar; $bar['l'] = $low; $bar['h'] = $high;
        $replacements[] = ['symbol' => $s, 'date' => $d, 'before' => $before, 'sensitivity_bar' => $bar];
    }
    unset($bar);
}
if (count($replacements) !== count($audit['flags']) || $replacements === []) { throw new RuntimeException('Unmatched quality flags.'); }
if (!is_dir($out)) { mkdir($out, 0775, true); }
$p = ['parent_sha256' => hash_file('sha256', $parentDir . '/protocol.json'), 'script_sha256' => hash_file('sha256', __FILE__),
    'audit_sha256' => hash_file('sha256', $auditDir . '/results.json'), 'replacements' => $replacements,
    'limits' => 'Diagnostic only: daily high/low replaced by complete official-RTH minute range for flagged outlier bars. Daily open/close/volume unchanged. Original inputs never edited. Not a canonical vendor correction or deployment price source.',
    'selection' => 'Rerun the entire original 108-case family, both starts and both costs; no new parameter selection.', 'orders_submitted' => 0];
if (file_exists($out . '/protocol.json')) { if ($read($out . '/protocol.json') != $p) { throw new RuntimeException('Sensitivity protocol drift.'); } }
else { A::write($out . '/protocol.json', $p); }
$symbols = ['SPY', 'SHY']; foreach ($parent['cases'] as $c) { $symbols = array_merge($symbols, $c['symbols']); }
$engine = new S(D::decode(array_intersect_key($raw, array_flip($symbols)))); unset($raw);
$bases = [];
foreach (['continuous30' => 'continuous', 'fresh2023_30' => 'fresh2023', 'continuous60' => 'cost60_2021', 'fresh2023_60' => 'cost60_2023'] as $scenario => $name) {
    $paths = ['selected_maximum' => $root . '/var/reports/opportunity_20260910/' . $name . '_p0_maximum_curve.json',
        'candidate' => $root . '/var/reports/opportunity_calendar_20260910/' . $name . '_p0_maximum_stop12__cost_band_' . (str_contains($scenario, '60') ? '1' : '2') . '_curve.json'];
    foreach ($paths as $base => $path) {
        if (hash_file('sha256', $path) !== $parent['input_sha256'][$base . '/' . $scenario]) { throw new RuntimeException('Anchor curve changed.'); }
        $bases[$base][$scenario] = $read($path);
    }
}
$rows = $winners = $passes = $maxCagr = [];
$profile = require $root . '/config/tactical_rotation.php';
foreach ($parent['cases'] as $id => $c) {
    $row = [];
    foreach (array_keys($bases['candidate']) as $scenario) {
        [$start, $end, $cost] = $parent['scenarios'][$scenario]; $run = $engine->run($c, $start, $end, $cost);
        $row['standalone'][$scenario] = B::metrics($run['curve']);
        foreach ($bases as $base => $scenarios) {
            foreach ($parent['satellite_allocations'] as $allocation) {
                $mix = B::mix($scenarios[$scenario], $run['curve'], $allocation);
                $row['mixes'][$base][(int) round(100 * $allocation)][$scenario] = ['full' => B::metrics($mix),
                    'train' => B::metrics($mix, '2021-01-04', '2024-01-01'), 'validation' => B::metrics($mix, '2024-01-01', '2026-01-01')];
            }
        }
    }
    foreach ($row['mixes'] as $base => $allocations) {
        foreach ($allocations as $allocation => $scenarios) {
            $improve = true;
            foreach ($scenarios as $scenario => $m) { $improve = $improve && $m['full']['cagr'] > B::metrics($bases[$base][$scenario])['cagr'] + 1e-10; }
            if ($improve) { $winners[$base][] = compact('id', 'allocation'); }
            $m = $scenarios['continuous30'];
            if ($m['train']['cagr'] >= $profile['validation']['minimum_train_cagr'] && $m['validation']['cagr'] >= $profile['validation']['minimum_validation_cagr']) { $passes[$base][] = compact('id', 'allocation'); }
        }
    }
    $rows[$id] = $row;
}
$summary = ['completed_at' => gmdate(DATE_ATOM), 'protocol_sha256' => hash_file('sha256', $out . '/protocol.json'),
    'standalone_replays' => count($rows) * 4, 'mixed_comparisons' => count($rows) * 2 * 3 * 4,
    'return_winners_both_starts_both_costs' => $winners, 'necessary_train_validation_cagr_passes' => $passes,
    'orders_submitted' => 0, 'limits' => $p['limits']];
A::write($out . '/results.json', $rows); A::write($out . '/summary.json', $summary);
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
