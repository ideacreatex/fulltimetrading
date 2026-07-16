<?php

declare(strict_types=1);

/**
 * Named research profiles are deliberately separate from config/config.php.
 * They may be used by reporting and validation tools, but must never enable
 * order submission or silently replace the deployed strategy defaults.
 *
 * @return array<string, array<string, mixed>>
 */
return [
    'advance-touch-alpaca-20260716' => [
        'status' => 'rejected_below_100pct_cagr_floor',
        'production_approved' => false,
        'validated_through' => '2026-07-15',
        'data_source' => 'Alpaca IEX, split-adjusted daily bars; fail-closed IEX 1-minute execution validation',
        'notes' => 'Causal next-session support limit retained only as a reproducible rejected control. It is below the explicit 100% CAGR floor, has weak/concentrated 2021-2023 evidence, and must not be used for paper entries.',
        'options' => [
            'provider' => 'alpaca',
            'feed' => 'iex',
            'adjustment' => 'split',
            'cache-namespace' => 'alpaca-param-experiment-iex',
            'symbols' => 'UPRO,TQQQ,SOXL,TECL',
            'initial-cash' => '30000',
            'max-open-positions' => '4',
            'max-gross-exposure-pct' => '2.0',
            'family-cap' => '0.44',
            'support-min-touches' => '5',
            'support-min-success-rate' => '0.80',
            'support-require-close-above' => 'true',
            'support-touch-tolerance-pct' => '0.01',
            'support-near-atr-multiple' => '0.60',
            'support-stop-atr-multiple' => '1.50',
            'support-target-atr-multiple' => '2.70',
            'support-signal-cooldown-bars' => '1',
            'support-weekly-enabled' => 'true',
            'support-entry-signal-mode' => 'advance_next_session',
            'advance-max-distance-pct' => '0.05',
            'advance-max-distance-atr' => '3.0',
            'advance-min-level-slope-pct' => '0.0',
            'advance-require-untouched' => 'true',
            'advance-level-projection' => 'dynamic_exact',
            'advance-max-projection-pct' => '0.01',
            'order-valid-bars' => '1',
            'order-fill-mode' => 'next_touch',
            'partial-take-profit-pct' => '0.50',
            'swing-stop-mode' => 'mental',
            'hard-stop-fill-mode' => 'gap_open',
            'break-even-profit-pct' => '0.05',
            'break-even-trigger-mode' => 'high',
            'break-even-stop-mode' => 'hard',
            'break-even-stop-offset-pct' => '0.0',
            'reentry-cooldown-days' => '0',
            'allow-same-strength-after-days' => '45',
            'layers' => '3',
            'require-green-garden' => 'true',
            'break-even-add-on-fraction' => '0.0',
            'unstable-market-position-pct' => '0.08',
            'stable-market-score-threshold' => '2.50',
            'transaction-cost-bps' => '10',
            'min-symbol-session-coverage-pct' => '0.98',
        ],
    ],
];
