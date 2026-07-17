<?php

declare(strict_types=1);

return [
    'run_id' => 'hybrid-v4-paper-2026-07-17',
    'profile' => 'causal-stock-rotation-hybrid-v4',
    'enabled' => true,
    'paper_only' => true,
    'live_review_not_before' => '2026-08-17',

    // The five-year selection remains the frozen SIP replay. The paper-only
    // Alpaca entitlement supplies IEX for bars that arrive after this cutoff.
    // A signal is blocked unless the splice validator proves complete,
    // monotonic coverage and an acceptable overlap at the boundary.
    'data' => [
        'historical_feed' => 'sip',
        'historical_cutoff' => '2026-07-15',
        'historical_snapshot_sha256' => '47a111363eff1ad594c458f284a5c0d3140399bcbac5b50472bffbda7c6a5863',
        'fresh_feed' => 'iex',
        'overlap_policy' => 'reject',
        'overlap_sessions' => 0,
        'cache_namespace' => 'alpaca-causal-tactical-rotation-v1-sip-split-1day',
        'fresh_cache_namespace' => 'alpaca-causal-tactical-rotation-v1-iex-split-1day-after-20260715',
        // Audit-only overlap: these IEX bars stop at the frozen cutoff and
        // are never eligible for the replay/decision series. The deliberately
        // wide limits detect session, adjustment, or feed corruption without
        // pretending that IEX OHLCV must equal consolidated SIP prints.
        'cross_feed_audit' => [
            'mode' => 'audit_only_cutoff_overlap_v1',
            'enabled' => true,
            'sessions' => 5,
            'price_tolerance_bps' => [
                'open' => 150.0,
                'high' => 150.0,
                'low' => 150.0,
                'close' => 50.0,
            ],
            'minimum_iex_to_sip_volume_ratio' => 0.001,
            'maximum_iex_to_sip_volume_ratio' => 0.50,
            'require_all_symbols' => true,
        ],
    ],

    // MOO/OPG orders are whole-share only. Entries are never chased after the
    // opening window. A broker-clock-confirmed DAY fallback is reserved for
    // risk-reducing sells through 15:45 New York; replacement buys remain
    // bounded to 09:30-09:32 and require every dependency sell to be filled.
    'execution' => [
        'time_in_force' => 'opg',
        'order_type' => 'market',
        'preopen_start' => '09:15',
        'preopen_cutoff' => '09:27',
        'postopen_rotation_cutoff' => '09:32',
        'risk_exit_day_cutoff' => '15:45',
        'evening_queue_start' => '19:05',
        'signal_refresh_after' => '16:20',
        'signal_refresh_before' => '23:30',
        'monitor_interval_seconds' => 15,
        'flat_handoff_stability_seconds' => 120,
        'ambiguous_retry_delay_seconds' => 120,
        'ambiguous_max_attempts' => 3,
        'ambiguous_missed_window_confirmations' => 2,
        'position_tolerance_shares' => 0.000001,
        'maximum_target_gross' => 1.20,
    ],
];
