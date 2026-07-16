<?php

declare(strict_types=1);

use FulltimeTrading\Strategy\ResearchProfileSafetyGate;

require __DIR__ . '/../bootstrap.php';

$profiles = require __DIR__ . '/../config/research_strategy_profiles.php';
$profile = $profiles['advance-touch-alpaca-20260716'] ?? null;

if (!is_array($profile)) {
    throw new RuntimeException('The selected advance-touch research profile is missing.');
}
if (($profile['production_approved'] ?? null) !== false) {
    throw new RuntimeException('A research-only profile must fail closed for production approval.');
}

$options = $profile['options'] ?? [];
$expected = [
    'provider' => 'alpaca',
    'feed' => 'iex',
    'symbols' => 'UPRO,TQQQ,SOXL,TECL',
    'family-cap' => '0.44',
    'support-min-touches' => '5',
    'support-entry-signal-mode' => 'advance_next_session',
    'advance-require-untouched' => 'true',
    'advance-level-projection' => 'dynamic_exact',
    'order-fill-mode' => 'next_touch',
    'support-target-atr-multiple' => '2.70',
    'partial-take-profit-pct' => '0.50',
    'break-even-profit-pct' => '0.05',
    'transaction-cost-bps' => '10',
];
foreach ($expected as $key => $value) {
    if (($options[$key] ?? null) !== $value) {
        throw new RuntimeException(sprintf('Unexpected research profile value for %s.', $key));
    }
}

$unsafeStrategy = [
    'entry_submission_enabled' => true,
    'entry_submission_block_reason' => '',
];
$gatedStrategy = (new ResearchProfileSafetyGate())->apply($unsafeStrategy, $profile);
if (($gatedStrategy['entry_submission_enabled'] ?? true) !== false) {
    throw new RuntimeException('A non-approved research profile must disable entry submission even when the environment enables it.');
}
if (($gatedStrategy['entry_submission_block_reason'] ?? '') !== 'research_profile_not_production_approved') {
    throw new RuntimeException('The research profile gate must expose its explicit block reason.');
}

echo "Research strategy profile OK\n";
