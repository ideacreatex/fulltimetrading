<?php

declare(strict_types=1);

use FulltimeTrading\Backtest\CausalTacticalRotationBacktester;
use FulltimeTrading\Backtest\TacticalRotationQualification;
use FulltimeTrading\Domain\Bar;

require __DIR__ . '/../bootstrap.php';

function tacticalAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @param list<float> $closes @return list<Bar> */
function tacticalBars(string $symbol, array $closes): array
{
    $bars = [];
    $date = new DateTimeImmutable('2024-01-01', new DateTimeZone('America/New_York'));
    foreach ($closes as $index => $close) {
        $open = $index === 0 ? $close : $closes[$index - 1];
        $bars[] = new Bar(
            $symbol,
            $date,
            (float) $open,
            max((float) $open, (float) $close) * 1.001,
            min((float) $open, (float) $close) * 0.999,
            (float) $close,
            10_000_000.0,
        );
        $date = $date->modify('+1 day');
    }

    return $bars;
}

$config = [
    'benchmark' => 'QQQ',
    'universe' => ['AAA', 'BBB'],
    'factor_weights' => [1 => 1.0],
    'volatility_period' => 2,
    'volatility_score_power' => 0.0,
    'volatility_target' => 100.0,
    'max_gross' => 1.0,
    'rebalance_sessions' => 1,
    'benchmark_sma_period' => 2,
    'dollar_volume_period' => 1,
    'min_dollar_volume' => 0.0,
    'minimum_history_sessions' => 2,
    'require_positive_score' => true,
    'drawdown_kill_pct' => 0.90,
    'drawdown_cooldown_sessions' => 2,
    'cost_bps' => 0.0,
    'margin_rate_annual' => 0.0,
];
$baselineBars = [
    'QQQ' => tacticalBars('QQQ', [100, 101, 102, 103, 104, 105]),
    'AAA' => tacticalBars('AAA', [100, 108, 121, 133, 134, 135]),
    'BBB' => tacticalBars('BBB', [100, 101, 102, 103, 113, 114]),
];
$changedBars = $baselineBars;
$changedBars['AAA'] = tacticalBars('AAA', [100, 108, 121, 90, 134, 135]);

$backtester = new CausalTacticalRotationBacktester($config);
$validatedConfig = $backtester->config();
tacticalAssert(
    (float) $validatedConfig['position_dynamic_trailing_daily_vol_multiple'] === 0.0
        && (float) $validatedConfig['position_dynamic_trailing_min_pct'] === 0.0
        && (float) $validatedConfig['position_dynamic_trailing_max_pct'] === 0.0
        && $validatedConfig['signal_market_filter']['symbol'] === 'QQQ'
        && (int) $validatedConfig['signal_market_filter']['sma_period'] === 2,
    'Dynamic trailing must default off and the signal filter must inherit the benchmark SMA.',
);
$baseline = $backtester->run($baselineBars, '2024-01-01', '2024-01-06', 100.0);
$changed = $backtester->run($changedBars, '2024-01-01', '2024-01-06', 100.0);
$baselineByDate = array_column($baseline['curve'], null, 'date');
$changedByDate = array_column($changed['curve'], null, 'date');

tacticalAssert(
    $baselineByDate['2024-01-04']['holding'] === 'AAA'
        && $changedByDate['2024-01-04']['holding'] === 'AAA',
    'Changing a session close must not change the position already chosen for that session open.',
);
tacticalAssert(
    $baselineByDate['2024-01-04']['signal_date'] === '2024-01-03'
        && $changedByDate['2024-01-04']['signal_date'] === '2024-01-03',
    'Every rebalance must identify the completed prior close that produced it.',
);
tacticalAssert(
    $baselineByDate['2024-01-05']['holding'] === 'AAA'
        && $changedByDate['2024-01-05']['holding'] === 'BBB',
    'A changed close may affect only the following session target.',
);
tacticalAssert(
    (float) $baseline['curve'][0]['gross_high_notional'] === 0.0
        && (float) $baseline['curve'][0]['pre_execution_equity'] === 0.0
        && (float) $baseline['curve'][0]['turnover_notional'] === 0.0,
    'The non-executing initial curve row must expose zero exact notional fields.',
);
foreach (array_slice($baseline['curve'], 1) as $row) {
    tacticalAssert(
        abs(
            (float) $row['turnover_notional']
            - (float) $row['turnover'] * (float) $row['pre_execution_equity'],
        ) < 1.0e-10,
        'Child turnover notional must use the exact pre-execution sleeve equity.',
    );
}

