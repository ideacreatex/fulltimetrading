#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Backtest\TacticalRotationQualification;
use FulltimeTrading\Data\AlpacaBarsProvider;
use FulltimeTrading\Data\HttpClient;
use FulltimeTrading\Domain\Bar;
use FulltimeTrading\Research\AdaptiveResearchFactory;
use FulltimeTrading\Research\AlgorithmTrendResearch;
use FulltimeTrading\Research\DailyDataAudit;
use FulltimeTrading\Trading\TacticalImplementationIdentity;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__);
$options = getopt('', ['data-dir:', 'output-dir:']);
$directory = (string) ($options['data-dir'] ?? $root . '/var/reports/independent_data_20260908');
$out = (string) ($options['output-dir'] ?? $root . '/var/reports/data_attribution_20260908');
if (file_exists($out)) { throw new RuntimeException('Use a new output directory.'); }
$read = static fn (string $file): array => json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
$protocol = $read($directory . '/protocol.json');
$manifest = $read($directory . '/manifest.json');
$identity = TacticalImplementationIdentity::current($root, $protocol['profile']);
if ($identity !== $protocol['operational_identity']) { throw new RuntimeException('Operational identity mismatch.'); }
mkdir($out, 0775, true);
$symbols = array_merge($protocol['original_universe'], ['SPY', 'QQQ']);
sort($symbols, SORT_STRING);
$load = static function (string $id) use ($read, $directory, $manifest, $symbols): array {
    $entry = $manifest['snapshots'][$id];
    $file = $directory . '/' . $entry['file'];
    if (!hash_equals($entry['sha256'], hash_file('sha256', $file))) { throw new RuntimeException('Changed snapshot: ' . $id); }
    $rows = $read($file);
    $answer = [];
    foreach ($symbols as $symbol) {
        if (!isset($rows[$symbol])) { continue; }
        $answer[$symbol] = array_values(array_filter(DailyDataAudit::decode([$symbol => $rows[$symbol]])[$symbol],
            static fn (Bar $bar): bool => DailyDataAudit::session($bar) >= '2020-01-01' && DailyDataAudit::session($bar) <= '2026-09-04'));
    }
    return $answer;
};
$sip = $load('sip_split');
$iex = $load('iex_split');
$yahoo = [];
foreach ($symbols as $symbol) { $yahoo += $load('yahoo_' . $symbol); }
$corroboration = DailyDataAudit::corroboratedOutliers($sip, $yahoo, $iex);
$report = ['started_at' => gmdate(DATE_ATOM), 'cases' => $protocol['cases'], 'frozen_profile' => $protocol['profile'],
    'source_manifest_sha256' => hash_file('sha256', $directory . '/manifest.json'),
    'implementation_sha256' => ['runner' => hash_file('sha256', __FILE__), 'audit' => hash_file('sha256', $root . '/src/Research/DailyDataAudit.php')],
    'corroborated_outliers' => $corroboration['events'], 'corroboration_policy' => ['reference' => 'Alpaca SIP split',
        'second' => 'Yahoo quote OHLC', 'third' => 'Alpaca IEX split', 'agreement_bps' => 25, 'disagreement_bps' => 100],
    'selection' => 'Attribution of observed data sensitivity, not a new optimized strategy.',
    'deployed' => false, 'errors' => [], 'snapshots' => [], 'replays' => []];
