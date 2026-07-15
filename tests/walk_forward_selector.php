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
        'params' => ['max_gross' => $gross, 'max_open' => $maxOpen],
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

$concentrated = wfCandidate('concentrated', 0.90, -0.20);
$concentrated['training']['trade_metrics']['best_trade_gross_profit_share_pct'] = 0.50;
$evaluation = $selector->evaluate($concentrated, $policy);
wfAssertSame(false, $evaluation['passes'], 'concentrated training result is rejected');
wfAssertSame(true, in_array('train_best_trade_concentration', $evaluation['failures'], true), 'concentration failure is explicit');

echo "Walk-forward selector OK\n";
