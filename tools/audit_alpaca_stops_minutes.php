#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Data\AlpacaBarsProvider;
use FulltimeTrading\Data\HttpClient;
use FulltimeTrading\Research\AlgorithmTrendResearch;
use FulltimeTrading\Research\DailyDataAudit as D;
use FulltimeTrading\Research\RthStandingStopAudit;
use FulltimeTrading\Trading\AlpacaPaperClient;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__);
$dir = $root . '/var/reports/alpaca_portfolio_stops_20260909';
$out = $root . '/var/reports/alpaca_stop_minutes_20260909';
$read = static fn ($p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
if (file_exists($out . '/results.json')) { throw new RuntimeException('Refusing to overwrite a completed audit.'); }
$selected = $read($dir . '/selection.json')['selected'];
$events = $byDate = [];
foreach ($selected as $id) {
    foreach ($read($dir . '/primary_all_30_stop_level_' . $id . '.json')['stops'] as $event) {
        $key = hash('sha256', json_encode([$event['date'], $event['symbol'], $event['stop'], $event['fill'], $event['kind']]));
        $events[$key] = $event;
        $byDate[$event['date']][$event['symbol']] = true;
    }
}
ksort($byDate);
if ($events === []) { throw new RuntimeException('No train-selected standing stops to audit.'); }
if (!is_dir($out)) { mkdir($out, 0775, true); }
$protocol = ['started_at' => gmdate(DATE_ATOM), 'selection' => $selected, 'selection_sha256' => hash_file('sha256', $dir . '/selection.json'),
    'unique_events' => count($events), 'symbol_days' => array_sum(array_map('count', $byDate)), 'dates' => count($byDate),
    'feed' => 'sip', 'adjustment' => 'all', 'all_train_selected_stop_events' => true, 'orders_submitted' => 0,
    'limits' => ['Daily-screen event audit only, not a path-dependent minute strategy replay.',
        'Minute lows cannot prove native stop election; NBBO and execution latency are not modeled.',
        'Next-minute open is a delayed-price diagnostic, not a guaranteed stop fill.']];
if (file_exists($out . '/protocol.json')) {
    if ($read($out . '/protocol.json')['selection_sha256'] !== $protocol['selection_sha256']) { throw new RuntimeException('Audit selection drift.'); }
} else { AlgorithmTrendResearch::write($out . '/protocol.json', $protocol); }
$http = new HttpClient();
if (!file_exists($out . '/calendar.json')) {
    $calendar = (new AlpacaPaperClient($http, 'https://paper-api.alpaca.markets/v2'))->calendar(array_key_first($byDate), array_key_last($byDate));
    AlgorithmTrendResearch::write($out . '/calendar.json', $calendar);
}
$calendar = array_column($read($out . '/calendar.json'), null, 'date');
$provider = new AlpacaBarsProvider($http, 'https://data.alpaca.markets', 'sip', 'all', 10000);
$zone = new DateTimeZone('America/New_York');
$rows = $sha = [];
$count = 0;
foreach ($byDate as $date => $symbols) {
    if (!isset($calendar[$date])) { throw new RuntimeException('Missing official trading session: ' . $date); }
    $session = $calendar[$date];
    $file = $out . '/minutes_' . $date . '.json';
    if (!file_exists($file)) {
        $minutes = $provider->getBars(array_keys($symbols), '1Min',
            (new DateTimeImmutable($date . ' ' . $session['open'], $zone))->format(DATE_ATOM),
            (new DateTimeImmutable($date . ' ' . $session['close'], $zone))->format(DATE_ATOM));
        AlgorithmTrendResearch::write($file, D::encode($minutes));
    } else { $minutes = D::decode($read($file)); }
    $sha[basename($file)] = hash_file('sha256', $file);
    foreach ($events as $key => $event) {
        if ($event['date'] === $date) { $rows[$key] = RthStandingStopAudit::inspect($event, $minutes[$event['symbol']] ?? [], $session); }
    }
    if (++$count % 20 === 0) { echo $count, '/', count($byDate), " stop sessions checked\n"; }
}
$delayed = array_column($rows, 'next_minute_vs_daily_fill_bps');
$delayed = array_values(array_filter($delayed, static fn ($v): bool => $v !== null));
$report = ['completed_at' => gmdate(DATE_ATOM), 'events' => count($rows), 'symbol_days' => $protocol['symbol_days'], 'dates' => count($byDate),
    'rth_touch' => count(array_filter($rows, static fn ($r): bool => $r['rth_touch'])),
    'no_rth_touch' => count(array_filter($rows, static fn ($r): bool => !$r['rth_touch'])),
    'missing_opening_minute' => count(array_filter($rows, static fn ($r): bool => !$r['opening_minute_present'])),
    'delayed_proxy_observations' => count($delayed), 'delayed_proxy_worse_than_daily' => count(array_filter($delayed, static fn ($v): bool => $v < -1e-6)),
    'delayed_proxy_bps' => ['minimum' => D::quantile($delayed, 0), 'p05' => D::quantile($delayed, .05), 'median' => D::quantile($delayed, .5)],
    'snapshots_sha256' => $sha, 'rows' => $rows, 'orders_submitted' => 0, 'proves_broker_stop_execution' => false];
AlgorithmTrendResearch::write($out . '/results.json', $report);
echo json_encode(array_diff_key($report, ['rows' => true, 'snapshots_sha256' => true])), "\n";
