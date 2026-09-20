<?php
declare(strict_types=1);

use FulltimeTrading\Paper\CandidateDefinition as Definition;
use FulltimeTrading\Paper\CandidateLedger as Ledger;
use FulltimeTrading\Paper\CandidateOrder as Order;
use FulltimeTrading\Paper\CandidateRelease as Release;
use FulltimeTrading\Paper\CandidateSignalArtifact as Artifact;

require dirname(__DIR__) . '/bootstrap.php';
$root = dirname(__DIR__); $checks = 0; $cycles = 0;
$check = static function (bool $ok, string $why) use (&$checks): void {
    ++$checks;
    if (!$ok) { throw new RuntimeException($why); }
};
$baseline = null;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--baseline-scheduler=')) { $baseline = substr($arg, strlen('--baseline-scheduler=')); }
    else { throw new InvalidArgumentException('Unknown test option'); }
}
if ($baseline !== null && !is_file($baseline)) { throw new RuntimeException('Baseline scheduler missing'); }
$sourceHash = Release::hash($root);
$sourceHashes = Release::files($root);

foreach (['full_fill', 'partial_fill', 'partial_fill_then_expiry', 'pending_outbox', 'unfilled_deadline'] as $scenario) {
    $fixture = sys_get_temp_dir() . '/weekly-cli-' . bin2hex(random_bytes(8));
    mkdir($fixture, 0700);
    $ledger = null;
    try {
        foreach ($sourceHashes as $file => $_) {
            if (!is_dir(dirname($fixture . '/' . $file))) { mkdir(dirname($fixture . '/' . $file), 0700, true); }
            copy($root . '/' . $file, $fixture . '/' . $file);
        }
        if ($baseline !== null) { copy($baseline, $fixture . '/src/Trading/TacticalPortfolioNotificationSchedule.php'); }
        // Clock imports are the only executor instrumentation. Queue/gate/action code is untouched.
        $path = $fixture . '/tools/candidate_paper_cycle.php';
        $original = file_get_contents($path);
        $anchor = 'use FulltimeTrading\\Data\\HttpClient;';
        $clockImports = "use WeeklyCliFixture\\FrozenDateTimeImmutable as DateTimeImmutable;\nuse function WeeklyCliFixture\\gmdate;\n";
        $check(substr_count($original, $anchor) === 1, 'Executor clock hook must have one exact anchor');
        $instrumented = str_replace($anchor, $clockImports . $anchor, $original);
        $check(str_replace($clockImports, '', $instrumented) === $original, 'No executor logic replaced');
        file_put_contents($path, $instrumented);
        copy(__DIR__ . '/fixtures/candidate_weekly_cli_runtime.php', $fixture . '/fixture_runtime.php');
        file_put_contents($fixture . '/fixture_entry.php', "<?php\nrequire __DIR__ . '/fixture_runtime.php';\nrequire __DIR__ . '/bin/trade';\n");
        $candidate = require $fixture . '/config/paper_candidate.php';
        $candidate['run_id'] = 'weekly-cli-fixture';
        $candidate['predecessor_run_id'] = 'weekly-cli-fixture-predecessor';
        file_put_contents($fixture . '/config/paper_candidate.php', "<?php\nreturn " . var_export($candidate, true) . ";\n");
        $run = $candidate['run_id']; $date = '2026-09-17'; $next = '2026-09-18';
        $books = Definition::books(require $fixture . '/config/tactical_rotation.php', [], [], []);
        $answers = $prices = [];
        foreach ($books as $name => $book) {
            foreach (['', ...$book['config']['universe']] as $incumbent) {
                $answers[$name][$incumbent] = ['date' => $date, 'previous_session' => '2026-09-16',
                    'reentry_conditions_met' => false, 'desired' => ['MSFT' => min(1.16, $book['config']['max_gross'])],
                    'closes' => ['MSFT' => 497.75], 'previous_closes' => ['MSFT' => 492.], 'volatility' => []];
            }
            foreach ($book['config']['universe'] as $symbol) { $prices[$symbol] = 497.75; }
        }
        $provenance = ['date' => $date];
        foreach (['raw', 'split', 's5tw', 'vvix'] as $id) {
            $directory = in_array($id, ['raw', 'split'], true) ? 'candidate_execution_data_' : 'candidate_external_data_';
            $path = $fixture . '/var/reports/' . $directory . '20260917/' . $id . '.json';
            Artifact::write($path, ['isolated_synthetic_fixture' => true]);
            $provenance[$id . '_sha256'] = hash_file('sha256', $path);
        }
        $hash = Release::hash($fixture);
        Artifact::write($fixture . '/signal.json', Artifact::build(['date' => $date, 'books' => $books,
            'context_answers' => $answers, 'nominal_closes' => $prices, 'confirmation' => false, 'provenance' => $provenance],
            $candidate, $hash, $next));
        // Synthetic credentials/admission exist ONLY inside this disposable, network-disabled fixture.
        $proof = array_fill_keys(['tests_passed', 'lint_passed', 'diff_clean', 'frozen_research_unchanged', 'replay_inputs_verified',
            'close_parity_verified', 'minute_audit_verified', 'comparative_benefit_verified', 'snapshot_contract_verified',
            'capital_sensitivity_verified', 'full_command_contract_verified', 'fault_matrix_verified'], true)
            + ['test_count' => 100, 'manual_orders_submitted' => 0, 'operational_database_modified' => false,
                'runtime_hash' => $hash, 'failures' => [], 'capital_reviewed' => 27567.66, 'isolated_synthetic_fixture' => true];
        Artifact::write($fixture . '/proof.json', $proof);
        Artifact::write($fixture . '/' . $candidate['release_manifest'], ['run_id' => $run, 'profile' => Definition::PROFILE,
            'execution_contract' => Order::CONTRACT, 'paper_only' => true, 'live_approved' => false, 'paper_admission' => true,
            'runtime_hash' => $hash, 'files' => Release::files($fixture), 'proof_path' => 'proof.json',
            'proof_sha256' => hash_file('sha256', $fixture . '/proof.json'), 'capital_reviewed' => 27567.66]);
        Artifact::write($fixture . '/var/run/candidate_commission.json', ['run_id' => $run, 'runtime_hash' => $hash,
            'commissioned_at' => '2026-09-15T13:41:21Z', 'launch_agent' => 'com.fulltimetrading.hybrid-v4-paper',
            'paper_only' => true, 'live_approved' => false]);
        $ledger = new Ledger($fixture . '/var/db/trading.sqlite');
        $ledger->provision(['run_id' => $run, 'profile' => Definition::PROFILE,
            'strategy_hash' => hash_file('sha256', $fixture . '/config/paper_candidate.php'), 'runtime_hash' => $hash,
            'data_contract' => ['execution_contract' => Order::CONTRACT, 'paper_only' => true, 'data' => $candidate['data']]],
            array_map(static fn ($book): float => $book['allocation'], $books));
        $ledger->activate($run, 27567.66, ['positions' => [], 'open_orders' => [], 'adoption' => 'flat_account_only', 'stable_for_seconds' => 120]);
        $ledger->views->saveSnapshot($run, ['captured_at' => '2026-09-17T20:22:00Z', 'equity' => 27567.66,
            'cash' => 27567.66, 'buying_power' => 55135.32, 'positions' => [], 'open_orders' => [],
            'reconciliation_status' => 'reconciled', 'payload' => ['isolated_synthetic_fixture' => true]]);
        $calendar = array_map(static fn ($day): array => ['date' => $day, 'open' => '09:30', 'close' => '16:00'],
            ['2026-09-14', '2026-09-15', '2026-09-16', '2026-09-17', '2026-09-18', '2026-09-21', '2026-09-22']);
        Artifact::write($fixture . '/broker.json', ['now' => '2026-09-18T09:20:00-04:00', 'capital' => 27567.66,
            'calendar' => $calendar, 'clock' => [], 'orders' => [], 'trace' => []]);
        $read = static fn (): array => json_decode(file_get_contents($fixture . '/broker.json'), true, 512, JSON_THROW_ON_ERROR);
        $invoke = static function (string $time) use ($fixture, $read, $check, &$cycles, $scenario): array {
            $state = $read(); $state['now'] = '2026-09-18T' . $time . '-04:00';
            $state['clock'] = ['timestamp' => $state['now'], 'is_open' => $time >= '09:30:00' && $time < '16:00:00',
                'next_open' => $time < '09:30:00' ? '2026-09-18T09:30:00-04:00' : '2026-09-21T09:30:00-04:00'];
            Artifact::write($fixture . '/broker.json', $state);
            $env = ['PATH' => dirname(PHP_BINARY), 'HOME' => $fixture, 'TMPDIR' => $fixture,
                'APCA_PAPER_BASE_URL' => 'https://paper-api.alpaca.markets/v2', 'APCA_PAPER_ACCOUNT_ID' => 'weekly-cli-fixture',
                'APCA_PAPER_EXPECTED_MULTIPLIER' => '2', 'APCA_PAPER_EXPECTED_SHORTING_ENABLED' => 'true', 'FTT_ORDERS_ENABLED' => 'true'];
            $p = proc_open([PHP_BINARY, '-d', 'allow_url_fopen=0',
                '-d', 'disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client',
                $fixture . '/fixture_entry.php', 'tactical-paper-executor', '--candidate=true', '--submit=true', '--telegram=true',
                '--artifact=' . $fixture . '/signal.json', '--output=' . $fixture . '/cycle.json'],
                [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $fixture, $env);
            if (!is_resource($p)) { throw new RuntimeException('Cannot start isolated CLI'); }
            $output = stream_get_contents($pipes[1]); fclose($pipes[1]); $code = proc_close($p); ++$cycles;
            $check(is_file($fixture . '/cycle.json'), 'CLI must emit its report: ' . $output);
            $report = json_decode(file_get_contents($fixture . '/cycle.json'), true, 512, JSON_THROW_ON_ERROR);
            $paused = $report['run_status'] === 'paused';
            $check($code === ($paused ? 2 : 0), "$scenario/$time CLI exit $code: " . $output);
            $check($report['errors'] === ($paused ? ['candidate_run_paused'] : []), "$scenario/$time unexpected errors: " . $output);
            $check($report['dry_run'] === false && $report['paper_only'] === true && $report['live_enabled'] === false, 'Real submit branch remains paper-only');
            $check($report['installation_commissioned'] === true && $report['reconciliation']['ok'] === true, 'Real identity/account/reconciliation gates pass in fixture');
            $check($report['signal']['fresh'] === true, 'Actual completed-session resolution remains fresh');
            return $report;
        };
        $traceCount = static fn (string $kind): int => count(array_filter($read()['trace'], static fn ($e): bool => $e['kind'] === $kind));
        $r = $invoke('09:20:00');
        $check($r['submitted'] === [] && $traceCount('submit') === 0, 'Initial pending close/activation reports prevent premature entry');
        $check($ledger->views->pendingNotifications() === [], 'Real outbox loop delivers via fake Telegram');
        for ($i = 1; $i <= 4; ++$i) {
            $r = $invoke('09:20:0' . $i);
            $check($r['action']['action'] === 'submit' && count($r['submitted']) === 1, 'Each restarted CLI submits exactly one frozen OPG');
        }
        $check($traceCount('submit') === 4 && count($ledger->active($run)) === 4, 'Four accepted buys, no duplicate POST');
        $check($ledger->views->positions($run) === [], 'Accepted is not filled');
        foreach (['09:27:00', '09:29:59', '09:30:05', '09:30:06', '09:30:07'] as $time) {
            $r = $invoke($time);
            $check($r['action']['action'] === 'wait', "$scenario/$time: accepted OPG must survive the real CLI notification/gate path; observed "
                . json_encode($r['action']) . '; weekly=' . ($r['notification_schedule']['weekly_status_session'] ?? 'none'));
            $check(!isset($r['notification_schedule']['weekly_status_key']), 'No invented Thursday weekly notification');
            $check($ledger->checkpoint($run, 'entry_batch')['payload']['aborted'] === null, 'No sticky abort across process restarts');
            $check($traceCount('submit') === 4 && $traceCount('cancel') === 0, 'Cutoff/open observations make no new POST or DELETE');
        }
        $buyIds = array_keys($read()['orders']);
        if ($scenario === 'full_fill') {
            $state = $read(); $total = 0;
            foreach ($buyIds as $id) {
                $state['orders'][$id] = array_replace($state['orders'][$id], ['status' => 'filled',
                    'filled_qty' => $state['orders'][$id]['qty'], 'filled_avg_price' => '492']);
                $total += (int) $state['orders'][$id]['qty'];
            }
            Artifact::write($fixture . '/broker.json', $state);
            for ($i = 10; $i <= 16; ++$i) { $invoke('09:30:' . $i); }
            $active = $ledger->active($run);
            $check(count($active) === 4 && array_sum(array_column($active, 'requested_qty')) === (float) $total, 'All four filled sleeves are covered exactly');
            foreach ($active as $stop) {
                $check($stop['leg'] === 'protective_stop' && $stop['status'] === 'new'
                    && $stop['payload']['body']['stop_price'] === '432.96', 'Every fully filled sleeve has acknowledged cost-based native protection');
            }
            $check($ledger->views->expectedBrokerPositions($run) === ['MSFT' => (float) $total], 'Broker full-fill quantity matches twelve-book ownership');
            $check($traceCount('submit') === 8 && $traceCount('cancel') === 0, 'Full fills need four stops, no needless cancellations or duplicate buys');
            $check($ledger->run($run)['status'] === 'active', 'Clean full execution preserves active status');
        } elseif (str_starts_with($scenario, 'partial_fill')) {
            $state = $read();
            $state['orders'][$buyIds[0]] = array_replace($state['orders'][$buyIds[0]],
                ['status' => 'partially_filled', 'filled_qty' => '2', 'filled_avg_price' => '492']);
            Artifact::write($fixture . '/broker.json', $state);
            $r = $invoke('09:30:10');
            $check($r['action']['action'] === 'cancel', 'Actual reconciler observes partial fill and starts bounded cleanup');
            if ($scenario === 'partial_fill_then_expiry') {
                $state = $read();
                foreach ($buyIds as $id) {
                    if ($state['orders'][$id]['status'] === 'new') { $state['orders'][$id]['status'] = 'expired'; break; }
                }
                Artifact::write($fixture . '/broker.json', $state);
            }
            for ($i = 11; $i <= 17; ++$i) { $r = $invoke('09:30:' . $i); }
            $active = $ledger->active($run);
            $check(count($active) === 1 && $active[0]['leg'] === 'protective_stop' && $active[0]['status'] === 'new', 'Only acknowledged native stop remains');
            $check((int) $active[0]['requested_qty'] === 2 && $active[0]['payload']['body']['stop_price'] === '432.96', 'Stop covers exactly confirmed fill at cost-based 12 percent floor');
            $check($ledger->views->expectedBrokerPositions($run) === ['MSFT' => 2.0], 'Two shares owned, no invented remainder');
            $expectedStatus = $scenario === 'partial_fill_then_expiry' ? 'paused' : 'active';
            $check($ledger->run($run)['status'] === $expectedStatus, 'Expiry pauses; expected partial cancellation does not');
            if ($expectedStatus === 'paused') {
                $check(str_starts_with($ledger->run($run)['last_error_code'], 'candidate_terminal_incomplete:'), 'Terminal expiry retains its saved cause');
            }
            $check($traceCount('submit') === 5, 'Only four initial buys and one confirmed-share stop were submitted');
        } else {
            if ($scenario === 'pending_outbox') {
                $ledger->views->queueNotification('fixture-genuine-pending', 'Actual pending fixture notification', ['run_id' => $run]);
                $time = '09:30:10';
            } else { $time = '09:32:00'; }
            $r = $invoke($time);
            $check($r['action']['action'] === 'cancel', 'Real pending outbox or bounded deadline still cancels');
            $check($ledger->views->pendingNotifications() === [], 'Delivery cannot undo the persisted abort');
            if ($scenario === 'pending_outbox') {
                $r = $invoke('09:30:11');
                $check($r['action']['action'] === 'cancel' && $traceCount('cancel') === 2,
                    'Delivered notification cannot undo sticky abort BEFORE the 09:32 deadline');
                $remainingTimes = ['09:30:12', '09:30:13', '09:30:14', '09:30:15'];
            } else { $remainingTimes = ['09:32:01', '09:32:02', '09:32:03', '09:32:04', '09:32:05']; }
            foreach ($remainingTimes as $later) { $invoke($later); }
            $check($ledger->active($run) === [] && $ledger->views->positions($run) === [], 'Zero-fill cleanup leaves no pretend ownership/stop');
            $check($traceCount('submit') === 4 && $traceCount('cancel') === 4, 'No late buy, retry or unnecessary stop');
        }
        $check(($ledger->checkpoint($run, 'entry_batch')['payload'] ?? []) === [], 'Completed cleanup clears only the batch');
        $configured = require $fixture . '/config/config.php';
        $check(realpath($configured['database_path']) === realpath($fixture . '/var/db/trading.sqlite'), 'Default database resolves only to disposable fixture');
        $check(!is_file($fixture . '/.env'), 'Operational credentials were never copied');
        $check($ledger->views->pendingNotifications() === [], 'All fixture notifications have durable delivery receipts');
    } finally {
        unset($ledger);
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($fixture);
    }
}
$check(Release::hash($root) === $sourceHash && Release::files($root) === $sourceHashes, 'Source release unchanged by fixture execution');
echo "Weekly submitted CLI: $checks assertions PASS; $cycles restarted cycles; 5 scenarios; no network or operational database access\n";
