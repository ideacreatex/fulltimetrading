#!/usr/bin/env php
<?php
declare(strict_types=1);

use FulltimeTrading\Paper\CandidateRelease;
use FulltimeTrading\Research\AlgorithmTrendResearch as Writer;
use FulltimeTrading\Research\CandidateInteractionAssessment as StopAudit;

require dirname(__DIR__) . '/bootstrap.php';
set_error_handler(static function (int $n, string $s): never { throw new RuntimeException($s); });
$root = dirname(__DIR__); $dir = $root . '/var/reports/candidate_vvix_parity_20260915_v3';
$read = static fn ($p): array => json_decode(file_get_contents($p), true, 512, JSON_THROW_ON_ERROR);
$p = $read($dir . '/protocol.json'); $s = $read($dir . '/summary.json'); $sha = hash_file('sha256', $dir . '/protocol.json');
$checks = 0; $check = static function (bool $ok, string $why) use (&$checks): void { ++$checks; if (!$ok) { throw new RuntimeException($why); } };
$check($s['complete'] === true && count($s['cases']) === 16 && $s['protocol_sha256'] === $sha && $s['orders_submitted'] === 0 && $s['database_modified'] === false, 'Incomplete or unsafe verification.');
foreach ($p['code_sha256'] as $path => $hash) { $check(hash_file('sha256', $root . '/' . $path) === $hash, 'Frozen source changed.'); }
foreach ($p['input_paths'] as $key => $path) { $check(hash_file('sha256', $root . '/' . $path) === $p['input_sha256'][$key], 'Frozen input changed.'); }
$previous = $read($root . '/docs/HYBRID_V4_INTERACTIONS_2026-09-15.json');
$check($previous['protocol_sha256'] === $p['parent_protocol_sha256'], 'Wrong parent receipt set.');
$stops = $quantities = $sessions = $assertions = $gaps = 0;
foreach ($p['cases'] as $id) {
    foreach ($p['conditions'] as $scenario => $_) {
        foreach ($p['costs_bps'] as $cost) {
            $name = $id . '__' . $scenario . '__' . $cost; $file = $dir . '/' . $name . '.json'; $proof = $read($file);
            $check($proof['passed'] === true && $proof['case'] === $name && $proof['protocol_sha256'] === $sha
                && $s['cases'][$name]['proof_sha256'] === hash_file('sha256', $file), 'Parity receipt mismatch.');
            $check($proof['source_path'] === 'var/reports/candidate_interactions_20260915/' . $name . '.json'
                && hash_file('sha256', $root . '/' . $proof['source_path']) === $proof['source_sha256']
                && $previous['receipts'][$name]['case_sha256'] === $proof['source_sha256'], 'Parent result changed since committed assessment.');
            $source = $read($root . '/' . $proof['source_path']);
            $check($proof['source_curve_sha256'] === $source['curve_sha256'] && hash_file('sha256', $root . '/' . $source['curve_path']) === $source['curve_sha256'], 'Original curve changed.');
            $check($proof['metrics'] === $source['metrics'] && $proof['orders_submitted'] === 0 && $proof['database_modified'] === false, 'Parity metrics or isolation mismatch.');
            $curve = $read($root . '/' . $source['curve_path']); $dates = array_fill_keys(array_column($curve, 'date'), true);
            $check($proof['sleeve_sessions'] === 12 * count($curve) && count($proof['last_state_sha256']) === 12 && $proof['quantity_decisions'] > 500, 'Incomplete sleeve coverage.');
            foreach ($proof['stop_events'] as $event) { $check(StopAudit::validStopEvent($event, $dates), 'Invalid preserved stop.'); ++$stops; }
            $quantities += $proof['quantity_decisions']; $sessions += $proof['sleeve_sessions']; $assertions += $proof['assertions']; $gaps += $proof['gap_preempted_decisions'];
        }
    }
}
$check($assertions === $s['total_assertions'] && $quantities === $s['total_quantity_decisions'], 'Parity totals differ.');
$tests = [];
foreach (['candidate_parity_inputs', 'candidate_close_engine', 'candidate_circuit', 'whole_share_sizing', 'candidate_execution_plan',
    'candidate_entry_batch', 'candidate_paper_ledger', 'paper_forward_execution_audit', 'alpaca_paper_client_guard'] as $test) {
    $output = []; $exit = 0; exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tests/' . $test . '.php') . ' 2>&1', $output, $exit);
    $tests[$test] = ['exit_code' => $exit, 'output' => implode("\n", $output), 'sha256' => hash_file('sha256', $root . '/tests/' . $test . '.php')];
    $check($exit === 0, 'Relevant regression failed: ' . $test);
}
$lint = [];
foreach (['src/Research/CandidateParityInputs.php', 'tests/candidate_parity_inputs.php', 'tools/verify_candidate_vvix_parity_20260915.php', 'tools/summarize_candidate_vvix_parity_20260915.php'] as $path) {
    $output = []; $exit = 0; exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($root . '/' . $path) . ' 2>&1', $output, $exit);
    $lint[$path] = ['exit_code' => $exit, 'sha256' => hash_file('sha256', $root . '/' . $path)]; $check($exit === 0, 'Lint failed.');
}
$check(CandidateRelease::verify($root, require $root . '/config/paper_candidate.php')['runtime_hash'] === $p['runtime_hash'], 'Active release changed.');
$final = $s + ['verified_at' => gmdate(DATE_ATOM), 'verification_assertions' => $checks, 'total_sleeve_sessions' => $sessions,
    'total_gap_preempted_decisions' => $gaps, 'saved_stop_events' => $stops, 'tests' => $tests, 'lint' => $lint,
    'snapshot_builder_parity_verified' => false, 'new_release_admitted' => false, 'broker_execution_verified' => false];
