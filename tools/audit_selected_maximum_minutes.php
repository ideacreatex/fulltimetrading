#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Data\AlpacaBarsProvider;
use FulltimeTrading\Data\HttpClient;
use FulltimeTrading\Research\AlgorithmTrendResearch as A;
use FulltimeTrading\Research\DailyDataAudit as D;
use FulltimeTrading\Research\RthStandingStopAudit;
use FulltimeTrading\Trading\AlpacaPaperClient;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__);
$dir = $root . '/var/reports/selected_maximum_20260909';
$out = $dir . '/minutes';
$read = static fn ($p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
if (file_exists($out . '/results.json')) { throw new RuntimeException('Minute audit already complete.'); }
$selection = $read($dir . '/selection.json');
$events = $byDate = [];
foreach ($selection['audit_ids'] as $id) {
    foreach ($read($dir . '/primary_' . $id . '.json')['stops'] as $event) {
        $key = hash('sha256', json_encode([$event['date'], $event['symbol'], $event['stop'], $event['fill'], $event['kind']]));
        $events[$key] = $event;
        $byDate[$event['date']][$event['symbol']] = true;
    }
}
ksort($byDate);
if ($events === []) { throw new RuntimeException('No stop events selected.'); }
if (!is_dir($out)) { mkdir($out, 0775, true); }
$protocol = ['started_at' => gmdate(DATE_ATOM), 'selection_sha256' => hash_file('sha256', $dir . '/selection.json'),
    'script_sha256' => hash_file('sha256', __FILE__), 'audit_ids' => $selection['audit_ids'],
    'events' => count($events), 'symbol_days' => array_sum(array_map('count', $byDate)), 'dates' => count($byDate),
    'feed' => 'Alpaca SIP adjustment=all', 'orders_submitted' => 0,
    'limits' => 'Event audit only, not a full path-dependent minute replay or proof of NBBO election/native broker execution. Next-minute open is a diagnostic, not a guaranteed fill.'];
if (file_exists($out . '/protocol.json')) {
    $frozen = $read($out . '/protocol.json');
    if ($frozen['selection_sha256'] !== $protocol['selection_sha256'] || $frozen['script_sha256'] !== $protocol['script_sha256']) { throw new RuntimeException('Minute audit resume drift.'); }
} else { A::write($out . '/protocol.json', $protocol); }
$http = new HttpClient();
if (!file_exists($out . '/calendar.json')) {
    A::write($out . '/calendar.json', (new AlpacaPaperClient($http, 'https://paper-api.alpaca.markets/v2'))->calendar(array_key_first($byDate), array_key_last($byDate)));
}
$calendar = array_column($read($out . '/calendar.json'), null, 'date');
$provider = new AlpacaBarsProvider($http, 'https://data.alpaca.markets', 'sip', 'all', 10000);
$zone = new DateTimeZone('America/New_York');
$rows = $sha = []; $fetched = $reused = 0;
foreach ($byDate as $date => $symbols) {
    $session = $calendar[$date] ?? throw new RuntimeException('Official session missing.');
    $file = $out . '/minutes_' . $date . '.json';
    if (file_exists($file)) { $minutes = D::decode($read($file)); }
    else {
        $oldFile = $root . '/var/reports/alpaca_stop_minutes_20260909/minutes_' . $date . '.json';
        $minutes = file_exists($oldFile) ? D::decode($read($oldFile)) : [];
        $missing = array_values(array_filter(array_keys($symbols), static fn ($symbol): bool => empty($minutes[$symbol])));
        $reused += count($symbols) - count($missing);
        if ($missing !== []) {
            $minutes = array_replace($minutes, $provider->getBars($missing, '1Min',
                (new DateTimeImmutable($date . ' ' . $session['open'], $zone))->format(DATE_ATOM),
                (new DateTimeImmutable($date . ' ' . $session['close'], $zone))->format(DATE_ATOM)));
            $fetched += count($missing);
        }
        A::write($file, D::encode($minutes));
    }
    $sha[basename($file)] = hash_file('sha256', $file);
    foreach ($events as $key => $event) {
        if ($event['date'] === $date) { $rows[$key] = RthStandingStopAudit::inspect($event, $minutes[$event['symbol']] ?? [], $session); }
    }
    printf("%s %d/%d events audited\n", $date, count($rows), count($events));
}
$delayed = array_values(array_filter(array_column($rows, 'next_minute_vs_daily_fill_bps'), static fn ($v): bool => $v !== null));
$report = ['completed_at' => gmdate(DATE_ATOM), 'events' => count($rows), 'symbol_days' => $protocol['symbol_days'], 'dates' => count($byDate),
    'rth_touch' => count(array_filter($rows, static fn ($r): bool => $r['rth_touch'])),
    'no_rth_touch' => count(array_filter($rows, static fn ($r): bool => !$r['rth_touch'])),
    'missing_opening_minute' => count(array_filter($rows, static fn ($r): bool => !$r['opening_minute_present'])),
    'delayed_observations' => count($delayed), 'delayed_worse' => count(array_filter($delayed, static fn ($v): bool => $v < -1e-6)),
    'delayed_bps' => $delayed === [] ? null : ['minimum' => D::quantile($delayed, 0), 'p05' => D::quantile($delayed, .05), 'median' => D::quantile($delayed, .5)],
    'fetched_symbol_days_this_invocation' => $fetched, 'reused_symbol_days_this_invocation' => $reused,
    'snapshots_sha256' => $sha, 'rows' => $rows, 'orders_submitted' => 0, 'proves_execution' => false];
A::write($out . '/results.json', $report);
echo json_encode(array_diff_key($report, ['rows' => true, 'snapshots_sha256' => true])), "\n";
