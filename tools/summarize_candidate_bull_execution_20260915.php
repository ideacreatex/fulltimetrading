#!/usr/bin/env php
<?php
declare(strict_types=1);
use FulltimeTrading\Paper\CandidateOrder;
use FulltimeTrading\Paper\CandidateRelease;
use FulltimeTrading\Paper\CandidateSignalArtifact;
use FulltimeTrading\Research\AlgorithmTrendResearch as Writer;
use FulltimeTrading\Research\CandidateInteractionAssessment as StopAudit;
use FulltimeTrading\Research\CandidatePathAttribution as Attribution;
use FulltimeTrading\Research\CandidateBullSnapshot as Snapshot;
require dirname(__DIR__) . '/bootstrap.php';
set_error_handler(static function (int $n, string $s): never { throw new RuntimeException($s); });
$root = dirname(__DIR__); $dir = $root . '/var/reports/candidate_bull_parity_20260915';
$read = static fn ($p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
$p = $read($dir . '/protocol.json'); $s = $read($dir . '/summary.json'); $sha = hash_file('sha256', $dir . '/protocol.json');
$parent = $read($root . '/docs/HYBRID_V4_BULL_RISK_2026-09-15.json');
$checks = 0; $check = static function (bool $ok, string $why) use (&$checks): void { ++$checks; if (!$ok) { throw new RuntimeException($why); } };
$check($s['complete'] === true && count($s['cases']) === 24 && $s['protocol_sha256'] === $sha && $s['orders_submitted'] === 0 && $s['database_modified'] === false, 'Incomplete/unsafe component proof.');
$check($parent['protocol_sha256'] === $p['parent_protocol_sha256'], 'Wrong frozen parent.');
foreach ($p['code_sha256'] as $path => $hash) { $check(hash_file('sha256', $root . '/' . $path) === $hash, 'Frozen code changed.'); }
foreach ($p['input_paths'] as $key => $path) { $check(hash_file('sha256', $root . '/' . $path) === $p['input_sha256'][$key], 'Frozen data changed.'); }
$totals = ['assertions' => 0, 'sleeve_sessions' => 0, 'quantity_decisions' => 0, 'gap_preempted_decisions' => 0, 'saved_stops' => 0];
$proofs = $sources = $receipts = [];
$names = array_keys($s['cases']); $names[] = $p['control'];
foreach ($names as $name) {
    $file = $dir . '/' . $name . '.json'; $a = $read($file); $record = $s['cases'][$name] ?? $s['control'];
    $sourcePath = $root . '/var/reports/candidate_bull_risk_20260915/' . $name . '.json'; $source = $read($sourcePath);
    $check($a['passed'] === true && $a['case'] === $name && $a['protocol_sha256'] === $sha
        && hash_file('sha256', $file) === $record['proof_sha256'], 'Component receipt mismatch.');
    $check(hash_file('sha256', $sourcePath) === $a['source_sha256'] && $parent['receipts'][$name]['case_sha256'] === $a['source_sha256'], 'Parent result changed.');
    $check($a['metrics'] === $source['metrics'] && $a['source_curve_sha256'] === $source['curve_sha256']
        && hash_file('sha256', $root . '/' . $source['curve_path']) === $source['curve_sha256'], 'Curve/metric mismatch.');
    $check($a['orders_submitted'] === 0 && $a['database_modified'] === false, 'Execution isolation failed.');
    $curve = $read($root . '/' . $source['curve_path']); $dates = array_fill_keys(array_column($curve, 'date'), true);
    $check(array_keys($a['circuit']['history']) === array_keys($dates), 'Circuit calendar mismatch.');
    if ($name !== $p['control']) {
        $check($a['sleeve_sessions'] === 12 * count($curve) && count($a['last_state_sha256']) === 12 && $a['quantity_decisions'] > 500, 'Incomplete component coverage.');
        foreach (['assertions', 'sleeve_sessions', 'quantity_decisions', 'gap_preempted_decisions'] as $field) {
            $check($record[$field] === $a[$field], 'Progress totals differ.'); $totals[$field] += $a[$field];
        }
        foreach ($a['stop_events'] as $event) { $check(StopAudit::validStopEvent($event, $dates), 'Invalid recorded stop.'); ++$totals['saved_stops']; }
    }
    $receipts[$name] = ['proof_sha256' => hash_file('sha256', $file), 'source_sha256' => $a['source_sha256'], 'source_curve_sha256' => $a['source_curve_sha256']];
    if (str_ends_with($name, '__continuous__60')) { $proofs[$name] = $a; $sources[$name] = $curve; }
}
$attribution = [];
foreach ($p['cases'] as $id) {
    $name = $id . '__continuous__60';
    $a = Attribution::compare($sources[$p['control']], $sources[$name], $proofs[$p['control']]['circuit'], $proofs[$name]['circuit']);
    $date = $a['first_circuit_divergence']['decided_at_close'];
    foreach (['base' => $p['control'], 'variant' => $name] as $label => $key) {
        $h = $proofs[$key]['circuit']['history'][$date];
        $a['first_divergence_drawdown'][$label] = $h['equity'] / $h['risk_epoch_peak'] - 1;
    }
    $check(abs(array_product(array_column($a['years'], 'relative_factor')) - 1 - $a['terminal_money_delta']) < 1e-9, 'Annual compounding mismatch.');
    $check(abs(array_product(array_column($a['prior_close_circuit_groups'], 'relative_factor')) - 1 - $a['terminal_money_delta']) < 1e-9, 'Circuit group compounding mismatch.');
    $attribution[$id] = $a;
}
$snapshotDir = $root . '/var/reports/candidate_bull_snapshot_20260915';
$sp = $read($snapshotDir . '/protocol.json'); $ss = $read($snapshotDir . '/summary.json');
$candidate = require $root . '/config/paper_candidate.php'; $profile = require $root . '/config/tactical_rotation.php';
$check($ss['complete'] === true && count($ss['receipts']) === 3 && $ss['protocol_sha256'] === hash_file('sha256', $snapshotDir . '/protocol.json')
    && $ss['orders_submitted'] === 0 && $ss['operational_database_modified'] === false && $ss['release_admitted'] === false, 'Snapshot binding not complete.');
foreach ($sp['code_sha256'] as $path => $hash) { $check(hash_file('sha256', $root . '/' . $path) === $hash, 'Snapshot builder changed.'); }
foreach (Snapshot::IDS as $id) {
    $file = $snapshotDir . '/' . $id . '.json'; $a = $read($file);
    $check($ss['receipts'][$id]['artifact_sha256'] === hash_file('sha256', $file) && $a['content_sha256'] === Snapshot::contentHash($a), 'Snapshot receipt changed.');
    foreach ($a['source_files'] as $key => $path) { $check(hash_file('sha256', $root . '/' . $path) === $a['source_sha256'][$key], 'Snapshot source changed.'); }
    // Re-sign with the ACTIVE checksum format so rejection proves identity, not merely differing JSON formatting.
    $a['content_sha256'] = hash('sha256', CandidateOrder::json(array_diff_key($a, ['content_sha256' => true, 'generated_at' => true])));
    try { CandidateSignalArtifact::validate($a, $candidate, $p['runtime_hash'], $profile); }
    catch (RuntimeException $e) { $check($e->getMessage() === 'Candidate signal identity failed.', 'Expected schema/identity rejection.'); continue; }
    $check(false, 'Research artifact entered the active signal contract.');
}
$tests = [];
foreach (['candidate_path_attribution', 'candidate_bull_snapshot', 'candidate_parity_inputs', 'candidate_bull_risk_study', 'candidate_bull_risk_assessment',
    'candidate_close_engine', 'candidate_circuit', 'whole_share_sizing', 'candidate_execution_plan', 'candidate_entry_batch',
    'candidate_paper_ledger', 'paper_forward_execution_audit', 'alpaca_paper_client_guard', 'paper_market_commentary'] as $test) {
    $output = []; $exit = 0; exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tests/' . $test . '.php') . ' 2>&1', $output, $exit);
    $tests[$test] = ['exit_code' => $exit, 'output' => implode("\n", $output), 'sha256' => hash_file('sha256', $root . '/tests/' . $test . '.php')];
    $check($exit === 0, 'Regression failed: ' . $test);
}
$lint = [];
foreach (['src/Research/CandidateComponentParity.php', 'src/Research/CandidateBullSnapshot.php', 'src/Research/CandidatePathAttribution.php',
    'tests/candidate_bull_snapshot.php', 'tests/candidate_path_attribution.php', 'tools/verify_candidate_bull_parity_20260915.php',
    'tools/verify_candidate_bull_snapshot_20260915.php', 'tools/summarize_candidate_bull_execution_20260915.php'] as $path) {
    $output = []; $exit = 0; exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($root . '/' . $path) . ' 2>&1', $output, $exit);
    $lint[$path] = ['exit_code' => $exit, 'sha256' => hash_file('sha256', $root . '/' . $path)]; $check($exit === 0, 'Lint failed.');
}
$check(CandidateRelease::verify($root, $candidate)['runtime_hash'] === $p['runtime_hash'], 'Active release changed.');
$final = ['verified_at' => gmdate(DATE_ATOM), 'protocol_sha256' => $sha, 'component_cases' => 24, 'additional_attribution_control_replays' => 1,
    'totals' => $totals, 'verification_assertions' => $checks, 'receipts' => $receipts, 'snapshot_binding' => $ss,
    'continuous_60bps_attribution' => $attribution, 'tests' => $tests, 'lint' => $lint,
    'active_runtime_hash' => $p['runtime_hash'], 'orders_submitted' => 0, 'operational_database_modified' => false,
    'new_release_admitted' => false, 'broker_execution_verified' => false, 'independent_holdout' => false];
