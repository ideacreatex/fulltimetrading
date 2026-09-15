#!/usr/bin/env php
<?php
declare(strict_types=1);
use FulltimeTrading\Paper\CandidateRelease as Release;
use FulltimeTrading\Research\CandidateBullRiskStudy as Study;
use FulltimeTrading\Research\CandidateResearchReplay as Replay;
use FulltimeTrading\Research\AlgorithmTrendResearch as Writer;
use FulltimeTrading\Research\SelectedMaximumResearch as Hash;
require dirname(__DIR__) . '/bootstrap.php';
set_error_handler(static function (int $n, string $s): never { throw new RuntimeException($s); });
$root = dirname(__DIR__); $dir = $root . '/var/reports/bull5_capital_sensitivity_20260915';
$lock = \FulltimeTrading\Support\ProcessLock::tryAcquire($root . '/var/run/bull5_capital_sensitivity.lock'); if ($lock === null) { exit(75); }
$read = static fn ($p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
$parentDir = $root . '/var/reports/candidate_bull_risk_20260915'; $parent = $read($parentDir . '/protocol.json');
$receipt = $read($root . '/docs/HYBRID_V4_BULL_RISK_2026-09-15.json'); $active = Release::verify($root, require $root . '/config/paper_candidate.php');
$protocol = ['schema' => 'bull5-capital-sensitivity-v1', 'capitals' => [25000., 27500., 27567.66, 27600., 30000.],
    'recipes' => ['deployed', 'bull_v110_ma50_boost105'], 'conditions' => array_intersect_key($parent['conditions'], array_flip(['continuous', 'fresh2023'])),
    'costs_bps' => [30, 60], 'parent_protocol_sha256' => hash_file('sha256', $parentDir . '/protocol.json'), 'input_paths' => $parent['input_paths'],
    'input_sha256' => $parent['input_sha256'], 'code_sha256' => $parent['code_sha256'] + ['tools/research_bull5_capital_20260915.php' => hash_file('sha256', __FILE__)],
    'runtime_hash' => $active['runtime_hash'], 'expected_cases' => 40, 'reuse_cases' => 8, 'purpose' => 'Execution/circuit/whole-share sensitivity near actual capital, not new alpha hypotheses or an independent holdout.'];
if ($receipt['protocol_sha256'] !== $protocol['parent_protocol_sha256']) { throw new RuntimeException('Parent protocol drift.'); }
foreach ($protocol['code_sha256'] as $file => $hash) { if (hash_file('sha256', $root . '/' . $file) !== $hash) { throw new RuntimeException('Code drift.'); } }
foreach ($protocol['input_paths'] as $id => $file) { if (hash_file('sha256', $root . '/' . $file) !== $protocol['input_sha256'][$id]) { throw new RuntimeException('Input drift.'); } }
if (!is_dir($dir)) { mkdir($dir, 0775, true); }
if (is_file($dir . '/protocol.json') && Hash::hash($read($dir . '/protocol.json')) !== Hash::hash($protocol)) { throw new RuntimeException('Frozen capital protocol changed.'); }
Writer::write($dir . '/protocol.json', $protocol); $sha = hash_file('sha256', $dir . '/protocol.json'); $profile = require $root . '/config/tactical_rotation.php';
$contexts = []; $summary = ['protocol_sha256' => $sha, 'results' => [], 'complete' => false, 'orders_submitted' => 0];
foreach ($protocol['capitals'] as $capital) { foreach ($protocol['conditions'] as $scenario => $window) { foreach ([30, 60] as $cost) { foreach ($protocol['recipes'] as $id) {
    $name = $id . '__' . $scenario . '__' . $cost; $key = $name . '__' . str_replace('.', '_', number_format($capital, 2, '.', '')); $path = $dir . '/' . $key . '.json';
    if (is_file($path)) {
        $row = $read($path); if ($row['protocol_sha256'] !== $sha) { throw new RuntimeException('Saved capital case changed.'); }
    } elseif ($capital === 27567.66) {
        $source = $parentDir . '/' . $name . '.json';
        if (hash_file('sha256', $source) !== $receipt['receipts'][$name]['case_sha256']) { throw new RuntimeException('Cached actual-capital case changed.'); }
        $old = $read($source); $row = ['protocol_sha256' => $sha, 'capital' => $capital, 'recipe' => $id, 'scenario' => $scenario, 'cost' => $cost,
            'metrics' => $old['metrics'], 'annual' => $old['annual'], 'reused_sha256' => hash_file('sha256', $source)];
        Writer::write($path, $row);
    } else {
        $contextKey = $window['bars_from'] . '_' . $window['end'];
        $ctx = $contexts[$contextKey] ??= Replay::context($root, $protocol['input_paths'], $window, $profile);
        $r = Replay::run(Study::cases()[$id], $ctx, $profile, $window, $cost, $capital, [Study::class, 'maps']);
        $curveFile = $dir . '/' . $key . '_curve.json'; Writer::write($curveFile, $r['curve']);
        $row = ['protocol_sha256' => $sha, 'capital' => $capital, 'recipe' => $id, 'scenario' => $scenario, 'cost' => $cost,
            'metrics' => $r['metrics'], 'annual' => $r['annual'], 'trade_ledger' => $r['trade_ledger'],
            'curve_sha256' => hash_file('sha256', $curveFile), 'curve_path' => substr($curveFile, strlen($root) + 1), 'orders_submitted' => 0];
        Writer::write($path, $row); unset($r, $ctx); gc_collect_cycles();
    }
    $summary['results'][$key] = array_intersect_key($row, array_flip(['capital', 'recipe', 'scenario', 'cost', 'metrics'])) + ['sha256' => hash_file('sha256', $path)];
    $summary['complete'] = count($summary['results']) === 40; Writer::write($dir . '/summary.json', $summary);
    printf("%s capital %.2f return %.3f%% DD %.3f%%\n", $name, $capital, 100 * $row['metrics']['return'], 100 * $row['metrics']['max_drawdown']);
} } } }
if (Release::hash($root) !== $active['runtime_hash']) { throw new RuntimeException('Active runtime changed during research.'); }