AlgorithmTrendResearch::write($out . '/protocol.json', $report);
$saveSnapshot = static function (string $id, array $bars, array $request) use ($out, &$report): void {
    $file = $out . '/' . $id . '.json';
    AlgorithmTrendResearch::write($file, DailyDataAudit::encode($bars));
    $report['snapshots'][$id] = ['request' => $request, 'sha256' => hash_file('sha256', $file),
        'file' => basename($file), 'fetched_at' => gmdate(DATE_ATOM)];
    AlgorithmTrendResearch::write($out . '/progress.json', $report);
};
$adjusted = [];
foreach (['split_spinoff' => 'split,spin-off', 'split_dividend' => 'split,dividend'] as $id => $adjustment) {
    try {
        $bars = (new AlpacaBarsProvider(new HttpClient(), 'https://data.alpaca.markets', 'sip', $adjustment, 10000))
            ->getBars($symbols, '1Day', '2020-01-01', '2026-09-04T23:59:59Z');
        $saveSnapshot($id, $bars, ['source' => 'Alpaca', 'feed' => 'sip', 'adjustment' => $adjustment,
            'start' => '2020-01-01', 'end' => '2026-09-04T23:59:59Z', 'symbols' => $symbols]);
        $adjusted[$id] = $bars;
        printf("Fetched adjustment isolation %s\n", $id);
    } catch (Throwable $e) { $report['errors'][$id] = $e->getMessage(); }
}
$dates = [];
foreach ($sip['SPY'] as $bar) { if (DailyDataAudit::session($bar) >= '2026-07-16') { $dates[] = DailyDataAudit::session($bar); } }
foreach ($corroboration['events'] as $event) {
    if ($event['date'] >= '2021-01-04') { $dates[] = $event['date']; }
}
$dates = array_values(array_unique($dates));
sort($dates, SORT_STRING);
$report['minute_plan'] = ['sessions' => $dates, 'symbols' => $symbols, 'start_local' => '09:30:00', 'end_local' => '09:32:59',
    'timezone' => 'America/New_York', 'use' => 'opening-price sensitivity; not NBBO, fill or market-impact proof'];
AlgorithmTrendResearch::write($out . '/progress.json', $report);
$minute = [];
foreach ($dates as $date) {
    try {
        $start = new DateTimeImmutable($date . ' 09:30:00', new DateTimeZone('America/New_York'));
        $end = new DateTimeImmutable($date . ' 09:32:59', new DateTimeZone('America/New_York'));
        $bars = (new AlpacaBarsProvider(new HttpClient(), 'https://data.alpaca.markets', 'sip', 'split', 10000))
            ->getBars($symbols, '1Min', $start->format(DATE_ATOM), $end->format(DATE_ATOM));
        $saveSnapshot('minute_' . $date, $bars, ['source' => 'Alpaca', 'feed' => 'sip', 'adjustment' => 'split',
            'timeframe' => '1Min', 'start' => $start->format(DATE_ATOM), 'end' => $end->format(DATE_ATOM), 'symbols' => $symbols]);
        foreach ($bars as $symbol => $series) {
            foreach ($series as $bar) {
                $local = $bar->time->setTimezone(new DateTimeZone('America/New_York'));
                if ($local->format('Y-m-d') !== $date) { throw new RuntimeException('Minute session mismatch.'); }
                $key = $local->format('H:i');
                if (isset($minute[$symbol][$date][$key])) { throw new RuntimeException('Duplicate minute.'); }
                $minute[$symbol][$date][$key] = $bar;
            }
        }
        printf("Fetched opening window %s: %d bars\n", $date, array_sum(array_map('count', $bars)));
    } catch (Throwable $e) { $report['errors']['minute_' . $date] = $e->getMessage(); }
}
$plans = ['sip_control' => $sip, 'yahoo_control' => $yahoo, 'sip_corroborated_price_sensitivity' => $corroboration['bars']];
foreach ($adjusted as $id => $bars) { $plans[$id] = $bars; }
foreach ($symbols as $symbol) { $plans['only_yahoo_' . $symbol] = array_replace($sip, [$symbol => $yahoo[$symbol]]); }
foreach (['open_only' => ['open'], 'close_only' => ['close'], 'volume_only' => ['volume'], 'ohlc_only' => ['open', 'high', 'low', 'close']] as $id => $fields) {
    $plans['yahoo_' . $id] = DailyDataAudit::replaceFields($sip, $yahoo, $fields);
}
foreach (['09:30', '09:32'] as $clock) {
    $bars = $sip;
    $missing = [];
    $differences = [];
    $observed = 0;
    foreach ($bars as $symbol => &$series) {
        foreach ($series as $i => $bar) {
            $date = DailyDataAudit::session($bar);
            if (!in_array($date, $dates, true)) { continue; }
            $open = $minute[$symbol][$date][$clock]->open ?? null;
            if ($open === null) { $missing[] = $symbol . '/' . $date; continue; }
            $delta = abs($open / $bar->open - 1.0) * 10000.0;
            $observed++;
            if ($delta > 25.0) { $differences[] = ['symbol' => $symbol, 'date' => $date, 'bps' => $delta, 'daily_open' => $bar->open, 'minute_open' => $open]; }
            $series[$i] = new Bar($symbol, $bar->time, $open, max($bar->high, $open), min($bar->low, $open), $bar->close, $bar->volume);
        }
    }
    unset($series);
    usort($differences, static fn (array $a, array $b): int => $b['bps'] <=> $a['bps']);
    $report['opening_comparison'][$clock] = ['observed' => $observed, 'missing' => $missing, 'above_25_bps' => count($differences),
        'largest' => array_slice($differences, 0, 30), 'partial_missing_keeps_daily_open' => $missing !== []];
    if ($missing === []) { $plans['minute_open_' . str_replace(':', '', $clock)] = $bars; }
    else { $report['errors']['minute_replay_' . $clock] = 'Skipped: incomplete minute-open coverage; no daily fallback used for replay.'; }
}
AlgorithmTrendResearch::write($out . '/attribution_plan.json', ['created_at' => gmdate(DATE_ATOM), 'scenarios' => array_keys($plans),
    'cost_bps' => 30, 'case_policy' => 'All three models for controls/adjustments; new_best only for each single-symbol substitution.',
    'minute_sample' => $report['minute_plan'], 'operational_identity' => $identity]);
