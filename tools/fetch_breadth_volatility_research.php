#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Data\AlpacaBarsProvider;
use FulltimeTrading\Data\HttpClient;
use FulltimeTrading\Data\YahooChartProvider;
use FulltimeTrading\Research\AlgorithmTrendResearch;
use FulltimeTrading\Research\DailyDataAudit;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__);
$out = $root . '/var/reports/breadth_volatility_data_20260909';
if (!file_exists($out)) { mkdir($out, 0775, true); }
$http = new HttpClient();
$manifest = ['started_at' => gmdate(DATE_ATOM), 'end' => '2026-09-08', 'snapshots' => [], 'errors' => [],
    'execution_enabled' => false, 'svxy_objective_change' => '2018-02-28',
    's5tw_definition' => 'S&P 500 Stocks Above 20-Day Average, percentage 0..100; vendor history, not reconstructed constituents'];
if (file_exists($out . '/manifest.json')) {
    $manifest = json_decode(file_get_contents($out . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
    if (isset($manifest['finished_at'])) { throw new RuntimeException('Refusing to overwrite a completed snapshot.'); }
    foreach ($manifest['snapshots'] as $item) {
        if (!hash_equals($item['sha256'], hash_file('sha256', $out . '/' . $item['file']))) { throw new RuntimeException('Snapshot hash drift.'); }
    }
}
$save = static function (string $id, array $data, string $source) use ($out, &$manifest): void {
    AlgorithmTrendResearch::write($out . '/' . $id . '.json', $data);
    $manifest['snapshots'][$id] = ['file' => $id . '.json', 'sha256' => hash_file('sha256', $out . '/' . $id . '.json'), 'source' => $source];
    AlgorithmTrendResearch::write($out . '/manifest.json', $manifest);
};
$url = 'https://api.investing.com/api/financialdata/historical/1225365?start-date=2010-01-01&end-date=2026-09-08&time-frame=Daily';
if (!isset($manifest['snapshots']['s5tw'])) {
$response = $http->get($url, ['domain-id' => 'www', 'Referer' => 'https://www.investing.com/']);
if ($response['status'] !== 200) { throw new RuntimeException('S5TW HTTP ' . $response['status']); }
$raw = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
$save('s5tw_raw', $raw, $url);
$series = [];
foreach ($raw['data'] ?? [] as $row) {
    $date = substr($row['rowDateTimestamp'], 0, 10);
    if (isset($series[$date])) { throw new RuntimeException('Duplicate S5TW date: ' . $date); }
    $series[$date] = ['open' => (float) $row['last_openRaw'], 'high' => (float) $row['last_maxRaw'],
        'low' => (float) $row['last_minRaw'], 'close' => (float) $row['last_closeRaw']];
}
ksort($series);
$save('s5tw', $series, $url);
printf("S5TW: %d sessions %s .. %s\n", count($series), array_key_first($series), array_key_last($series));
}
foreach (['SPY', 'QQQ', 'SVXY'] as $symbol) {
    if (isset($manifest['snapshots']['yahoo_' . $symbol])) { continue; }
    $bars = (new YahooChartProvider($http))->getBars([$symbol], '1Day', '2010-01-01', '2026-09-08');
    if (($bars[$symbol] ?? []) === []) { throw new RuntimeException('Missing Yahoo bars: ' . $symbol); }
    $save('yahoo_' . $symbol, DailyDataAudit::encode($bars), 'Yahoo chart quote OHLC, not adjclose; split adjusted');
    printf("Yahoo %s: %d bars\n", $symbol, count($bars[$symbol]));
}
foreach (['split', 'all'] as $adjustment) {
    if (isset($manifest['snapshots']['sip_' . $adjustment])) { continue; }
    try {
        $bars = (new AlpacaBarsProvider($http, 'https://data.alpaca.markets', 'sip', $adjustment, 10000))
            ->getBars(['SPY', 'QQQ', 'SVXY'], '1Day', '2016-01-01', '2026-09-08T23:59:59Z');
        $save('sip_' . $adjustment, DailyDataAudit::encode($bars), 'Alpaca SIP daily adjustment=' . $adjustment);
        printf("SIP %s: %d SVXY bars\n", $adjustment, count($bars['SVXY']));
    } catch (Throwable $e) { $manifest['errors']['sip_' . $adjustment] = $e->getMessage(); }
}
$manifest['finished_at'] = gmdate(DATE_ATOM);
AlgorithmTrendResearch::write($out . '/manifest.json', $manifest);
