#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Backtest\TacticalRotationQualification;
use FulltimeTrading\Research\AdaptiveResearchFactory;
use FulltimeTrading\Research\AlgorithmTrendResearch;
use FulltimeTrading\Research\DailyDataAudit;
use FulltimeTrading\Research\HybridV4Research;
use FulltimeTrading\Research\OfflineHybridDataset;
use FulltimeTrading\Trading\TacticalImplementationIdentity;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__);
$options = getopt('', ['data-dir:', 'output-dir:', 'suite:']);
$directory = (string) ($options['data-dir'] ?? $root . '/var/reports/independent_data_20260908');
$out = (string) ($options['output-dir'] ?? $root . '/var/reports/independent_audit_20260908');
if (file_exists($out)) { throw new RuntimeException('Use a new output directory.'); }
$read = static fn (string $path): array => json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$protocol = $read($directory . '/protocol.json');
$manifest = $read($directory . '/manifest.json');
if (!isset($manifest['finished_at'])) { throw new RuntimeException('Snapshot collection is incomplete.'); }
$identity = TacticalImplementationIdentity::current($root, $protocol['profile']);
if ($identity !== $protocol['operational_identity']) { throw new RuntimeException('Operational identity drift.'); }
mkdir($out, 0775, true);
$sources = ['original' => DailyDataAudit::decode($read($directory . '/original.json')), 'yahoo' => []];
$original = OfflineHybridDataset::load($root, '2026-09-04');
if (DailyDataAudit::encode($sources['original']) !== DailyDataAudit::encode($original['bars'])) {
    throw new RuntimeException('Original data mismatch.');
}
unset($original);
foreach ($manifest['snapshots'] as $id => $item) {
    $path = $directory . '/' . $item['file'];
    if (!hash_equals($item['sha256'], hash_file('sha256', $path))) { throw new RuntimeException('Snapshot drift: ' . $id); }
    $bars = DailyDataAudit::decode($read($path));
    if (str_starts_with($id, 'yahoo_')) { $sources['yahoo'] += $bars; } else { $sources[$id] = $bars; }
}
$report = ['started_at' => gmdate(DATE_ATOM), 'protocol_sha256' => hash_file('sha256', $directory . '/protocol.json'),
    'manifest_sha256' => hash_file('sha256', $directory . '/manifest.json'), 'cases' => $protocol['cases'],
    'source_errors' => $manifest['errors'], 'comparisons' => [], 'coverage' => [], 'scenarios' => [], 'skipped' => [],
    'limitations' => $protocol['limitations'], 'deployed' => false, 'order_submission_enabled' => false];
$report['implementation_sha256'] = ['runner' => hash_file('sha256', __FILE__),
    'daily_data_audit' => hash_file('sha256', $root . '/src/Research/DailyDataAudit.php')];
