#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Research\AdaptiveResearchFactory as F;
use FulltimeTrading\Research\AdaptiveRotationEnsembleBacktester as E;
use FulltimeTrading\Research\AlgorithmTrendResearch as A;
use FulltimeTrading\Research\BreadthVolatilityResearch as B;
use FulltimeTrading\Research\DailyDataAudit as D;
use FulltimeTrading\Research\PortfolioCircuitController as C;
use FulltimeTrading\Research\SelectedMaximumResearch as S;
use FulltimeTrading\Trading\TacticalImplementationIdentity;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__); $out = $root . '/var/reports/start_dependence_20260909';
if (file_exists($out)) { throw new RuntimeException('Diagnostic already frozen.'); }
$read = static fn ($p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
$oldDir = $root . '/var/reports/selected_maximum_20260909';
$p = $read($oldDir . '/protocol.json');
$old = $read($root . '/var/reports/alpaca_stops_from_2023_20260909/protocol.json');
$profile = require $root . '/config/tactical_rotation.php';
if (TacticalImplementationIdentity::current($root, $profile) !== $p['operational_identity']) { throw new RuntimeException('Operational identity drift.'); }
$files = ['bars' => $root . '/var/reports/alpaca_stops_data_20260909/sip_all.json',
    's5tw' => $root . '/var/reports/breadth_volatility_data_20260909/s5tw.json', 'vvix' => $root . '/var/reports/vvix_data_20260909/cboe_vvix.json'];
foreach ($files as $key => $file) { if (!hash_equals($p['input_sha256'][$key], hash_file('sha256', $file))) { throw new RuntimeException('Frozen data mismatch.'); } }
$all = D::decode($read($files['bars']));
$calendar = array_keys(D::indexed(['SPY' => $all['SPY']])['SPY']);
$breadth = B::validateBreadth($read($files['s5tw']), $calendar); $vvix = $read($files['vvix']);
$maps = S::maps($all, $breadth, $vvix);
$maximum = $old['cases'][S::MAXIMUM];
$erId = 'maximum_stop12__algorithm__efficiency_score_60_0p5_all';
$cases = ['maximum' => $maximum, 'ER60_stop12' => S::materialize($p['cases'][$erId], ['maximum' => $maximum], $all, $breadth, $vvix, $maps)];
$input = array_intersect_key($all, array_fill_keys(array_merge($profile['universe'], ['SPY', 'QQQ']), true));
foreach ($input as $s => $rows) { $input[$s] = array_values(array_filter($rows, static fn ($b): bool => D::session($b) >= '2020-01-01' && D::session($b) <= '2026-09-04')); }
$priorSessions = count(array_filter($calendar, static fn ($d): bool => $d >= '2021-01-04' && $d < '2023-01-03'));
$alignedPhase = (3 - $priorSessions % 3) % 3;
for ($i = 1; $i < 500; $i++) {
    if ((($priorSessions + $i - 1) % 3 === 0) !== (($i - 1) % 3 === $alignedPhase)) { throw new RuntimeException('Calendar phase alignment failed.'); }
}
mkdir($out, 0775, true);
A::write($out . '/protocol.json', ['frozen_at' => gmdate(DATE_ATOM), 'cases' => $cases, 'starts' => ['2021-01-04', '2023-01-03'],
    'phases' => [0, 1, 2], 'static_three_phase_ensemble' => true, 'earlier_sessions' => $priorSessions, 'fresh_phase_matching_2021' => $alignedPhase,
    'input_sha256' => array_map(static fn ($f) => hash_file('sha256', $f), $files), 'operational_identity' => $p['operational_identity'],
    'script_sha256' => hash_file('sha256', __FILE__), 'orders_enabled' => false,
    'limits' => 'Descriptive state experiments, not additive causal attribution. The inherited-weight fresh account starts flat with hypothetical new capital, not a free rebalance of the continuing account. No state or positions are reset in the continuing run.']);
$report = ['cases' => [], 'replays' => 0, 'old_curve_matches' => 0, 'phase_alignment_checks' => 499, 'orders_submitted' => 0];
$compact = static fn ($curve): array => array_map(static fn ($r): array => array_intersect_key($r, array_flip(['date', 'period_start_date', 'start_equity', 'equity', 'equity_low', 'equity_high', 'turnover'])), $curve);
foreach ($cases as $name => $d) {
    $inherited = [];
    foreach (['2021-01-04', '2023-01-03'] as $start) {
        $modes = ['phase0' => ['rebalance_phase' => 0], 'phase1' => ['rebalance_phase' => 1], 'phase2' => ['rebalance_phase' => 2], 'three_phases' => ['phase_count' => 3]];
        if ($start === '2023-01-03') { $modes['aligned_phase_inherited_weights'] = ['rebalance_phase' => $alignedPhase]; }
        foreach ($modes as $mode => $change) {
            $tester = F::make($profile, S::merge($d['changes'], $change), 30);
            if ($mode === 'aligned_phase_inherited_weights') {
                $books = $tester->config();
                foreach ($books as $book => &$definition) { $definition['allocation'] = $inherited[$book]; }
                unset($definition);
                $tester = new E($books);
            }
            $controller = new C($d['circuit'], $maps['confirm'][$d['confirmation']]);
            $run = $tester->runControlled($input, $start, '2026-09-04', $controller, 30000.0);
            $curve = $compact($run['curve']);
            if ($mode === 'phase0') {
                $id = $name === 'maximum' ? 'maximum' : $erId;
                if ($curve != $read($oldDir . '/' . ($start === '2021-01-04' ? 'full2021' : 'primary') . '_' . $id . '_curve.json')) { throw new RuntimeException('Original comparison changed.'); }
                $report['old_curve_matches']++;
            }
            $r = ['full' => $tester->metrics($run), 'continuing_slice_from_2023' => $tester->metrics($run, '2023-01-03'), 'annual' => []];
            for ($year = (int) substr($start, 0, 4); $year <= 2026; $year++) { $r['annual'][$year] = $tester->metrics($run, "$year-01-01", ($year + 1) . '-01-01')['return']; }
            if ($start === '2021-01-04' && $mode === 'phase0') {
                $states = []; $total = 0.0;
                foreach ($run['sleeve_curves'] as $book => $rows) {
                    $before = array_values(array_filter($rows, static fn ($r): bool => $r['date'] < '2023-01-03'));
                    $last = $before[array_key_last($before)];
                    $states[$book] = array_intersect_key($last, array_flip(['date', 'equity', 'holding', 'circuit_cooldown_left', 'standing_stop_next']));
                    $total += $last['equity'];
                }
                foreach ($states as $book => &$state) { $state['weight'] = $state['equity'] / $total; $inherited[$book] = $state['weight']; }
                unset($state);
                $report['cases'][$name]['before2023'] = ['sleeves' => $states, 'total_equity' => $total,
                    'portfolio_state' => $controller->report()['history']['2022-12-30']];
            }
            $report['cases'][$name][$start][$mode] = $r;
            $report['replays']++;
            A::write($out . '/' . $name . '_' . $start . '_' . $mode . '_curve.json', $curve);
            printf("%s %s %s CAGR %.2f DD %.2f\n", $name, $start, $mode, 100 * $r['full']['cagr'], 100 * $r['full']['max_drawdown']);
            unset($run, $tester, $curve); gc_collect_cycles();
        }
    }
}
$report['fresh_phase_matching_2021'] = $alignedPhase;
$report['completed_at'] = gmdate(DATE_ATOM);
$report['operational_identity_unchanged'] = TacticalImplementationIdentity::current($root, $profile) === $p['operational_identity'];
if (!$report['operational_identity_unchanged']) { throw new RuntimeException('Operational identity changed.'); }
A::write($out . '/results.json', $report);
