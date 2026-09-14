#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Research\AlgorithmTrendResearch as A;
use FulltimeTrading\Research\ResearchMultiplicityAudit;
use FulltimeTrading\Trading\TacticalImplementationIdentity;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__); $out = $root . '/var/reports/cross_asset_research_20260909';
$read = static fn ($f): array => json_decode(file_get_contents($f), true, 512, JSON_THROW_ON_ERROR);
if (file_exists($out . '/results.json')) { throw new RuntimeException('Completed report is immutable.'); }
$p = $read($out . '/protocol.json'); $rows = $read($out . '/primary.json'); $s = $read($out . '/selection.json');
$start = $read($root . '/var/reports/start_dependence_20260909/results.json');
$audit = []; $prefixes = 0;
foreach ($s['jobs'] as $job) {
    $r = $read($out . '/' . $job['scenario'] . '_' . $job['id'] . '.json');
    if ($r['protocol_sha256'] !== hash_file('sha256', $out . '/protocol.json')) { throw new RuntimeException('Wrong audit protocol.'); }
    $audit[$job['id']][$job['scenario']] = $r;
    $prefixes += (int) ($r['prefix_pass'] === true);
}
if ($prefixes !== count($s['audit_ids'])) { throw new RuntimeException('Missing prefix checks.'); }
$run = static function (array $command) use ($root): array {
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $root);
    if (!is_resource($process)) { throw new RuntimeException('Cannot run verification.'); }
    fclose($pipes[0]); $text = stream_get_contents($pipes[1]); fclose($pipes[1]);
    return ['exit_code' => proc_close($process), 'output' => $text];
};
$oldTests = $read($root . '/var/reports/selected_maximum_20260909/tests.json'); $tests = [];
foreach (array_merge(array_keys($oldTests['tests']), ['cross_asset_research']) as $name) {
    $tests[$name] = $run([PHP_BINARY, '-d', 'memory_limit=2G', $root . '/tests/' . $name . '.php']);
    if ($tests[$name]['exit_code'] !== 0) { throw new RuntimeException('Test failed: ' . $name . ' ' . $tests[$name]['output']); }
    echo $name, " PASS\n";
}
$diff = $run(['git', 'diff', '--check']);
if ($diff['exit_code'] !== 0) { throw new RuntimeException('Whitespace errors.'); }
foreach ($p['code_sha256'] as $file => $sha) { if (!hash_equals($sha, hash_file('sha256', $root . '/' . $file))) { throw new RuntimeException('Frozen research code drift: ' . $file); } }
$qualified = $joint = $strict = $by2021 = $by2023 = [];
foreach ($rows as $id => $r) {
    if ($r['continuous']['qualification']['qualifies']) { $qualified[] = $id; }
    $by2021[$id] = $r['continuous']['metrics']['full']['cagr']; $by2023[$id] = $r['fresh2023']['metrics']['full']['cagr'];
    $better = $id !== 'maximum'; $some = false;
    foreach (['continuous', 'fresh2023'] as $window) {
        foreach (['cagr', 'max_drawdown'] as $metric) {
            $x = $r[$window]['metrics']['full'][$metric]; $b = $rows['maximum'][$window]['metrics']['full'][$metric];
            $better = $better && $x >= $b - 1e-10; $some = $some || $x > $b + 1e-10;
        }
    }
    if ($better && $some) { $joint[] = $id; }
}
arsort($by2021); arsort($by2023);
foreach ($audit as $id => $replays) {
    if (!in_array($id, $joint, true)) { continue; }
    $better = true;
    foreach ($replays as $scenario => $r) {
        if ($scenario === 'prefix2023') { continue; }
        foreach (['cagr', 'max_drawdown'] as $metric) { $better = $better && $r['metrics']['full'][$metric] >= $audit['maximum'][$scenario]['metrics']['full'][$metric] - 1e-10; }
    }
    if ($better) { $strict[] = $id; }
}
$logs = static function ($curve): array {
    $r = [];
    foreach ($curve as $row) { if ($row['date'] >= '2024-01-01') { $r[$row['date']] = log($row['equity'] / $row['start_equity']); } }
    return $r;
};
$base = $logs($read($out . '/continuous_maximum_curve.json')); $matrix = [];
foreach ($p['cases'] as $id => $_) {
    if ($id === 'maximum') { continue; }
    $values = $logs($read($out . '/continuous_' . $id . '_curve.json'));
    if (array_keys($values) !== array_keys($base)) { throw new RuntimeException('Unaligned bootstrap matrix.'); }
    $matrix[$id] = array_values(array_map(static fn ($a, $b): float => $a - $b, $values, $base));
}
$multiple = ResearchMultiplicityAudit::run($matrix, 20, 1000, 20260912);
$report = ['completed_at' => gmdate(DATE_ATOM), 'protocol' => $p, 'primary' => $rows, 'audit' => $audit,
    'primary_replays' => count($p['cases']) * 2, 'audit_replays' => count($s['jobs']), 'prefix_checks' => $prefixes,
    'tests' => $tests, 'tests_passed' => count($tests), 'selection' => $s, 'top2021_hindsight' => array_slice(array_keys($by2021), 0, 5),
    'top2023_hindsight' => array_slice(array_keys($by2023), 0, 5), 'original_gate_qualified' => $qualified,
    'improves_both_primary_starts' => $joint, 'improves_all_audits_among_selected' => $strict, 'multiplicity' => $multiple,
    'start_diagnostic' => $start, 'ready_for_order_enabled_demo' => false, 'orders_submitted' => 0,
    'operational_identity_unchanged' => TacticalImplementationIdentity::current($root, require $root . '/config/tactical_rotation.php') === $p['operational_identity'],
    'source_links' => ['https://www.cboe.com/tradable-products/vix/vix-historical-data', 'https://www.cboe.com/tradable-products/vix/term-structure',
        'https://www.ishares.com/us/products/239565/HYG', 'https://www.nber.org/papers/w22208']];
if (!$report['operational_identity_unchanged']) { throw new RuntimeException('Operational identity drift.'); }
A::write($out . '/results.json', $report);
A::write($root . '/docs/research_results/cross_asset_20260909.json', $report);
printf("Complete: %d hypotheses, %d primary replays, %d audits, %d tests. Qualified %d; all-audit improvements %d; p %.4f.\n", count($p['cases']), $report['primary_replays'], $report['audit_replays'], count($tests), count($qualified), count($strict), $multiple['familywise_p_value']);