$signalFilterConfig = array_replace($config, [
    'benchmark' => 'SPY',
    'signal_market_filter' => ['symbol' => 'QQQ', 'sma_period' => 2],
    'market_context' => ['symbol' => 'SPY', 'sma_period' => 2, 'risk_off_multiplier' => 1.0],
    'universe' => ['AAA'],
    'benchmark_volatility_target' => 0.20,
    'volatility_target' => 100.0,
    'max_gross' => 1.25,
]);
$signalFilterBars = [
    'SPY' => tacticalBars('SPY', [100, 110, 90]),
    'QQQ' => tacticalBars('QQQ', [100, 101, 102]),
    'AAA' => tacticalBars('AAA', [100, 102, 105]),
];
$volatileSignalFilterBars = $signalFilterBars;
$volatileSignalFilterBars['QQQ'] = tacticalBars('QQQ', [100, 200, 201]);
$filtered = (new CausalTacticalRotationBacktester($signalFilterConfig))
    ->run($signalFilterBars, '2024-01-01', '2024-01-03', 100.0);
$filteredWithDifferentQqqVolatility = (new CausalTacticalRotationBacktester($signalFilterConfig))
    ->run($volatileSignalFilterBars, '2024-01-01', '2024-01-03', 100.0);
tacticalAssert(
    $filtered['next_target']['symbol'] === 'AAA'
        && (float) $filtered['next_target']['gross'] > 0.0
        && abs(
            (float) $filtered['next_target']['gross']
            - (float) $filteredWithDifferentQqqVolatility['next_target']['gross'],
        ) < 1.0e-12,
    'QQQ may causally enable the signal while SPY remains the unchanged volatility-sizing benchmark.',
);

