<?php

declare(strict_types=1);

use FulltimeTrading\Backtest\WalkForwardSelector;

require __DIR__ . '/../bootstrap.php';

function wfAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%s: expected %s, got %s',
            $message,
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}

/** @return array<string, mixed> */
function wfCandidate(string $name, float $ann, float $dd, float $gross = 2.0, int $maxOpen = 4): array
{
    return [
        'variant' => $name,
        'params' => [
            'max_gross' => $gross,
            'max_open' => $maxOpen,
            'support_entry_signal_mode' => 'advance_next_session',
            'order_fill_mode' => 'intraday_touch_reclaim',
        ],
        'training' => [
            'points' => 500,
            'trades' => 100,
            'annualized_return_pct' => $ann,
            'max_drawdown_pct' => $dd,
            'trade_metrics' => [
                'best_trade_gross_profit_share_pct' => 0.15,
                'top_5_trades_gross_profit_share_pct' => 0.45,
                'top_symbol_gross_profit_share_pct' => 0.40,
                'pnl_without_top_5_trades' => 1000.0,
            ],
        ],
        // Deliberately ignored by the selector. This guards against leakage.
        'post_split_annualized_return_pct' => -10.0,
        'full_period_score' => 999.0,
    ];
}

$policy = [
    'min_train_trades' => 50,
    'min_train_annualized_return_pct' => 0.40,
    'max_train_drawdown_pct' => 0.35,
    'max_best_trade_gross_profit_share_pct' => 0.25,
    'max_top_5_trades_gross_profit_share_pct' => 0.60,
    'max_top_symbol_gross_profit_share_pct' => 0.65,
];
$selector = new WalkForwardSelector();
$candidates = [
    wfCandidate('alpha', 0.60, -0.20),
    wfCandidate('beta', 0.70, -0.30),
];
$selected = $selector->select($candidates, $policy);
wfAssertSame('alpha', $selected['selected_variant'], 'best train-only score wins');

$candidates[0]['post_split_annualized_return_pct'] = 1000.0;
$candidates[0]['full_period_score'] = -1000.0;
$candidates[1]['post_split_annualized_return_pct'] = -1000.0;
$candidates[1]['full_period_score'] = 1000.0;
$selectedAfterPostMutation = $selector->select($candidates, $policy);
wfAssertSame('alpha', $selectedAfterPostMutation['selected_variant'], 'post/full mutations cannot alter selection');

$tie = $selector->select([
    wfCandidate('zeta', 0.60, -0.20),
    wfCandidate('aardvark', 0.60, -0.20),
], $policy);
wfAssertSame('aardvark', $tie['selected_variant'], 'tie breaks deterministically by name');

$enveloped = $selector->select([
    wfCandidate('high-risk', 0.90, -0.20, 2.5, 5),
    wfCandidate('production', 0.60, -0.20, 2.0, 4),
], $policy, ['max_gross' => 2.0, 'max_open' => 4]);
wfAssertSame('production', $enveloped['selected_variant'], 'production envelope is applied before selection');

$executionEnvelope = [
    'allowed_signal_fill_pairs' => ['advance_next_session:intraday_touch_reclaim'],
];
$causalExecution = $selector->evaluate(wfCandidate('causal', 0.60, -0.20), $policy, $executionEnvelope);
wfAssertSame(true, $causalExecution['passes'], 'allowlisted causal execution contract is accepted');

$sameDayTouch = wfCandidate('same-day-touch', 0.60, -0.20);
$sameDayTouch['params']['support_entry_signal_mode'] = 'touch_confirmed';
$sameDayTouch['params']['order_fill_mode'] = 'same_day_touch';
$sameDayEvaluation = $selector->evaluate($sameDayTouch, $policy, $executionEnvelope);
wfAssertSame(false, $sameDayEvaluation['passes'], 'same-day touch execution is rejected by the production contract');
wfAssertSame(
    true,
    in_array('production_execution_contract', $sameDayEvaluation['failures'], true),
    'execution-contract failure is explicit',
);

$frozen = [
    'selected_variant' => 'alpha',
    'selected_training' => [
        'annualized_return_pct' => 1.20,
        'max_drawdown_pct' => -0.20,
    ],
    'data_quality_passes' => true,
    'selected_full_period_data_quality_passes' => true,
    'selected_full_period_data_quality_failures' => [],
    'selected_full_period_max_observed_or_bounded_gross_exposure' => 1.20,
    'frozen_oos_evaluation' => [
        'annualized_return_pct' => 1.10,
        'max_drawdown_pct' => -0.25,
        'passes' => true,
        'failures' => [],
        'data_quality_passes' => true,
        'data_quality_failures' => [],
        'max_observed_or_bounded_gross_exposure' => 1.25,
    ],
];
$qualified = $selector->qualifyFrozen($frozen, 1.0, 0.35, ['max_observed_gross' => 1.30]);
wfAssertSame(true, $qualified['historically_qualified'], 'A clean frozen train/OOS result qualifies.');
wfAssertSame('alpha', $qualified['historically_qualified_variant'], 'Only the frozen train choice may qualify.');

$holdoutDqFailure = $frozen;
$holdoutDqFailure['frozen_oos_evaluation']['data_quality_passes'] = false;
$holdoutDqFailure['frozen_oos_evaluation']['data_quality_failures'] = ['candidate_session_gap'];
$rejectedForHoldoutDq = $selector->qualifyFrozen(
    $holdoutDqFailure,
    1.0,
    0.35,
    ['max_observed_gross' => 1.30],
);
wfAssertSame(false, $rejectedForHoldoutDq['historically_qualified'], 'Frozen OOS DQ failure rejects qualification.');
wfAssertSame('alpha', $rejectedForHoldoutDq['selected_variant'], 'OOS DQ failure cannot trigger fallback selection.');
wfAssertSame(
    true,
    in_array('holdout_data_quality:candidate_session_gap', $rejectedForHoldoutDq['historical_qualification_failures'], true),
    'Frozen OOS DQ reason is preserved.',
);

$holdoutGrossFailure = $frozen;
$holdoutGrossFailure['frozen_oos_evaluation']['max_observed_or_bounded_gross_exposure'] = 1.31;
$rejectedForHoldoutGross = $selector->qualifyFrozen(
    $holdoutGrossFailure,
    1.0,
    0.35,
    ['max_observed_gross' => 1.30],
);
wfAssertSame(
    true,
    in_array('holdout_max_observed_gross', $rejectedForHoldoutGross['historical_qualification_failures'], true),
    'Holdout observed-gross breach rejects the frozen variant.',
);

$fullGrossFailure = $frozen;
$fullGrossFailure['selected_full_period_max_observed_or_bounded_gross_exposure'] = 1.31;
$rejectedForFullGross = $selector->qualifyFrozen(
    $fullGrossFailure,
    1.0,
    0.35,
    ['max_observed_gross' => 1.30],
);
wfAssertSame(
    true,
    in_array('full_period_max_observed_gross', $rejectedForFullGross['historical_qualification_failures'], true),
    'Full-period observed-gross breach rejects the frozen variant.',
);

$concentrated = wfCandidate('concentrated', 0.90, -0.20);
$concentrated['training']['trade_metrics']['best_trade_gross_profit_share_pct'] = 0.50;
$evaluation = $selector->evaluate($concentrated, $policy);
wfAssertSame(false, $evaluation['passes'], 'concentrated training result is rejected');
wfAssertSame(true, in_array('train_best_trade_concentration', $evaluation['failures'], true), 'concentration failure is explicit');

echo "Walk-forward selector OK\n";
