#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Trading\PortfolioEmergencyDeRiskPolicy;

require __DIR__ . '/../bootstrap.php';

$options = [];
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--') && str_contains($argument, '=')) {
        [$name, $value] = explode('=', substr($argument, 2), 2);
        $options[$name] = $value;
    }
}
$input = (string) ($options['input'] ?? '');
if ($input === '' || !is_file($input)) {
    fwrite(STDERR, "Usage: php tools/research_emergency_de_risk.php --input=/absolute/snapshot.json \\\n");
    fwrite(STDERR, "  --trigger-ratio=1.75 --target-ratio=1.50 \\\n");
    fwrite(STDERR, "  --max-reduction-fraction=0.25 --minimum-remaining-quantity=1\n");
    fwrite(STDERR, "Policy values may instead be supplied in snapshot.policy. No API calls or orders are made.\n");
    exit(1);
}

try {
    $snapshot = json_decode((string) file_get_contents($input), true, 512, JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, 'Invalid JSON snapshot: ' . $error->getMessage() . "\n");
    exit(1);
}
if (!is_array($snapshot)) {
    fwrite(STDERR, "Snapshot root must be a JSON object.\n");
    exit(1);
}

$policy = is_array($snapshot['policy'] ?? null) ? $snapshot['policy'] : $snapshot;
$alpaca = is_array($snapshot['alpaca'] ?? null) ? $snapshot['alpaca'] : [];
$account = is_array($alpaca['account'] ?? null) ? $alpaca['account'] : [];
$positions = is_array($snapshot['positions'] ?? null)
    ? $snapshot['positions']
    : (is_array($alpaca['positions'] ?? null) ? $alpaca['positions'] : []);
$equity = $snapshot['equity'] ?? $account['equity'] ?? null;

$requiredNumbers = [
    'equity' => $equity,
    'trigger_ratio' => $options['trigger-ratio'] ?? $policy['trigger_ratio'] ?? null,
    'target_ratio' => $options['target-ratio'] ?? $policy['target_ratio'] ?? null,
    'max_reduction_fraction_per_position' => $options['max-reduction-fraction']
        ?? $policy['max_reduction_fraction_per_position']
        ?? null,
    'minimum_remaining_quantity' => $options['minimum-remaining-quantity']
        ?? $policy['minimum_remaining_quantity']
        ?? null,
];
foreach ($requiredNumbers as $name => $value) {
    if (!is_numeric($value)) {
        fwrite(STDERR, "Snapshot requires numeric {$name}.\n");
        exit(1);
    }
}

$plan = (new PortfolioEmergencyDeRiskPolicy())->plan(
    (float) $requiredNumbers['equity'],
    array_values($positions),
    (float) $requiredNumbers['trigger_ratio'],
    (float) $requiredNumbers['target_ratio'],
    (float) $requiredNumbers['max_reduction_fraction_per_position'],
    (float) $requiredNumbers['minimum_remaining_quantity'],
);

echo json_encode([
    'tool' => 'research_emergency_de_risk',
    'network_used' => false,
    'orders_submitted' => false,
    'plan' => $plan,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
