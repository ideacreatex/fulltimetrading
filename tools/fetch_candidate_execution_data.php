#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Data\AlpacaBarsProvider;
use FulltimeTrading\Data\HttpClient;
use FulltimeTrading\Research\AlgorithmTrendResearch as A;
use FulltimeTrading\Research\DailyDataAudit as D;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__);
$end = $argv[1] ?? '2026-09-14';
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $end)) { throw new InvalidArgumentException('Canonical end date required.'); }
$profile = require $root . '/config/tactical_rotation.php';
$symbols = array_values(array_unique(array_merge($profile['universe'], ['SPY', 'QQQ', 'SVXY'])));
sort($symbols, SORT_STRING);
$out = $root . '/var/reports/candidate_execution_data_' . str_replace('-', '', $end);
if (!is_dir($out)) { mkdir($out, 0775, true); }
$read = static fn ($path): array => json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$manifest = ['provider' => 'Alpaca', 'feed' => 'sip', 'host' => 'https://data.alpaca.markets',
    'start' => '2016-01-01', 'end' => $end, 'symbols' => $symbols, 'adjustments' => ['raw', 'split'],
    'purpose' => 'Nominal whole-share execution and split-only no-dividend replay; no orders.', 'orders_submitted' => 0];
if (file_exists($out . '/protocol.json') && $read($out . '/protocol.json') !== $manifest) { throw new RuntimeException('Data contract drift.'); }
A::write($out . '/protocol.json', $manifest);
foreach ($manifest['adjustments'] as $adjustment) {
    $file = $out . '/' . $adjustment . '.json';
    if (file_exists($file) && file_exists($out . '/' . $adjustment . '_manifest.json')) {
        $saved = $read($out . '/' . $adjustment . '_manifest.json');
        if (hash_file('sha256', $file) !== $saved['sha256']) { throw new RuntimeException('Frozen price file changed.'); }
        echo $adjustment, " verified cache\n"; continue;
    }
    // Delayed SIP rejects a future/recent end even when the daily session has closed.
    $requestedEnd = min($end . 'T23:59:59Z', gmdate('Y-m-d\TH:i:s\Z', time() - 16 * 60));
    $bars = (new AlpacaBarsProvider(new HttpClient(), $manifest['host'], 'sip', $adjustment, 10000))
        ->getBars($symbols, '1Day', $manifest['start'], $requestedEnd);
    $coverage = D::coverage($bars);
    foreach ($coverage as $symbol => $row) {
        if ($row['bars'] === 0 || $row['internal_missing_sessions'] !== [] || $row['extra_sessions'] !== [] || $row['last'] !== $end) {
            throw new RuntimeException('Incomplete execution history: ' . $symbol);
        }
    }
    A::write($file, D::encode($bars));
    A::write($out . '/' . $adjustment . '_manifest.json', ['sha256' => hash_file('sha256', $file),
        'protocol_sha256' => hash_file('sha256', $out . '/protocol.json'), 'requested_end' => $requestedEnd,
        'coverage' => $coverage, 'captured_at' => gmdate(DATE_ATOM)]);
    echo $adjustment, ': ', array_sum(array_column($coverage, 'bars')), " bars\n";
}
