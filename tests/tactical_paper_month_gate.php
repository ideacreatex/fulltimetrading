<?php

declare(strict_types=1);

use FulltimeTrading\Storage\TacticalPaperRepository;

require dirname(__DIR__) . '/bootstrap.php';

$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/ftt-month-gate-' . bin2hex(random_bytes(6));
if (!mkdir($tmp, 0775, true) && !is_dir($tmp)) {
    throw new RuntimeException('Unable to create month-gate test directory.');
}
$db = $tmp . '/trading.sqlite';
$reportPath = $tmp . '/report.json';
$markdownPath = $tmp . '/report.md';
$paper = require $root . '/config/tactical_paper.php';
$repo = new TacticalPaperRepository($db);
$repo->migrate();
$runId = (string) $paper['run_id'];
$repo->ensureRun([
    'run_id' => $runId,
    'profile' => (string) $paper['profile'],
    'strategy_hash' => str_repeat('a', 64),
    'runtime_hash' => str_repeat('b', 64),
    'data_contract' => (array) $paper['data'],
    'live_review_not_before' => (string) $paper['live_review_not_before'],
], [
    'dynamic_loo10' => 0.6,
    'qqq200_full' => 2 / 15,
    'spy200_full' => 2 / 15,
    'qqq150_ex_crypto' => 2 / 15,
]);
$repo->activate($runId, 100.0, [
    'positions' => [],
    'open_orders' => [],
    'adoption' => 'flat_account_only',
    'stable_for_seconds' => 120,
]);

$pdo = new PDO('sqlite:' . $db);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$activatedAt = (new DateTimeImmutable('-32 days'))->format(DateTimeInterface::ATOM);
$pdo->prepare('UPDATE tactical_paper_run SET activated_at=:activated WHERE run_id=:run_id')
    ->execute([':activated' => $activatedAt, ':run_id' => $runId]);

$weekRows = [
    ['-28 days', 100.0, 110.0],
    ['-21 days', 110.0, 111.0],
    ['-14 days', 111.0, 112.0],
    ['-7 days', 112.0, 113.0],
];
foreach ($weekRows as $index => [$relative, $first, $last]) {
    $base = (new DateTimeImmutable($relative))->setTime(10, 0);
    foreach ([[$base, $first], [$base->setTime(15, 59), $last]] as $rowIndex => [$at, $equity]) {
        $repo->saveSnapshot($runId, [
            'captured_at' => $at->format(DateTimeInterface::ATOM),
            'equity' => $equity,
            'cash' => $equity,
            'buying_power' => 2 * $equity,
            'positions' => [],
            'open_orders' => [],
            'reconciliation_status' => 'reconciled_hold',
            'payload' => ['errors' => $index === 0 && $rowIndex === 0 ? ['synthetic_reconcile_error'] : []],
        ]);
    }
}

$insert = $pdo->prepare(
    'INSERT INTO tactical_paper_intent(
        decision_id,epoch_key,run_id,sleeve_id,signal_date,scheduled_session,leg,symbol,side,
        requested_qty,client_order_id,order_id,status,cumulative_filled_qty,cumulative_fill_notional,
        attempt_count,payload,created_at,submitted_at,updated_at
     ) VALUES(
        :decision_id,:epoch_key,:run_id,:sleeve_id,:signal_date,:scheduled_session,:leg,:symbol,:side,
        :requested_qty,:client_order_id,:order_id,:status,:filled_qty,:filled_notional,
        1,:payload,:created_at,:submitted_at,:updated_at
     )'
);
$intentAt = (new DateTimeImmutable('-10 days'))->format(DateTimeInterface::ATOM);
$insert->execute([
    ':decision_id' => str_repeat('c', 64),
    ':epoch_key' => str_repeat('d', 64),
    ':run_id' => $runId,
    ':sleeve_id' => 'dynamic_loo10',
    ':signal_date' => (new DateTimeImmutable('-11 days'))->format('Y-m-d'),
    ':scheduled_session' => (new DateTimeImmutable('-10 days'))->format('Y-m-d'),
    ':leg' => 'exit',
    ':symbol' => 'AAPL',
    ':side' => 'sell',
    ':requested_qty' => 1,
    ':client_order_id' => 'ftthv4_month_gate_test',
    ':order_id' => 'paper-test-order',
    ':status' => 'filled',
    ':filled_qty' => 1,
    ':filled_notional' => 100,
    ':payload' => '{}',
    ':created_at' => $intentAt,
    ':submitted_at' => $intentAt,
    ':updated_at' => $intentAt,
]);

