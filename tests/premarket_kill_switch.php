<?php

declare(strict_types=1);

use FulltimeTrading\Trading\PendingEntryPremarketKillSwitch;

require_once __DIR__ . '/../src/Trading/PendingEntryPremarketKillSwitch.php';

function expectPremarket(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param array<string, mixed> $decision */
function expectPremarketReason(array $decision, string $reason, string $message): void
{
    expectPremarket(
        ($decision['reason'] ?? null) === $reason,
        $message . ': ' . json_encode($decision, JSON_UNESCAPED_SLASHES),
    );
}

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function snapshot(array $overrides = []): array
{
    return array_merge([
        'symbol' => 'TQQQ',
        'observed_at' => '2026-07-16T08:28:00-04:00',
        'previous_close' => 100.0,
        'premarket_price' => 99.0,
        'support_price' => 98.0,
        'regular_session_expected' => true,
    ], $overrides);
}

/** @return array<string, mixed> */
function policy(array $overrides = []): array
{
    return array_merge([
        'gap_down_threshold_pct' => 3.0,
        'support_breach_enabled' => true,
        'support_breach_buffer_pct' => 0.0,
        'require_session_calendar_confirmation' => true,
        'max_data_age_seconds' => 300,
        'max_future_skew_seconds' => 15,
        'premarket_start' => '04:00',
        'regular_open' => '09:30',
    ], $overrides);
}

$evaluatedAt = new DateTimeImmutable('2026-07-16T08:30:00-04:00');

$keep = PendingEntryPremarketKillSwitch::evaluate(snapshot(), policy(), $evaluatedAt);
expectPremarket(($keep['decision'] ?? null) === 'keep', 'A fresh observation above both risk thresholds must be kept.');
expectPremarket(($keep['cancel_pending_entry'] ?? true) === false, 'Keep must not request cancellation.');
expectPremarketReason($keep, 'premarket_conditions_acceptable', 'Keep reason must be stable.');
expectPremarket(($keep['advisory_only'] ?? false) === true, 'The pure policy must label its output advisory-only.');

$sharpGap = PendingEntryPremarketKillSwitch::evaluate(
    snapshot(['premarket_price' => 97.0, 'support_price' => 96.0]),
    policy(),
    $evaluatedAt,
);
expectPremarket(($sharpGap['decision'] ?? null) === 'cancel', 'The exact configured gap threshold must trigger cancellation.');
expectPremarketReason($sharpGap, 'sharp_premarket_gap_down', 'Sharp gap reason must be stable.');
expectPremarket(($sharpGap['trigger_reasons'] ?? null) === ['sharp_premarket_gap_down'], 'Sharp gap trigger list must be deterministic.');

$supportBreach = PendingEntryPremarketKillSwitch::evaluate(
    snapshot(['premarket_price' => 98.5, 'support_price' => 99.0]),
    policy(),
    $evaluatedAt,
);
expectPremarketReason($supportBreach, 'premarket_support_breached', 'Support breach must cancel independently of a sharp gap.');

$both = PendingEntryPremarketKillSwitch::evaluate(
    snapshot(['premarket_price' => 95.0, 'support_price' => 98.0]),
    policy(),
    $evaluatedAt,
);
expectPremarket(
    ($both['trigger_reasons'] ?? null) === ['sharp_premarket_gap_down', 'premarket_support_breached'],
    'Multiple triggers must keep a stable priority order.',
);
expectPremarketReason($both, 'sharp_premarket_gap_down', 'The first stable trigger must be the primary reason.');

$stale = PendingEntryPremarketKillSwitch::evaluate(
    snapshot(['observed_at' => '2026-07-16T08:20:00-04:00']),
    policy(),
    $evaluatedAt,
);
expectPremarketReason($stale, 'snapshot_stale', 'Stale data must fail closed.');

$future = PendingEntryPremarketKillSwitch::evaluate(
    snapshot(['observed_at' => '2026-07-16T08:31:00-04:00']),
    policy(),
    $evaluatedAt,
);
expectPremarketReason($future, 'snapshot_observed_at_in_future', 'Excessive future skew must fail closed.');

$missingSupport = PendingEntryPremarketKillSwitch::evaluate(
    snapshot(['support_price' => null]),
    policy(),
    $evaluatedAt,
);
expectPremarketReason($missingSupport, 'snapshot_support_price_invalid', 'Missing required support must fail closed.');

$supportDisabled = PendingEntryPremarketKillSwitch::evaluate(
    snapshot(['support_price' => null]),
    policy(['support_breach_enabled' => false]),
    $evaluatedAt,
);
expectPremarket(($supportDisabled['decision'] ?? null) === 'keep', 'Explicitly disabled support checking must not require support data.');

$bufferedSupport = PendingEntryPremarketKillSwitch::evaluate(
    snapshot(['premarket_price' => 98.0, 'support_price' => 100.0]),
    policy(['support_breach_buffer_pct' => 2.0, 'gap_down_threshold_pct' => 3.0]),
    $evaluatedAt,
);
expectPremarket(($bufferedSupport['decision'] ?? null) === 'keep', 'Touching the buffered support floor is not a breach.');

$invalidPolicy = PendingEntryPremarketKillSwitch::evaluate(
    snapshot(),
    policy(['gap_down_threshold_pct' => 0.0]),
    $evaluatedAt,
);
expectPremarketReason($invalidPolicy, 'policy_gap_down_threshold_invalid', 'Invalid policy must fail closed.');

$unconfirmedSession = PendingEntryPremarketKillSwitch::evaluate(
    snapshot(['regular_session_expected' => null]),
    policy(),
    $evaluatedAt,
);
expectPremarketReason(
    $unconfirmedSession,
    'snapshot_regular_session_confirmation_invalid',
    'Missing trading-calendar confirmation must fail closed.',
);

$holiday = PendingEntryPremarketKillSwitch::evaluate(
    snapshot(['regular_session_expected' => false]),
    policy(),
    $evaluatedAt,
);
expectPremarketReason($holiday, 'regular_session_not_expected', 'A confirmed market holiday must fail closed.');

$timezoneMissing = PendingEntryPremarketKillSwitch::evaluate(
    snapshot(['observed_at' => '2026-07-16T08:28:00']),
    policy(),
    $evaluatedAt,
);
expectPremarketReason(
    $timezoneMissing,
    'snapshot_observed_at_timezone_missing',
    'Ambiguous observation timezone must fail closed.',
);

$outsideObservation = PendingEntryPremarketKillSwitch::evaluate(
    snapshot(['observed_at' => '2026-07-16T09:31:00-04:00']),
    policy(['max_future_skew_seconds' => 120]),
    new DateTimeImmutable('2026-07-16T09:31:30-04:00'),
);
expectPremarketReason($outsideObservation, 'snapshot_outside_premarket_window', 'A regular-session observation must fail closed.');

$lateEvaluation = PendingEntryPremarketKillSwitch::evaluate(
    snapshot(['observed_at' => '2026-07-16T09:29:00-04:00']),
    policy(),
    new DateTimeImmutable('2026-07-16T09:30:00-04:00'),
);
expectPremarketReason($lateEvaluation, 'evaluation_outside_premarket_window', 'Evaluation at or after the open must fail closed.');

$weekend = PendingEntryPremarketKillSwitch::evaluate(
    snapshot(['observed_at' => '2026-07-18T08:28:00-04:00']),
    policy(),
    new DateTimeImmutable('2026-07-18T08:30:00-04:00'),
);
expectPremarketReason($weekend, 'snapshot_outside_premarket_window', 'Weekend observations must fail closed.');

$tempDir = sys_get_temp_dir() . '/ftt-premarket-kill-switch-' . bin2hex(random_bytes(8));
if (!mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
    throw new RuntimeException('Unable to create temporary test directory.');
}

try {
    $inputPath = $tempDir . '/snapshots.json';
    file_put_contents($inputPath, json_encode([
        'evaluated_at' => '2026-07-16T08:30:00-04:00',
        'policy' => policy(),
        'snapshots' => [
            snapshot(),
            snapshot(['symbol' => 'SOXL', 'premarket_price' => 96.0, 'support_price' => 95.0]),
        ],
    ], JSON_THROW_ON_ERROR));

    [$exitCode, $stdout, $stderr] = runPremarketCli($inputPath);
    expectPremarket($exitCode === 0, 'Valid offline CLI run must succeed: ' . $stderr);
    $cliResult = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    expectPremarket(($cliResult['execution_authorized'] ?? true) === false, 'CLI must explicitly deny execution authorization.');
    expectPremarket(($cliResult['network_used'] ?? true) === false, 'CLI must explicitly report no network use.');
    expectPremarket(($cliResult['orders_submitted'] ?? true) === false, 'CLI must explicitly report no submitted orders.');
    expectPremarket(($cliResult['aggregate_decision'] ?? null) === 'cancel', 'Any cancellation must make the aggregate result cancel.');
    expectPremarket(($cliResult['summary']['keep'] ?? null) === 1, 'CLI must count keep decisions.');
    expectPremarket(($cliResult['summary']['cancel'] ?? null) === 1, 'CLI must count cancel decisions.');

    file_put_contents($inputPath, json_encode([
        'policy' => policy(),
        'snapshots' => [snapshot()],
    ], JSON_THROW_ON_ERROR));
    [$invalidExit, $invalidStdout] = runPremarketCli($inputPath);
    $invalidResult = json_decode($invalidStdout, true, 512, JSON_THROW_ON_ERROR);
    expectPremarket($invalidExit === 2, 'Missing evaluation time must make CLI fail closed.');
    expectPremarket(($invalidResult['aggregate_decision'] ?? null) === 'cancel', 'Invalid CLI payload must have aggregate cancel.');
    expectPremarket(($invalidResult['orders_submitted'] ?? true) === false, 'Fail-closed CLI output must preserve safety flags.');
} finally {
    foreach (glob($tempDir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($tempDir);
}

fwrite(STDOUT, "Premarket kill-switch tests passed.\n");

/** @return array{0:int, 1:string, 2:string} */
function runPremarketCli(string $inputPath): array
{
    $process = proc_open(
        [PHP_BINARY, __DIR__ . '/../tools/evaluate_premarket_kill_switch.php', '--input=' . $inputPath],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__),
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to launch offline premarket CLI.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [$exitCode, (string) $stdout, (string) $stderr];
}
