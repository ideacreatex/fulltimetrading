#!/usr/bin/env php
<?php
declare(strict_types=1);
use FulltimeTrading\Research\AlgorithmTrendResearch as Writer;
use FulltimeTrading\Research\DailyDataAudit as Data;
use FulltimeTrading\Research\RthStandingStopAudit as Audit;
require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__); $read = static fn ($p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
$parent = $read($root . '/docs/HYBRID_V4_BULL_EXECUTION_2026-09-15.json'); $events = $sourceHashes = [];
foreach (['continuous', 'fresh2023'] as $scenario) { foreach ([30, 60] as $cost) {
    $name = 'bull_v110_ma50_boost105__' . $scenario . '__' . $cost; $path = 'var/reports/candidate_bull_parity_20260915/' . $name . '.json';
    $a = $read($root . '/' . $path); $sha = hash_file('sha256', $root . '/' . $path);
    if ($sha !== $parent['receipts'][$name]['proof_sha256'] || !$a['passed']) { throw new RuntimeException('Frozen bull replay changed.'); }
    $sourceHashes[$path] = $sha;
    foreach ($a['stop_events'] as $event) { $events[hash('sha256', json_encode([$event['date'], $event['symbol'], $event['stop'], $event['fill'], $event['kind']], JSON_THROW_ON_ERROR))] = $event; }
} }
$oldDir = $root . '/var/reports/candidate_execution_20260915/minutes'; $old = $read($oldDir . '/results.json'); $protocol = $read($oldDir . '/protocol.json');
if ($old['protocol_sha256'] !== hash_file('sha256', $oldDir . '/protocol.json') || $protocol['feed'] !== 'Alpaca SIP adjustment=split') { throw new RuntimeException('Minute provenance failed.'); }
if (array_diff_key($events, $old['rows']) !== []) { throw new RuntimeException('New stop events need a separate minute acquisition; do not reuse a different stop.'); }
$calendar = array_column($read($oldDir . '/calendar.json'), null, 'date'); $rows = $cache = $hashes = [];
foreach ($events as $key => $event) {
    $file = 'minutes_' . $event['date'] . '.json';
    if (!isset($cache[$file])) {
        $hash = hash_file('sha256', $oldDir . '/' . $file);
        if ($hash !== $old['snapshots_sha256'][$file]) { throw new RuntimeException('Cached minute data changed.'); }
        $hashes[$file] = $hash; $cache[$file] = Data::decode($read($oldDir . '/' . $file));
    }
    $rows[$key] = Audit::inspect($event, $cache[$file][$event['symbol']] ?? [], $calendar[$event['date']]);
    if (\FulltimeTrading\Research\SelectedMaximumResearch::hash($rows[$key]) !== \FulltimeTrading\Research\SelectedMaximumResearch::hash($old['rows'][$key])
        || !$rows[$key]['rth_touch'] || !$rows[$key]['opening_minute_present']) {
        throw new RuntimeException('Minute observation no longer reproduces frozen event.');
    }
}
$dir = $root . '/var/reports/bull5_admission_20260915'; if (!is_dir($dir)) { mkdir($dir, 0775, true); }
$report = ['completed_at' => gmdate(DATE_ATOM), 'recipe' => 'bull_v110_ma50_boost105', 'cases' => 4, 'unique_events' => count($events),
    'exact_reused_events' => count($rows), 'rth_touch' => count($rows), 'missing_opening_minute' => 0,
    'source_sha256' => $sourceHashes, 'minute_protocol_sha256' => hash_file('sha256', $oldDir . '/protocol.json'),
    'calendar_sha256' => hash_file('sha256', $oldDir . '/calendar.json'), 'minute_snapshots_sha256' => $hashes,
    'script_sha256' => hash_file('sha256', __FILE__), 'orders_submitted' => 0, 'new_downloads' => 0,
    'proves_execution' => false, 'scope' => 'Exact existing stop/price/basis matches across both recent paths and 30/60 bps. Daily/minute stop touch is not NBBO election or guaranteed fills.',
    'delay_sensitivity' => array_intersect_key($old, array_flip(['delayed_observations', 'delayed_worse', 'delayed_bps']))];
Writer::write($dir . '/minutes.json', $report); echo json_encode(array_diff_key($report, ['source_sha256' => true, 'minute_snapshots_sha256' => true]), JSON_PRETTY_PRINT), "\n";
