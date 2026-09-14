#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Data\HttpClient;
use FulltimeTrading\Data\YahooChartProvider;
use FulltimeTrading\Research\AlgorithmTrendResearch;
use FulltimeTrading\Research\DailyDataAudit;

require dirname(__DIR__) . '/bootstrap.php';
$out = dirname(__DIR__) . '/var/reports/vvix_data_20260909';
if (file_exists($out)) { throw new RuntimeException('Use a new snapshot directory.'); }
mkdir($out, 0775, true);
$url = 'https://cdn.cboe.com/api/global/us_indices/daily_prices/VVIX_History.csv';
$http = new HttpClient();
$r = $http->get($url);
if ($r['status'] !== 200) { throw new RuntimeException('VVIX HTTP ' . $r['status']); }
$lines = preg_split('/\r?\n/', trim($r['body']));
if (str_getcsv(array_shift($lines), ',', '"', '') !== ['DATE', 'VVIX']) { throw new RuntimeException('Unexpected Cboe schema.'); }
$series = [];
foreach ($lines as $line) {
    if (trim($line) === '') { continue; }
    [$rawDate, $value] = str_getcsv($line, ',', '"', '');
    $date = DateTimeImmutable::createFromFormat('!m/d/Y', $rawDate)->format('Y-m-d');
    if ($date > '2026-09-08') { continue; }
    if (isset($series[$date]) || !is_numeric($value) || (float) $value <= 0) { throw new RuntimeException('Invalid VVIX row.'); }
    $series[$date] = (float) $value;
}
ksort($series);
AlgorithmTrendResearch::write($out . '/cboe_vvix.json', $series);
$manifest = ['source' => $url, 'fetched_at' => gmdate(DATE_ATOM), 'raw_sha256' => hash('sha256', $r['body']),
    'canonical_sha256' => hash_file('sha256', $out . '/cboe_vvix.json'), 'first' => array_key_first($series),
    'last' => array_key_last($series), 'sessions' => count($series), 'execution_enabled' => false];
try {
    $bars = (new YahooChartProvider($http))->getBars(['^VVIX'], '1Day', '2010-01-01', '2026-09-08');
    AlgorithmTrendResearch::write($out . '/yahoo_vvix.json', DailyDataAudit::encode($bars));
    $manifest['yahoo_sha256'] = hash_file('sha256', $out . '/yahoo_vvix.json');
    $manifest['yahoo_bars'] = count($bars['^VVIX'] ?? []);
} catch (Throwable $e) { $manifest['yahoo_error'] = $e->getMessage(); }
AlgorithmTrendResearch::write($out . '/manifest.json', $manifest);
echo json_encode($manifest, JSON_PRETTY_PRINT), "\n";
