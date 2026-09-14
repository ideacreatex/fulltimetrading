#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Research\AdaptiveResearchFactory as F;
use FulltimeTrading\Research\AlgorithmTrendResearch as A;
use FulltimeTrading\Research\BreadthVolatilityResearch as B;
use FulltimeTrading\Research\DailyDataAudit as D;
use FulltimeTrading\Research\OpportunityPolicy as O;
use FulltimeTrading\Research\PaperExecutionRotationBacktester as Single;
use FulltimeTrading\Research\PaperExecutionRotationEnsembleBacktester as Ensemble;
use FulltimeTrading\Research\PortfolioCircuitController as Circuit;
use FulltimeTrading\Research\SelectedMaximumResearch as S;
use FulltimeTrading\Paper\CandidateSleeveState;
use FulltimeTrading\Trading\WholeShareSizing;

require dirname(__DIR__) . '/bootstrap.php';
set_error_handler(static function (int $n, string $s): never { throw new RuntimeException($s); });
$root = dirname(__DIR__); $read = static fn ($p): array => json_decode(file_get_contents($root . '/' . $p), true, 512, JSON_THROW_ON_ERROR);
$end = '2026-09-04'; $start = $argv[1] ?? '2021-01-04'; $cost = 30.;
if (!in_array($start, ['2021-01-04', '2023-01-03'], true)) { throw new InvalidArgumentException('Unknown frozen scenario.'); }
$scenario = $start === '2021-01-04' ? 'continuous' : 'fresh2023';
$all = D::decode($read('var/reports/candidate_execution_data_20260914/split.json'));
foreach ($all as $symbol => $series) { $all[$symbol] = array_values(array_filter($series, static fn ($bar): bool => D::session($bar) <= $end)); }
$raw = D::decode($read('var/reports/candidate_execution_data_20260914/raw.json')); $nominal = [];
foreach ($raw as $symbol => $series) { foreach ($series as $bar) { $nominal[$symbol][D::session($bar)] = ['open' => $bar->open, 'close' => $bar->close]; } }
unset($raw);
$calendar = array_keys(D::indexed(['SPY' => $all['SPY']])['SPY']);
$breadth = B::validateBreadth($read('var/reports/breadth_volatility_data_20260909/s5tw.json'), $calendar);
$vvix = $read('var/reports/vvix_data_20260909/cboe_vvix.json');
$profile = require $root . '/config/tactical_rotation.php';
$anchor = $read('var/reports/alpaca_stops_from_2023_20260909/protocol.json')['cases'][S::MAXIMUM];
$maps = S::maps($all, $breadth, $vvix);
$features = O::features($all, $vvix, $profile['universe']);
$changes = S::merge($anchor['changes'], ['phase_count' => 3, 'standing_stop_pct' => .12,
    'standing_stop_trailing' => true, 'position_exit_cooldown_sessions' => 1, 'external_daily_scale' => $maps['scale']]);
