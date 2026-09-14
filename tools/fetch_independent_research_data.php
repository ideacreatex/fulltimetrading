#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Data\AlpacaBarsProvider;
use FulltimeTrading\Data\HttpClient;
use FulltimeTrading\Data\YahooChartProvider;
use FulltimeTrading\Research\AdaptiveResearchFactory;
use FulltimeTrading\Research\AlgorithmTrendResearch;
use FulltimeTrading\Research\DailyDataAudit;
use FulltimeTrading\Research\OfflineHybridDataset;
use FulltimeTrading\Trading\TacticalImplementationIdentity;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__);
$options = getopt('', ['output-dir:']);
$out = (string) ($options['output-dir'] ?? $root . '/var/reports/independent_data_20260908');
if (file_exists($out)) { throw new RuntimeException('Use a new research snapshot directory.'); }
mkdir($out, 0775, true);
$original = OfflineHybridDataset::load($root, '2026-09-04');
$identity = TacticalImplementationIdentity::current($root, $original['profile']);
$oldProtocol = json_decode(file_get_contents($root . '/var/reports/algorithm_trends_20260908/protocol.json'), true, 512, JSON_THROW_ON_ERROR);
$comboProtocol = json_decode(file_get_contents($root . '/var/reports/algorithm_combinations_20260908/protocol.json'), true, 512, JSON_THROW_ON_ERROR);
$cases = ['baseline' => $oldProtocol['cases']['baseline']['changes'],
    'previous_best' => $oldProtocol['cases']['previous_best']['changes'],
    'new_best' => $comboProtocol['cases']['combo_prior_best_downside_size_0p5_dynamic']['changes']];
$extra = ['ABT', 'AMGN', 'BA', 'BAC', 'BBY', 'BNY', 'C', 'CAT', 'COP', 'CSCO', 'CVS', 'CVX', 'DE', 'DG', 'DIS',
    'DLTR', 'DUK', 'F', 'FCX', 'FDX', 'GS', 'IBM', 'INTC', 'JNJ', 'JPM', 'KO', 'KSS', 'MCD', 'MMM', 'MO', 'NEE',
    'NFLX', 'NUE', 'ORCL', 'OXY', 'PEP', 'PFE', 'PG', 'SO', 'T', 'TGT', 'TMUS', 'UNH', 'UPS', 'VZ', 'WFC', 'WMT', 'XOM'];
$symbols = array_keys($original['bars']);
$all = array_values(array_unique(array_merge($symbols, $extra)));
sort($all, SORT_STRING);
$protocol = ['created_at' => gmdate(DATE_ATOM), 'end' => '2026-09-04', 'operational_identity' => $identity,
    'profile' => $original['profile'], 'cases' => $cases, 'original_universe' => $original['profile']['universe'],
    'additional_universe' => $extra, 'original_provenance' => $original['provenance'],
    'selection' => 'Three previously frozen candidates; no parameter selection on the new sources.',
    'limitations' => ['Fixed additional universe is a transfer stress, NOT survivorship-free historical membership.',
        'Independent distributor is not an independent realized market history.', 'No future observations are manufactured.',
        'Yahoo quote OHLC is used, not adjclose. Alpaca all-adjusted sensitivity is not a cash-dividend execution ledger.'],
    'costs_bps' => [20, 30, 40], 'production_approved' => false, 'order_submission_enabled' => false];
foreach ($cases as $id => $changes) {
    $protocol['candidate_config_sha256'][$id] = hash('sha256', json_encode(AdaptiveResearchFactory::make($original['profile'], $changes, 30.0)->config(), JSON_THROW_ON_ERROR));
}
AlgorithmTrendResearch::write($out . '/protocol.json', $protocol);
AlgorithmTrendResearch::write($out . '/original.json', DailyDataAudit::encode($original['bars']));
$manifest = ['started_at' => gmdate(DATE_ATOM), 'snapshots' => [], 'errors' => []];
$save = static function (string $id, array $bars, array $request) use ($out, &$manifest): void {
    DailyDataAudit::indexed($bars);
    $file = $out . '/' . $id . '.json';
    AlgorithmTrendResearch::write($file, DailyDataAudit::encode($bars));
    $manifest['snapshots'][$id] = ['request' => $request, 'fetched_at' => gmdate(DATE_ATOM),
        'file' => basename($file), 'sha256' => hash_file('sha256', $file), 'bars' => array_sum(array_map('count', $bars))];
    AlgorithmTrendResearch::write($out . '/manifest.json', $manifest);
    printf("saved %s: %d bars\n", $id, $manifest['snapshots'][$id]['bars']);
};
foreach ([['sip_split', 'sip', 'split', '2016-01-01', $all], ['sip_all', 'sip', 'all', '2020-01-01', $symbols],
    ['iex_split', 'iex', 'split', '2020-01-01', $symbols]] as [$id, $feed, $adjustment, $start, $requested]) {
    try {
        $bars = (new AlpacaBarsProvider(new HttpClient(), 'https://data.alpaca.markets', $feed, $adjustment, 10000))
            ->getBars($requested, '1Day', $start, '2026-09-04T23:59:59Z');
        $save($id, $bars, ['provider' => 'Alpaca', 'feed' => $feed, 'adjustment' => $adjustment,
            'start' => $start, 'end' => '2026-09-04T23:59:59Z', 'symbols' => $requested]);
    } catch (Throwable $e) {
        $manifest['errors'][$id] = $e->getMessage();
        AlgorithmTrendResearch::write($out . '/manifest.json', $manifest);
        fprintf(STDERR, "%s unavailable: %s\n", $id, $e->getMessage());
    }
}
$yahoo = new YahooChartProvider(new HttpClient());
foreach ($all as $symbol) {
    try {
        $bars = $yahoo->getBars([$symbol], '1Day', '1998-01-01', '2026-09-04');
        if ($bars[$symbol] === []) { throw new RuntimeException('No Yahoo daily bars.'); }
        $save('yahoo_' . $symbol, $bars, ['provider' => 'Yahoo', 'feed' => 'chart_quote_ohlcv',
            'adjustment' => 'provider_quote_not_adjc', 'start' => '1998-01-01', 'end' => '2026-09-04', 'symbols' => [$symbol]]);
    } catch (Throwable $e) {
        $manifest['errors']['yahoo_' . $symbol] = $e->getMessage();
        AlgorithmTrendResearch::write($out . '/manifest.json', $manifest);
        fprintf(STDERR, "Yahoo %s unavailable: %s\n", $symbol, $e->getMessage());
    }
    usleep(150000);
}
if ($identity !== TacticalImplementationIdentity::current($root, $original['profile'])) {
    throw new RuntimeException('Operational implementation changed during research.');
}
$manifest['finished_at'] = gmdate(DATE_ATOM);
$manifest['operational_identity_unchanged'] = true;
AlgorithmTrendResearch::write($out . '/manifest.json', $manifest);
echo "Research data collection completed: $out\n";
