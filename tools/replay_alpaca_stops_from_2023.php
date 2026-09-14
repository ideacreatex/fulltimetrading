#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Research\AdaptiveResearchFactory;
use FulltimeTrading\Research\AlgorithmTrendResearch;
use FulltimeTrading\Research\AlpacaStopResearchGrid;
use FulltimeTrading\Research\BreadthVolatilityResearch;
use FulltimeTrading\Research\DailyDataAudit;
use FulltimeTrading\Research\HybridV4Research;
use FulltimeTrading\Research\PortfolioCircuitController;
use FulltimeTrading\Trading\TacticalImplementationIdentity;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__);
$out = $root . '/var/reports/alpaca_stops_from_2023_20260909';
if (file_exists($out)) { throw new RuntimeException('Refusing to replace a replay.'); }
$read = static fn (string $p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
$sourceDir = $root . '/var/reports/alpaca_portfolio_stops_20260909';
$frozen = $read($sourceDir . '/protocol.json');
$base = $read($root . '/var/reports/independent_data_20260908/protocol.json');
$profile = require $root . '/config/tactical_rotation.php';
if (TacticalImplementationIdentity::current($root, $profile) !== $frozen['operational_identity']) { throw new RuntimeException('Operational profile changed.'); }
foreach ($frozen['implementation_sha256'] as $file => $hash) {
    if (!hash_equals($hash, hash_file('sha256', $root . '/' . $file))) { throw new RuntimeException('Frozen research code changed: ' . $file); }
}
if (!hash_equals($frozen['primary_data_sha256'], hash_file('sha256', $frozen['primary_data_file']))) { throw new RuntimeException('Frozen data changed.'); }
$all = DailyDataAudit::decode($read($frozen['primary_data_file']));
$calendar = array_keys(DailyDataAudit::indexed(['SPY' => $all['SPY']])['SPY']);
$breadthFile = $root . '/var/reports/breadth_volatility_data_20260909/s5tw.json';
$vvixFile = $root . '/var/reports/vvix_data_20260909/cboe_vvix.json';
$breadth = BreadthVolatilityResearch::validateBreadth($read($breadthFile), $calendar);
$vvix = $read($vvixFile);
$confirmations = AlpacaStopResearchGrid::confirmations($all, $breadth, $vvix);
$bars = array_intersect_key($all, array_fill_keys(array_merge($base['original_universe'], ['SPY', 'QQQ']), true));
foreach ($bars as $symbol => $series) {
    $bars[$symbol] = array_values(array_filter($series, static fn ($b): bool => DailyDataAudit::session($b) >= '2020-01-01' && DailyDataAudit::session($b) <= '2026-09-04'));
}
$cases = ['control_original' => ['changes' => [], 'circuit' => [], 'confirmation' => 'none'],
    'control_balanced' => ['changes' => $frozen['seeds']['balanced'], 'circuit' => [], 'confirmation' => 'none']];
foreach (['balanced__global_confirmed_f6156ed86a11', 'balanced__stop_then_confirm_aacf0d177f3b'] as $id) {
    $definition = $frozen['cases'][$id];
    $cases[$id] = ['changes' => array_replace($frozen['seeds'][$definition['seed']], $definition['changes']),
        'circuit' => $definition['circuit'], 'confirmation' => $definition['confirmation']];
}
$protocol = ['created_at' => gmdate(DATE_ATOM), 'start' => '2023-01-03', 'end' => '2026-09-04',
    'source' => 'Alpaca SIP adjustment=all', 'cost_bps' => 30, 'initial_equity' => 30000.0, 'cases' => $cases,
    'start_semantics' => 'New flat portfolio, original initial sleeve allocations, fresh risk peaks and cadence. First signal at 2023-01-03 close; earliest entry next session.',
    'warmup' => 'Older data only initializes indicators and external market histories; no inherited positions, sleeve profits or pauses.',
    'comparison' => 'Also measure the same dates from the old uninterrupted 2021 replay without restarting its state.',
    'selection' => 'The four previously displayed configurations, unchanged. No new search or selection on the shorter period.',
    'qualification' => 'Not reevaluated on a shortened training window; existing operational gates remain unchanged.',
    'operational_identity' => $frozen['operational_identity'], 'source_protocol_sha256' => hash_file('sha256', $sourceDir . '/protocol.json'),
    'input_sha256' => ['bars' => $frozen['primary_data_sha256'], 's5tw' => hash_file('sha256', $breadthFile), 'vvix' => hash_file('sha256', $vvixFile)],
    'implementation_sha256' => [], 'orders_enabled' => false, 'limitations' => $frozen['limits']];
foreach (array_merge(glob($root . '/src/Research/*.php'), [$root . '/tools/replay_alpaca_stops_from_2023.php']) as $file) {
    $protocol['implementation_sha256'][substr($file, strlen($root) + 1)] = hash_file('sha256', $file);
}
mkdir($out, 0775, true);
AlgorithmTrendResearch::write($out . '/protocol.json', $protocol);
$compact = static fn (array $curve): array => array_map(static fn (array $r): array => array_intersect_key($r,
    array_flip(['date', 'period_start_date', 'start_equity', 'equity', 'equity_low', 'equity_high', 'turnover'])), $curve);
$curveMetrics = static function (array $curve): array {
    if (count($curve) < 2) { throw new RuntimeException('Insufficient comparison curve.'); }
    $first = $curve[0]; $last = $curve[array_key_last($curve)];
    $factor = $last['equity'] / $first['start_equity'];
    $days = (int) (new DateTimeImmutable($first['period_start_date']))->diff(new DateTimeImmutable($last['date']))->days;
    $peak = $first['start_equity']; $dd = 0.0;
    foreach ($curve as $row) {
        $peak = max($peak, $row['equity_high'], $row['equity']);
        $dd = min($dd, min($row['equity_low'], $row['equity']) / $peak - 1);
    }
    return ['return' => $factor - 1, 'cagr' => $factor ** (365.25 / max(1, $days)) - 1, 'max_drawdown' => $dd];
};
$answer = ['protocol' => array_diff_key($protocol, ['cases' => true]), 'cases' => [], 'prefix_checks' => 0, 'annual_compounding_checks' => 0,
    'legacy_metric_checks' => 0, 'operational_identity_unchanged' => false, 'order_submission_enabled' => false];
foreach ($cases as $id => $definition) {
    $tester = AdaptiveResearchFactory::make($profile, $definition['changes'], 30);
    $controller = new PortfolioCircuitController($definition['circuit'], $confirmations[$definition['confirmation']]);
    $run = $tester->runControlled($bars, $protocol['start'], $protocol['end'], $controller, 30000.0);
    $first = $run['curve'][0];
    if ($first['date'] !== $protocol['start'] || $first['equity'] !== 30000.0 || $first['holding'] !== null || $first['turnover'] !== 0.0) {
        throw new RuntimeException('Replay did not start flat with equal capital.');
    }
    $annual = []; $factor = 1.0;
    for ($year = 2023; $year <= 2026; $year++) {
        $annual[$year] = $tester->metrics($run, "$year-01-01", ($year + 1) . '-01-01');
        $factor *= 1 + $annual[$year]['return'];
    }
    $full = $tester->metrics($run);
    if (abs($factor - (1 + $full['return'])) > 1e-8) { throw new RuntimeException('Annual compounding mismatch.'); }
    $answer['annual_compounding_checks']++;
    $old = $read($sourceDir . '/primary_all_30_stop_level_' . $id . '_curve.json');
    $oldResult = $read($sourceDir . '/primary_all_30_stop_level_' . $id . '.json');
    foreach ($curveMetrics($old) as $key => $value) {
        if (abs($value - $oldResult['metrics']['full'][$key]) > 1e-9) { throw new RuntimeException('Legacy metric reproduction mismatch.'); }
        $answer['legacy_metric_checks']++;
    }
    $oldSlice = array_values(array_filter($old, static fn ($r): bool => $r['date'] >= '2023-01-01'));
    $prefixEnd = '2024-12-31';
    $prefixMaps = AlpacaStopResearchGrid::confirmations(HybridV4Research::truncateBars($all, $prefixEnd),
        array_filter($breadth, static fn ($d): bool => $d <= $prefixEnd, ARRAY_FILTER_USE_KEY),
        array_filter($vvix, static fn ($d): bool => $d <= $prefixEnd, ARRAY_FILTER_USE_KEY));
    $prefix = $tester->runControlled(HybridV4Research::truncateBars($bars, $prefixEnd), $protocol['start'], $prefixEnd,
        new PortfolioCircuitController($definition['circuit'], $prefixMaps[$definition['confirmation']]), 30000.0);
    if ($compact($prefix['curve']) !== $compact(array_values(array_filter($run['curve'], static fn ($r): bool => $r['date'] <= $prefixEnd)))) {
        throw new RuntimeException('Fresh-start prefix depends on future input.');
    }
    $answer['prefix_checks']++;
    $stopCount = array_sum(array_map(static fn ($s): int => count($s['standing_stop_events'] ?? []), $run['sleeves']));
    $answer['cases'][$id] = ['full' => $full, 'annual' => $annual, 'initial_equity' => 30000.0,
        'final_equity' => $run['curve'][array_key_last($run['curve'])]['equity'], 'sleeve_stop_events' => $stopCount,
        'global_events' => $controller->report()['events'], 'old_continuous_slice_from_2023' => $curveMetrics($oldSlice),
        'old_continuous_annual' => array_filter($oldResult['annual'], static fn ($y): bool => $y >= 2023, ARRAY_FILTER_USE_KEY)];
    AlgorithmTrendResearch::write($out . '/' . $id . '_curve.json', $compact($run['curve']));
    printf("%s: return %.2f%%, CAGR %.2f%%, DD %.2f%%; prefix PASS\n", $id, $full['return'] * 100, $full['cagr'] * 100, $full['max_drawdown'] * 100);
}
$answer['operational_identity_unchanged'] = TacticalImplementationIdentity::current($root, $profile) === $frozen['operational_identity'];
if (!$answer['operational_identity_unchanged']) { throw new RuntimeException('Operational identity changed during replay.'); }
$answer['completed_at'] = gmdate(DATE_ATOM);
AlgorithmTrendResearch::write($out . '/results.json', $answer);
AlgorithmTrendResearch::write($root . '/docs/research_results/alpaca_stops_from_2023_20260909.json', $answer);
echo "Four new-start replays and four truncated replays complete; no trading changes.\n";
