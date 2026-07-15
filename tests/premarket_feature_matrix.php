<?php

declare(strict_types=1);

use FulltimeTrading\Research\PremarketFeatureMatrixBuilder;

require_once __DIR__ . '/../src/Research/PremarketFeatureMatrixBuilder.php';

function expectMatrix(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string, mixed> */
function matrixObservation(): array
{
    return [
        'symbol' => 'TQQQ',
        'session_date' => '2026-07-16',
        'previous_close' => 100.0,
        'premarket_open' => 98.0,
        'premarket_high' => 99.0,
        'premarket_low' => 95.0,
        'premarket_last' => 96.0,
        'premarket_vwap' => 97.0,
        'premarket_volume' => 500000,
        'reference_premarket_volume' => 250000,
        'daily_rsi' => 42.0,
        'weekly_rsi' => 55.0,
        'weekly_ema20' => 95.0,
        'weekly_sma20' => 97.0,
        'checkpoints' => [
            'open' => 96.5,
            '5m' => 97.0,
            '15m' => 96.0,
            '30m' => 98.0,
            '60m' => 99.0,
        ],
    ];
}

$row = PremarketFeatureMatrixBuilder::buildRow(matrixObservation());
expectMatrix(($row['valid'] ?? false) === true, 'A complete observation must produce a valid feature row.');
expectMatrix(($row['reason'] ?? null) === 'feature_row_built', 'Valid row reason must be stable.');
expectMatrix(($row['features']['premarket_open_gap_pct'] ?? null) === -2.0, 'Open gap must be based on previous close.');
expectMatrix(($row['features']['premarket_last_gap_pct'] ?? null) === -4.0, 'Last gap must be based on previous close.');
expectMatrix(($row['features']['premarket_range_pct_of_previous_close'] ?? null) === 4.0, 'Range feature must be normalized.');
expectMatrix(($row['features']['premarket_last_vs_vwap_pct'] ?? null) === -1.030928, 'VWAP feature must be deterministic.');
expectMatrix(($row['features']['premarket_volume_ratio'] ?? null) === 2.0, 'Relative volume must be calculated.');
expectMatrix(($row['features']['premarket_last_vs_20w_ema_pct'] ?? null) === 1.052632, 'Distance to 20W EMA must be calculated.');
expectMatrix(($row['features']['premarket_last_vs_20w_sma_pct'] ?? null) === -1.030928, 'Distance to 20W SMA must be calculated.');
expectMatrix(($row['features']['weekly_trend_context'] ?? null) === 'between_20w_ema_and_sma', 'Weekly trend context must use both 20W averages.');
expectMatrix(($row['entry_checkpoints']['open']['vs_regular_open_pct'] ?? null) === 0.0, 'Open checkpoint must be the checkpoint baseline.');
expectMatrix(($row['entry_checkpoints']['60m']['vs_regular_open_pct'] ?? null) === 2.590674, '60m checkpoint must be comparable to the open.');
expectMatrix(($row['exit_reference_levels']['premarket_low'] ?? null) === 95.0, 'Premarket low must be retained as an exit reference.');
expectMatrix(($row['exit_reference_levels']['regular_15m'] ?? null) === 96.0, '15m level must be retained as an exit reference.');

$badRsi = matrixObservation();
$badRsi['weekly_rsi'] = 101.0;
$invalidRsi = PremarketFeatureMatrixBuilder::buildRow($badRsi);
expectMatrix(($invalidRsi['reason'] ?? null) === 'weekly_rsi_invalid', 'Out-of-range RSI must be rejected.');

$missingCheckpoint = matrixObservation();
unset($missingCheckpoint['checkpoints']['60m']);
$invalidCheckpoint = PremarketFeatureMatrixBuilder::buildRow($missingCheckpoint);
expectMatrix(($invalidCheckpoint['valid'] ?? true) === false, 'An incomplete checkpoint set must be invalid.');
expectMatrix(($invalidCheckpoint['reason'] ?? null) === 'checkpoint_60m_invalid', 'Missing checkpoint reason must be stable.');

$inconsistent = matrixObservation();
$inconsistent['premarket_high'] = 94.0;
$invalidRange = PremarketFeatureMatrixBuilder::buildRow($inconsistent);
expectMatrix(($invalidRange['reason'] ?? null) === 'premarket_range_invalid', 'Inverted range must be rejected.');

$tempDir = sys_get_temp_dir() . '/ftt-premarket-matrix-' . bin2hex(random_bytes(8));
if (!mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
    throw new RuntimeException('Unable to create matrix test directory.');
}
try {
    $input = $tempDir . '/observations.json';
    file_put_contents($input, json_encode(['observations' => [matrixObservation()]], JSON_THROW_ON_ERROR));
    $process = proc_open(
        [PHP_BINARY, __DIR__ . '/../tools/build_premarket_feature_matrix.php', '--input=' . $input],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        dirname(__DIR__),
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to launch feature matrix CLI.');
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    expectMatrix($exit === 0, 'Offline matrix CLI must succeed: ' . $stderr);
    $result = json_decode((string) $stdout, true, 512, JSON_THROW_ON_ERROR);
    expectMatrix(($result['network_used'] ?? true) === false, 'Matrix CLI must report no network use.');
    expectMatrix(($result['orders_submitted'] ?? true) === false, 'Matrix CLI must report no orders.');
    expectMatrix(($result['profitability_evaluated'] ?? true) === false, 'Matrix must not imply a profitability result.');
    expectMatrix(($result['data_complete'] ?? false) === true, 'Complete input must be labeled complete.');
} finally {
    foreach (glob($tempDir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($tempDir);
}

fwrite(STDOUT, "Premarket feature matrix tests passed.\n");
