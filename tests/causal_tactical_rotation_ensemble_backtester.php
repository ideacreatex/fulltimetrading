<?php

declare(strict_types=1);

use FulltimeTrading\Backtest\CausalTacticalRotationBacktester;
use FulltimeTrading\Backtest\CausalTacticalRotationEnsembleBacktester;
use FulltimeTrading\Domain\Bar;

require __DIR__ . '/../bootstrap.php';

function ensembleAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function ensembleAssertNear(float $expected, float $actual, string $message, float $epsilon = 1.0e-10): void
{
    if (abs($expected - $actual) > $epsilon) {
        throw new RuntimeException(sprintf('%s Expected %.14f, got %.14f.', $message, $expected, $actual));
    }
}

/** @param list<float> $closes @return list<Bar> */
function ensembleBars(string $symbol, array $closes): array
{
    $bars = [];
    $date = new DateTimeImmutable('2024-01-01', new DateTimeZone('America/New_York'));
    foreach ($closes as $index => $close) {
        $open = $index === 0 ? $close : $closes[$index - 1];
        $bars[] = new Bar(
            $symbol,
            $date,
            (float) $open,
            max((float) $open, (float) $close) * 1.01,
            min((float) $open, (float) $close) * 0.99,
            (float) $close,
            10_000_000.0,
        );
        $date = $date->modify('+1 day');
    }

    return $bars;
}

