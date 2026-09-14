#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Data\HttpClient;
use FulltimeTrading\Paper\CandidateOrder;
use FulltimeTrading\Research\AlgorithmTrendResearch as A;
use FulltimeTrading\Research\BreadthVolatilityResearch as B;
use FulltimeTrading\Research\DailyDataAudit as D;

require dirname(__DIR__) . '/bootstrap.php';
set_error_handler(static function (int $n, string $s): never { throw new RuntimeException($s); });
$root = dirname(__DIR__); $end = $argv[1] ?? '2026-09-14'; CandidateOrder::date($end);
$data = $root . '/var/reports/candidate_execution_data_' . str_replace('-', '', $end);
$out = $root . '/var/reports/candidate_external_data_' . str_replace('-', '', $end);
$read = static fn ($p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
$priceManifest = $read($data . '/split_manifest.json');
if (hash_file('sha256', $data . '/split.json') !== $priceManifest['sha256']) { throw new RuntimeException('Alpaca calendar snapshot drift.'); }
$all = D::decode($read($data . '/split.json')); $calendar = array_keys(D::indexed(['SPY' => $all['SPY']])['SPY']); unset($all);
if (end($calendar) !== $end) { throw new RuntimeException('External end differs from Alpaca history.'); }
if (!is_dir($out)) { mkdir($out, 0775, true); }
if (file_exists($out . '/manifest.json')) {
    $m = $read($out . '/manifest.json');
    if ($m['end'] !== $end || $m['alpaca_calendar_sha256'] !== $priceManifest['sha256']) { throw new RuntimeException('External snapshot contract drift.'); }
    foreach ($m['snapshots'] as $id => $s) { if (hash_file('sha256', $out . '/' . $id . '.json') !== $s['sha256']) { throw new RuntimeException('External snapshot content drift.'); } }
    echo "candidate external data: verified frozen snapshot through {$end}\n"; exit;
}
$http = new HttpClient();
$url = 'https://api.investing.com/api/financialdata/historical/1225365?start-date=2010-01-01&end-date=' . $end . '&time-frame=Daily';
$r = $http->get($url, ['domain-id' => 'www', 'Referer' => 'https://www.investing.com/']);
if ($r['status'] !== 200) { throw new RuntimeException('S5TW HTTP ' . $r['status']); }
$raw = json_decode($r['body'], true, 512, JSON_THROW_ON_ERROR); $breadth = [];
foreach ($raw['data'] ?? [] as $row) {
    $date = substr($row['rowDateTimestamp'], 0, 10); CandidateOrder::date($date);
    if ($date > $end || isset($breadth[$date])) { throw new RuntimeException('S5TW duplicate/future date.'); }
    $bar = [];
    foreach (['open' => 'last_openRaw', 'high' => 'last_maxRaw', 'low' => 'last_minRaw', 'close' => 'last_closeRaw'] as $key => $source) {
        if (!is_numeric($row[$source] ?? null) || !is_finite((float) $row[$source])) { throw new RuntimeException('S5TW nonnumeric value.'); }
        $bar[$key] = (float) $row[$source];
    }
    $breadth[$date] = $bar;
}
ksort($breadth); $errors = [];
try { B::validateBreadth($breadth, $calendar); } catch (Throwable $e) { $errors[] = $e->getMessage(); }
if (array_key_last($breadth) !== $end) { $errors[] = 'S5TW latest=' . array_key_last($breadth); }
$snapshots['s5tw'] = ['source' => $url,
    'raw_sha256' => hash('sha256', $r['body']), 'captured_at' => gmdate(DATE_ATOM), 'last' => array_key_last($breadth)];
$url = 'https://cdn.cboe.com/api/global/us_indices/daily_prices/VVIX_History.csv';
$r = $http->get($url);
if ($r['status'] !== 200) { throw new RuntimeException('Cboe VVIX HTTP ' . $r['status']); }
$lines = preg_split('/\r?\n/', trim($r['body']));
if (str_getcsv(array_shift($lines), ',', '"', '') !== ['DATE', 'VVIX']) { throw new RuntimeException('Unexpected Cboe schema.'); }
$vvix = [];
foreach ($lines as $line) {
    if (trim($line) === '') { continue; }
    [$inputDate, $value] = str_getcsv($line, ',', '"', '');
    $parsed = DateTimeImmutable::createFromFormat('!m/d/Y', $inputDate);
    if ($parsed === false || $parsed->format('m/d/Y') !== $inputDate) { throw new RuntimeException('Invalid Cboe date.'); }
    $date = $parsed->format('Y-m-d');
    if ($date > $end) { continue; }
    if (isset($vvix[$date]) || !is_numeric($value) || !is_finite((float) $value) || $value <= 0) { throw new RuntimeException('Invalid VVIX row.'); }
    $vvix[$date] = (float) $value;
}
ksort($vvix);
foreach ($calendar as $date) { if (!isset($vvix[$date])) { $errors[] = 'Cboe VVIX missing Alpaca session: ' . $date; } }
if (array_key_last($vvix) !== $end) { $errors[] = 'Cboe VVIX latest=' . array_key_last($vvix); }
$snapshots['vvix'] = ['source' => $url,
    'raw_sha256' => hash('sha256', $r['body']), 'captured_at' => gmdate(DATE_ATOM), 'last' => array_key_last($vvix)];
A::write($out . '/availability.json', ['checked_at' => gmdate(DATE_ATOM), 'required_session' => $end,
    'sources' => $snapshots, 'errors' => $errors, 'new_entries_allowed' => false]);
if ($errors !== []) { throw new RuntimeException('External data unavailable: ' . implode('; ', $errors)); }
foreach (['s5tw' => $breadth, 'vvix' => $vvix] as $id => $series) {
    A::write($out . '/' . $id . '.json', $series); $snapshots[$id]['sha256'] = hash_file('sha256', $out . '/' . $id . '.json');
}
A::write($out . '/manifest.json', ['end' => $end, 'alpaca_calendar_sha256' => $priceManifest['sha256'],
    'snapshots' => $snapshots, 'orders_submitted' => 0, 'publication_note' => 'Historical vendor series, not point-in-time constituent reconstruction.']);
echo "candidate external data: S5TW and Cboe VVIX complete through {$end}; no Yahoo fallback\n";
