#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Research\AdaptiveResearchFactory as F;
use FulltimeTrading\Research\AlgorithmTrendResearch as A;
use FulltimeTrading\Research\BreadthVolatilityResearch as B;
use FulltimeTrading\Research\DailyDataAudit as D;
use FulltimeTrading\Research\OpportunityPolicy as O;
use FulltimeTrading\Research\PaperExecutionRotationEnsembleBacktester as E;
use FulltimeTrading\Research\PortfolioCircuitController as C;
use FulltimeTrading\Research\ResearchTradeLedger as L;
use FulltimeTrading\Research\SelectedMaximumResearch as S;

require dirname(__DIR__) . '/bootstrap.php';
set_error_handler(static function (int $n, string $message): never { throw new RuntimeException($message); });
$root = dirname(__DIR__); $out = $root . '/var/reports/candidate_execution_20260915';
$read = static fn ($p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
$profile = require $root . '/config/tactical_rotation.php';
$parent = $read($root . '/var/reports/opportunity_calendar_20260910/protocol.json');
$anchor = $read($root . '/var/reports/alpaca_stops_from_2023_20260909/protocol.json')['cases'][S::MAXIMUM];
$files = ['raw' => '/var/reports/candidate_execution_data_20260914/raw.json',
    'split' => '/var/reports/candidate_execution_data_20260914/split.json',
    'all' => '/var/reports/alpaca_stops_data_20260909/sip_all.json',
    's5tw' => '/var/reports/breadth_volatility_data_20260909/s5tw.json',
    'vvix' => '/var/reports/vvix_data_20260909/cboe_vvix.json'];
$protocol = ['end' => '2026-09-04', 'starts' => ['continuous' => '2021-01-04', 'fresh2023' => '2023-01-03'],
    'costs_bps' => [30, 60], 'variants' => ['maximum', 'candidate'], 'sizing' => ['fractional', 'whole_previous_close'],
    'initial_equity' => 30000, 'cost_band_stress' => 'Strength 1 at 60bps preserves strength 2 at 30bps switching hurdle.',
    'execution_contract' => 'Whole quantities fixed using previous-close NAV/nominal close; actual opening gap cannot retrospectively resize the order. Returns use split-only bars, excluding dividend reinvestment. Gap gross is measured, not capped retrospectively.',
    'limits' => 'Stop fills are daily stop-level simulations, not broker NBBO fill proof. Nominal whole-share sizing is an execution sensitivity, not an independent holdout.',
    'orders_submitted' => 0];
foreach ($files as $name => $file) { $protocol['input_sha256'][$name] = hash_file('sha256', $root . $file); }
foreach (['src/Research/PaperExecutionRotationBacktester.php', 'src/Research/PaperExecutionRotationEnsembleBacktester.php',
    'src/Trading/WholeShareSizing.php', 'tools/replay_candidate_execution.php'] as $file) { $protocol['code_sha256'][$file] = hash_file('sha256', $root . '/' . $file); }
foreach ($parent['code_sha256'] as $file => $sha) { if (hash_file('sha256', $root . '/' . $file) !== $sha) { throw new RuntimeException('Frozen parent changed.'); } }
if (!is_dir($out)) { mkdir($out, 0775, true); }
if (file_exists($out . '/protocol.json') && $read($out . '/protocol.json') !== $protocol) { throw new RuntimeException('Execution replay resume drift.'); }
A::write($out . '/protocol.json', $protocol); $protocolSha = hash_file('sha256', $out . '/protocol.json');
$vvix = $read($root . $files['vvix']);
$raw = D::decode($read($root . $files['raw'])); $nominal = [];
foreach ($raw as $symbol => $bars) {
    foreach ($bars as $bar) { $nominal[$symbol][D::session($bar)] = ['open' => $bar->open, 'close' => $bar->close]; }
}
unset($raw);
$universe = $profile['universe'];
$summary = [];
foreach (['all', 'split'] as $dataMode) {
    $all = D::decode($read($root . $files[$dataMode]));
    foreach ($all as $symbol => $series) {
        $all[$symbol] = array_values(array_filter($series, static fn ($b): bool => D::session($b) <= $protocol['end']));
    }
    $calendar = array_keys(D::indexed(['SPY' => $all['SPY']])['SPY']);
    $breadth = B::validateBreadth($read($root . $files['s5tw']), $calendar);
    $maps = S::maps($all, $breadth, $vvix);
    $features = $dataMode === 'all' ? $read($root . '/var/reports/opportunity_calendar_20260910/features.json')
        : O::features($all, $vvix, $universe);
    $bars = array_intersect_key($all, array_flip(array_merge($universe, ['SPY', 'QQQ'])));
    foreach ($bars as $symbol => $series) {
        $bars[$symbol] = array_values(array_filter($series, static fn ($b): bool => D::session($b) >= '2020-01-01' && D::session($b) <= $protocol['end']));
    }
    unset($all);
    foreach ($protocol['starts'] as $scenario => $start) {
        foreach ($protocol['costs_bps'] as $cost) {
            foreach ($protocol['variants'] as $variant) {
                foreach ($protocol['sizing'] as $sizeMode) {
                    if ($dataMode === 'all' && ($cost !== 30 || $variant !== 'candidate' || $sizeMode !== 'fractional')) { continue; }
                    $id = implode('_', [$dataMode, $scenario, $cost, $variant, $sizeMode]);
                    $path = $out . '/' . $id . '.json';
                    if (file_exists($path)) {
                        $saved = $read($path);
                        if ($saved['protocol_sha256'] !== $protocolSha) { throw new RuntimeException('Execution result drift.'); }
                        $summary[$id] = $saved['metrics']; continue;
                    }
                    $changes = $anchor['changes'];
                    if ($variant === 'candidate') { $changes = S::merge($changes, ['phase_count' => 3,
                        'standing_stop_pct' => .12, 'standing_stop_trailing' => true, 'position_exit_cooldown_sessions' => 1]); }
                    $changes['external_daily_scale'] = $maps['scale'];
                    $books = F::make(D::withUniverse($profile, $universe), $changes, $cost)->config();
                    foreach ($books as &$book) {
                        $book['config']['opportunity_policy'] = $variant === 'candidate'
                            ? ['family' => 'cost_band', 'window' => 20, 'strength' => $cost === 30 ? 2 : 1] : [];
                        $book['config']['opportunity_features'] = $features;
                        $book['config']['whole_share_execution'] = $sizeMode === 'whole_previous_close';
                        $book['config']['nominal_prices'] = $sizeMode === 'whole_previous_close' ? $nominal : [];
                    }
                    unset($book);
                    $engine = new E($books); $controller = new C($anchor['circuit'], $maps['confirm'][$anchor['confirmation']]);
                    $result = $engine->runControlled($bars, $start, $protocol['end'], $controller, 30000.);
                    $curve = array_map(static fn ($r): array => array_intersect_key($r, array_flip([
                        'date', 'period_start_date', 'start_equity', 'equity', 'equity_low', 'equity_high', 'turnover'])), $result['curve']);
                    $exact = null;
                    if ($dataMode === 'all') {
                        $frozen = $read($root . '/var/reports/opportunity_calendar_20260910/' . $scenario . '_p0_maximum_stop12__cost_band_2_curve.json');
                        $exact = json_encode($curve) === json_encode($frozen);
                        if (!$exact) { throw new RuntimeException('Fractional fork failed exact parent replay.'); }
                    }
                    $quantities = [];
                    foreach ($result['sleeve_curves'] as $name => $rows) {
                        foreach ($rows as $row) {
                            if (isset($row['fixed_quantity_target'])) { $quantities[] = ['sleeve' => $name, 'date' => $row['date'],
                                'reference_session' => $row['quantity_reference_session'], 'quantities' => $row['fixed_quantity_target']]; }
                        }
                    }
                    $metrics = $engine->metrics($result); $summary[$id] = $metrics;
                    $annual = [];
                    for ($year = (int) substr($start, 0, 4); $year <= 2026; ++$year) { $annual[$year] = $engine->metrics($result, "$year-01-01", ($year + 1) . '-01-01'); }
                    A::write($path, ['protocol_sha256' => $protocolSha, 'metrics' => $metrics, 'annual' => $annual,
                        'exact_frozen_fractional_match' => $exact, 'sleeves' => count($books), 'trade_ledger' => L::fromSleeveCurves($result['sleeve_curves']),
                        'quantity_decisions' => $quantities, 'controller' => $controller->report(), 'next_targets_research_only' => $result['next_targets']]);
                    A::write(substr($path, 0, -5) . '_curve.json', $curve);
                    printf("%s CAGR %.3f%% DD %.3f%% gross %.3f\n", $id, $metrics['cagr'] * 100, $metrics['max_drawdown'] * 100, $metrics['max_gross_bound']);
                    unset($result, $engine, $controller, $books); gc_collect_cycles();
                }
            }
        }
    }
    unset($bars, $features, $maps); gc_collect_cycles();
}
A::write($out . '/summary.json', ['protocol_sha256' => $protocolSha, 'results' => $summary, 'completed_at' => gmdate(DATE_ATOM)]);
