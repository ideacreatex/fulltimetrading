#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Data\HttpClient;
use FulltimeTrading\Domain\Bar;
use FulltimeTrading\Research\AdaptiveResearchFactory;
use FulltimeTrading\Research\AlgorithmTrendResearch;
use FulltimeTrading\Research\DailyDataAudit;
use FulltimeTrading\Trading\TacticalImplementationIdentity;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__);
$options = getopt('', ['data-dir:', 'attribution-dir:', 'output-dir:']);
$directory = (string) ($options['data-dir'] ?? $root . '/var/reports/independent_data_20260908');
$attribution = (string) ($options['attribution-dir'] ?? $root . '/var/reports/data_attribution_20260908');
$out = (string) ($options['output-dir'] ?? $root . '/var/reports/opening_audit_20260908');
if (file_exists($out)) { throw new RuntimeException('Use a new output directory.'); }
$read = static fn (string $file): array => json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
$protocol = $read($directory . '/protocol.json');
$manifest = $read($directory . '/manifest.json');
$attr = $read($attribution . '/results.json');
$identity = TacticalImplementationIdentity::current($root, $protocol['profile']);
if ($identity !== $protocol['operational_identity']) { throw new RuntimeException('Operational identity mismatch.'); }
mkdir($out, 0775, true);
$load = static function (string $base, array $entry) use ($read): array {
    $file = $base . '/' . $entry['file'];
    if (!hash_equals($entry['sha256'], hash_file('sha256', $file))) { throw new RuntimeException('Source snapshot drift.'); }
    return DailyDataAudit::decode($read($file));
};
$all = $load($directory, $manifest['snapshots']['sip_split']);
$raw = [];
foreach (array_merge($protocol['original_universe'], ['SPY', 'QQQ']) as $symbol) {
    $raw[$symbol] = array_values(array_filter($all[$symbol], static fn (Bar $b): bool => DailyDataAudit::session($b) >= '2020-01-01'));
}
unset($all);
$sources = ['sip_split' => $raw, 'sip_split_spinoff' => $load($attribution, $attr['snapshots']['split_spinoff'])];
$rawIndex = DailyDataAudit::indexed($raw);
$minute = [];
foreach ($attr['snapshots'] as $id => $entry) {
    if (!str_starts_with($id, 'minute_') || substr($id, 7) < '2026-07-16') { continue; }
    $bars = $load($attribution, $entry);
    foreach ($bars as $symbol => $series) {
        foreach ($series as $bar) {
            $local = $bar->time->setTimezone(new DateTimeZone('America/New_York'));
            $minute[$symbol][$local->format('Y-m-d')][$local->format('H:i')] = $bar;
        }
    }
}
$report = ['started_at' => gmdate(DATE_ATOM), 'sample_start' => '2026-07-16', 'sample_end' => '2026-09-04',
    'cost_bps' => 30, 'clocks' => ['daily', '09:30', '09:32'], 'cases' => $protocol['cases'],
    'source_attribution_sha256' => hash_file('sha256', $attribution . '/results.json'),
    'implementation_sha256' => ['runner' => hash_file('sha256', __FILE__), 'audit' => hash_file('sha256', $root . '/src/Research/DailyDataAudit.php')],
    'missing_policy' => 'Only retain a daily open if that symbol has NO modeled buy, sell or resize on that date in the transformed replay; otherwise mark replay invalid.',
    'limitation' => 'Sampled minute-open proxies, not actual auction fills, full-period intraday proof or market impact.',
    'replays' => [], 'quotes' => [], 'errors' => [], 'deployed' => false];