Writer::write($dir . '/assessment.json', $final); Writer::write($root . '/docs/HYBRID_V4_BULL_EXECUTION_2026-09-15.json', $final);
$a = $attribution['bull_v110_ma50_boost105'];
$lines = ['# Bull5 execution and data binding, 2026-09-15', '',
    'Three declared SMA50 bull-risk +5% variants, with VVIX reentry thresholds 100/105/110. These are execution checks of existing hypotheses, not new return discoveries. The active paper release and pending orders were not changed.', '',
    '## Completed', '',
    '- 24/24 close/whole-share/prefix checks: four historical paths, 30/60 bps, twelve sleeves. Frozen source hashes, metrics and entire equity curves match the committed bull-risk study.',
    '- ' . $totals['assertions'] . ' replay assertions, ' . $totals['sleeve_sessions'] . ' sleeve-session observations, ' . $totals['quantity_decisions'] . ' quantity comparisons. Opening-gap stops preempted ' . $totals['gap_preempted_decisions'] . ' sizing comparisons; these are not counted as quantity matches.',
    '- ' . $totals['saved_stops'] . ' recorded historical stops passed receipt/price checks. These split-adjusted daily-model events are not native broker fill proofs.',
    '- Three isolated September 14 snapshot artifacts were independently rebuilt twice from Alpaca SIP raw/split and validated external indicator files. They bind exact recipe, profile, runtime reference, source files and full dated scale/confirmation maps. Self-checksummed tampering is rejected against trusted recomputation.',
    '- All three latest snapshots have bullish=false and equal deployed close contexts. Thus this date exercises the non-boosted branch only; positive bull behavior is covered by the historical component and causal-map tests, not by claiming the latest snapshot exercised it.',
    '- Snapshot schema is deliberately incompatible with the active signal contract, including after re-signing in its checksum format. No order/entry permission is granted. Peak construction memory: ' . round($ss['peak_memory_bytes'] / 1048576, 2) . ' MiB, under the 512 MiB test limit.', '',
    '## Why The Large 60 Bps Gain Occurs', '',
    'The continuous 2021-01-04 to 2026-09-04 comparison starts at $27,567.66. VVIX110 bull5 ends at $' . number_format($a['variant_terminal'], 2) . ' versus $' . number_format($a['base_terminal'], 2) . ': +' . number_format(100 * $a['terminal_money_delta'], 2) . '% terminal money, not +34% annual alpha.', '',
    'At the August 17, 2021 close, the deployed control reaches -18.26% risk-epoch drawdown and schedules liquidation; bull5 is at -16.55% and does not trigger. The first differing restriction applies August 18. The control releases only after the May 27, 2022 close, following 197 cash-restricted sessions. Across the complete replay, prior-close force_cash applies on 217 control sessions versus 26 for VVIX110 bull5.', '',
    '| Year | Control return | Bull5 VVIX110 return | Relative capital factor |', '|---|---:|---:|---:|'];