$books = F::make($profile, $changes, $cost)->config();
foreach ($books as &$book) {
    $book['config']['opportunity_policy'] = ['family' => 'cost_band', 'window' => 20, 'strength' => 2];
    $book['config']['opportunity_features'] = $features;
    $book['config']['whole_share_execution'] = true; $book['config']['nominal_prices'] = $nominal;
}
unset($book);
$bars = array_intersect_key($all, array_flip(array_merge($profile['universe'], ['SPY', 'QQQ'])));
foreach ($bars as $symbol => $series) { $bars[$symbol] = array_values(array_filter($series, static fn ($bar): bool => D::session($bar) >= '2020-01-01')); }
$engine = new Ensemble($books); $books = $engine->config();
if (\FulltimeTrading\Paper\CandidateDefinition::books($profile, $maps['scale'], $features, $nominal) !== $books) {
    throw new RuntimeException('Deployable definition differs from the selected research recipe.');
}
$controller = new Circuit($anchor['circuit'], $maps['confirm'][$anchor['confirmation']]);
$result = $engine->runControlled($bars, $start, $end, $controller, 30000.);
$frozen = $read('var/reports/candidate_execution_20260915/split_' . $scenario . '_30_candidate_whole_previous_close.json');
if ($engine->metrics($result) !== $frozen['metrics']) { throw new RuntimeException('New API changed the frozen execution replay.'); }
$feedback = $controller->report()['history']; $assertions = $sizing = $sessions = 0; $lastStates = [];
$check = static function (bool $ok, string $why) use (&$assertions): void { ++$assertions; if (!$ok) { throw new RuntimeException($why); } };
foreach ($books as $name => $book) {
    $single = new Single($book['config']); $contexts = $single->paperSignalContexts($bars, $end); $state = null;
    $rows = $result['sleeve_curves'][$name];
    foreach ($rows as $index => $row) {
        $date = $row['date']; $weights = $row['holding'] === null ? [] : [$row['holding'] => (float) $row['gross_close']];
        $observation = ['date' => $date, 'equity' => (float) $row['equity'], 'weights' => $weights,
            'execution_complete' => (bool) $row['rebalance'], 'stop_filled' => isset($row['standing_stop_event'])];
        $context = $contexts($date, $row['holding']);
        $f = array_intersect_key($feedback[$date], array_flip(['force_cash', 'scale']));
        $state = CandidateSleeveState::advance($book['config'], $state, $observation, $context, $f);
        $check($state['risk_signal'] === $row['risk_signal'], 'Risk-signal parity: ' . $name . '/' . $date);
        $check($state['cooldown_left'] === $row['circuit_cooldown_left'], 'Cooldown parity: ' . $name . '/' . $date);
        $check(CandidateSleeveState::advance($book['config'], $state, $observation, $context, $f) === $state, 'Repeated close changed state.');
        $next = $rows[$index + 1] ?? null;
        if ($next !== null && $state['target']['rebalance_due_next_session'] && ($next['standing_stop_event']['kind'] ?? '') !== 'gap_open') {
            $target = $state['target']; $prices = [];
            foreach ($nominal as $symbol => $series) { if (isset($series[$date])) { $prices[$symbol] = $series[$date]['close']; } }
            $q = WholeShareSizing::target((float) $row['equity'], array_map(static fn ($w): float => $w * $row['equity'], $weights),
                $target['symbol'], (float) $target['gross'], $prices, $cost);
            $check($q === $next['fixed_quantity_target'], 'Prior-close quantity parity: ' . $name . '/' . $date);
            ++$sizing;
        }
        $state = json_decode(json_encode($state, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        ++$sessions;
    }
    foreach ($result['next_targets'][$name] as $field => $expected) {
        if (!array_key_exists($field, $state['target'])) { continue; }
        $actual = $state['target'][$field];
        $check(is_float($expected) || is_int($expected) ? abs($actual - $expected) < 1e-10 : $actual === $expected,
            'Final target mismatch: ' . $name . '/' . $field . ' actual=' . json_encode($actual) . ' expected=' . json_encode($expected));
    }
    $prefixDate = '2024-03-01'; $prefix = $single->paperSignalContexts($bars, $prefixDate);
    foreach ([null, 'MSFT', 'NVDA'] as $incumbent) {
        $check($contexts($prefixDate, $incumbent) === $prefix($prefixDate, $incumbent), 'Future bars changed a historical close decision.');
    }
    $lastStates[$name] = $state;
    echo $name . ": close, risk, quantity and prefix parity PASS\n";
}
$report = ['completed_at' => gmdate(DATE_ATOM), 'start' => $start, 'end' => $end, 'sleeves' => count($books),
    'sleeve_sessions' => $sessions, 'quantity_decisions' => $sizing, 'assertions' => $assertions,
    'exact_frozen_metrics' => true, 'orders_submitted' => 0, 'states' => $lastStates];
$report['stops'] = array_merge(...array_values(array_map(static fn ($s): array => $s['standing_stop_events'], $result['sleeves'])));
foreach (['src/Paper/CandidateDefinition.php', 'src/Paper/CandidateSleeveState.php', 'src/Research/PaperExecutionRotationBacktester.php',
    'src/Trading/WholeShareSizing.php', 'tools/verify_candidate_close_parity.php'] as $file) { $report['code_sha256'][$file] = hash_file('sha256', $root . '/' . $file); }
A::write($root . '/var/reports/candidate_execution_20260915/close_parity_' . $scenario . '.json', $report);
echo "candidate_close_parity: {$assertions} assertions PASS\n";