foreach ($sources as $id => $bars) {
    if ($bars !== []) { $report['coverage'][$id] = DailyDataAudit::coverage($bars); }
}
$select = static function (array $bars, array $symbols, string $start, string $end): array {
    $answer = [];
    foreach ($symbols as $symbol) {
        if (!array_key_exists($symbol, $bars)) { throw new RuntimeException('Missing symbol: ' . $symbol); }
        $answer[$symbol] = array_values(array_filter($bars[$symbol], static fn ($bar): bool =>
            DailyDataAudit::session($bar) >= $start && DailyDataAudit::session($bar) <= $end));
    }
    return $answer;
};
$baseSymbols = array_keys($sources['original']);
foreach (['sip_split', 'sip_all', 'iex_split', 'yahoo'] as $id) {
    try {
        $candidate = $select($sources[$id] ?? [], $baseSymbols, '2020-01-01', '2026-09-04');
        $report['comparisons']['original_vs_' . $id] = DailyDataAudit::compare($sources['original'], $candidate);
        $report['comparisons']['tail_original_vs_' . $id] = DailyDataAudit::compare($sources['original'], $candidate, '2026-07-16');
    } catch (Throwable $e) { $report['skipped']['compare_' . $id] = $e->getMessage(); }
}
if (isset($sources['sip_split']) && $sources['yahoo'] !== []) {
    try {
        $report['comparisons']['fresh_sip_vs_yahoo'] = DailyDataAudit::compare(
            $select($sources['sip_split'], $baseSymbols, '2020-01-01', '2026-09-04'),
            $select($sources['yahoo'], $baseSymbols, '2020-01-01', '2026-09-04'));
    } catch (Throwable $e) { $report['skipped']['compare_fresh_sip_yahoo'] = $e->getMessage(); }
}
AlgorithmTrendResearch::write($out . '/data_audit.json', $report);
$plans = [];
foreach (['original', 'sip_split', 'sip_all', 'iex_split', 'yahoo'] as $source) {
    $plans[$source . '_2021_2026'] = ['source' => $source, 'data_start' => '2020-01-01', 'start' => '2021-01-04', 'end' => '2026-09-04',
        'universe' => $protocol['original_universe'], 'costs' => [20, 30, 40], 'kind' => 'same_history_source_replication'];
}
foreach (['all_fields' => ['open', 'high', 'low', 'close', 'volume'], 'volume_only' => ['volume'],
    'prices_only' => ['open', 'high', 'low', 'close'], 'open_only' => ['open']] as $id => $fields) {
    $plans['sip_tail_' . $id] = ['source' => 'sip_split', 'data_start' => '2020-01-01', 'start' => '2021-01-04', 'end' => '2026-09-04',
        'universe' => $protocol['original_universe'], 'costs' => [30], 'kind' => 'tail_feed_attribution', 'replace_fields' => $fields];
}
foreach ([['sip_split', '2016-01-01', '2017-01-03', '2020-12-31'],
    ['sip_split', '2016-01-01', '2020-01-02', '2020-12-31'],
    ['yahoo', '1998-01-01', '2000-01-03', '2009-12-31'],
    ['yahoo', '2009-01-01', '2010-01-04', '2020-12-31'],
    ['yahoo', '2019-01-01', '2020-01-02', '2020-12-31']] as [$source, $warmup, $start, $end]) {
    $plans[$source . '_' . substr($start, 0, 4) . '_' . substr($end, 0, 4)] = ['source' => $source, 'data_start' => $warmup,
        'start' => $start, 'end' => $end, 'universe' => $protocol['original_universe'], 'costs' => [30], 'kind' => 'earlier_history_fixed_survivor_universe'];
}
foreach (['sip_split', 'yahoo'] as $source) {
    foreach (['expanded' => array_merge($protocol['original_universe'], $protocol['additional_universe']),
        'unseen_only' => $protocol['additional_universe']] as $id => $universe) {
        $plans[$source . '_' . $id] = ['source' => $source, 'data_start' => '2020-01-01', 'start' => '2021-01-04', 'end' => '2026-09-04',
            'universe' => $universe, 'costs' => [30], 'kind' => 'retrospective_universe_transfer_not_point_in_time'];
    }
}
if (isset($options['suite'])) {
    if ($options['suite'] !== 'earlier') { throw new InvalidArgumentException('Unknown suite.'); }
    $plans = array_filter($plans, static fn (array $p): bool => $p['kind'] === 'earlier_history_fixed_survivor_universe');
}
AlgorithmTrendResearch::write($out . '/test_plan.json', ['frozen_before_replay' => gmdate(DATE_ATOM), 'plans' => $plans,
    'cases' => $protocol['cases'], 'data_snapshot_manifest_sha256' => $report['manifest_sha256']]);
