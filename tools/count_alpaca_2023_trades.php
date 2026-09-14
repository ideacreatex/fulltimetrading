#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Research\AdaptiveResearchFactory;
use FulltimeTrading\Research\AlgorithmTrendResearch;
use FulltimeTrading\Research\AlpacaStopResearchGrid;
use FulltimeTrading\Research\BreadthVolatilityResearch;
use FulltimeTrading\Research\DailyDataAudit;
use FulltimeTrading\Research\PortfolioCircuitController;
use FulltimeTrading\Research\ResearchTradeLedger;
use FulltimeTrading\Trading\TacticalImplementationIdentity;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__);
$read = static fn (string $p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
$source = $root . '/var/reports/alpaca_stops_from_2023_20260909';
$out = $root . '/var/reports/alpaca_2023_trade_counts_20260909';
if (file_exists($out)) { throw new RuntimeException('Refusing to overwrite trade counts.'); }
$protocol = $read($source . '/protocol.json');
$original = $read($source . '/results.json');
if (!isset($original['completed_at'])) { throw new RuntimeException('Original replay incomplete.'); }
foreach ($protocol['implementation_sha256'] as $file => $sha) {
    if (!hash_equals($sha, hash_file('sha256', $root . '/' . $file))) { throw new RuntimeException('Frozen implementation changed: ' . $file); }
}
$profile = require $root . '/config/tactical_rotation.php';
if (TacticalImplementationIdentity::current($root, $profile) !== $protocol['operational_identity']) { throw new RuntimeException('Operational identity drift.'); }
$files = ['bars' => $root . '/var/reports/alpaca_stops_data_20260909/sip_all.json',
    's5tw' => $root . '/var/reports/breadth_volatility_data_20260909/s5tw.json', 'vvix' => $root . '/var/reports/vvix_data_20260909/cboe_vvix.json'];
foreach ($files as $key => $file) {
    if (!hash_equals($protocol['input_sha256'][$key], hash_file('sha256', $file))) { throw new RuntimeException('Input snapshot changed.'); }
}
$all = DailyDataAudit::decode($read($files['bars']));
$calendar = array_keys(DailyDataAudit::indexed(['SPY' => $all['SPY']])['SPY']);
$breadth = BreadthVolatilityResearch::validateBreadth($read($files['s5tw']), $calendar);
$confirmations = AlpacaStopResearchGrid::confirmations($all, $breadth, $read($files['vvix']));
$bars = array_intersect_key($all, array_fill_keys(array_merge($profile['universe'], ['SPY', 'QQQ']), true));
foreach ($bars as $symbol => $series) {
    $bars[$symbol] = array_values(array_filter($series, static fn ($b): bool => DailyDataAudit::session($b) >= '2020-01-01' && DailyDataAudit::session($b) <= $protocol['end']));
}
mkdir($out, 0775, true);
$report = ['started_at' => gmdate(DATE_ATOM), 'source_protocol_sha256' => hash_file('sha256', $source . '/protocol.json'),
    'source_results_sha256' => hash_file('sha256', $source . '/results.json'), 'input_sha256' => $protocol['input_sha256'],
    'counter_sha256' => hash_file('sha256', $root . '/src/Research/ResearchTradeLedger.php'), 'script_sha256' => hash_file('sha256', __FILE__),
    'cases' => [], 'curve_equivalence_checks' => 0, 'counter_prefix_checks' => 0, 'orders_submitted' => 0];
$compact = static fn ($curve): array => array_map(static fn ($r): array => array_intersect_key($r,
    array_flip(['date', 'period_start_date', 'start_equity', 'equity', 'equity_low', 'equity_high', 'turnover'])), $curve);
foreach ($protocol['cases'] as $id => $definition) {
    $tester = AdaptiveResearchFactory::make($profile, $definition['changes'], $protocol['cost_bps']);
    $run = $tester->runControlled($bars, $protocol['start'], $protocol['end'],
        new PortfolioCircuitController($definition['circuit'], $confirmations[$definition['confirmation']]), $protocol['initial_equity']);
    if ($compact($run['curve']) != $read($source . '/' . $id . '_curve.json') || $tester->metrics($run) != $original['cases'][$id]['full']) {
        throw new RuntimeException('Trade count replay changed the original result: ' . $id);
    }
    $report['curve_equivalence_checks']++;
    $ledger = ResearchTradeLedger::fromSleeveCurves($run['sleeve_curves']);
    $prefix = ResearchTradeLedger::fromSleeveCurves(array_map(static fn ($r): array => array_values(array_filter($r,
        static fn ($row): bool => $row['date'] <= '2024-12-31')), $run['sleeve_curves']));
    foreach (['portfolio', 'sleeves'] as $scope) {
        if ($prefix[$scope]['closed'] !== array_values(array_filter($ledger[$scope]['closed'], static fn ($t): bool => $t['exit_date'] <= '2024-12-31'))) {
            throw new RuntimeException('Counter prefix mismatch.');
        }
        $report['counter_prefix_checks']++;
    }
    $annual = [];
    foreach ($original['cases'][$id]['annual'] as $year => $metrics) {
        $annual[$year] = ['return' => $metrics['return'], 'closed_trades' => $ledger['portfolio']['annual_closed'][$year],
            'sleeve_closed_trades' => $ledger['sleeves']['annual_closed'][$year]];
    }
    $report['cases'][$id] = ['annual' => $annual, 'ledger' => $ledger, 'full' => $original['cases'][$id]['full']];
    $exposures = array_map(static fn ($rows): array => array_map(static fn ($row): array => array_intersect_key($row,
        array_flip(['date', 'holding', 'standing_stop_event', 'rebalance', 'signal_date'])), $rows), $run['sleeve_curves']);
    AlgorithmTrendResearch::write($out . '/' . $id . '_sleeve_exposure.json', $exposures);
    printf("%s: %d portfolio / %d sleeve closed trades; annual %s; curve unchanged\n", $id,
        $ledger['portfolio']['closed_total'], $ledger['sleeves']['closed_total'], json_encode($ledger['portfolio']['annual_closed']));
}
$report['operational_identity_unchanged'] = TacticalImplementationIdentity::current($root, $profile) === $protocol['operational_identity'];
if (!$report['operational_identity_unchanged']) { throw new RuntimeException('Operational identity changed during counting.'); }
$report['completed_at'] = gmdate(DATE_ATOM);
AlgorithmTrendResearch::write($out . '/results.json', $report);
AlgorithmTrendResearch::write($root . '/docs/research_results/alpaca_2023_trade_counts_20260909.json', $report);
