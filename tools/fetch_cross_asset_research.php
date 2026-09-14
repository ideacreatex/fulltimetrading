#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Data\AlpacaBarsProvider;
use FulltimeTrading\Data\HttpClient;
use FulltimeTrading\Research\AlgorithmTrendResearch as A;
use FulltimeTrading\Research\DailyDataAudit as D;

require dirname(__DIR__) . '/bootstrap.php';
$out = dirname(__DIR__) . '/var/reports/cross_asset_data_20260909';
if (file_exists($out . '/manifest.json')) { throw new RuntimeException('Completed snapshot is immutable.'); }
if (!is_dir($out)) { mkdir($out, 0775, true); }
$symbols = ['HYG', 'LQD', 'IEF', 'TLT', 'SHY', 'IWM', 'RSP', 'XLY', 'XLP', 'XLK', 'XLU', 'XLF', 'EEM', 'EFA', 'GLD', 'CPER', 'UUP', 'VIXY', 'VIXM'];
$http = new HttpClient();
$read = static fn ($f): array => json_decode(file_get_contents($f), true, 512, JSON_THROW_ON_ERROR);
$file = $out . '/alpaca_sip_all.json';
if (!file_exists($file)) {
    $bars = (new AlpacaBarsProvider($http, 'https://data.alpaca.markets', 'sip', 'all', 10000))
        ->getBars($symbols, '1Day', '2016-01-01', '2026-09-08T23:59:59Z');
    A::write($file, D::encode($bars));
} else { $bars = D::decode($read($file)); }
$oldFile = dirname(__DIR__) . '/var/reports/alpaca_stops_data_20260909/sip_all.json';
$old = $read($oldFile);
$calendarBars = D::decode(['SPY' => $old['SPY']]);
unset($old);
$manifest = ['started_at' => gmdate(DATE_ATOM), 'symbols' => $symbols, 'feed' => 'Alpaca SIP adjustment=all',
    'start' => '2016-01-01', 'end' => '2026-09-08', 'coverage' => D::coverage($bars + $calendarBars),
    'calendar_snapshot_sha256' => hash_file('sha256', $oldFile),
    'alpaca_sha256' => hash_file('sha256', $file), 'cboe' => [], 'orders_enabled' => false, 'script_sha256' => hash_file('sha256', __FILE__)];
foreach ($manifest['coverage'] as $symbol => $r) {
    if ($r['first'] > '2016-01-05' || $r['last'] !== '2026-09-08' || $r['internal_missing_sessions'] !== []) { throw new RuntimeException('ETF coverage failure: ' . $symbol . ' ' . json_encode($r)); }
}
foreach (['VIX', 'VIX9D', 'OVX', 'GVZ'] as $symbol) {
    $url = 'https://cdn.cboe.com/api/global/us_indices/daily_prices/' . $symbol . '_History.csv';
    $r = $http->get($url);
    if ($r['status'] !== 200) { throw new RuntimeException($symbol . ' HTTP ' . $r['status']); }
    $lines = preg_split('/\r?\n/', trim($r['body']));
    $header = array_map(static fn ($v): string => strtoupper(trim($v, " \t\r\n\xEF\xBB\xBF")), str_getcsv(array_shift($lines), ',', '"', ''));
    $dateIndex = array_search('DATE', $header, true);
    $closeIndex = array_search('CLOSE', $header, true);
    if ($closeIndex === false) { $closeIndex = array_search($symbol, $header, true); }
    if ($dateIndex === false || $closeIndex === false) { throw new RuntimeException('Unknown Cboe schema: ' . json_encode($header)); }
    $series = [];
    foreach ($lines as $line) {
        if (trim($line) === '') { continue; }
        $row = str_getcsv($line, ',', '"', '');
        $rawDate = trim($row[$dateIndex]);
        $date = DateTimeImmutable::createFromFormat('!m/d/Y', $rawDate);
        if ($date === false || $date->format('m/d/Y') !== $rawDate) { throw new RuntimeException('Invalid Cboe date: ' . $rawDate); }
        $key = $date->format('Y-m-d');
        if ($key > '2026-09-08' || $key < '2016-01-01') { continue; }
        $value = trim($row[$closeIndex]);
        if (isset($series[$key]) || !is_numeric($value) || !is_finite((float) $value) || (float) $value <= 0) { throw new RuntimeException('Invalid Cboe value.'); }
        $series[$key] = (float) $value;
    }
    ksort($series);
    A::write($out . '/' . $symbol . '.json', $series);
    $manifest['cboe'][$symbol] = ['url' => $url, 'raw_sha256' => hash('sha256', $r['body']),
        'sha256' => hash_file('sha256', $out . '/' . $symbol . '.json'), 'first' => array_key_first($series), 'last' => array_key_last($series), 'rows' => count($series)];
    printf("%s %d daily observations\n", $symbol, count($series));
}
$manifest['completed_at'] = gmdate(DATE_ATOM);
A::write($out . '/manifest.json', $manifest);
printf("New data: %d Alpaca ETFs and %d Cboe indices; data-only GET requests.\n", count($symbols), count($manifest['cboe']));
