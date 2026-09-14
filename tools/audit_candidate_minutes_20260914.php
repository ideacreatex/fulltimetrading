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
$root = dirname(__DIR__); $dir = $root . '/var/reports/paper_candidate_20260914'; $out = $dir . '/minutes';
$read = static fn ($p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
$events = $byDate = $inputs = [];
foreach (['continuous', 'fresh2023'] as $scenario) {
    $file = $dir . '/' . $scenario . '_baseline.json'; $source = $read($file); $inputs[$scenario] = hash_file('sha256', $file);
    if (!$source['exact_frozen_curve_match']) { throw new RuntimeException('Candidate replay not verified.'); }
    foreach ($source['stops'] as $event) {
        $key = hash('sha256', json_encode([$event['date'], $event['symbol'], $event['stop'], $event['fill'], $event['kind']], JSON_THROW_ON_ERROR));
        $events[$key] = $event; $byDate[$event['date']][$event['symbol']] = true;
    }
}
ksort($byDate);
$protocol = ['input_sha256' => $inputs, 'script_sha256' => hash_file('sha256', __FILE__), 'events' => count($events),
    'symbol_days' => array_sum(array_map('count', $byDate)), 'feed' => 'Alpaca SIP adjustment=all',
    'limits' => 'Candidate-specific RTH touch audit, not NBBO election, guaranteed fills, or complete minute path replay.', 'orders_submitted' => 0];
if (!is_dir($out)) { mkdir($out, 0775, true); }
if (file_exists($out . '/protocol.json')) {
    if ($read($out . '/protocol.json') !== $protocol) { throw new RuntimeException('Minute protocol drift.'); }
} else { A::write($out . '/protocol.json', $protocol); }
$http = new HttpClient();
if (!file_exists($out . '/calendar.json')) {
    A::write($out . '/calendar.json', (new AlpacaPaperClient($http, 'https://paper-api.alpaca.markets/v2'))->calendar(array_key_first($byDate), array_key_last($byDate)));
}
$calendar = array_column($read($out . '/calendar.json'), null, 'date');
$provider = new AlpacaBarsProvider($http, 'https://data.alpaca.markets', 'sip', 'all', 10000);
$zone = new DateTimeZone('America/New_York'); $rows = $sha = []; $fetched = $reused = 0;
foreach ($byDate as $date => $symbols) {
    $session = $calendar[$date] ?? throw new RuntimeException('Missing official session.'); $file = $out . '/minutes_' . $date . '.json';
    if (file_exists($file)) { $minutes = D::decode($read($file)); }
    else {
        $minutes = [];
        foreach (['selected_maximum_20260909/minutes', 'alpaca_stop_minutes_20260909'] as $cache) {
            $old = $root . '/var/reports/' . $cache . '/minutes_' . $date . '.json';
            if (file_exists($old)) { $minutes = array_replace($minutes, D::decode($read($old))); }
        }
        $minutes = array_intersect_key($minutes, $symbols);
        $missing = array_values(array_filter(array_keys($symbols), static fn ($s): bool => empty($minutes[$s])));
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
    printf("%s %d/%d unique stops audited\n", $date, count($rows), count($events));
}
$delay = array_values(array_filter(array_column($rows, 'next_minute_vs_daily_fill_bps'), static fn ($v): bool => $v !== null));
$report = ['completed_at' => gmdate(DATE_ATOM), 'protocol_sha256' => hash_file('sha256', $out . '/protocol.json'),
    'events' => count($rows), 'symbol_days' => $protocol['symbol_days'], 'dates' => count($byDate),
    'rth_touch' => count(array_filter($rows, static fn ($r): bool => $r['rth_touch'])),
    'no_rth_touch' => count(array_filter($rows, static fn ($r): bool => !$r['rth_touch'])),
    'missing_opening_minute' => count(array_filter($rows, static fn ($r): bool => !$r['opening_minute_present'])),
    'delayed_observations' => count($delay), 'delayed_worse' => count(array_filter($delay, static fn ($v): bool => $v < -1e-6)),
    'delayed_bps' => ['minimum' => D::quantile($delay, 0), 'p05' => D::quantile($delay, .05), 'median' => D::quantile($delay, .5)],
    'fetched_symbol_days_this_invocation' => $fetched, 'reused_symbol_days_this_invocation' => $reused,
    'snapshots_sha256' => $sha, 'rows' => $rows, 'orders_submitted' => 0, 'proves_execution' => false];
A::write($out . '/results.json', $report);
echo json_encode(array_diff_key($report, ['rows' => true, 'snapshots_sha256' => true]), JSON_THROW_ON_ERROR), "\n";
