#!/usr/bin/env php
<?php
declare(strict_types=1);

use FulltimeTrading\Paper\CandidateRelease;
use FulltimeTrading\Research\AlgorithmTrendResearch as Writer;
use FulltimeTrading\Research\CandidateBullRiskStudy as Study;
use FulltimeTrading\Research\CandidateInteractionStudy as Prior;
use FulltimeTrading\Research\CandidateResearchReplay as Replay;
use FulltimeTrading\Research\SelectedMaximumResearch as Hash;

require dirname(__DIR__) . '/bootstrap.php';
set_error_handler(static function (int $n, string $s): never { throw new RuntimeException($s); });
$root = dirname(__DIR__); $relative = 'var/reports/candidate_bull_risk_20260915'; $dir = $root . '/' . $relative;
$lock = \FulltimeTrading\Support\ProcessLock::tryAcquire($root . '/var/run/research_candidate_bull_20260915.lock');
if ($lock === null) { exit(75); }
$maxNew = 0;
foreach (array_slice($argv, 1) as $arg) {
    if (!preg_match('/^--max-new=(\d+)$/D', $arg, $m)) { throw new InvalidArgumentException('Only --max-new=N is supported.'); }
    $maxNew = (int) $m[1];
}
$read = static fn ($path): array => json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
$parentPath = $root . '/var/reports/candidate_interactions_20260915/protocol.json'; $parent = $read($parentPath);
$release = CandidateRelease::verify($root, require $root . '/config/paper_candidate.php');
if ($release['runtime_hash'] !== $parent['deployed_runtime_hash']) { throw new RuntimeException('Active release differs from parent.'); }
$p = ['schema' => 'candidate-conditional-bull-risk-v1', 'cases' => Study::cases(), 'conditions' => Prior::conditions(), 'costs_bps' => [30, 60],
    'initial_equity' => $parent['initial_equity'], 'deployed_runtime_hash' => $release['runtime_hash'],
    'input_paths' => $parent['input_paths'], 'input_sha256' => $parent['input_sha256'], 'code_sha256' => $parent['code_sha256'],
    'parent_protocol_sha256' => hash_file('sha256', $parentPath), 'independent_holdout' => false, 'deployment_authority' => false,
    'planned_comparisons' => 144, 'planned_new' => 96, 'planned_reused' => 48, 'orders_submitted' => 0,
    'parity_controls' => ['deployed__early2017__30', 'deployed__continuous__30'],
    'bull_definition' => 'Both completed SPY/QQQ closes above own SMA50 or SMA200; each SMA above its value ten sessions earlier; both closes above five sessions earlier; SVXY above SMA200. Boost 1.05 or 1.10 only then. Existing defensive cap, stops and circuit confirmation unchanged. Next-session execution only.',
    'selection' => 'Twelve conditional sizing rules predeclared before results; six cached controls. Compare with deployed, own VVIX anchor and constant-risk control. All eight labels are descriptive sensitivity, not a new mandatory gate. Retain losses and adjacent settings.',
    'data_contract' => $parent['data_contract']];
