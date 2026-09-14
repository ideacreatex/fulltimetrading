#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Backtest\TacticalRotationQualification;
use FulltimeTrading\Research\AdaptiveResearchFactory;
use FulltimeTrading\Research\AlgorithmTrendResearch;
use FulltimeTrading\Research\AlpacaStopResearchGrid;
use FulltimeTrading\Research\BreadthVolatilityResearch as B;
use FulltimeTrading\Research\DailyDataAudit as D;
use FulltimeTrading\Research\HybridV4Research;
use FulltimeTrading\Research\PortfolioCircuitController;
use FulltimeTrading\Research\ResearchMultiplicityAudit;
use FulltimeTrading\Trading\TacticalImplementationIdentity;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__);
$read = static fn ($p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
$out = $root . '/var/reports/alpaca_portfolio_stops_20260909';
$resume = in_array('--resume', $argv, true);
if (file_exists($out) && (!$resume || file_exists($out . '/results.json'))) { throw new RuntimeException('Use a new output directory or resume an incomplete run.'); }
$dataDir = $root . '/var/reports/alpaca_stops_data_20260909';
$manifest = $read($dataDir . '/manifest.json');
if (!isset($manifest['completed_at']) || !hash_equals($manifest['snapshot_sha256'], hash_file('sha256', $dataDir . '/sip_all.json'))) { throw new RuntimeException('SIP snapshot not verified.'); }
$all = D::decode($read($dataDir . '/sip_all.json'));
$base = $read($root . '/var/reports/independent_data_20260908/protocol.json');
if (TacticalImplementationIdentity::current($root, $base['profile']) !== $base['operational_identity']) { throw new RuntimeException('Operational identity drift.'); }
$calendar = array_keys(D::indexed(['SPY' => $all['SPY']])['SPY']);
$breadth = B::validateBreadth($read($root . '/var/reports/breadth_volatility_data_20260909/s5tw.json'), $calendar);
$vvix = $read($root . '/var/reports/vvix_data_20260909/cboe_vvix.json');
$confirmations = AlpacaStopResearchGrid::confirmations($all, $breadth, $vvix);
$scales = B::windowScale(B::events($breadth)['high_touch'], 10, 1.25);
$svxyTrend = B::trend($all['SVXY'], 200);
foreach ($scales as $date => $value) { if (!$svxyTrend[$date]) { $scales[$date] = min($scales[$date], 0.5); } }
$seeds = ['prior' => $base['cases']['new_best'], 'balanced' => array_replace($base['cases']['new_best'], ['external_daily_scale' => $scales])];
$select = static function (array $bars, array $universe, string $start, string $end): array {
    $answer = array_intersect_key($bars, array_fill_keys(array_merge($universe, ['SPY', 'QQQ']), true));
    foreach ($answer as $symbol => $series) { $answer[$symbol] = array_values(array_filter($series, static fn ($b): bool => D::session($b) >= $start && D::session($b) <= $end)); }
    return $answer;
};
$sources = ['all' => $select($all, $base['original_universe'], '2020-01-01', '2026-09-04'),
    'spinoff' => D::decode($read($root . '/var/reports/data_attribution_20260908/split_spinoff.json')),
    'expanded' => $select($all, array_merge($base['original_universe'], $base['additional_universe']), '2020-01-01', '2026-09-04')];
$availability = D::availableBy($base['original_universe'], $all, '2020-12-31');
$sources['earlier'] = $select($all, $availability['included'], '2016-01-01', '2020-12-31');
$definitions = AlpacaStopResearchGrid::definitions();
$cases = [];
foreach ($seeds as $seed => $_) { foreach ($definitions as $id => $definition) { $cases[$seed . '__' . $id] = $definition + ['seed' => $seed]; } }
$protocol = ['frozen_at' => gmdate(DATE_ATOM), 'primary' => 'Alpaca SIP adjustment=all, 30bps',
    'primary_dates' => ['2021-01-04', '2026-09-04'], 'train' => ['2021-01-04', '2023-12-31'],
    'selection' => 'Two per family by train CAGR / abs(train drawdown); both seeds compete. Full-period maximum only as labeled diagnostic.',
    'cases' => $cases, 'seeds' => $seeds, 'primary_data_sha256' => $manifest['snapshot_sha256'],
    'primary_data_file' => $dataDir . '/sip_all.json', 'operational_identity' => $base['operational_identity'],
    'limits' => ['Daily OHLC stop fills are screening approximations, not verified broker executions.',
        'Daily bars do not prove RTH trigger time or NBBO election; minute stress is required for stop candidates.',
        'Stops may gap below their level. Fixed-price fills are not guaranteed.',
        'Trailing stop ratchets at completed daily closes only, not at the unknown intraday high.',
        'Global circuit observes completed close/low then exits next open. It is not an instantaneous portfolio stop.',
        'Lifetime equity and max drawdown are never reset. Only risk-epoch peaks rearm.',
        'All periods previously observed; chronological validation is not untouched future evidence.',
        'Adjusted bars are not a complete cash/share corporate-action ledger; fixed universe is retrospective.'],
    'implementation_sha256' => [], 'execution_enabled' => false];
foreach (['src/Research/AdaptiveRotationBacktester.php', 'src/Research/AdaptiveRotationEnsembleBacktester.php',
    'src/Research/PortfolioCircuitController.php', 'src/Research/AlpacaStopResearchGrid.php', 'tools/research_alpaca_portfolio_stops.php'] as $file) {
    $protocol['implementation_sha256'][$file] = hash_file('sha256', $root . '/' . $file);
}
if (!$resume) { mkdir($out, 0775, true); AlgorithmTrendResearch::write($out . '/protocol.json', $protocol); }
else {
    $previous = $read($out . '/protocol.json');
    if ($previous['cases'] != $cases || $previous['implementation_sha256'] !== $protocol['implementation_sha256']
        || $previous['primary_data_sha256'] !== $protocol['primary_data_sha256']) { throw new RuntimeException('Resume contract mismatch.'); }
}
$report = ['started_at' => gmdate(DATE_ATOM), 'primary' => [], 'replication' => [], 'replays' => 0, 'prefix_checks' => 0, 'readiness' => false];
$compact = static fn ($curve): array => array_map(static fn ($r): array => array_intersect_key($r, array_flip(['date', 'period_start_date', 'start_equity', 'equity', 'equity_low', 'equity_high', 'turnover'])), $curve);
$execute = static function (string $id, array $definition, string $source, int $cost, string $stage, string $fill = 'stop_level', ?string $endOverride = null) use ($base, $cases, $seeds, $sources, $availability, $confirmations, $out, $read, $compact, &$report): array {
    $file = $out . '/' . $stage . '_' . $source . '_' . $cost . '_' . $fill . '_' . $id;
    if ($endOverride !== null) { $file .= '_' . $endOverride; }
    $changes = isset($definition['control_changes']) ? $definition['control_changes'] : array_replace($seeds[$definition['seed']], $definition['changes']);
    if (($changes['standing_stop_pct'] ?? 0.0) > 0) { $changes['standing_stop_fill'] = $fill; }
    $profile = $source === 'expanded' ? D::withUniverse($base['profile'], array_merge($base['original_universe'], $base['additional_universe']))
        : ($source === 'earlier' ? D::withUniverse($base['profile'], $availability['included']) : $base['profile']);
    $start = $source === 'earlier' ? '2017-01-03' : '2021-01-04';
    $end = $endOverride ?? ($source === 'earlier' ? '2020-12-31' : '2026-09-04');
    $configHash = hash('sha256', json_encode([$profile, $changes, $definition['circuit'] ?? [], $definition['confirmation'] ?? 'none', $cost, $source, $start, $end]));
    if (file_exists($file . '.json')) {
        $row = $read($file . '.json');
        if ($row['config_sha256'] !== $configHash) { throw new RuntimeException('Cached replay mismatch.'); }
    } else {
        $tester = AdaptiveResearchFactory::make($profile, $changes, $cost);
        $controller = new PortfolioCircuitController($definition['circuit'] ?? [], $confirmations[$definition['confirmation'] ?? 'none']);
        $input = $endOverride === null ? $sources[$source] : HybridV4Research::truncateBars($sources[$source], $endOverride);
        $run = $tester->runControlled($input, $start, $end, $controller);
        $metrics = ['full' => $tester->metrics($run), 'train' => $tester->metrics($run, '2021-01-04', '2024-01-01'),
            'validation' => $tester->metrics($run, '2024-01-01', '2026-01-01'), 'later' => $tester->metrics($run, '2026-01-01')];
        $annual = [];
        for ($year = 2021; $year <= 2026; $year++) { $annual[$year] = $tester->metrics($run, "$year-01-01", ($year + 1) . '-01-01'); }
        $qualification = $source === 'earlier' || $endOverride !== null ? null : (new TacticalRotationQualification($profile['validation']))
            ->evaluate($metrics['train'], $metrics['validation'], $metrics['later'], $metrics['full'], $annual);
        $stops = [];
        foreach ($run['sleeves'] as $name => $sleeve) { foreach ($sleeve['standing_stop_events'] ?? [] as $event) { $stops[] = $event + ['sleeve' => $name]; } }
        $global = $controller->report();
        $row = ['metrics' => $metrics, 'qualification' => $qualification, 'annual' => $annual,
            'stops' => $stops, 'global_events' => $global['events'], 'global_blocked_sessions' => count(array_filter($global['history'], static fn ($r): bool => $r['force_cash'])),
            'config_sha256' => $configHash];
        AlgorithmTrendResearch::write($file . '_curve.json', $compact($run['curve']));
        AlgorithmTrendResearch::write($file . '.json', $row);
    }
    $report['replays']++;
    $report[$stage][$source][$cost][$fill][$id . ($endOverride === null ? '' : '__' . $endOverride)] = $row;
    printf("%d %s %s %d %s: CAGR %.2f DD %.2f stops %d portfolio events %d pass %s\n", $report['replays'], $stage, $source, $cost, $id,
        $row['metrics']['full']['cagr'] * 100, $row['metrics']['full']['max_drawdown'] * 100, count($row['stops']), count($row['global_events']),
        ($row['qualification']['qualifies'] ?? false) ? 'YES' : 'no');
    return $row;
};
$controls = ['control_original' => ['control_changes' => []], 'control_prior' => ['control_changes' => $seeds['prior']], 'control_balanced' => ['control_changes' => $seeds['balanced']]];
foreach ($controls as $id => $definition) { $execute($id, $definition, 'all', 30, 'primary'); }
foreach ($cases as $id => $definition) {
    $execute($id, $definition, 'all', 30, 'primary');
    if ($report['replays'] % 10 === 0) { AlgorithmTrendResearch::write($out . '/progress.json', $report); }
}
$scores = [];
foreach ($cases as $id => $d) {
    $m = $report['primary']['all'][30]['stop_level'][$id]['metrics']['train'];
    $scores[$d['family']][$id] = $m['cagr'] / max(0.05, abs($m['max_drawdown']));
}
$selected = [];
foreach ($scores as $family => $values) { arsort($values); $selected = array_merge($selected, array_slice(array_keys($values), 0, 2)); }
$report['train_selected'] = $selected;
AlgorithmTrendResearch::write($out . '/selection.json', ['selected' => $selected, 'at' => gmdate(DATE_ATOM), 'rule' => $protocol['selection']]);
$logs = static fn ($c): array => array_map(static fn ($r): float => log($r['equity'] / $r['start_equity']), array_values(array_filter($c, static fn ($r): bool => $r['date'] >= '2024-01-01')));
$baselineLogs = $logs($read($out . '/primary_all_30_stop_level_control_prior_curve.json'));
$matrix = [];
foreach ($cases as $id => $_) { $matrix[$id] = array_map(static fn ($a, $b): float => $a - $b, $logs($read($out . '/primary_all_30_stop_level_' . $id . '_curve.json')), $baselineLogs); }
$report['multiplicity'] = ResearchMultiplicityAudit::run($matrix, 20, 1000, 20260910);
$replicate = $controls;
foreach ($selected as $id) { $replicate[$id] = $cases[$id]; }
foreach ($replicate as $id => $definition) {
    foreach ([['all', 60], ['spinoff', 30], ['spinoff', 60], ['expanded', 30], ['earlier', 30]] as [$source, $cost]) {
        $execute($id, $definition, $source, $cost, 'replication');
    }
    if (isset($definition['changes']['standing_stop_pct'])) { $execute($id, $definition, 'all', 30, 'replication', 'daily_low'); }
    if (!isset($definition['control_changes'])) {
        foreach (['2023-12-29', '2025-12-31'] as $end) {
            $execute($id, $definition, 'all', 30, 'prefix', 'stop_level', $end);
            $expected = array_values(array_filter($read($out . '/primary_all_30_stop_level_' . $id . '_curve.json'), static fn ($r): bool => $r['date'] <= $end));
            if ($read($out . '/prefix_all_30_stop_level_' . $id . '_' . $end . '_curve.json') != $expected) { throw new RuntimeException('Actual history prefix mismatch.'); }
            $report['prefix_checks']++;
        }
    }
    AlgorithmTrendResearch::write($out . '/progress.json', $report);
}
$report['primary_qualified'] = array_keys(array_filter($report['primary']['all'][30]['stop_level'], static fn ($r): bool => $r['qualification']['qualifies']));
$report['operational_identity_unchanged'] = TacticalImplementationIdentity::current($root, $base['profile']) === $base['operational_identity'];
$report['completed_at'] = gmdate(DATE_ATOM);
AlgorithmTrendResearch::write($out . '/results.json', $report);
echo 'Alpaca stop research complete: ', $report['replays'], ' replays, ', count($report['primary_qualified']), " primary historical PASS. Not deployed.\n";