foreach ($plans as $id => $bars) {
    foreach ($protocol['cases'] as $case => $changes) {
        if (str_starts_with($id, 'only_yahoo_') && $case !== 'new_best') { continue; }
        try {
            $tester = AdaptiveResearchFactory::make($protocol['profile'], $changes, 30.0);
            $run = $tester->run($bars, '2021-01-04', '2026-09-04');
            $metrics = ['full' => $tester->metrics($run), 'train' => $tester->metrics($run, '2021-01-04', '2024-01-01'),
                'validation' => $tester->metrics($run, '2024-01-01', '2026-01-01'), 'later_2026' => $tester->metrics($run, '2026-01-01'),
                'tail' => $tester->metrics($run, '2026-07-16')];
            $annual = [];
            for ($year = 2021; $year <= 2026; $year++) { $annual[$year] = $tester->metrics($run, "$year-01-01", ($year + 1) . '-01-01'); }
            $report['replays'][$id][$case] = ['metrics' => $metrics, 'annual' => $annual,
                'qualification' => (new TacticalRotationQualification($protocol['profile']['validation']))->evaluate(
                    $metrics['train'], $metrics['validation'], $metrics['later_2026'], $metrics['full'], $annual)];
            $curve = array_map(static fn (array $row): array => array_intersect_key($row, array_flip([
                'date', 'start_equity', 'equity', 'equity_low', 'equity_high', 'gross_bound', 'holdings', 'turnover'])), $run['curve']);
            AlgorithmTrendResearch::write($out . '/' . $id . '_' . $case . '_curve.json', $curve);
            $report['replays'][$id][$case]['curve_sha256'] = hash_file('sha256', $out . '/' . $id . '_' . $case . '_curve.json');
            AlgorithmTrendResearch::write($out . '/progress.json', $report);
            printf("Attribution %s/%s: CAGR %.2f%% DD %.2f%%\n", $id, $case, 100 * $metrics['full']['cagr'], 100 * $metrics['full']['max_drawdown']);
            unset($tester, $run);
        } catch (Throwable $e) { $report['errors']['replay_' . $id . '_' . $case] = $e->getMessage(); }
    }
}
$report['finished_at'] = gmdate(DATE_ATOM);
$report['operational_identity_unchanged'] = $identity === TacticalImplementationIdentity::current($root, $protocol['profile']);
if (!$report['operational_identity_unchanged']) { throw new RuntimeException('Operational identity changed.'); }
AlgorithmTrendResearch::write($out . '/results.json', $report);
echo "Data attribution complete: $out/results.json\n";