$bars = [
    'QQQ' => ensembleBars('QQQ', [100, 101, 102, 103, 104, 105, 106]),
    'AAA' => ensembleBars('AAA', [100, 101, 103, 110, 120, 130, 140]),
    'BBB' => ensembleBars('BBB', [100, 100.5, 101, 102, 103, 104, 105]),
];
$baseConfig = [
    'benchmark' => 'QQQ',
    'universe' => ['AAA'],
    'factor_weights' => [1 => 1.0],
    'volatility_period' => 2,
    'volatility_score_power' => 0.0,
    'volatility_target' => 100.0,
    'benchmark_volatility_target' => 0.0,
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
$definitions = [
    'fast_qqq200' => [
        'allocation' => 0.32,
        'config' => $baseConfig,
    ],
    'slow_qqq150' => [
        'allocation' => 0.34,
        'config' => array_replace($baseConfig, ['universe' => ['BBB']]),
    ],
    'fast_spy200' => [
        'allocation' => 0.34,
        'config' => array_replace($baseConfig, ['rebalance_sessions' => 2]),
    ],
];

$initialEquity = 1000.03;
$ensemble = new CausalTacticalRotationEnsembleBacktester($definitions);
$result = $ensemble->run($bars, '2024-01-01', '2024-01-07', $initialEquity);

ensembleAssert(
    $result['capital_model'] === 'independent_static_sleeves'
        && $result['shadow_only'] === true
        && $result['order_submission_enabled'] === false,
    'The ensemble must expose only independent static shadow sleeves and no submission path.',
);
ensembleAssert(
    array_keys($result['next_targets']) === array_keys($definitions),
    'Every sleeve must retain its own next target.',
);
foreach ($result['next_targets'] as $name => $target) {
    ensembleAssert(
        $target['shadow_only'] === true
            && $target['capital_scope'] === 'independent_static_sleeve'
            && (float) $target['allocation'] === (float) $definitions[$name]['allocation'],
        'Every next target must remain sleeve-scoped and shadow-only.',
    );
}

$allocatedInitial = array_sum(array_map(
    static fn (array $sleeve): float => (float) $sleeve['initial_equity'],
    $result['sleeves'],
));
ensembleAssertNear(
    $initialEquity,
    $allocatedInitial,
    'Static sleeve capitals must sum to the exact requested initial capital.',
    0.0,
);
ensembleAssertNear(
    $initialEquity,
    (float) $result['curve'][0]['start_equity'],
    'The aggregate initial row must preserve exact capital.',
    0.0,
);
ensembleAssert(
    (float) $result['curve'][0]['gross_high_notional'] === 0.0
        && (float) $result['curve'][0]['pre_execution_equity'] === 0.0
        && (float) $result['curve'][0]['turnover_notional'] === 0.0,
    'The aggregate non-executing first row must have zero exact notionals.',
);

foreach ($result['curve'] as $index => $aggregateRow) {
    $sumStart = 0.0;
    $sumEquity = 0.0;
    $sumLowEquity = 0.0;
    $sumHighNotional = 0.0;
    $sumPreExecutionEquity = 0.0;
    $sumTurnoverNotional = 0.0;
    foreach ($result['sleeve_curves'] as $curve) {
        $row = $curve[$index];
        $attributedPnl = array_sum(array_map(
            static fn (array $segment): float => (float) $segment['pnl'],
            (array) ($row['episode_pnl_segments'] ?? []),
        ));
        ensembleAssertNear(
            (float) $row['equity'] - (float) $row['start_equity'],
            $attributedPnl,
            'Every child row must fully attribute P/L across the execution-open boundary.',
            1.0e-8,
        );
        $sumStart += (float) $row['start_equity'];
        $sumEquity += (float) $row['equity'];
        $sumLowEquity += (float) $row['equity_low'];
        $sumHighNotional += (float) $row['gross_high_notional'];
        $sumPreExecutionEquity += (float) $row['pre_execution_equity'];
        $sumTurnoverNotional += (float) $row['turnover_notional'];
    }
    ensembleAssertNear($sumStart, (float) $aggregateRow['start_equity'], 'Aggregate start equity must be a sleeve sum.');
    ensembleAssertNear($sumEquity, (float) $aggregateRow['equity'], 'Aggregate close equity must be a sleeve sum.');
    ensembleAssertNear(
        $sumHighNotional,
        (float) $aggregateRow['gross_high_notional'],
        'Same-symbol sleeve notionals must be summed without netting.',
    );
    $expectedGross = $sumHighNotional > 0.0
        ? $sumHighNotional / max(0.000001, $sumLowEquity)
        : 0.0;
    ensembleAssertNear(
        $expectedGross,
        (float) $aggregateRow['gross_bound'],
        'Combined conservative gross must be sum(high notionals) divided by sum(low equities).',
    );
    $expectedTurnover = $sumPreExecutionEquity > 0.0
        ? $sumTurnoverNotional / $sumPreExecutionEquity
        : 0.0;
    ensembleAssertNear(
        $expectedTurnover,
        (float) $aggregateRow['turnover'],
        'Combined turnover must use summed notionals over summed pre-execution equities.',
    );
}

// An isolated child replay with its frozen initial allocation must be bit-for-bit
// identical to the corresponding ensemble sleeve. This rejects hidden transfers,
// cross-sleeve target netting and periodic allocation rebalancing.
foreach ($definitions as $name => $definition) {
    $isolated = (new CausalTacticalRotationBacktester($definition['config']))->run(
        $bars,
        '2024-01-01',
        '2024-01-07',
        (float) $result['sleeves'][$name]['initial_equity'],
    );
    ensembleAssert(
        $isolated['curve'] === $result['sleeve_curves'][$name]
            && $isolated['next_target'] === array_intersect_key(
                $result['next_targets'][$name],
                $isolated['next_target'],
            ),
        'Each ensemble sleeve must be exactly reproducible as an isolated child replay.',
    );
}

$lastIndex = array_key_last($result['curve']);
$fastFinalShare = (float) $result['sleeve_curves']['fast_qqq200'][$lastIndex]['equity']
    / (float) $result['curve'][$lastIndex]['equity'];
ensembleAssert(
    abs($fastFinalShare - 0.32) > 0.005,
    'Profits must drift sleeve equity weights instead of being transferred back to target allocations.',
);

$metrics = $ensemble->metrics($result);
$metricsFromEmbeddedCurves = $ensemble->metrics($result['curve']);
ensembleAssert(
    $metrics === $metricsFromEmbeddedCurves,
    'Full-result and embedded-curve ensemble metrics must be identical.',
);
ensembleAssertNear(
    (float) $result['curve'][$lastIndex]['equity'] / $initialEquity - 1.0,
    (float) $metrics['return'],
    'Ensemble return must use the aggregate dollar curve.',
);
ensembleAssert(
    (int) $metrics['return_symbols'] === 2
        && (int) $metrics['positive_holding_episodes'] === 3
        && (float) $metrics['top1_positive_episode_share'] > 0.0
        && (float) $metrics['top1_positive_episode_share'] < 1.0,
    'Episodes must remain independent by sleeve while return symbols are deduplicated across sleeves.',
);

$aggregateReturns = array_map(
    static fn (array $row): float => (float) $row['equity'] / (float) $row['start_equity'] - 1.0,
    $result['curve'],
);
$positiveAggregateReturns = array_values(array_filter(
    $aggregateReturns,
    static fn (float $return): bool => $return > 0.0,
));
rsort($positiveAggregateReturns, SORT_NUMERIC);
$expectedTopDayShare = (float) $positiveAggregateReturns[0] / array_sum($positiveAggregateReturns);
ensembleAssertNear(
    $expectedTopDayShare,
    (float) $metrics['top1_positive_day_share'],
    'Day concentration must be measured on aggregate ensemble returns.',
);
ensembleAssertNear(
    max(array_column($result['curve'], 'gross_bound')),
    (float) $metrics['max_gross_bound'],
    'Metrics must retain the exact aggregate conservative gross maximum.',
);

$capitalWeightedEnsemble = new CausalTacticalRotationEnsembleBacktester([
    'tiny_high_return' => [
        'allocation' => 0.01,
        'config' => $baseConfig,
    ],
    'large_lower_return' => [
        'allocation' => 0.99,
        'config' => array_replace($baseConfig, ['universe' => ['BBB']]),
    ],
]);
$capitalWeightedResult = $capitalWeightedEnsemble->run(
    $bars,
    '2024-01-01',
    '2024-01-07',
    1000.0,
);
$episodeGains = [];
foreach ($capitalWeightedResult['sleeve_curves'] as $name => $curve) {
    $episodeGains[$name] = array_sum(array_map(
        static fn (array $row): float => array_sum(array_map(
            static fn (array $segment): float => (float) $segment['pnl'],
            (array) ($row['episode_pnl_segments'] ?? []),
        )),
        $curve,
    ));
}
$tinyPercentageGain = $episodeGains['tiny_high_return']
    / (float) $capitalWeightedResult['sleeves']['tiny_high_return']['initial_equity'];
$largePercentageGain = $episodeGains['large_lower_return']
    / (float) $capitalWeightedResult['sleeves']['large_lower_return']['initial_equity'];
$capitalWeightedMetrics = $capitalWeightedEnsemble->metrics($capitalWeightedResult);
ensembleAssert(
    $tinyPercentageGain > $largePercentageGain
        && $episodeGains['tiny_high_return'] < $episodeGains['large_lower_return'],
    'The fixture must distinguish percentage leadership from actual ensemble profit leadership.',
);
ensembleAssertNear(
    $episodeGains['large_lower_return'] / array_sum($episodeGains),
    (float) $capitalWeightedMetrics['top1_positive_episode_share'],
    'Episode concentration must use static-allocation dollar P/L, not unweighted sleeve percentages.',
);
ensembleAssert(
    (int) $capitalWeightedMetrics['positive_holding_episodes'] === 2,
    'Both independent positive dollar-P/L episodes must remain represented.',
);

$invalidDefinitions = [
    [
        'only' => ['allocation' => 1.0, 'config' => $baseConfig],
    ],
    [
        'one' => ['allocation' => 0.40, 'config' => $baseConfig],
        'two' => ['allocation' => 0.50, 'config' => $baseConfig],
    ],
    [
        'one' => ['allocation' => 0.0, 'config' => $baseConfig],
        'two' => ['allocation' => 1.0, 'config' => $baseConfig],
    ],
];
foreach ($invalidDefinitions as $invalidDefinition) {
    $rejected = false;
    try {
        new CausalTacticalRotationEnsembleBacktester($invalidDefinition);
    } catch (InvalidArgumentException) {
        $rejected = true;
    }
    ensembleAssert($rejected, 'Invalid sleeve count/allocation definitions must fail closed.');
}

echo "Causal tactical rotation ensemble backtester OK\n";
