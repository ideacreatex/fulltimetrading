#!/usr/bin/env php
<?php
declare(strict_types=1);

use FulltimeTrading\Paper\CandidateDefinition;
use FulltimeTrading\Paper\CandidateRelease;
use FulltimeTrading\Research\AlgorithmTrendResearch as Writer;
use FulltimeTrading\Research\CandidateBullRiskStudy as Study;
use FulltimeTrading\Research\CandidateComponentParity as Parity;
use FulltimeTrading\Research\CandidateResearchReplay as Replay;
use FulltimeTrading\Research\DeployedCandidateStudy as Recipes;
use FulltimeTrading\Research\PaperExecutionRotationEnsembleBacktester as Ensemble;
use FulltimeTrading\Research\PortfolioCircuitController as Circuit;
use FulltimeTrading\Research\SelectedMaximumResearch as Hash;

require dirname(__DIR__) . '/bootstrap.php';
set_error_handler(static function (int $n, string $s): never { throw new RuntimeException($s); });
$root = dirname(__DIR__); $dir = $root . '/var/reports/candidate_bull_parity_20260915';
$lock = \FulltimeTrading\Support\ProcessLock::tryAcquire($root . '/var/run/candidate_bull_parity_20260915.lock');
if ($lock === null) { exit(75); }
$read = static fn ($p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
$parentDir = $root . '/var/reports/candidate_bull_risk_20260915'; $parent = $read($parentDir . '/protocol.json');
$receipts = $read($root . '/docs/HYBRID_V4_BULL_RISK_2026-09-15.json');
$release = CandidateRelease::verify($root, require $root . '/config/paper_candidate.php');
if ($release['runtime_hash'] !== $parent['deployed_runtime_hash'] || $receipts['protocol_sha256'] !== hash_file('sha256', $parentDir . '/protocol.json')) { throw new RuntimeException('Parent/release drift.'); }
$protocol = ['schema' => 'bull5-component-parity-v1', 'cases' => ['bull_v100_ma50_boost105', 'bull_v105_ma50_boost105', 'bull_v110_ma50_boost105'],
    'conditions' => $parent['conditions'], 'costs_bps' => [30, 60], 'initial_equity' => $parent['initial_equity'], 'runtime_hash' => $release['runtime_hash'],
    'parent_protocol_sha256' => hash_file('sha256', $parentDir . '/protocol.json'), 'input_paths' => $parent['input_paths'], 'input_sha256' => $parent['input_sha256'],
    'code_sha256' => $parent['code_sha256'], 'expected_cases' => 24, 'orders_submitted' => 0, 'database_modified' => false, 'deployment_authority' => false,
    'control' => 'deployed__continuous__60', 'control_purpose' => 'One additional frozen-control replay records circuit path for attribution, not another hypothesis.',
    'purpose' => 'Exact metrics/curve and production close/whole-share/prefix parity for the three existing bull5 hypotheses; preserve circuit events and daily history for attribution. No new profit claim or independent holdout.'];
foreach (['src/Research/CandidateComponentParity.php', 'src/Research/CandidateParityInputs.php', 'tests/candidate_parity_inputs.php', 'tools/verify_candidate_bull_parity_20260915.php'] as $file) { $protocol['code_sha256'][$file] = hash_file('sha256', $root . '/' . $file); }
foreach ($protocol['code_sha256'] as $file => $hash) { if (hash_file('sha256', $root . '/' . $file) !== $hash) { throw new RuntimeException('Frozen source drift.'); } }
foreach ($protocol['input_paths'] as $key => $file) { if (hash_file('sha256', $root . '/' . $file) !== $protocol['input_sha256'][$key]) { throw new RuntimeException('Frozen input drift.'); } }
if (!is_dir($dir)) { mkdir($dir, 0775, true); }
if (is_file($dir . '/protocol.json') && Hash::hash($read($dir . '/protocol.json')) !== Hash::hash($protocol)) { throw new RuntimeException('Parity protocol drift.'); }
Writer::write($dir . '/protocol.json', $protocol); $sha = hash_file('sha256', $dir . '/protocol.json');
$summary = ['protocol_sha256' => $sha, 'cases' => [], 'control' => null, 'complete' => false, 'orders_submitted' => 0, 'database_modified' => false];
$tasks = [['id' => 'deployed', 'scenario' => 'continuous', 'cost' => 60, 'parity' => false]];
foreach ($protocol['cases'] as $id) { foreach ($protocol['conditions'] as $scenario => $_) { foreach ([30, 60] as $cost) { $tasks[] = compact('id', 'scenario', 'cost') + ['parity' => true]; } } }
$profile = require $root . '/config/tactical_rotation.php'; $contexts = [];
foreach ($tasks as $task) {
    $id = $task['id']; $cost = $task['cost']; $window = $protocol['conditions'][$task['scenario']];
    $name = $id . '__' . $task['scenario'] . '__' . $cost; $sourcePath = $parentDir . '/' . $name . '.json'; $source = $read($sourcePath); $out = $dir . '/' . $name . '.json';
    if ($source['protocol_sha256'] !== $protocol['parent_protocol_sha256'] || hash_file('sha256', $sourcePath) !== $receipts['receipts'][$name]['case_sha256']
        || hash_file('sha256', $root . '/' . $source['curve_path']) !== $source['curve_sha256']) { throw new RuntimeException('Frozen result changed.'); }
    if (is_file($out)) {
        $proof = $read($out);
        if ($proof['protocol_sha256'] !== $sha || $proof['source_sha256'] !== hash_file('sha256', $sourcePath) || $proof['passed'] !== true) { throw new RuntimeException('Existing parity proof changed.'); }
    } else {
        if (CandidateRelease::hash($root) !== $protocol['runtime_hash']) { throw new RuntimeException('Active runtime changed.'); }
        $group = $window['bars_from'] . '__' . $window['end']; $ctx = $contexts[$group] ??= Replay::context($root, $protocol['input_paths'], $window, $profile);
        $case = Study::cases()[$id]; $maps = Study::maps($case, $ctx['all'], $ctx['breadth'], $ctx['vvix']);
        $books = Recipes::books($case['spec'], $profile, $maps['scale'], $ctx['features'], $ctx['nominal'], $cost);
        if ($cost === 30 && $books !== CandidateDefinition::books($profile, $maps['scale'], $ctx['features'], $ctx['nominal'])) { throw new RuntimeException('Unexpected changes beyond dated scale/confirmation.'); }
        foreach ($books as &$book) { $book['config']['universe'] = array_values(array_intersect($book['config']['universe'], array_keys($ctx['all']))); }
        unset($book); $engine = new Ensemble($books); $books = $engine->config(); $controller = new Circuit(CandidateDefinition::CIRCUIT, $maps['confirmation']);
        $result = $engine->runControlled($ctx['bars'], $window['start'], $window['end'], $controller, $protocol['initial_equity']);
        if ($engine->metrics($result) !== $source['metrics']) { throw new RuntimeException('Frozen metric mismatch: ' . $name); }
        $curve = array_map(static fn ($row): array => array_intersect_key($row, array_flip(['date', 'equity', 'start_equity', 'equity_low', 'equity_high', 'period_start_date', 'turnover'])), $result['curve']);
        if (Hash::hash($curve) !== Hash::hash($read($root . '/' . $source['curve_path']))) { throw new RuntimeException('Frozen curve mismatch.'); }
        $circuit = $controller->report();
        $proof = $task['parity'] ? Parity::inspect($books, $ctx['bars'], $ctx['nominal'], $result, $circuit['history'], $window['end'], $cost) : [];
        $proof += ['protocol_sha256' => $sha, 'case' => $name, 'passed' => true, 'source_sha256' => hash_file('sha256', $sourcePath),
            'source_curve_sha256' => $source['curve_sha256'], 'metrics' => $source['metrics'], 'circuit' => $circuit,
            'bullish' => array_intersect_key($maps['bullish'], array_flip(array_column($curve, 'date'))), 'orders_submitted' => 0, 'database_modified' => false];
        Writer::write($out, $proof); unset($engine, $result, $curve, $controller, $books, $ctx, $maps); gc_collect_cycles();
    }
    $entry = array_intersect_key($proof, array_flip(['passed', 'assertions', 'sleeve_sessions', 'quantity_decisions', 'gap_preempted_decisions'])) + ['proof_sha256' => hash_file('sha256', $out)];
    if ($task['parity']) { $summary['cases'][$name] = $entry; } else { $summary['control'] = $entry; }
    $summary['complete'] = count($summary['cases']) === 24 && $summary['control'] !== null; $summary['updated_at'] = gmdate(DATE_ATOM);
    Writer::write($dir . '/summary.json', $summary); echo $name, ': ', $proof['assertions'] ?? 2, " checks PASS\n";
}
echo 'Bull component cases: ', count($summary['cases']), "/24; plus frozen circuit control\n";