foreach ($a['years'] as $year => $r) { $lines[] = sprintf('| %s | %.2f%% | %.2f%% | %.6f |', $year, 100 * $r['base_return'], 100 * $r['variant_return'], $r['relative_factor']); }
$lines = [...$lines, '', '2026 is partial through September 4. Annual factors multiply to ' . number_format($a['reconciled_relative_factor'], 8) . '. The 2021 advantage is partly surrendered in 2022. Grouped daily log-return differences also reconcile exactly; they use the PREVIOUS close restriction to avoid same-day attribution lookahead.', '',
    'This establishes strong path/circuit sensitivity, not an independent causal split between sizing, rounding, holding selection, costs and confirmation. Those remain coupled. Whole-share rounding was reproduced, not removed in a counterfactual. Do not extrapolate the 34.06% as stable expected improvement. The separately initialized 2023 path was only +2.90% at 60 bps and +3.84% at 30 bps in the parent study.', '',
    '## Remaining Admission Work', '',
    'This is NOT a new release PASS and does not replace maximum-stop12-costband2-whole-v1. A future staged release still needs its full command/snapshot contract, persisted ledger and partial-fill/stop failure matrix, variant-specific minute execution evidence in a consistent price-adjustment basis, and capital sensitivity. No active strategy replacement while its orders/positions exist. The monthly gate and activation remain unchanged; no automatic live transition.', '',
    'All history is repeatedly/adaptively used, not an independent holdout. The selected stock basket is not a point-in-time universe. S5TW is external Investing.com data and VVIX is Cboe data, not Alpaca; tradable instrument bars are Alpaca SIP. The 2018 SVXY mandate change and imperfect auction/stop modeling remain limitations.', '',
    '## Verification', '',
    count($tests) . ' relevant regression programs passed, plus ' . $checks . ' receipt/hash/stop assertions. Snapshot unit test: 55 assertions; snapshot filesystem integration: 37 assertions; accounting unit test: 12 assertions.', '',
    '```sh', 'php -d memory_limit=8G tools/verify_candidate_bull_parity_20260915.php', 'php -d memory_limit=512M tools/verify_candidate_bull_snapshot_20260915.php',
    'php -d memory_limit=1G tools/summarize_candidate_bull_execution_20260915.php', '```', '',
    'Per-case proofs: var/reports/candidate_bull_parity_20260915. Isolated snapshots: var/reports/candidate_bull_snapshot_20260915. The attribution control was rerun once specifically to record its previously unsaved circuit history. No second parameter sweep was performed.'];
if (file_put_contents($root . '/docs/HYBRID_V4_BULL_EXECUTION_2026-09-15.md', implode("\n", $lines) . "\n") === false) { throw new RuntimeException('Cannot write execution report.'); }
echo json_encode(['cases' => 24, 'totals' => $totals, 'snapshot_recipes' => 3, 'test_programs' => count($tests),
    'verification_assertions' => $checks, 'new_release_admitted' => false, 'runtime_unchanged' => true], JSON_PRETTY_PRINT), "\n";