$referenceCurves = [];
$runs = 0;
foreach ($plans as $id => $plan) {
    try {
        if ($plan['kind'] === 'earlier_history_fixed_survivor_universe') {
            $availability = DailyDataAudit::availableBy($plan['universe'], $sources[$plan['source']] ?? [], $plan['end']);
            $plan['universe'] = $availability['included'];
            $plan['not_yet_in_this_history'] = $availability['not_yet_in_this_history'];
        }
        $symbols = array_values(array_unique(array_merge($plan['universe'], ['SPY', 'QQQ'])));
        $bars = $select($sources[$plan['source']] ?? [], $symbols, $plan['data_start'], $plan['end']);
        if (isset($plan['replace_fields'])) {
            $bars = DailyDataAudit::replaceFields($sources['original'], $bars, $plan['replace_fields'], '2026-07-16');
        }
        $coverage = DailyDataAudit::coverage($bars);
        foreach ($coverage as $symbol => $row) {
            // Pre-IPO emptiness is allowed only for the earlier-history test.
            if ($row['bars'] === 0 && $plan['kind'] !== 'earlier_history_fixed_survivor_universe') {
                throw new RuntimeException('No data: ' . $symbol);
            }
            if ($row['internal_missing_sessions'] !== [] || $row['extra_sessions'] !== []) {
                throw new RuntimeException('Calendar mismatch: ' . $symbol . ' ' . json_encode($row));
            }
            if ($row['bars'] > 0 && $row['last'] !== $coverage['SPY']['last']) {
                throw new RuntimeException('Truncated last session: ' . $symbol);
            }
        }
        $profile = DailyDataAudit::withUniverse($protocol['profile'], $plan['universe']);
        foreach ($protocol['cases'] as $case => $changes) {
            foreach ($plan['costs'] as $cost) {
                $tester = AdaptiveResearchFactory::make($profile, $changes, (float) $cost);
                $run = $tester->run($bars, $plan['start'], $plan['end']);
                $curve = $run['curve'];
                $m = ['full' => $tester->metrics($curve),
                    'train' => $tester->metrics($curve, '2021-01-04', '2024-01-01'),
                    'validation' => $tester->metrics($curve, '2024-01-01', '2026-01-01'),
                    'later_2026' => $tester->metrics($curve, '2026-01-01'),
                    'after_feed_splice' => $tester->metrics($curve, '2026-07-16')];
                $annual = [];
                for ($year = (int) substr($plan['start'], 0, 4); $year <= (int) substr($plan['end'], 0, 4); $year++) {
                    $annual[$year] = $tester->metrics($curve, sprintf('%04d-01-01', $year), sprintf('%04d-01-01', $year + 1));
                }
                $row = ['metrics' => $m, 'annual' => $annual, 'activity' => HybridV4Research::activity($run['sleeve_curves']),
                    'curve_sha256' => hash('sha256', json_encode($curve, JSON_THROW_ON_ERROR))];
                if ($plan['start'] === '2021-01-04') {
                    $row['historical_qualification'] = (new TacticalRotationQualification($profile['validation']))
                        ->evaluate($m['train'], $m['validation'], $m['later_2026'], $m['full'], $annual);
                }
                if ($cost === 30) {
                    if ($id === 'original_2021_2026') { $referenceCurves[$case] = $curve; }
                    if (isset($referenceCurves[$case]) && $plan['start'] === '2021-01-04') {
                        $reference = $referenceCurves[$case];
                        if (array_column($curve, 'date') !== array_column($reference, 'date')) { throw new RuntimeException('Replay calendars differ.'); }
                        $different = 0;
                        foreach ($curve as $i => $point) {
                            foreach ($point['sleeves'] as $sleeve => $child) {
                                $different += (int) (($child['holding'] ?? null) !== ($reference[$i]['sleeves'][$sleeve]['holding'] ?? null));
                            }
                        }
                        $row['versus_original'] = ['different_sleeve_holding_days' => $different,
                            'terminal_wealth_ratio' => end($curve)['equity'] / end($reference)['equity']];
                    }
                    AlgorithmTrendResearch::write($out . '/' . $id . '_' . $case . '_curve.json', $curve);
                }
                $report['scenarios'][$id]['plan'] = $plan;
                $report['scenarios'][$id]['cases'][$case][$cost] = $row;
                $runs++;
                $report['completed_replays'] = $runs;
                AlgorithmTrendResearch::write($out . '/progress.json', $report);
                printf("%s / %s / %d bps: CAGR %.2f%%, DD %.2f%%, total %.2f%%\n", $id, $case, $cost,
                    100 * $m['full']['cagr'], 100 * $m['full']['max_drawdown'], 100 * $m['full']['return']);
                unset($tester, $run, $curve);
            }
        }
        unset($bars);
    } catch (Throwable $e) {
        $report['skipped'][$id] = $e->getMessage();
        AlgorithmTrendResearch::write($out . '/progress.json', $report);
        fprintf(STDERR, "SKIPPED %s: %s\n", $id, $e->getMessage());
    }
}
if ($identity !== TacticalImplementationIdentity::current($root, $protocol['profile'])) { throw new RuntimeException('Operational identity changed.'); }
$report['finished_at'] = gmdate(DATE_ATOM);
$report['operational_identity_unchanged'] = true;
AlgorithmTrendResearch::write($out . '/results.json', $report);
echo "Independent-source audit finished: $out/results.json\n";