AlgorithmTrendResearch::write($out . '/protocol.json', $report);
foreach ($sources as $source => $base) {
    foreach (['daily', '09:30', '09:32'] as $clock) {
        $bars = $base;
        $missing = [];
        $replaced = 0;
        foreach ($bars as $symbol => &$series) {
            foreach ($series as $i => $bar) {
                $date = DailyDataAudit::session($bar);
                if ($date < '2026-07-16' || $clock === 'daily') { continue; }
                $open = $minute[$symbol][$date][$clock]->open ?? null;
                if ($open === null) { $missing[] = $symbol . '/' . $date; continue; }
                // Match the reference price basis; do not mix pre-spin-off and raw prices.
                $open *= $bar->close / $rawIndex[$symbol][$date]->close;
                $series[$i] = new Bar($symbol, $bar->time, $open, max($bar->high, $open), min($bar->low, $open), $bar->close, $bar->volume);
                $replaced++;
            }
        }
        unset($series);
        foreach ($protocol['cases'] as $case => $changes) {
            $tester = AdaptiveResearchFactory::make($protocol['profile'], $changes, 30.0);
            $run = $tester->run($bars, '2021-01-04', '2026-09-04');
            $affected = DailyDataAudit::missingExecutionBars($run['sleeve_curves'], $missing);
            $row = ['valid_for_sample' => $affected === [], 'replaced_opens' => $replaced, 'missing_bars' => $missing,
                'affected_executions' => $affected, 'full' => $affected === [] ? $tester->metrics($run) : null,
                'tail' => $affected === [] ? $tester->metrics($run, '2026-07-16') : null];
            $report['replays'][$source][$clock][$case] = $row;
            AlgorithmTrendResearch::write($out . '/progress.json', $report);
            printf("Opening %s/%s/%s: valid=%s, tail=%s, missing executions=%d\n", $source, $clock, $case,
                $affected === [] ? 'yes' : 'no', $affected === [] ? sprintf('%.4f%%', 100 * $row['tail']['return']) : 'unavailable', count($affected));
            unset($tester, $run);
        }
    }
}
$http = new HttpClient();
$headers = ['APCA-API-KEY-ID' => getenv('APCA_DATA_API_KEY_ID') ?: getenv('APCA_API_KEY_ID') ?: '',
    'APCA-API-SECRET-KEY' => getenv('APCA_DATA_API_SECRET_KEY') ?: getenv('APCA_API_SECRET_KEY') ?: ''];
$quoteSymbols = ['MSFT', 'NVDA', 'TSLA', 'SMCI', 'MSTR', 'PLTR'];
foreach (['2026-08-31', '2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04'] as $date) {
    try {
        $start = new DateTimeImmutable($date . ' 09:30:00', new DateTimeZone('America/New_York'));
        $end = $start->modify('+3 seconds');
        $query = ['symbols' => implode(',', $quoteSymbols), 'start' => $start->format(DATE_ATOM), 'end' => $end->format(DATE_ATOM),
            'feed' => 'sip', 'limit' => 10000, 'sort' => 'asc'];
        $quotes = [];
        $next = null;
        $pages = 0;
        do {
            if ($next !== null) { $query['page_token'] = $next; }
            $response = $http->get('https://data.alpaca.markets/v2/stocks/quotes?' . http_build_query($query), $headers);
            if ($response['status'] !== 200) { throw new RuntimeException('Data-only quotes HTTP ' . $response['status']); }
            $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
            AlgorithmTrendResearch::write($out . '/quotes_' . $date . '_' . $pages . '.json', $payload);
            foreach ($payload['quotes'] ?? [] as $symbol => $series) { $quotes[$symbol] = array_merge($quotes[$symbol] ?? [], $series); }
            $next = $payload['next_page_token'] ?? null;
            $pages++;
        } while ($next !== null && $next !== '' && $pages < 5);
        $window = ['pages' => $pages, 'truncated' => $next !== null && $next !== '', 'symbols' => []];
        foreach ($quoteSymbols as $symbol) {
            $spreads = [];
            $invalid = 0;
            foreach ($quotes[$symbol] ?? [] as $q) {
                $bid = (float) ($q['bp'] ?? 0);
                $ask = (float) ($q['ap'] ?? 0);
                if ($bid <= 0.0 || $ask < $bid || ($q['bs'] ?? 0) <= 0 || ($q['as'] ?? 0) <= 0) { $invalid++; continue; }
                $spreads[] = ($ask - $bid) / (($ask + $bid) / 2.0) * 10000.0;
            }
            $window['symbols'][$symbol] = ['valid_quotes' => count($spreads), 'invalid_quotes' => $invalid,
                'full_spread_bps_p50' => DailyDataAudit::quantile($spreads, 0.5), 'full_spread_bps_p95' => DailyDataAudit::quantile($spreads, 0.95)];
        }
        $report['quotes'][$date] = $window;
        AlgorithmTrendResearch::write($out . '/progress.json', $report);
        printf("Opening NBBO sample %s: %d pages, truncated=%s\n", $date, $pages, $window['truncated'] ? 'yes' : 'no');
    } catch (Throwable $e) { $report['errors']['quotes_' . $date] = $e->getMessage(); }
}
$report['finished_at'] = gmdate(DATE_ATOM);
$report['operational_identity_unchanged'] = $identity === TacticalImplementationIdentity::current($root, $protocol['profile']);
if (!$report['operational_identity_unchanged']) { throw new RuntimeException('Operational implementation changed.'); }
AlgorithmTrendResearch::write($out . '/results.json', $report);
echo "Opening execution audit complete: $out/results.json\n";
