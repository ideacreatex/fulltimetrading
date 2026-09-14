#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Paper\CandidateDataSnapshot;
use FulltimeTrading\Paper\CandidateCloseEngine;
use FulltimeTrading\Paper\CandidateLedger;
use FulltimeTrading\Paper\CandidateOrder;
use FulltimeTrading\Research\AlgorithmTrendResearch;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__); $date = $argv[1] ?? '2026-09-11'; $next = $argv[2] ?? '2026-09-14';
$candidate = require $root . '/config/paper_candidate.php';
$inputs = CandidateDataSnapshot::load($root, $date, $candidate);
$path = tempnam(sys_get_temp_dir(), 'candidate-snapshot-');
try {
    $ledger = new CandidateLedger($path);
    $identity = ['run_id' => 'snapshot-offline', 'profile' => $candidate['profile'],
        'strategy_hash' => hash_file('sha256', $root . '/config/paper_candidate.php'), 'runtime_hash' => str_repeat('0', 64),
        'data_contract' => ['execution_contract' => CandidateOrder::CONTRACT, 'paper_only' => true]];
    $ledger->provision($identity, array_map(static fn ($b): float => $b['allocation'], $inputs['books']));
    $ledger->activate($identity['run_id'], 27567.66, ['positions' => [], 'open_orders' => [], 'adoption' => 'flat_account_only', 'stable_for_seconds' => 120]);
    $engine = new CandidateCloseEngine($ledger);
    $prepared = $engine->prepare($identity['run_id'], $date, $next, $inputs['books'], $inputs['contexts'],
        $inputs['nominal_closes'], $inputs['confirmation'], $inputs['provenance']);
    $plan = $engine->commit($prepared);
    $summary = ['scope' => 'Offline full-data preparation in a temporary database; not the active paper run.',
        'signal_date' => $date, 'scheduled_session' => $next, 'orders_submitted' => 0, 'operational_database_modified' => false,
        'peak_memory_bytes' => memory_get_peak_usage(true), 'provenance' => $inputs['provenance'], 'plans' => $plan['plans']];
    AlgorithmTrendResearch::write($root . '/var/reports/candidate_execution_20260915/snapshot_' . str_replace('-', '', $date) . '.json', $summary);
    echo json_encode(array_diff_key($summary, ['plans' => true, 'provenance' => true]), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), "\n";
} finally {
    unset($ledger, $engine);
    foreach ([$path, $path . '-wal', $path . '-shm'] as $file) { if (is_file($file)) { unlink($file); } }
}
