<?php

declare(strict_types=1);

use FulltimeTrading\Domain\Bar;
use FulltimeTrading\Research\AdaptiveResearchFactory;
use FulltimeTrading\Research\AdaptiveRotationBacktester;
use FulltimeTrading\Research\AlgorithmSignalPolicy;
use FulltimeTrading\Research\AlgorithmTrendResearch;
use FulltimeTrading\Research\HybridV4Research;

require dirname(__DIR__) . '/bootstrap.php';

function algorithmAssert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

$cases = AlgorithmTrendResearch::cases();
$profile = require dirname(__DIR__) . '/config/tactical_rotation.php';
$hashes = [];
foreach ($cases as $id => $case) {
    $config = AdaptiveResearchFactory::make($profile, $case['changes'], 30.0)->config();
    $hashes[] = hash('sha256', json_encode($config, JSON_THROW_ON_ERROR));
}
algorithmAssert(count($cases) === 158 && count(array_unique($hashes)) === 158, '156 effective new policies and two distinct controls required.');
$families = array_count_values(array_column($cases, 'family'));
foreach (AlgorithmSignalPolicy::FAMILIES as $family) {
    algorithmAssert($families[$family] === 12, 'Each research family needs 12 predeclared variants.');
}

$bars = [];
foreach (['SPY', 'QQQ', 'AAA', 'BBB', 'CCC'] as $k => $symbol) {
    $close = 100.0;
    for ($i = 0; $i < 330; $i++) {
        $open = $close * (1.0 + 0.005 * sin($i * 0.4 + $k));
        $close = $open * (1.002 + 0.011 * sin($i * 0.7 + 2 * $k));
        $bars[$symbol][] = new Bar($symbol, new DateTimeImmutable('2023-01-01T21:00:00Z +' . $i . ' days'),
            $open, max($open, $close) * 1.005, min($open, $close) * 0.995, $close, 1000000.0 * (1.1 + sin($i)));
    }
}
$config = [
    'benchmark' => 'SPY', 'universe' => ['AAA', 'BBB', 'CCC'],
    'factor_weights' => [5 => -2.0, 20 => 1.0, 60 => 1.0],
    'benchmark_sma_period' => 20, 'minimum_history_sessions' => 61,
    'min_dollar_volume' => 0.0, 'max_gross' => 0.95,
];
$prefix = HybridV4Research::truncateBars($bars, '2023-09-20');
$changed = 0;
$base = (new AdaptiveRotationBacktester($config))->run($bars, '2023-08-01', '2023-11-26');
foreach (array_filter($cases, static fn (array $r): bool => ($r['scope'] ?? '') === 'all') as $case) {
    $policy = $case['changes']['algorithm_policy'];
    $cfg = array_replace($config, ['algorithm_policy' => $policy]);
    $short = (new AdaptiveRotationBacktester($cfg))->run($prefix, '2023-08-01', '2023-09-20');
    $fullInput = (new AdaptiveRotationBacktester($cfg))->run($bars, '2023-08-01', '2023-09-20');
    algorithmAssert($short === $fullInput, 'Future bars changed a policy prefix or next target: ' . $policy['family']);
    $full = (new AdaptiveRotationBacktester($cfg))->run($bars, '2023-08-01', '2023-11-26');
    $changed += (int) ($full['curve'] !== $base['curve']);
    foreach ($full['curve'] as $row) {
        algorithmAssert($row['signal_date'] === null || $row['signal_date'] < $row['date'], 'Same-day close used for execution.');
        algorithmAssert(is_finite($row['equity']) && $row['equity'] > 0.0, 'Invalid simulated equity.');
    }
}
algorithmAssert($changed > 20, 'Fixtures must exercise policies, not merely config parsing: ' . $changed);
$riskRow = ['volatility' => 0.3, 'algo_downside' => 0.15, 'algo_tail' => 0.9,
    'algo_gap' => 0.03, 'algo_drawdown' => 0.2, 'algo_volofvol' => 0.8, 'algo_vote' => 0.25];
foreach (['downside_size' => 0.5, 'tail_size' => 1.5, 'gap_size' => 0.01, 'drawdown_size' => 0.1,
    'volofvol_size' => 0.5, 'trend_vote_risk' => 0.5, 'breadth_risk' => 0.6] as $family => $strength) {
    $scale = AlgorithmSignalPolicy::riskScale($riskRow,
        ['AAA' => ['2024-01-01' => ['algo_sma' => 100.0, 'close' => 99.0, 'history_sessions' => 300]]],
        ['AAA'], '2024-01-01', 253, ['family' => $family, 'window' => 20, 'strength' => $strength]);
    algorithmAssert(is_finite($scale) && $scale > 0.0 && $scale !== 1.0, 'Risk branch must be exercised: ' . $family);
}

$band = ['family' => 'resize_band', 'window' => 20, 'strength' => 0.05];
algorithmAssert(AlgorithmSignalPolicy::executionTarget(['AAA' => 0.8], ['AAA' => 0.82], 1.0, $band) === ['AAA' => 0.8], 'Band must reduce small churn.');
algorithmAssert(AlgorithmSignalPolicy::executionTarget(['AAA' => 0.8], [], 1.0, $band) === [], 'Band must never suppress forced exit/cooldown.');
algorithmAssert(AlgorithmSignalPolicy::executionTarget(['AAA' => 0.8], ['BBB' => 0.8], 1.0, $band) === ['BBB' => 0.8], 'Band must never suppress a symbol switch.');
algorithmAssert(AlgorithmSignalPolicy::executionTarget(['AAA' => 1.01], ['AAA' => 0.99], 1.0, $band) === ['AAA' => 0.99], 'Band must not retain overweight.');
algorithmAssert(AlgorithmSignalPolicy::executionTarget(['AAA' => 0.8], ['AAA' => 0.78], 1.0, $band + ['mode' => 'increase_only']) === ['AAA' => 0.78], 'Increase-only band must permit de-risking.');
algorithmAssert(AlgorithmSignalPolicy::score(0.5, [], [], []) === 0.5, 'Disabled scoring must be identity.');
algorithmAssert(AlgorithmSignalPolicy::score(0.5, [], [], ['family' => 'volume_score', 'window' => 20, 'strength' => 0.5]) === null, 'Missing feature must fail closed.');
algorithmAssert(AlgorithmSignalPolicy::score(0.5, ['algo_return' => 0.1], ['algo_return' => 0.1], ['family' => 'relative_strength', 'window' => 20, 'strength' => 1.0]) === 0.5, 'Market-matched return must not add relative strength.');
foreach ([null, ['family' => 'unknown', 'window' => 20, 'strength' => 1], ['family' => 'volume_score', 'window' => 20, 'strength' => INF], ['family' => 'breadth_risk', 'window' => 20, 'strength' => 1.2]] as $bad) {
    $rejected = false;
    try { AlgorithmSignalPolicy::validate($bad); } catch (InvalidArgumentException) { $rejected = true; }
    algorithmAssert($rejected, 'Malformed policy accepted.');
}
echo "Algorithm trend research tests OK: 156 variants, 78 prefix checks, $changed exercised policies\n";
