#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Research\PremarketFeatureMatrixBuilder;

// Deliberately isolated from bootstrap.php, .env, brokers and HTTP providers.
require_once __DIR__ . '/../src/Research/PremarketFeatureMatrixBuilder.php';

$input = null;
$output = null;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help') {
        fwrite(STDOUT, "Usage: php tools/build_premarket_feature_matrix.php --input=observations.json [--output=matrix.json]\n");
        exit(0);
    }
    if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
        matrixFailure('argument_invalid', $output);
    }
    [$key, $value] = explode('=', substr($argument, 2), 2);
    if ($key === 'input' && trim($value) !== '') {
        $input = $value;
    } elseif ($key === 'output' && trim($value) !== '') {
        $output = $value;
    } else {
        matrixFailure('argument_invalid', $output);
    }
}

if (!is_string($input) || !is_file($input) || !is_readable($input)) {
    matrixFailure('input_file_unreadable', $output);
}
$raw = file_get_contents($input);
if (!is_string($raw)) {
    matrixFailure('input_file_unreadable', $output);
}
try {
    $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    matrixFailure('input_json_invalid', $output, hash('sha256', $raw));
}
$observations = is_array($payload) ? ($payload['observations'] ?? null) : null;
if (!is_array($observations) || !array_is_list($observations) || $observations === []) {
    matrixFailure('observations_payload_invalid', $output, hash('sha256', $raw));
}

$rows = [];
$valid = 0;
foreach ($observations as $observation) {
    $row = PremarketFeatureMatrixBuilder::buildRow(is_array($observation) ? $observation : []);
    $rows[] = $row;
    if (($row['valid'] ?? false) === true) {
        $valid++;
    }
}

$result = matrixEnvelope([
    'ok' => true,
    'reason' => 'offline_feature_matrix_built',
    'input_sha256' => hash('sha256', $raw),
    'data_complete' => $valid === count($rows),
    'summary' => [
        'observations' => count($rows),
        'valid' => $valid,
        'invalid' => count($rows) - $valid,
    ],
    'rows' => $rows,
]);
matrixEmit($result, $output);
exit(0);

/** @param array<string, mixed> $payload @return array<string, mixed> */
function matrixEnvelope(array $payload): array
{
    return array_merge([
        'schema_version' => 1,
        'mode' => 'offline_research_only',
        'execution_authorized' => false,
        'network_used' => false,
        'orders_submitted' => false,
        'profitability_evaluated' => false,
    ], $payload);
}

function matrixFailure(string $reason, ?string $output, ?string $hash = null): never
{
    matrixEmit(matrixEnvelope([
        'ok' => false,
        'reason' => $reason,
        'input_sha256' => $hash,
        'data_complete' => false,
        'summary' => ['observations' => 0, 'valid' => 0, 'invalid' => 0],
        'rows' => [],
    ]), $output);
    exit(2);
}

/** @param array<string, mixed> $result */
function matrixEmit(array $result, ?string $output): void
{
    $json = json_encode(
        $result,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    ) . "\n";
    if (is_string($output) && $output !== '') {
        $directory = dirname($output);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            fwrite(STDERR, "Unable to create output directory.\n");
            exit(3);
        }
        $temporary = $output . '.tmp.' . getmypid();
        if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $output)) {
            @unlink($temporary);
            fwrite(STDERR, "Unable to write output file.\n");
            exit(3);
        }
    }
    fwrite(STDOUT, $json);
}
