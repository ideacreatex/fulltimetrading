<?php

declare(strict_types=1);

$fullUniverse = [
    'AAPL',
    'MSFT',
    'NVDA',
    'AMZN',
    'META',
    'GOOGL',
    'TSLA',
    'AVGO',
    'AMD',
    'MU',
    'MRVL',
    'ANET',
    'PLTR',
    'SMCI',
    'MSTR',
    'COIN',
    'CRWD',
    'PANW',
    'DELL',
    'WDC',
];

return [
    'profile' => 'causal-stock-rotation-hybrid-v4',
    'status' => 'research_candidate_paper_shadow',
    'production_approved' => false,
    'paper_shadow_enabled' => true,
    'order_submission_enabled' => false,
    'order_submission_block_reason' => 'hybrid ensemble paper-forward shadow required before tactical rotation entries',

    // SPY supplies the portfolio-volatility throttle. Each sleeve freezes its
    // own signal-market filter below; the two concepts must not be coupled.
    'benchmark' => 'SPY',
    'signal_market_filter' => [
        'symbol' => 'SPY',
        'sma_period' => 200,
    ],
    'market_context' => [
        'symbol' => 'QQQ',
        'sma_period' => 50,
        'risk_off_multiplier' => 1.0,
    ],
    'universe' => $fullUniverse,
    'factor_weights' => [
        5 => -2.0,
        20 => 1.0,
        60 => 1.0,
        90 => 2.0,
        120 => -1.0,
        // A zero-weight 252-session term deliberately requires complete
        // one-year history without changing the score itself.
        252 => 0.0,
    ],
    'volatility_period' => 20,
    'volatility_score_power' => 1.0,
    'volatility_target' => 0.45,
    'benchmark_volatility_target' => 0.28,
    'max_gross' => 1.18,
    'rebalance_sessions' => 3,
    'rebalance_schedule' => 'fixed',
    'benchmark_sma_period' => 200,
    'asset_sma_period' => 0,
    'dollar_volume_period' => 20,
    'min_dollar_volume' => 5_000_000.0,
    'minimum_history_sessions' => 253,
    'require_positive_score' => true,
    'position_trailing_close_pct' => 0.225,
    'position_dynamic_trailing_daily_vol_multiple' => 0.0,
    'position_dynamic_trailing_min_pct' => 0.0,
    'position_dynamic_trailing_max_pct' => 0.0,
    'position_exit_cooldown_sessions' => 7,
    'drawdown_kill_pct' => 0.15,
    'drawdown_cooldown_sessions' => 50,
    'drawdown_rearm_after_cooldown' => true,
    'cost_bps' => 20.0,
    'margin_rate_annual' => 0.0625,

    // Independent static-capital books: no cross-sleeve transfers and no
    // optimistic cost netting. The 60/40 frontier point is the most profitable
    // frozen weight that passes the <=15% single-episode concentration ceiling
    // at both the base 20 bps and required 30 bps stress costs.
    'sleeves' => [
        'dynamic_loo10' => [
            'allocation' => 0.60,
            'config' => [
                'signal_market_filter' => ['symbol' => 'SPY', 'sma_period' => 200],
                'market_context' => [
                    'symbol' => 'QQQ',
                    'sma_period' => 50,
                    'risk_off_multiplier' => 0.85,
                ],
                'universe' => $fullUniverse,
                'volatility_target' => 0.49,
                'benchmark_volatility_target' => 0.29,
                'max_gross' => 1.16,
                'position_trailing_close_pct' => 0.225,
                'position_dynamic_trailing_daily_vol_multiple' => 4.0,
                'position_dynamic_trailing_min_pct' => 0.12,
                'position_dynamic_trailing_max_pct' => 0.25,
                'position_exit_cooldown_sessions' => 8,
                'drawdown_kill_pct' => 0.19,
                'drawdown_cooldown_sessions' => 40,
                'drawdown_rearm_after_cooldown' => true,
            ],
        ],
        'qqq200_full' => [
            'allocation' => 0.13333333333333333,
            'config' => [
                'signal_market_filter' => ['symbol' => 'QQQ', 'sma_period' => 200],
                'universe' => $fullUniverse,
            ],
        ],
        'spy200_full' => [
            'allocation' => 0.13333333333333333,
            'config' => [
                'signal_market_filter' => ['symbol' => 'SPY', 'sma_period' => 200],
                'universe' => $fullUniverse,
            ],
        ],
        'qqq150_ex_crypto' => [
            'allocation' => 0.13333333333333333,
            'config' => [
                'signal_market_filter' => ['symbol' => 'QQQ', 'sma_period' => 150],
                'universe' => array_values(array_diff($fullUniverse, ['MSTR', 'COIN'])),
            ],
        ],
    ],

    'validation' => [
        'train_start' => '2021-01-04',
        'validation_start' => '2024-01-01',
        'holdout_start' => '2026-01-01',
        'minimum_train_cagr' => 1.0,
        'minimum_validation_cagr' => 1.0,
        'minimum_holdout_cagr' => 1.0,
        'maximum_drawdown' => 0.35,
        'maximum_gross_bound' => 1.30,
        'maximum_negative_years' => 1,
        'maximum_full_top1_positive_day_share' => 0.10,
        'maximum_full_top5_positive_day_share' => 0.30,
        'maximum_holdout_top1_positive_day_share' => 0.15,
        'maximum_holdout_top5_positive_day_share' => 0.35,
        'minimum_train_ex_top5_days_cagr' => 0.40,
        'minimum_validation_ex_top5_days_cagr' => 0.40,
        'minimum_holdout_ex_top5_days_cagr' => 0.20,
        'minimum_train_positive_holding_episodes' => 25,
        'minimum_validation_positive_holding_episodes' => 15,
        'minimum_full_positive_holding_episodes' => 50,
        'maximum_train_top1_positive_episode_share' => 0.20,
        'maximum_validation_top1_positive_episode_share' => 0.20,
        'maximum_full_top1_positive_episode_share' => 0.15,
        'minimum_train_return_symbols' => 10,
        'minimum_validation_return_symbols' => 10,
        'minimum_full_return_symbols' => 15,
        'maximum_full_annualized_turnover' => 50.0,
        // Every trade is already debited at 20/30 bps without sleeve netting.
        // 80x is an independent live-review kill ceiling with >15% headroom
        // over the frozen 69.51x historical maximum, not a free-cost waiver.
        'maximum_single_year_annualized_turnover' => 80.0,
        'required_cost_stress_bps' => 30.0,
    ],
];
