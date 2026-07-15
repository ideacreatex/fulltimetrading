#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Trading\PendingEntryPremarketKillSwitch;

// Intentionally do not load bootstrap.php: this offline research command does
// not read .env, construct a broker client, use HTTP or submit orders.
require_once __DIR__ . '/../src/Trading/PendingEntryPremarketKillSwitch.php';

$options = [
    'input' => null,
    'output' => null,
    'evaluated-at' => null,
];

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--help') {
        fwrite(STDOUT, "Usage: php tools/evaluate_premarket_kill_switch.php --input=payload.json [--evaluated-at=ISO8601] [--output=result.json]\n");
        exit(0);
    }
    if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
        failClosed('argument_invalid', null, $options['output']);
    }
    [$key, $value] = explode('=', substr($argument, 2), 2);
    if (!array_key_exists($key, $options) || trim($value) === '') {
        failClosed('argument_invalid', null, $options['output']);
    }
    $options[$key] = $value;
}

$inputPath = (string) ($options['input'] ?? '');
if ($inputPath === '' || !is_file($inputPath) || !is_readable($inputPath)) {
    failClosed('input_file_unreadable', null, $options['output']);
}

$raw = file_get_contents($inputPath);
if (!is_string($raw)) {
    failClosed('input_file_unreadable', null, $options['output']);
}

try {
    $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    failClosed('input_json_invalid', hash('sha256', $raw), $options['output']);
}
if (!is_array($payload)) {
    failClosed('input_payload_invalid', hash('sha256', $raw), $options['output']);
}

$evaluatedAtRaw = trim((string) ($options['evaluated-at'] ?? $payload['evaluated_at'] ?? ''));
if ($evaluatedAtRaw === '') {
    failClosed('evaluated_at_missing', hash('sha256', $raw), $options['output']);
}
if (preg_match('/(?:Z|[+\-]\d{2}:?\d{2})$/i', $evaluatedAtRaw) !== 1) {
    failClosed('evaluated_at_timezone_missing', hash('sha256', $raw), $options['output']);
}
try {
    $evaluatedAt = new DateTimeImmutable($evaluatedAtRaw);
} catch (Throwable) {
    failClosed('evaluated_at_invalid', hash('sha256', $raw), $options['output']);
}

$policy = $payload['policy'] ?? [];
if (!is_array($policy)) {
    failClosed('policy_payload_invalid', hash('sha256', $raw), $options['output'], $evaluatedAt);
}

$snapshots = $payload['snapshots'] ?? null;
if (!is_array($snapshots) || !array_is_list($snapshots) || $snapshots === []) {
    failClosed('snapshots_payload_invalid', hash('sha256', $raw), $options['output'], $evaluatedAt);
}

$decisions = [];
$cancelCount = 0;
foreach ($snapshots as $snapshot) {
    $decision = PendingEntryPremarketKillSwitch::evaluate(
        is_array($snapshot) ? $snapshot : [],
        $policy,
        $evaluatedAt,
    );
    $decisions[] = $decision;
    if (($decision['decision'] ?? 'cancel') === 'cancel') {
        $cancelCount++;
    }
}

$result = safetyEnvelope([
    'ok' => true,
    'reason' => 'offline_evaluation_completed',
    'input_sha256' => hash('sha256', $raw),
    'evaluated_at' => $evaluatedAt->format(DateTimeInterface::ATOM),
    'aggregate_decision' => $cancelCount > 0 ? 'cancel' : 'keep',
    'summary' => [
        'snapshots' => count($decisions),
        'cancel' => $cancelCount,
        'keep' => count($decisions) - $cancelCount,
    ],
    'decisions' => $decisions,
]);

emitResult($result, $options['output']);
exit(0);

/**
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function safetyEnvelope(array $payload): array
{
    return array_merge([
        'schema_version' => 1,
        'mode' => 'offline_research_only',
        'execution_authorized' => false,
        'network_used' => false,
        'orders_submitted' => false,
    ], $payload);
}

function failClosed(
    string $reason,
    ?string $inputHash,
    mixed $outputPath,
    ?DateTimeImmutable $evaluatedAt = null,
): never {
    emitResult(safetyEnvelope([
        'ok' => false,
        'reason' => $reason,
        'input_sha256' => $inputHash,
        'evaluated_at' => $evaluatedAt?->format(DateTimeInterface::ATOM),
        'aggregate_decision' => 'cancel',
        'summary' => [
            'snapshots' => 0,
            'cancel' => 0,
            'keep' => 0,
        ],
        'decisions' => [],
    ]), is_string($outputPath) ? $outputPath : null);
    exit(2);
}

/** @param array<string, mixed> $result */
function emitResult(array $result, mixed $outputPath): void
{
    $json = json_encode(
        $result,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    ) . "\n";

    if (is_string($outputPath) && $outputPath !== '') {
        $directory = dirname($outputPath);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            fwrite(STDERR, "Unable to create output directory.\n");
            exit(3);
        }
        $temporaryPath = $outputPath . '.tmp.' . getmypid();
        if (file_put_contents($temporaryPath, $json, LOCK_EX) === false || !rename($temporaryPath, $outputPath)) {
            @unlink($temporaryPath);
            fwrite(STDERR, "Unable to write output file.\n");
            exit(3);
        }
    }

    fwrite(STDOUT, $json);
}
