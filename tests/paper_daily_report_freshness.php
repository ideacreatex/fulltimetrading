<?php

declare(strict_types=1);

use FulltimeTrading\Trading\PaperDailyReportFreshnessGuard;

require __DIR__ . '/../bootstrap.php';

function expectFreshness(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param array<string, mixed> $assessment */
function expectFreshnessReason(array $assessment, string $reason, string $message): void
{
    expectFreshness(($assessment['reason'] ?? null) === $reason, $message . ': ' . json_encode($assessment));
}

function marketTime(string $value): DateTimeImmutable
{
    return new DateTimeImmutable($value, new DateTimeZone('America/New_York'));
}

function writeFreshnessReport(string $path, string $generatedAt, string $asOf): void
{
    file_put_contents($path, json_encode([
        'generated_at' => $generatedAt,
        'as_of' => $asOf,
        'data' => ['symbols_loaded' => 5],
    ], JSON_THROW_ON_ERROR));
}

/** @param list<array<string, mixed>> $steps @return array<string, mixed> */
function findFreshnessStep(array $steps, string $name): array
{
    foreach ($steps as $step) {
        if (($step['name'] ?? '') === $name) {
            return $step;
        }
    }

    return [];
}

$tempDir = sys_get_temp_dir() . '/ftt-paper-daily-freshness-' . bin2hex(random_bytes(8));
if (!mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
    throw new RuntimeException('Unable to create test directory.');
}

try {
    $weekendNow = marketTime('2026-07-19 12:00:00'); // Sunday: Friday is the latest possible closed bar.
    $cycleStartedAt = marketTime('2026-07-19 10:00:00');
    $stagedReport = $tempDir . '/report.current-cycle.json';
    writeFreshnessReport($stagedReport, marketTime('2026-07-19 11:00:00')->format(DateTimeInterface::ATOM), '2026-07-17');

    $fresh = PaperDailyReportFreshnessGuard::evaluate(
        $stagedReport,
        true,
        ['ok' => true, 'exit_code' => 0],
        $cycleStartedAt,
        '',
        true,
        $weekendNow,
    );
    expectFreshness(PaperDailyReportFreshnessGuard::allowsDownstream($fresh), 'Friday report must remain current during the weekend.');
    expectFreshness(($fresh['latest_expected_closed_bar'] ?? '') === '2026-07-17', 'Weekend gate must target Friday, not Sunday.');
    expectFreshness(
        PaperDailyReportFreshnessGuard::closedBarReportEnd('2026-07-19', '', $weekendNow) === '2026-07-17',
        'A normal cycle must cap provider data at the latest closed daily bar.',
    );
    expectFreshness(
        PaperDailyReportFreshnessGuard::closedBarReportEnd('2026-07-19', '2026-06-30', $weekendNow) === '2026-06-30',
        'An explicit as-of must cap the provider end date for deterministic historical dry-runs.',
    );

    $failedRefresh = PaperDailyReportFreshnessGuard::evaluate(
        $stagedReport,
        true,
        ['ok' => false, 'exit_code' => 1],
        $cycleStartedAt,
        '',
        true,
        $weekendNow,
    );
    expectFreshness(!PaperDailyReportFreshnessGuard::allowsDownstream($failedRefresh), 'A failed daily report step must block every downstream consumer.');
    expectFreshnessReason($failedRefresh, 'daily_signal_report_failed', 'Failed refresh must not fall back to an old final report');

    writeFreshnessReport($stagedReport, marketTime('2026-07-19 09:00:00')->format(DateTimeInterface::ATOM), '2026-07-17');
    $oldGeneration = PaperDailyReportFreshnessGuard::evaluate(
        $stagedReport,
        true,
        ['ok' => true, 'exit_code' => 0],
        $cycleStartedAt,
        '',
        true,
        $weekendNow,
    );
    expectFreshnessReason($oldGeneration, 'report_not_generated_during_current_cycle', 'Current-cycle provenance must be enforced');

    writeFreshnessReport($stagedReport, marketTime('2026-07-19 11:00:00')->format(DateTimeInterface::ATOM), '2026-07-19');
    $weekendPartial = PaperDailyReportFreshnessGuard::evaluate(
        $stagedReport,
        true,
        ['ok' => true, 'exit_code' => 0],
        $cycleStartedAt,
        '',
        false,
        $weekendNow,
    );
    expectFreshnessReason($weekendPartial, 'report_as_of_is_not_a_closed_daily_bar', 'Weekend date must never be accepted as a daily close');

    $tuesdayNoon = marketTime('2026-07-21 12:00:00');
    writeFreshnessReport($stagedReport, marketTime('2026-07-21 11:00:00')->format(DateTimeInterface::ATOM), '2026-07-21');
    $intradayBar = PaperDailyReportFreshnessGuard::evaluate(
        $stagedReport,
        true,
        ['ok' => true, 'exit_code' => 0],
        marketTime('2026-07-21 10:00:00'),
        '',
        false,
        $tuesdayNoon,
    );
    expectFreshnessReason($intradayBar, 'report_as_of_is_not_a_closed_daily_bar', 'An unfinished current-day bar must be rejected');

    writeFreshnessReport($stagedReport, marketTime('2026-07-21 11:00:00')->format(DateTimeInterface::ATOM), '2026-07-16');
    $staleBar = PaperDailyReportFreshnessGuard::evaluate(
        $stagedReport,
        true,
        ['ok' => true, 'exit_code' => 0],
        marketTime('2026-07-21 10:00:00'),
        '',
        false,
        $tuesdayNoon,
    );
    expectFreshnessReason($staleBar, 'report_as_of_stale', 'More than one missing weekday must fail closed');

    $wednesdayNoon = marketTime('2026-07-22 12:00:00');
    writeFreshnessReport($stagedReport, marketTime('2026-07-22 11:00:00')->format(DateTimeInterface::ATOM), '2026-07-20');
    $oneSessionStale = PaperDailyReportFreshnessGuard::evaluate(
        $stagedReport,
        true,
        ['ok' => true, 'exit_code' => 0],
        marketTime('2026-07-22 10:00:00'),
        '',
        true,
        $wednesdayNoon,
    );
    expectFreshnessReason($oneSessionStale, 'report_as_of_stale', 'A single missing ordinary trading session must fail closed.');

    $observedHoliday = marketTime('2026-07-03 17:00:00');
    expectFreshness(
        PaperDailyReportFreshnessGuard::latestExpectedClosedBarDate($observedHoliday)->format('Y-m-d') === '2026-07-02',
        'The Independence Day observed holiday must not be mistaken for a missing market-data session.',
    );
    expectFreshness(
        PaperDailyReportFreshnessGuard::latestExpectedClosedBarDate(marketTime('2026-04-03 17:00:00'))->format('Y-m-d') === '2026-04-02',
        'Good Friday must not be mistaken for a missing market-data session.',
    );
    expectFreshness(
        !PaperDailyReportFreshnessGuard::barWasClosedWhenReportGenerated([
            'generated_at' => marketTime('2026-07-21 09:36:00')->format(DateTimeInterface::ATOM),
        ], '2026-07-21'),
        'An intraday snapshot must never become a daily close merely because the clock advances later.',
    );
    expectFreshness(
        PaperDailyReportFreshnessGuard::barWasClosedWhenReportGenerated([
            'generated_at' => marketTime('2026-07-22 09:36:00')->format(DateTimeInterface::ATOM),
        ], '2026-07-21'),
        'A prior session bar is closed when a next-morning report is generated.',
    );

    $afterClose = marketTime('2026-07-21 17:00:00');
    writeFreshnessReport($stagedReport, marketTime('2026-07-21 16:30:00')->format(DateTimeInterface::ATOM), '2026-07-21');
    $closedToday = PaperDailyReportFreshnessGuard::evaluate(
        $stagedReport,
        true,
        ['ok' => true, 'exit_code' => 0],
        marketTime('2026-07-21 16:20:00'),
        '',
        true,
        $afterClose,
    );
    expectFreshness(PaperDailyReportFreshnessGuard::allowsDownstream($closedToday), 'Today as-of must be accepted after the daily bar settles.');

    writeFreshnessReport($stagedReport, marketTime('2026-07-21 15:00:00')->format(DateTimeInterface::ATOM), '2026-07-21');
    $partialSnapshotReusedLater = PaperDailyReportFreshnessGuard::evaluate(
        $stagedReport,
        true,
        ['ok' => true, 'exit_code' => 0],
        marketTime('2026-07-21 14:50:00'),
        '',
        false,
        $afterClose,
    );
    expectFreshnessReason(
        $partialSnapshotReusedLater,
        'report_generated_before_as_of_bar_closed',
        'An intraday snapshot must not become valid merely because the market closed later',
    );

    $existingReport = $tempDir . '/report.existing.json';
    writeFreshnessReport($existingReport, marketTime('2026-07-19 08:00:00')->format(DateTimeInterface::ATOM), '2026-07-17');
    $existingDryRun = PaperDailyReportFreshnessGuard::evaluate(
        $existingReport,
        false,
        null,
        $cycleStartedAt,
        '',
        false,
        $weekendNow,
    );
    expectFreshness(PaperDailyReportFreshnessGuard::allowsDownstream($existingDryRun), 'A refresh=false dry-run may use an explicitly verified current closed-bar report.');

    $existingSubmit = PaperDailyReportFreshnessGuard::evaluate(
        $existingReport,
        false,
        null,
        $cycleStartedAt,
        '',
        true,
        $weekendNow,
    );
    expectFreshnessReason($existingSubmit, 'submit_requires_report_created_by_current_cycle', 'Submit must not use a report from an earlier cycle');

    writeFreshnessReport($existingReport, marketTime('2026-07-19 08:00:00')->format(DateTimeInterface::ATOM), '2025-12-31');
    $historicalDryRun = PaperDailyReportFreshnessGuard::evaluate(
        $existingReport,
        false,
        null,
        $cycleStartedAt,
        '2025-12-31',
        false,
        $weekendNow,
    );
    expectFreshness(PaperDailyReportFreshnessGuard::allowsDownstream($historicalDryRun), 'An explicit historical as-of must remain available for dry-run inspection.');

    $asOfMismatch = PaperDailyReportFreshnessGuard::evaluate(
        $existingReport,
        false,
        null,
        $cycleStartedAt,
        '2025-12-30',
        false,
        $weekendNow,
    );
    expectFreshnessReason($asOfMismatch, 'report_as_of_mismatch', 'Requested as-of must exactly match the report');

    // End-to-end regression: even a valid-looking final report must never be reused when this cycle's refresh fails.
    $integrationNow = new DateTimeImmutable();
    $integrationAsOf = PaperDailyReportFreshnessGuard::latestExpectedClosedBarDate($integrationNow)->format('Y-m-d');
    $finalReport = $tempDir . '/integration-report.json';
    $textReport = $tempDir . '/integration-report.txt';
    $planOutput = $tempDir . '/integration-plan.json';
    $monitorOutput = $tempDir . '/integration-monitor.json';
    $cycleOutput = $tempDir . '/integration-cycle.json';
    writeFreshnessReport($finalReport, $integrationNow->format(DateTimeInterface::ATOM), $integrationAsOf);
    file_put_contents($planOutput, "plan sentinel\n");
    file_put_contents($monitorOutput, "monitor sentinel\n");
    $planHash = hash_file('sha256', $planOutput);
    $monitorHash = hash_file('sha256', $monitorOutput);

    $command = [
        PHP_BINARY,
        __DIR__ . '/../tools/paper_daily_cycle.php',
        '--provider=invalid-regression-provider',
        '--profile=default',
        '--refresh-report=true',
        '--submit=true',
        '--monitor=true',
        '--telegram=false',
        '--report-output=' . $finalReport,
        '--text-output=' . $textReport,
        '--plan-output=' . $planOutput,
        '--monitor-output=' . $monitorOutput,
        '--cycle-output=' . $cycleOutput,
    ];
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start paper cycle regression process.');
    }
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    expectFreshness($exitCode !== 0, 'Cycle must fail when daily_signal_report fails. Output: ' . $stdout . ' ' . $stderr);
    expectFreshness(is_file($cycleOutput), 'Failed cycle must still write an explicit cycle payload.');
    $cycle = json_decode((string) file_get_contents($cycleOutput), true, 512, JSON_THROW_ON_ERROR);
    expectFreshness(is_array($cycle) && empty($cycle['report_ready']), 'Failed refresh must mark report_ready=false.');
    expectFreshness(($cycle['report_freshness_gate']['reason'] ?? '') === 'daily_signal_report_failed', 'Cycle payload must expose the refresh failure reason.');
    $planStep = findFreshnessStep($cycle['steps'] ?? [], 'paper_order_plan');
    $monitorStep = findFreshnessStep($cycle['steps'] ?? [], 'paper_position_monitor');
    expectFreshness(!empty($planStep['skipped']), 'paper_order_plan must be explicitly skipped after refresh failure.');
    expectFreshness(!empty($monitorStep['skipped']), 'cycle monitor must be explicitly skipped after refresh failure.');
    expectFreshness(hash_file('sha256', $planOutput) === $planHash, 'Skipped paper_order_plan must not touch its previous output.');
    expectFreshness(hash_file('sha256', $monitorOutput) === $monitorHash, 'Skipped cycle monitor must not touch its previous output.');

    echo "Paper daily report freshness guard OK\n";
} finally {
    foreach (glob($tempDir . '/*') ?: [] as $path) {
        @unlink($path);
    }
    @rmdir($tempDir);
}
