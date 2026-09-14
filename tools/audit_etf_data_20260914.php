#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Data\AlpacaBarsProvider;
use FulltimeTrading\Data\HttpClient;
use FulltimeTrading\Research\AlgorithmTrendResearch as A;
use FulltimeTrading\Research\DailyDataAudit as D;
use FulltimeTrading\Research\StandardEtfResearch as S;
use FulltimeTrading\Trading\AlpacaPaperClient;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__); $out = $root . '/var/reports/etf_data_audit_20260914';
$read = static fn ($p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
$files = [$root . '/var/reports/alpaca_stops_data_20260909/sip_all.json', $root . '/var/reports/cross_asset_data_20260909/alpaca_sip_all.json'];
$symbols = ['SPY', 'SHY']; foreach (S::definitions() as $c) { $symbols = array_merge($symbols, $c['symbols']); }
$symbols = array_flip($symbols); $events = [];
foreach ($files as $file) {
    foreach (array_intersect_key($read($file), $symbols) as $s => $series) {
        foreach ($series as $bar) {
            $date = substr($bar['t'], 0, 10);
            if ($date < '2017-01-01' || $date > '2026-09-04') { continue; }
            if ($bar['h'] / max($bar['o'], $bar['c']) > 1.4 || $bar['l'] / min($bar['o'], $bar['c']) < .6) {
                $events[] = ['symbol' => $s, 'date' => $date, 'frozen_bar' => $bar, 'source_sha256' => hash_file('sha256', $file)];
            }
        }
    }
}
if (!is_dir($out)) { mkdir($out, 0775, true); }
$http = new HttpClient(); $provider = new AlpacaBarsProvider($http, 'https://data.alpaca.markets', 'sip', 'all', 10000);
$client = new AlpacaPaperClient($http, 'https://paper-api.alpaca.markets/v2'); $zone = new DateTimeZone('America/New_York');
foreach ($events as &$event) {
    $d = $event['date']; $s = $event['symbol']; $file = $out . '/' . $s . '_' . $d . '.json';
    if (file_exists($file)) { $snapshot = $read($file); }
    else {
        $session = $client->calendar($d, $d)[0] ?? throw new RuntimeException('Calendar missing.');
        $start = new DateTimeImmutable($d . ' ' . $session['open'], $zone); $end = new DateTimeImmutable($d . ' ' . $session['close'], $zone);
        $snapshot = ['calendar' => $session, 'minutes' => D::encode($provider->getBars([$s], '1Min', $start->format(DATE_ATOM), $end->format(DATE_ATOM))),
            'daily_refetched' => D::encode($provider->getBars([$s], '1Day', $d . 'T00:00:00-05:00', $d . 'T23:59:59-05:00'))];
        A::write($file, $snapshot);
    }
    $session = $snapshot['calendar'];
    $start = new DateTimeImmutable($d . ' ' . $session['open'], $zone); $end = new DateTimeImmutable($d . ' ' . $session['close'], $zone);
    $rth = array_values(array_filter(D::decode($snapshot['minutes'])[$s] ?? [], static fn ($b): bool => $b->time >= $start && $b->time < $end));
    if ($rth === []) { throw new RuntimeException('Minute evidence missing.'); }
    $event['rth_minutes'] = count($rth); $event['rth_minimum'] = min(array_map(static fn ($b): float => $b->low, $rth));
    $event['rth_maximum'] = max(array_map(static fn ($b): float => $b->high, $rth));
    $event['daily_refetched'] = $snapshot['daily_refetched'][$s] ?? [];
    $event['snapshot_sha256'] = hash_file('sha256', $file);
}
unset($event);
$result = ['checked_at' => gmdate(DATE_ATOM), 'script_sha256' => hash_file('sha256', __FILE__), 'flags' => $events,
    'rule' => 'Daily high > 1.4 times max(open,close), or daily low < 0.6 times min(open,close); fixed diagnostic, no automatic price edits.',
    'orders_submitted' => 0, 'limits' => 'RTH minute range is not consolidated NBBO proof. Refetching a bar is evidence, not permission to hand-repair frozen data.'];
A::write($out . '/results.json', $result); echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
