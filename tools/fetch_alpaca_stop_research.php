#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Data\AlpacaBarsProvider;
use FulltimeTrading\Data\HttpClient;
use FulltimeTrading\Research\AlgorithmTrendResearch;
use FulltimeTrading\Research\DailyDataAudit;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__);
$out = $root . '/var/reports/alpaca_stops_data_20260909';
if (file_exists($out)) { throw new RuntimeException('Refusing to replace a data snapshot.'); }
$base = json_decode(file_get_contents($root . '/var/reports/independent_data_20260908/protocol.json'), true, 512, JSON_THROW_ON_ERROR);
$symbols = array_values(array_unique(array_merge($base['original_universe'], $base['additional_universe'], ['SPY', 'QQQ', 'SVXY'])));
mkdir($out, 0775, true);
$manifest = ['started_at' => gmdate(DATE_ATOM), 'provider' => 'Alpaca', 'host' => 'https://data.alpaca.markets',
    'feed' => 'sip', 'adjustment' => 'all', 'start' => '2016-01-01', 'end' => '2026-09-08', 'symbols' => $symbols, 'execution_enabled' => false];
AlgorithmTrendResearch::write($out . '/manifest.json', $manifest);
$bars = (new AlpacaBarsProvider(new HttpClient(), 'https://data.alpaca.markets', 'sip', 'all', 10000))
    ->getBars($symbols, '1Day', '2016-01-01', '2026-09-08T23:59:59Z');
$coverage = DailyDataAudit::coverage($bars);
foreach ($coverage as $symbol => $row) {
    if ($row['bars'] === 0 || $row['internal_missing_sessions'] !== [] || $row['extra_sessions'] !== [] || $row['last'] !== '2026-09-08') {
        throw new RuntimeException('Incomplete SIP daily history for ' . $symbol . ': ' . json_encode($row));
    }
}
AlgorithmTrendResearch::write($out . '/sip_all.json', DailyDataAudit::encode($bars));
$manifest['snapshot_sha256'] = hash_file('sha256', $out . '/sip_all.json');
$manifest['coverage'] = $coverage;
$manifest['bars'] = array_sum(array_column($coverage, 'bars'));
$manifest['completed_at'] = gmdate(DATE_ATOM);
AlgorithmTrendResearch::write($out . '/manifest.json', $manifest);
echo 'Alpaca SIP all: ', count($symbols), ' symbols, ', $manifest['bars'], " daily bars\n";
