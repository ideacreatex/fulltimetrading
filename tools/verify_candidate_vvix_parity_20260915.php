#!/usr/bin/env php
<?php
declare(strict_types=1);

use FulltimeTrading\Paper\CandidateDefinition;
use FulltimeTrading\Paper\CandidateRelease;
use FulltimeTrading\Paper\CandidateSleeveState;
use FulltimeTrading\Research\AlgorithmTrendResearch as Writer;
use FulltimeTrading\Research\CandidateInteractionStudy as Study;
use FulltimeTrading\Research\CandidateParityInputs;
use FulltimeTrading\Research\CandidateResearchReplay as Replay;
use FulltimeTrading\Research\DeployedCandidateStudy as Recipes;
use FulltimeTrading\Research\PaperExecutionRotationBacktester as Single;
use FulltimeTrading\Research\PaperExecutionRotationEnsembleBacktester as Ensemble;
use FulltimeTrading\Research\PortfolioCircuitController as Circuit;
use FulltimeTrading\Research\SelectedMaximumResearch as Hash;
use FulltimeTrading\Trading\WholeShareSizing;

require dirname(__DIR__) . '/bootstrap.php';
set_error_handler(static function (int $n, string $s): never { throw new RuntimeException($s); });
$root = dirname(__DIR__); $dir = $root . '/var/reports/candidate_vvix_parity_20260915_v3';
$lock = \FulltimeTrading\Support\ProcessLock::tryAcquire($root . '/var/run/candidate_vvix_parity_20260915.lock');
if ($lock === null) { exit(75); }
$read = static fn ($p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
$parentPath = $root . '/var/reports/candidate_interactions_20260915/protocol.json'; $parent = $read($parentPath);
$release = CandidateRelease::verify($root, require $root . '/config/paper_candidate.php');
if ($release['runtime_hash'] !== $parent['deployed_runtime_hash']) { throw new RuntimeException('Active release changed.'); }
$protocol = ['schema' => 'isolated-vvix-close-quantity-parity-v3', 'cases' => ['vvix_confirmation_105', 'vvix_confirmation_110'],
    'conditions' => Study::conditions(), 'costs_bps' => [30, 60], 'initial_equity' => $parent['initial_equity'],
    'input_paths' => $parent['input_paths'], 'input_sha256' => $parent['input_sha256'], 'code_sha256' => $parent['code_sha256'],
    'parent_protocol_sha256' => hash_file('sha256', $parentPath), 'runtime_hash' => $release['runtime_hash'],
    'expected_cases' => 16, 'orders_submitted' => 0, 'database_modified' => false, 'deployment_authority' => false,
    'purpose' => 'Verify frozen historical metrics/curves and production CandidateSleeveState/WholeShareSizing parity for existing VVIX 105/110 candidates. These are execution checks, not new hypotheses or an independent holdout.',
    'supersedes_incomplete_protocol_sha256' => [hash_file('sha256', $root . '/var/reports/candidate_vvix_parity_20260915/protocol.json'), hash_file('sha256', $root . '/var/reports/candidate_vvix_parity_20260915_v2/protocol.json')],
    'harness_correction' => 'Both initial attempts completed zero cases. The first required PLTR prefix bars before IPO; the second compared missing versus null volatility metadata for PLTR/CRWD. Only proven pre-IPO null metadata is normalized; any pre-IPO price, target or nonnull volatility fails. Actual signals and prices remain exactly compared. No synthetic data or strategy changes.',
    'limitations' => 'No active snapshot builder, broker gateway, persistent ledger, native-stop lifecycle or live forward fills are exercised. Circuit feedback comes from historical replay. Opening-gap stop preemption is counted separately, not mistaken for sizing parity.' ];
foreach (['src/Research/CandidateResearchReplay.php', 'src/Research/CandidateParityInputs.php', 'tests/candidate_parity_inputs.php', 'tools/verify_candidate_vvix_parity_20260915.php'] as $file) { $protocol['code_sha256'][$file] = hash_file('sha256', $root . '/' . $file); }
foreach ($protocol['code_sha256'] as $file => $hash) { if (hash_file('sha256', $root . '/' . $file) !== $hash) { throw new RuntimeException('Frozen source drift.'); } }
foreach ($protocol['input_paths'] as $key => $file) { if (hash_file('sha256', $root . '/' . $file) !== $protocol['input_sha256'][$key]) { throw new RuntimeException('Frozen input drift.'); } }
if (!is_dir($dir)) { mkdir($dir, 0775, true); }
if (is_file($dir . '/protocol.json') && Hash::hash($read($dir . '/protocol.json')) !== Hash::hash($protocol)) { throw new RuntimeException('Parity protocol drift.'); }
Writer::write($dir . '/protocol.json', $protocol); $sha = hash_file('sha256', $dir . '/protocol.json');
$summary = ['protocol_sha256' => $sha, 'cases' => [], 'complete' => false, 'orders_submitted' => 0, 'database_modified' => false, 'deployment_authority' => false];
$profile = require $root . '/config/tactical_rotation.php'; $contexts = [];
foreach ($protocol['cases'] as $id) {
    foreach (Study::conditions() as $scenario => $window) {
        foreach ([30, 60] as $cost) {
            $name = $id . '__' . $scenario . '__' . $cost; $out = $dir . '/' . $name . '.json';
            $sourcePath = 'var/reports/candidate_interactions_20260915/' . $name . '.json'; $source = $read($root . '/' . $sourcePath);
            if ($source['protocol_sha256'] !== $protocol['parent_protocol_sha256'] || $source['id'] !== $id || $source['scenario'] !== $scenario
                || $source['cost_bps'] !== $cost || hash_file('sha256', $root . '/' . $source['curve_path']) !== $source['curve_sha256']) { throw new RuntimeException('Frozen result identity changed.'); }
            if (is_file($out)) {
                $proof = $read($out);
                if ($proof['protocol_sha256'] !== $sha || $proof['source_sha256'] !== hash_file('sha256', $root . '/' . $sourcePath) || $proof['passed'] !== true) { throw new RuntimeException('Existing proof drift.'); }
            } else {
                if (CandidateRelease::hash($root) !== $protocol['runtime_hash']) { throw new RuntimeException('Active runtime changed.'); }
                $group = $window['bars_from'] . '__' . $window['end']; $ctx = $contexts[$group] ??= Replay::context($root, $protocol['input_paths'], $window, $profile);
                $spec = Study::cases()[$id]['spec']; $maps = Recipes::maps($spec, $ctx['all'], $ctx['breadth'], $ctx['vvix']);
                $books = Recipes::books($spec, $profile, $maps['scale'], $ctx['features'], $ctx['nominal'], $cost);
                $checks = $sessions = $quantities = $gapPreemptions = 0;
                $check = static function (bool $ok, string $why) use (&$checks): void { ++$checks; if (!$ok) { throw new RuntimeException($why); } };
                if ($cost === 30) { $check($books === CandidateDefinition::books($profile, $maps['scale'], $ctx['features'], $ctx['nominal']), 'Only VVIX confirmation may differ from deployed book definition.'); }
                foreach ($books as &$book) { $book['config']['universe'] = array_values(array_intersect($book['config']['universe'], array_keys($ctx['all']))); }
                unset($book); $engine = new Ensemble($books); $books = $engine->config();
                $controller = new Circuit(CandidateDefinition::CIRCUIT, $maps['confirmation']);
                $result = $engine->runControlled($ctx['bars'], $window['start'], $window['end'], $controller, $protocol['initial_equity']);
                $check($engine->metrics($result) === $source['metrics'], 'Frozen metrics differ: ' . $name);
                $curve = array_map(static fn ($row): array => array_intersect_key($row, array_flip(['date', 'equity', 'start_equity', 'equity_low', 'equity_high', 'period_start_date', 'turnover'])), $result['curve']);
                $check(Hash::hash($curve) === Hash::hash($read($root . '/' . $source['curve_path'])), 'Frozen equity curve differs.');
                $history = $controller->report()['history']; $lastStates = $stops = [];
                foreach ($books as $sleeve => $book) {
                    $single = new Single($book['config']); $signals = $single->paperSignalContexts($ctx['bars'], $window['end']); $state = null;
                    $rows = $result['sleeve_curves'][$sleeve];
                    foreach ($rows as $i => $row) {
                        $date = $row['date']; $weights = $row['holding'] === null ? [] : [$row['holding'] => (float) $row['gross_close']];
                        $observation = ['date' => $date, 'equity' => (float) $row['equity'], 'weights' => $weights,
                            'execution_complete' => (bool) $row['rebalance'], 'stop_filled' => isset($row['standing_stop_event'])];
                        $signal = $signals($date, $row['holding']); $feedback = array_intersect_key($history[$date], array_flip(['force_cash', 'scale']));
                        $state = CandidateSleeveState::advance($book['config'], $state, $observation, $signal, $feedback);
                        $check($state['risk_signal'] === $row['risk_signal'], 'Risk signal mismatch: ' . $name . '/' . $sleeve . '/' . $date);
                        $check($state['cooldown_left'] === $row['circuit_cooldown_left'], 'Cooldown mismatch.');
                        $check(CandidateSleeveState::advance($book['config'], $state, $observation, $signal, $feedback) === $state, 'Duplicate close mutated state.');
                        $next = $rows[$i + 1] ?? null;
                        if ($next !== null && $state['target']['rebalance_due_next_session']) {
                            if (($next['standing_stop_event']['kind'] ?? '') === 'gap_open') { ++$gapPreemptions; }
                            else {
                                $target = $state['target']; $prices = [];
                                foreach ($ctx['nominal'] as $symbol => $series) { if (isset($series[$date])) { $prices[$symbol] = $series[$date]['close']; } }
                                $q = WholeShareSizing::target((float) $row['equity'], array_map(static fn ($w): float => $w * $row['equity'], $weights),
                                    $target['symbol'], (float) $target['gross'], $prices, $cost);
                                $check($q === $next['fixed_quantity_target'], 'Prior-close whole shares differ: ' . $name . '/' . $sleeve . '/' . $date); ++$quantities;
                            }
                        }
                        if (isset($row['standing_stop_event'])) { $stops[] = ['sleeve' => $sleeve] + $row['standing_stop_event']; }
                        $state = json_decode(json_encode($state, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR); ++$sessions;
                    }
                    foreach ($result['next_targets'][$sleeve] as $field => $expected) {
                        if (array_key_exists($field, $state['target'])) { $actual = $state['target'][$field]; $check(is_numeric($expected) ? abs($actual - $expected) < 1e-10 : $actual === $expected, 'Final target differs.'); }
                    }
                    $prefixDate = $rows[intdiv(count($rows), 2)]['date'];
                    $prefixConfig = CandidateParityInputs::prefixConfig($book['config'], $ctx['bars'], $prefixDate);
                    $prefix = (new Single($prefixConfig))->paperSignalContexts($ctx['bars'], $prefixDate);
                    $futureSymbols = array_diff($book['config']['universe'], $prefixConfig['universe']);
                    foreach ([null, 'MSFT', 'NVDA'] as $held) {
                        $check(CandidateParityInputs::comparableContext($signals($prefixDate, $held), $futureSymbols)
                            === CandidateParityInputs::comparableContext($prefix($prefixDate, $held), $futureSymbols), 'Future bars changed close signal.');
                    }
                    $lastStates[$sleeve] = Hash::hash($state);
                }
                $check($sessions > 6000 && $quantities > 500 && count($books) === 12, 'Insufficient parity coverage.');
                $proof = ['protocol_sha256' => $sha, 'case' => $name, 'passed' => true, 'assertions' => $checks, 'sleeve_sessions' => $sessions,
                    'quantity_decisions' => $quantities, 'gap_preempted_decisions' => $gapPreemptions, 'source_path' => $sourcePath,
                    'source_sha256' => hash_file('sha256', $root . '/' . $sourcePath), 'source_curve_sha256' => $source['curve_sha256'],
                    'last_state_sha256' => $lastStates, 'stop_events' => $stops, 'stop_price_basis' => 'split-adjusted historical units',
                    'orders_submitted' => 0, 'database_modified' => false, 'metrics' => $source['metrics']];
                Writer::write($out, $proof); unset($engine, $result, $curve, $controller, $books, $ctx, $signals, $prefix, $single, $rows, $stops); gc_collect_cycles();
            }
            $summary['cases'][$name] = array_intersect_key($proof, array_flip(['passed', 'assertions', 'sleeve_sessions', 'quantity_decisions', 'gap_preempted_decisions']));
            $summary['cases'][$name]['proof_sha256'] = hash_file('sha256', $out);
            $summary['complete'] = count($summary['cases']) === 16; $summary['updated_at'] = gmdate(DATE_ATOM);
            Writer::write($dir . '/summary.json', $summary); echo $name, ': ', $proof['assertions'], " close/quantity checks PASS\n";
        }
    }
}
if (CandidateRelease::hash($root) !== $protocol['runtime_hash']) { throw new RuntimeException('Active runtime changed during parity.'); }
$summary['runtime_unchanged'] = true; $summary['protocol'] = $protocol;
$summary['total_assertions'] = array_sum(array_column($summary['cases'], 'assertions'));
$summary['total_quantity_decisions'] = array_sum(array_column($summary['cases'], 'quantity_decisions'));
Writer::write($dir . '/summary.json', $summary); Writer::write($root . '/docs/HYBRID_V4_VVIX_PARITY_2026-09-15.json', $summary);
echo json_encode(['complete' => true, 'cases' => 16, 'assertions' => $summary['total_assertions'], 'quantity_decisions' => $summary['total_quantity_decisions'], 'runtime_unchanged' => true]), "\n";
