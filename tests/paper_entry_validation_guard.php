#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$tmpDir = sys_get_temp_dir() . '/ftt-entry-guard-' . bin2hex(random_bytes(6));
if (!mkdir($tmpDir, 0775, true) && !is_dir($tmpDir)) {
    throw new RuntimeException('Unable to create test directory.');
}

$reportPath = $tmpDir . '/report.json';
$outputPath = $tmpDir . '/plan.json';
$report = [
    'as_of' => '2026-07-14',
    'action' => 'review_signals',
    'risk' => [
        // A crafted report cannot override the independent config kill-switch.
        'entry_submission_enabled' => true,
        'entry_submission_block_reason' => '',
        'initial_cash' => 30000.0,
        'max_open_positions' => 1,
        'max_gross_exposure_pct' => 1.0,
        'family_exposure_cap_pct' => 1.0,
    ],
    'market' => ['allows_long_risk' => true],
    'model' => ['open_positions' => []],
    'signals_today' => [
        [
            'symbol' => 'AAPL',
            'direction' => 'long',
            'entry' => 200.0,
            'stop' => 190.0,
            'target' => 215.0,
            'score' => 10.0,
            'setup_key' => 'z-setup',
            'signal_date' => '2026-07-14',
            'timeframe' => 'D',
            'ma_type' => 'ema',
            'ma_period' => 20,
        ],
        [
            'symbol' => 'ZZZ',
            'direction' => 'long',
            'entry' => 100.0,
            'stop' => 95.0,
            'target' => 110.0,
            'score' => 10.0,
            'setup_key' => 'a-setup',
            'signal_date' => '2026-07-14',
            'timeframe' => 'D',
            'ma_type' => 'ema',
            'ma_period' => 20,
        ],
    ],
];
file_put_contents($reportPath, json_encode($report, JSON_THROW_ON_ERROR));

$command = [
    PHP_BINARY,
    $root . '/tools/paper_order_plan.php',
    '--report=' . $reportPath,
    '--output=' . $outputPath,
    '--submit=false',
    '--paper-open-counts=false',
    '--model-open-counts=false',
    '--ignore-model-open=true',
    '--paper-sizing-cash=false',
    '--telegram=false',
];
$process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $root);
if (!is_resource($process)) {
    throw new RuntimeException('Unable to start paper order planner.');
}
$stdout = stream_get_contents($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[1]);
fclose($pipes[2]);
$exitCode = proc_close($process);

try {
    assertSame(0, $exitCode, 'Planner should complete a blocked dry run. stderr=' . $stderr . ' stdout=' . $stdout);
    $payload = json_decode((string) file_get_contents($outputPath), true, 512, JSON_THROW_ON_ERROR);
    $plan = is_array($payload['plan'] ?? null) ? $payload['plan'] : [];
    assertSame(false, $plan['entry_submission_enabled'] ?? null, 'Production entry gate must remain false.');
    assertSame(
        'author_style_unqualified_tactical_rotation_shadow_only_2026-07-16',
        $plan['entry_submission_block_reason'] ?? null,
        'The config-level production validation reason must override a crafted report.',
    );
    assertSame(0, count(is_array($plan['orders'] ?? null) ? $plan['orders'] : []), 'Blocked validation must produce no entry orders.');
    $skipped = is_array($plan['skipped'] ?? null) ? $plan['skipped'] : [];
    assertSame('production_validation_blocks_entries', $skipped[0]['reason'] ?? null, 'Signal must carry the production validation skip reason.');
    assertSame('ZZZ', $skipped[0]['symbol'] ?? null, 'Planner tie-break must preserve the serialized backtester setup_key order.');
} finally {
    @unlink($reportPath);
    @unlink($outputPath);
    @rmdir($tmpDir);
}

echo "paper entry validation guard tests passed\n";

function assertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
