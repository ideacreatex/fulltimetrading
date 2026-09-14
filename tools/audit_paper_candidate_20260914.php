#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Backtest\TacticalRotationQualification;
use FulltimeTrading\Research\AdaptiveResearchFactory as F;
use FulltimeTrading\Research\AlgorithmTrendResearch as A;
use FulltimeTrading\Research\BreadthVolatilityResearch as B;
use FulltimeTrading\Research\DailyDataAudit as D;
use FulltimeTrading\Research\OpportunityRotationEnsembleBacktester as E;
use FulltimeTrading\Research\PortfolioCircuitController as C;
use FulltimeTrading\Research\ResearchTradeLedger as L;
use FulltimeTrading\Research\SelectedMaximumResearch as S;
use FulltimeTrading\Trading\TacticalImplementationIdentity;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__);
$out = $root . '/var/reports/paper_candidate_20260914';
$read = static fn (string $p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
$frozenDir = $root . '/var/reports/opportunity_calendar_20260910';
$p = $read($frozenDir . '/protocol.json');
$profile = require $root . '/config/tactical_rotation.php';
if (TacticalImplementationIdentity::current($root, $profile) !== $p['operational_identity']) { throw new RuntimeException('Operational identity changed.'); }
foreach ($p['code_sha256'] as $file => $sha) {
    if (hash_file('sha256', $root . '/' . $file) !== $sha) { throw new RuntimeException('Frozen code changed: ' . $file); }
}
$files = ['bars' => $root . '/var/reports/alpaca_stops_data_20260909/sip_all.json',
    's5tw' => $root . '/var/reports/breadth_volatility_data_20260909/s5tw.json',
    'vvix' => $root . '/var/reports/vvix_data_20260909/cboe_vvix.json'];
foreach ($files as $key => $file) {
    if (hash_file('sha256', $file) !== $p['input_sha256'][$key]) { throw new RuntimeException('Frozen input changed: ' . $key); }
}
if (hash_file('sha256', $frozenDir . '/features.json') !== $p['features_sha256']) { throw new RuntimeException('Frozen factors changed.'); }
$cases = ['baseline' => [], 'slippage50' => ['standing_stop_slippage_bps' => 50.0],
    'slippage100' => ['standing_stop_slippage_bps' => 100.0], 'daily_low' => ['standing_stop_fill' => 'daily_low']];
$protocol = ['parent_sha256' => hash_file('sha256', $frozenDir . '/protocol.json'), 'script_sha256' => hash_file('sha256', __FILE__),
    'cases' => $cases, 'starts' => ['continuous' => '2021-01-04', 'fresh2023' => '2023-01-03'], 'end' => $p['end'],
    'candidate' => 'maximum_stop12__cost_band_2', 'orders_submitted' => 0,
    'limits' => 'Path-dependent daily stop sensitivity, not guaranteed broker fills. Daily-low fill is an ex-post adverse scenario, never an executable signal. No selection of a new winner.'];
if (!is_dir($out)) { mkdir($out, 0775, true); }
if (file_exists($out . '/protocol.json')) {
    if ($read($out . '/protocol.json') !== $protocol) { throw new RuntimeException('Resume drift.'); }
} else { A::write($out . '/protocol.json', $protocol); }
$all = D::decode($read($files['bars']));
$calendar = array_keys(D::indexed(['SPY' => $all['SPY']])['SPY']);
$maps = S::maps($all, B::validateBreadth($read($files['s5tw']), $calendar), $read($files['vvix']));
$anchor = $read($root . '/var/reports/alpaca_stops_from_2023_20260909/protocol.json')['cases'][S::MAXIMUM];
$universe = $read($root . '/var/reports/independent_data_20260908/protocol.json')['original_universe'];
$bars = array_intersect_key($all, array_fill_keys(array_merge($universe, ['SPY', 'QQQ']), true));
foreach ($bars as $symbol => $series) { $bars[$symbol] = array_values(array_filter($series, static fn ($b): bool => D::session($b) >= '2020-01-01' && D::session($b) <= $p['end'])); }
unset($all);
$features = $read($frozenDir . '/features.json');
foreach ($protocol['starts'] as $scenario => $start) {
    foreach ($cases as $case => $overrides) {
        $path = $out . '/' . $scenario . '_' . $case;
        if (file_exists($path . '.json')) {
            if ($read($path . '.json')['protocol_sha256'] !== hash_file('sha256', $out . '/protocol.json')) { throw new RuntimeException('Result drift.'); }
            continue;
        }
        $changes = S::merge($anchor['changes'], ['phase_count' => 3, 'standing_stop_pct' => 0.12,
            'standing_stop_trailing' => true, 'position_exit_cooldown_sessions' => 1]);
        $changes = S::merge($changes, $overrides);
        $changes['external_daily_scale'] = $maps['scale'];
        $books = F::make(D::withUniverse($profile, $universe), $changes, 30)->config();
        foreach ($books as &$book) {
            $book['config']['opportunity_policy'] = $p['cases'][$protocol['candidate']]['policy'];
            $book['config']['opportunity_features'] = $features;
        }
        unset($book);
        $engine = new E($books);
        $controller = new C($anchor['circuit'], $maps['confirm'][$anchor['confirmation']]);
        $run = $engine->runControlled($bars, $start, $p['end'], $controller, 30000);
        $curve = array_map(static fn ($r): array => array_intersect_key($r, array_flip([
            'date', 'period_start_date', 'start_equity', 'equity', 'equity_low', 'equity_high', 'turnover'])), $run['curve']);
        // JSON does not preserve float zero versus integer zero; compare serialized numbers.
        $match = $case === 'baseline' ? json_encode($curve, JSON_THROW_ON_ERROR) === json_encode($read($frozenDir . '/' . $scenario . '_p0_' . $protocol['candidate'] . '_curve.json'), JSON_THROW_ON_ERROR) : null;
        if ($match === false) { throw new RuntimeException('Candidate does not reproduce exactly.'); }
        $events = [];
        foreach ($run['sleeve_curves'] as $name => $rows) {
            foreach ($rows as $row) {
                if (isset($row['standing_stop_event'])) { $events[] = $row['standing_stop_event'] + ['sleeve' => $name]; }
            }
        }
        $metrics = ['full' => $engine->metrics($run), 'train' => $engine->metrics($run, '2021-01-04', '2024-01-01'),
            'validation' => $engine->metrics($run, '2024-01-01', '2026-01-01'), 'holdout' => $engine->metrics($run, '2026-01-01')];
        $annual = [];
        for ($year = (int) substr($start, 0, 4); $year <= 2026; $year++) { $annual[$year] = $engine->metrics($run, "$year-01-01", ($year + 1) . '-01-01'); }
        $q = $scenario === 'continuous' ? (new TacticalRotationQualification($profile['validation']))->evaluate(
            $metrics['train'], $metrics['validation'], $metrics['holdout'], $metrics['full'], $annual) : null;
        A::write($path . '_curve.json', $curve);
        A::write($path . '.json', ['protocol_sha256' => hash_file('sha256', $out . '/protocol.json'), 'scenario' => $scenario,
            'case' => $case, 'exact_frozen_curve_match' => $match, 'metrics' => $metrics, 'annual' => $annual,
            'trade_ledger' => L::fromSleeveCurves($run['sleeve_curves']), 'qualification' => $q, 'stops' => $events,
            'controller' => $controller->report(), 'next_targets_research_only' => $run['next_targets'], 'orders_submitted' => 0]);
        printf("%s %s CAGR %.4f DD %.4f stop events %d\n", $scenario, $case, $metrics['full']['cagr'] * 100, $metrics['full']['max_drawdown'] * 100, count($events));
        unset($run, $books, $engine, $controller); gc_collect_cycles();
    }
}