$command = [
    PHP_BINARY,
    $root . '/bin/trade',
    'tactical-paper-month-report',
    '--db=' . $db,
    '--output=' . $reportPath,
    '--markdown=' . $markdownPath,
    '--telegram=false',
];
$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
if (!is_resource($process)) {
    throw new RuntimeException('Unable to run tactical month report test command.');
}
$stdout = (string) stream_get_contents($pipes[1]);
$stderr = (string) stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exit = proc_close($process);
if ($exit !== 0) {
    throw new RuntimeException("Month report command failed: {$stdout}\n{$stderr}");
}
$report = json_decode((string) file_get_contents($reportPath), true, 512, JSON_THROW_ON_ERROR);
$failed = (array) ($report['failed_gates'] ?? []);
monthAssert(in_array('fewer_than_two_completed_exit_episodes', $failed, true), 'A single exit episode must not pass the month gate.');
monthAssert(in_array('reconciliation_error_rate_above_one_percent', $failed, true), 'Snapshot payload errors must count against reconciliation health.');
monthAssert(in_array('single_positive_week_concentration_above_70_percent', $failed, true), 'One dominant positive week must block the stability gate.');
monthAssert(($report['orders']['completed_exit_episodes'] ?? null) === 1, 'Completed exit episodes must be de-duplicated by symbol/session.');
monthAssert(($report['weekly_consistency']['positive_weeks'] ?? null) === 4, 'Positive observed weeks must be counted.');
monthAssert(($report['eligible_for_human_live_review'] ?? null) === false, 'Synthetic unstable month must remain blocked.');
monthAssert(($report['observed_dates'] ?? null) === 4, 'Calendar age must not stand in for snapshot coverage.');
monthAssert(str_contains($stdout, '4 stored snapshot dates') && str_contains($stdout, '/20 market dates'), 'The report must distinguish stored dates from market sessions.');
$expectedReview = max(new DateTimeImmutable($paper['live_review_not_before']), (new DateTimeImmutable($activatedAt))->modify('+31 days'));
monthAssert($report['earliest_calendar_review_at'] === $expectedReview->format(DateTimeInterface::ATOM), 'The review date must respect actual activation plus 31 days.');
monthAssert($report['live_review_not_before'] === $expectedReview->format('Y-m-d'), 'Do not report an expired configuration date as the actual review threshold.');
monthAssert(is_string($report['latest_snapshot_at'] ?? null) && str_contains($stdout, $report['latest_snapshot_at']), 'The report must expose the age of its last actual observation.');
$mixed = [
    ['captured_at' => '2026-09-08T16:01:00-04:00', 'equity' => 102],
    ['captured_at' => '2026-09-08T20:00:30+00:00', 'equity' => 101],
    ['captured_at' => '2026-09-08T15:59:00-04:00', 'equity' => 99],
];
$normalized = FulltimeTrading\Trading\TacticalPaperObservation::since($mixed, '2026-09-08T20:00:00+00:00', new DateTimeImmutable('2026-09-08T21:00:00Z'));
monthAssert(array_column($normalized, 'equity') === [101, 102], 'Mixed offsets must be filtered and sorted by actual time, not strings.');
$dates = FulltimeTrading\Trading\TacticalPaperObservation::dates([
    ['captured_at' => '2026-09-05T16:00:00-04:00'],
    ['captured_at' => '2026-09-07T16:00:00-04:00'],
    ['captured_at' => '2026-09-08T16:00:00-04:00'],
    ['captured_at' => '2026-09-08T20:01:00+00:00'],
    ['captured_at' => '2026-09-09T16:00:00-04:00', 'payload' => ['dry_run' => true]],
]);
monthAssert(count($dates['stored']) === 4 && $dates['market'] === ['2026-09-08'], 'Weekend, Labor Day, duplicate offsets and dry-runs must not inflate observed market dates.');
monthAssert(abs(FulltimeTrading\Trading\TacticalPaperObservation::maxDrawdown([['equity' => 60.0], ['equity' => 80.0]], 100.0) + 0.4) < 1.0e-12,
    'Loss before the first stored snapshot must still be measured from activation capital.');

echo "Tactical paper month gate OK\n";

function monthAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
