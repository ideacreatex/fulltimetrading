<?php
declare(strict_types=1);

use FulltimeTrading\Paper\CandidateRelease as Release;

require dirname(__DIR__) . '/bootstrap.php';
$checks = ['tests_passed', 'lint_passed', 'diff_clean', 'frozen_research_unchanged', 'replay_inputs_verified',
    'close_parity_verified', 'minute_audit_verified', 'comparative_benefit_verified', 'snapshot_contract_verified',
    'capital_sensitivity_verified', 'full_command_contract_verified', 'fault_matrix_verified'];
$n = 0; $check = static function (bool $ok, string $why) use (&$n): void { ++$n; if (!$ok) { throw new RuntimeException($why); } };
$proof = array_fill_keys($checks, true) + ['test_count' => 49, 'manual_orders_submitted' => 0, 'operational_database_modified' => false, 'failures' => []];
$good = Release::assess($proof);
$check($good['paper_admission'] && !$good['live_approved'] && !$good['strict_validation_selected'] && $good['fresh_current_signal_still_required'],
    'Experimental software admission never grants live or current-source freshness.');
foreach ($checks as $field) {
    $check(!Release::assess(array_replace($proof, [$field => false]))['paper_admission'], 'No individual release check may be bypassed: ' . $field);
    $check(!Release::assess(array_replace($proof, [$field => 'true']))['paper_admission'], 'Malformed proof must fail closed: ' . $field);
}
foreach ([['test_count' => 48], ['manual_orders_submitted' => 1], ['operational_database_modified' => true], ['failures' => ['exception']]] as $bad) {
    $check(!Release::assess(array_replace($proof, $bad))['paper_admission'], 'Incomplete or non-isolated proof cannot admit release.');
}
$record = ['run_id' => 'test', 'runtime_hash' => str_repeat('a', 64), 'commissioned_at' => gmdate(DATE_ATOM),
    'launch_agent' => 'com.fulltimetrading.hybrid-v4-paper', 'paper_only' => true, 'live_approved' => false];
$check(Release::commissioningMatches($record, 'test', str_repeat('a', 64)), 'Verified release commissioning survives an ordinary restart.');
foreach ([null, [], array_replace($record, ['run_id' => 'old']), array_replace($record, ['runtime_hash' => str_repeat('b', 64)]),
    array_replace($record, ['paper_only' => false]), array_replace($record, ['live_approved' => true]),
    array_replace($record, ['commissioned_at' => gmdate(DATE_ATOM, time() + 300)])] as $bad) {
    $check(!Release::commissioningMatches($bad, 'test', str_repeat('a', 64)), 'Uncommissioned or changed release cannot enter before installer success.');
}
echo "candidate_release_gate: {$n} assertions PASS\n";