$invalidSignalMarketFilters = [
    'QQQ',
    ['symbol' => '', 'sma_period' => 2],
    ['symbol' => 'QQQ', 'sma_period' => 1],
];
foreach ($invalidSignalMarketFilters as $invalidSignalMarketFilter) {
    $rejected = false;
    try {
        new CausalTacticalRotationBacktester(array_replace($config, [
            'signal_market_filter' => $invalidSignalMarketFilter,
        ]));
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    tacticalAssert($rejected, 'Invalid signal market filters must fail closed.');
}

$scheduledBacktester = new CausalTacticalRotationBacktester(array_replace($config, ['rebalance_sessions' => 3]));
$scheduled = $scheduledBacktester->run($baselineBars, '2024-01-01', '2024-01-06', 100.0);
tacticalAssert(
    $scheduled['next_target']['rebalance_due_next_session'] === false
        && $scheduled['next_target']['action'] === 'hold'
        && $scheduled['next_target']['symbol'] === null
        && (float) $scheduled['next_target']['gross'] === 0.0
        && $scheduled['next_target']['ranked_symbol'] === 'BBB',
    'A fresh ranking must not be advertised as an executable next-open trade before its rebalance session.',
);

$tieBars = [
    'QQQ' => tacticalBars('QQQ', [100, 101, 102, 103, 104, 105]),
    'AAA' => tacticalBars('AAA', [100, 101, 103, 106, 110, 115]),
    'BBB' => tacticalBars('BBB', [100, 101, 103, 106, 110, 115]),
];
$tieForward = (new CausalTacticalRotationBacktester(array_replace($config, [
    'universe' => ['AAA', 'BBB'],
])))->run($tieBars, '2024-01-01', '2024-01-06', 100.0);
$tieReverse = (new CausalTacticalRotationBacktester(array_replace($config, [
    'universe' => ['BBB', 'AAA'],
])))->run($tieBars, '2024-01-01', '2024-01-06', 100.0);
tacticalAssert(
    $tieForward === $tieReverse
        && in_array('AAA', array_column($tieForward['curve'], 'holding'), true),
    'Equal scores must use a deterministic symbol tie-break independent of universe order.',
);

$trailingBacktester = new CausalTacticalRotationBacktester(array_replace($config, [
    'rebalance_sessions' => 1,
    'position_trailing_close_pct' => 0.10,
    'position_exit_cooldown_sessions' => 2,
]));
$trailing = $trailingBacktester->run($changedBars, '2024-01-01', '2024-01-06', 100.0);
$trailingByDate = array_column($trailing['curve'], null, 'date');
tacticalAssert(
    $trailingByDate['2024-01-04']['risk_signal'] === 'position_trailing_close'
        && $trailingByDate['2024-01-05']['holding'] === null,
    'A close through the frozen trailing threshold must queue a causal next-open exit.',
);

$dynamicTrailingBacktester = new CausalTacticalRotationBacktester(array_replace($config, [
    'rebalance_sessions' => 1,
    'position_trailing_close_pct' => 0.0,
    'position_dynamic_trailing_daily_vol_multiple' => 0.25,
    'position_dynamic_trailing_min_pct' => 0.0,
    'position_dynamic_trailing_max_pct' => 0.50,
    'position_exit_cooldown_sessions' => 2,
]));
$dynamicTrailing = $dynamicTrailingBacktester->run(
    $changedBars,
    '2024-01-01',
    '2024-01-06',
    100.0,
);
$dynamicTrailingByDate = array_column($dynamicTrailing['curve'], null, 'date');
tacticalAssert(
    $changedByDate['2024-01-04']['risk_signal'] === null
        && $dynamicTrailingByDate['2024-01-04']['risk_signal'] === 'position_trailing_close'
        && $dynamicTrailingByDate['2024-01-04']['holding'] === 'AAA'
        && $dynamicTrailingByDate['2024-01-05']['holding'] === null,
    'Completed-day annualized volatility must be converted to daily volatility and queue only a next-open exit.',
);

$invalidDynamicTrailingConfigs = [
    ['position_dynamic_trailing_daily_vol_multiple' => -0.01],
    ['position_dynamic_trailing_daily_vol_multiple' => INF],
    ['position_dynamic_trailing_min_pct' => -0.01],
    ['position_dynamic_trailing_max_pct' => 1.0],
    [
        'position_dynamic_trailing_min_pct' => 0.20,
        'position_dynamic_trailing_max_pct' => 0.10,
    ],
    [
        'position_dynamic_trailing_daily_vol_multiple' => 1.0,
        'position_dynamic_trailing_max_pct' => 0.0,
    ],
];
foreach ($invalidDynamicTrailingConfigs as $invalidDynamicTrailingConfig) {
    $rejected = false;
    try {
        new CausalTacticalRotationBacktester(array_replace($config, $invalidDynamicTrailingConfig));
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    tacticalAssert($rejected, 'Invalid dynamic trailing configuration must fail closed.');
}

$repeatDrawdownBars = [
    'QQQ' => tacticalBars('QQQ', [100, 101, 102, 103, 104, 105, 106, 107, 108, 109]),
    'AAA' => tacticalBars('AAA', [100, 101, 102, 103, 90, 91, 95, 83, 84, 85]),
];
$repeatDrawdownConfig = array_replace($config, [
    'universe' => ['AAA'],
    'market_context' => ['symbol' => 'QQQ', 'sma_period' => 2, 'risk_off_multiplier' => 1.0],
    'benchmark_volatility_target' => 0.0,
    'rebalance_sessions' => 1,
    'position_trailing_close_pct' => 0.0,
    'position_exit_cooldown_sessions' => 1,
    'drawdown_kill_pct' => 0.10,
    'drawdown_cooldown_sessions' => 1,
    'drawdown_rearm_after_cooldown' => true,
]);
$repeatDrawdown = (new CausalTacticalRotationBacktester($repeatDrawdownConfig))
    ->run($repeatDrawdownBars, '2024-01-01', '2024-01-10', 100.0);
$repeatByDate = array_column($repeatDrawdown['curve'], null, 'date');
tacticalAssert(
    $repeatByDate['2024-01-05']['risk_signal'] === 'portfolio_drawdown'
        && $repeatByDate['2024-01-06']['holding'] === null
        && $repeatByDate['2024-01-08']['risk_signal'] === 'portfolio_drawdown'
        && $repeatDrawdown['circuit_activations'] === 2,
    'A portfolio drawdown circuit must rearm after cooldown and protect a new equity epoch.',
);

$manualCurve = [
    [
        'date' => '2024-01-01',
        'start_equity' => 100.0,
        'equity' => 110.0,
        'equity_low' => 99.0,
        'equity_high' => 111.0,
        'gross_bound' => 1.0,
        'turnover' => 1.0,
        'holding' => 'AAA',
    ],
    [
        'date' => '2024-01-02',
        'period_start_date' => '2023-12-29',
        'start_equity' => 110.0,
        'equity' => 121.0,
        'equity_low' => 109.0,
        'equity_high' => 122.0,
        'gross_bound' => 1.0,
        'turnover' => 0.0,
        'holding' => 'AAA',
    ],
];
$secondDay = $backtester->metrics($manualCurve, '2024-01-02');
tacticalAssert(
    abs((float) $secondDay['return'] - 0.10) < 1.0e-12,
    'A sliced OOS period must include its first session return from start_equity.',
);
tacticalAssert(
    abs((float) $secondDay['cagr'] - (1.10 ** (365.25 / 4.0) - 1.0)) < 1.0e-9,
    'A sliced CAGR must annualize from the prior-close capital boundary, including a weekend gap.',
);

$switchAttribution = $backtester->metrics([
    [
        'date' => '2024-02-01',
        'start_equity' => 100.0,
        'equity' => 110.0,
        'equity_low' => 99.0,
        'equity_high' => 111.0,
        'gross_bound' => 1.0,
        'turnover' => 2.0,
        'holding' => 'BBB',
        'return_symbol' => 'BBB',
        'episode_pnl_segments' => [
            ['symbol' => 'AAA', 'pnl' => 1.0],
            ['symbol' => 'BBB', 'pnl' => 9.0],
        ],
    ],
    [
        'date' => '2024-02-02',
        'start_equity' => 110.0,
        'equity' => 112.0,
        'equity_low' => 109.0,
        'equity_high' => 113.0,
        'gross_bound' => 1.0,
        'turnover' => 0.0,
        'holding' => 'BBB',
        'return_symbol' => 'BBB',
        'episode_pnl_segments' => [
            ['symbol' => 'BBB', 'pnl' => 2.0],
        ],
    ],
]);
tacticalAssert(
    (int) $switchAttribution['positive_holding_episodes'] === 2
        && (int) $switchAttribution['return_symbols'] === 2
        && abs((float) $switchAttribution['top1_positive_episode_share'] - 11.0 / 12.0) < 1.0e-12,
    'Switch-day concentration must split old-position and new-position P/L at the execution open.',
);

$profile = require __DIR__ . '/../config/tactical_rotation.php';
tacticalAssert(
    ($profile['production_approved'] ?? true) === false
        && ($profile['order_submission_enabled'] ?? true) === false
        && ($profile['paper_shadow_enabled'] ?? false) === true,
    'The tactical candidate must remain paper-shadow only and fail closed for order submission.',
);
tacticalAssert(
    (float) $profile['validation']['minimum_train_ex_top5_days_cagr'] >= 0.40
        && (float) $profile['validation']['minimum_validation_ex_top5_days_cagr'] >= 0.40
        && (float) $profile['validation']['minimum_holdout_ex_top5_days_cagr'] >= 0.20
        && (float) $profile['validation']['maximum_holdout_top1_positive_day_share'] <= 0.15
        && (float) $profile['validation']['maximum_full_top1_positive_episode_share'] <= 0.15
        && (int) $profile['validation']['minimum_full_positive_holding_episodes'] >= 50
        && (int) $profile['validation']['minimum_full_return_symbols'] >= 15,
    'Every evaluation segment must reject a result dominated by its best sessions.',
);

$passingMetrics = [
    'cagr' => 1.20,
    'max_drawdown' => -0.20,
    'max_gross_bound' => 1.20,
    'top1_positive_day_share' => 0.05,
    'top5_positive_day_share' => 0.15,
    'ex_top5_days_cagr' => 0.60,
    'positive_holding_episodes' => 60,
    'top1_positive_episode_share' => 0.10,
    'return_symbols' => 20,
    'annualized_turnover' => 30.0,
];
$qualification = new TacticalRotationQualification((array) $profile['validation']);
$passingQualification = $qualification->evaluate(
    $passingMetrics,
    $passingMetrics,
    $passingMetrics,
    $passingMetrics,
    ['2024' => ['return' => 1.0, 'annualized_turnover' => 30.0]],
);
tacticalAssert(
    $passingQualification['qualifies'] === true
        && $passingQualification['failed_gates'] === [],
    'A metrics set satisfying every frozen historical gate must qualify.',
);
$concentratedHoldout = $passingMetrics;
$concentratedHoldout['top1_positive_day_share'] = 0.20;
$failedQualification = $qualification->evaluate(
    $passingMetrics,
    $passingMetrics,
    $concentratedHoldout,
    $passingMetrics,
    ['2024' => ['return' => 1.0, 'annualized_turnover' => 30.0]],
);
tacticalAssert(
    $failedQualification['qualifies'] === false
        && in_array('holdout_top1_day_share', $failedQualification['failed_gates'], true),
    'A concentrated holdout must fail explicitly instead of being diluted by full-period metrics.',
);

echo "Causal tactical rotation backtester OK\n";