Writer::write($dir . '/assessment.json', $final); Writer::write($root . '/docs/HYBRID_V4_VVIX_PARITY_2026-09-15.json', $final);
$lines = ['# Isolated VVIX close/quantity parity, 2026-09-15', '',
    'VVIX reentry thresholds 105 and 110: 16/16 isolated historical execution checks passed across four paths and 30/60 bps. No strategy switch, broker orders, operational database mutation or activation reset.', '',
    '## Verified', '',
    '- Every case exactly matches its previously frozen metrics and full equity curve. Parent receipts also match the committed interactions assessment.',
    '- Production CandidateSleeveState and WholeShareSizing match historical risk signals, cooldowns, duplicate-close behavior, final targets and next-session whole-share quantities across all twelve sleeves.',
    '- ' . $sessions . ' sleeve-session observations, ' . $quantities . ' whole-share decisions and ' . $assertions . ' replay assertions. Opening-gap stops preempted ' . $gaps . ' sizing comparisons; these are recorded, not presented as successful quantity comparisons.',
    '- Prefix comparisons preserve exact decisions, prices and available volatility. Pre-IPO symbols are not assigned synthetic observations.', '',
    '## Test-Harness Corrections', '',
    'Two initial incomplete attempts are preserved by protocol hashes. The first requested PLTR data before its IPO. The second detected missing versus null volatility metadata for PLTR/CRWD, not different trading decisions. The final prefix adapter removes only metadata proven unavailable at that date and rejects any pre-IPO price, target or nonnull volatility. Regression tests cover those rejection paths. Full replay universes and trading logic were not changed.', '',
    '## Not Yet Proven', '',
    '- This is component parity, NOT a complete new release admission. The active snapshot builder still binds the deployed indicator definition; these variants were supplied only to an isolated replay.',
    '- The actual broker gateway, persisted candidate ledger, native protective-stop lifecycle, partial fills and cash reservation were not exercised by these historical checks. Related unit tests passed, but that does not constitute variant-specific forward evidence.',
    '- Historical stop records are in split-adjusted units. A further minute audit must use the same adjustment basis or explicit dated raw/split conversion, never compare them directly with nominal broker prices.',
    '- Existing monthly observation continues from 2026-09-15T01:51:22Z. A historical PASS does not bypass runtime identity, account, execution, observation or live gates.', '',
    '## Evidence', '',
    count($tests) . ' relevant test programs passed; ' . $checks . ' receipt/hash/stop checks; ' . $stops . ' historical stop records preserved. Runtime identity unchanged. Complete per-case proofs remain under var/reports/candidate_vvix_parity_20260915_v3.', '',
    '```sh', 'php tests/candidate_parity_inputs.php', 'php -d memory_limit=8G tools/verify_candidate_vvix_parity_20260915.php',
    'php -d memory_limit=1G tools/summarize_candidate_vvix_parity_20260915.php', '```'];
if (file_put_contents($root . '/docs/HYBRID_V4_VVIX_PARITY_2026-09-15.md', implode("\n", $lines) . "\n") === false) { throw new RuntimeException('Cannot persist parity report.'); }
echo json_encode(['cases' => 16, 'replay_assertions' => $assertions, 'quantity_decisions' => $quantities, 'regression_programs' => count($tests),
    'verification_assertions' => $checks, 'new_release_admitted' => false, 'runtime_unchanged' => true]), "\n";