foreach (['src/Research/CandidateBullRiskStudy.php', 'src/Research/CandidateResearchReplay.php', 'tests/candidate_bull_risk_study.php', 'tools/research_candidate_bull_20260915.php'] as $file) { $p['code_sha256'][$file] = hash_file('sha256', $root . '/' . $file); }
foreach ($p['code_sha256'] as $file => $hash) { if (hash_file('sha256', $root . '/' . $file) !== $hash) { throw new RuntimeException('Frozen code drift: ' . $file); } }
foreach ($p['input_paths'] as $name => $file) { if (hash_file('sha256', $root . '/' . $file) !== $p['input_sha256'][$name]) { throw new RuntimeException('Frozen input drift: ' . $name); } }
if (!is_dir($dir)) { mkdir($dir, 0775, true); }
if (is_file($dir . '/protocol.json') && Hash::hash($read($dir . '/protocol.json')) !== Hash::hash($p)) { throw new RuntimeException('Protocol changed; preserve old experiment.'); }
Writer::write($dir . '/protocol.json', $p); $sha = hash_file('sha256', $dir . '/protocol.json');
$summary = ['protocol_sha256' => $sha, 'expected_comparisons' => 144, 'complete' => false, 'results' => [], 'reused' => 0, 'computed' => 0, 'orders_submitted' => 0];
$save = static function () use (&$summary, $dir): void {
    $summary['updated_at'] = gmdate(DATE_ATOM); $summary['complete'] = count($summary['results']) === 144;
    Writer::write($dir . '/summary.json', $summary);
};
$tasks = [];
foreach (Study::cases() as $id => $case) {
    foreach (Prior::conditions() as $scenario => $window) {
        foreach ([30, 60] as $cost) { $name = $id . '__' . $scenario . '__' . $cost; $tasks[$name] = compact('id', 'case', 'scenario', 'window', 'cost', 'name'); }
    }
}
foreach ($tasks as $name => $task) {
    if ($task['case']['reuse_id'] === null) { continue; }
    $sourcePath = 'var/reports/candidate_interactions_20260915/' . $name . '.json'; $source = $read($root . '/' . $sourcePath);
    if ($source['protocol_sha256'] !== $p['parent_protocol_sha256'] || $source['id'] !== $task['id']
        || $source['scenario'] !== $task['scenario'] || $source['cost_bps'] !== $task['cost']
        || hash_file('sha256', $root . '/' . $source['curve_path']) !== $source['curve_sha256']) { throw new RuntimeException('Cached control drift: ' . $name); }
    $r = ['protocol_sha256' => $sha, 'id' => $task['id'], 'scenario' => $task['scenario'], 'cost_bps' => $task['cost'], 'reused' => true,
        'metrics' => $source['metrics'], 'annual' => $source['annual'], 'trade_ledger' => $source['trade_ledger'], 'stop_events' => $source['stop_events'],
        'curve_path' => $source['curve_path'], 'curve_sha256' => $source['curve_sha256'], 'source_path' => $sourcePath, 'source_sha256' => hash_file('sha256', $root . '/' . $sourcePath)];
    if (is_file($dir . '/' . $name . '.json') && Hash::hash($read($dir . '/' . $name . '.json')) !== Hash::hash($r)) { throw new RuntimeException('Cached receipt changed.'); }
    Writer::write($dir . '/' . $name . '.json', $r); $summary['results'][$name] = $r['metrics']; ++$summary['reused'];
}
$save(); echo 'Cached comparisons verified: ', $summary['reused'], "\n";
$profile = require $root . '/config/tactical_rotation.php'; $contexts = [];
$context = static function (array $window) use (&$contexts, $root, $p, $profile): array {
    $key = $window['bars_from'] . '__' . $window['end'];
    return $contexts[$key] ??= Replay::context($root, $p['input_paths'], $window, $profile);
};
// Two reruns validate the new replay helper, not additional hypotheses.
foreach ($p['parity_controls'] as $name) {
    $task = $tasks[$name]; $path = $dir . '/parity_' . $name . '.json'; $cached = $read($dir . '/' . $name . '.json');
    $expected = ['metrics' => $cached['metrics'], 'annual' => $cached['annual'], 'trade_ledger' => $cached['trade_ledger'], 'curve' => $read($root . '/' . $cached['curve_path'])];
    if (is_file($path)) {
        $parity = $read($path);
        if ($parity['protocol_sha256'] !== $sha || $parity['result_sha256'] !== Hash::hash($expected) || $parity['passed'] !== true) { throw new RuntimeException('Replay parity proof changed.'); }
    } else {
        $run = Replay::run($task['case'], $context($task['window']), $profile, $task['window'], $task['cost'], $p['initial_equity'], [Study::class, 'maps']);
        $actual = array_intersect_key($run, $expected);
        if (Hash::hash($expected) !== Hash::hash($actual)) { throw new RuntimeException('New replay helper differs from frozen control: ' . $name); }
        Writer::write($path, ['protocol_sha256' => $sha, 'passed' => true, 'case' => $name, 'result_sha256' => Hash::hash($actual)]);
        unset($run); gc_collect_cycles();
    }
    echo 'Exact replay parity: ', $name, " PASS\n";
}
$new = 0;
foreach ($tasks as $name => $task) {
    if (isset($summary['results'][$name])) { continue; }
    $path = $dir . '/' . $name . '.json';
    if (is_file($path)) {
        $r = $read($path);
        if ($r['protocol_sha256'] !== $sha || $r['id'] !== $task['id'] || $r['scenario'] !== $task['scenario'] || $r['cost_bps'] !== $task['cost']
            || $r['reused'] !== false || hash_file('sha256', $root . '/' . $r['curve_path']) !== $r['curve_sha256']) { throw new RuntimeException('Resume identity drift.'); }
    } else {
        if ($maxNew > 0 && $new >= $maxNew) { break; }
        if (CandidateRelease::hash($root) !== $p['deployed_runtime_hash']) { throw new RuntimeException('Operational release changed.'); }
        $run = Replay::run($task['case'], $context($task['window']), $profile, $task['window'], $task['cost'], $p['initial_equity'], [Study::class, 'maps']);
        $curvePath = $relative . '/' . $name . '_curve.json'; Writer::write($root . '/' . $curvePath, $run['curve']); unset($run['curve']);
        $r = ['protocol_sha256' => $sha, 'id' => $task['id'], 'scenario' => $task['scenario'], 'cost_bps' => $task['cost'], 'reused' => false,
            'curve_path' => $curvePath, 'curve_sha256' => hash_file('sha256', $root . '/' . $curvePath)] + $run;
        Writer::write($path, $r); ++$new; unset($run); gc_collect_cycles();
        printf("%s CAGR %.3f%% DD %.3f%% boost sessions %d\n", $name, 100 * $r['metrics']['cagr'], 100 * $r['metrics']['max_drawdown'], $r['boost_eligible_sessions']);
    }
    $summary['results'][$name] = $r['metrics']; ++$summary['computed']; $save();
}
$save(); echo 'Bull comparisons: ', count($summary['results']), '/144; new in this invocation: ', $new, "\n";
