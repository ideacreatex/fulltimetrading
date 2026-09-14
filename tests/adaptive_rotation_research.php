<?php

declare(strict_types=1);

use FulltimeTrading\Backtest\CausalTacticalRotationBacktester;
use FulltimeTrading\Domain\Bar;
use FulltimeTrading\Research\AdaptiveResearchFactory;
use FulltimeTrading\Research\AdaptiveRotationBacktester;
use FulltimeTrading\Research\HybridV4Research;

require dirname(__DIR__) . '/bootstrap.php';

function adaptiveAssert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

$bars = [];
foreach (['SPY', 'QQQ', 'AAA', 'BBB', 'CCC', 'DDD', 'EEE'] as $symbol) {
    $price = 100.0;
    for ($i = 0; $i < 85; $i++) {
        $open = $price;
        $price *= $i === 32 && !in_array($symbol, ['SPY', 'QQQ'], true)
            ? 0.70 : 1.01 + ($i % 3) * 0.001 + ($symbol === 'AAA' ? 0.001 : 0.0);
        $bars[$symbol][] = new Bar($symbol, new DateTimeImmutable('2024-01-01T21:00:00Z +' . $i . ' days'),
            $open, max($open, $price), min($open, $price), $price, 10000000.0);
    }
}
$config = [
    'benchmark' => 'SPY', 'universe' => ['AAA', 'BBB', 'CCC', 'DDD', 'EEE'],
    'market_context' => ['symbol' => 'QQQ', 'sma_period' => 20],
    'factor_weights' => [1 => 1.0], 'volatility_period' => 5, 'volatility_score_power' => 0.0,
    'volatility_target' => 100.0, 'max_gross' => 0.8, 'rebalance_sessions' => 1,
    'benchmark_sma_period' => 20, 'dollar_volume_period' => 1, 'min_dollar_volume' => 0.0,
    'minimum_history_sessions' => 20, 'drawdown_kill_pct' => 0.1,
    'drawdown_cooldown_sessions' => 20, 'cost_bps' => 0.0, 'margin_rate_annual' => 0.0,
];
$baseline = (new CausalTacticalRotationBacktester($config))->run($bars, '2024-01-26', '2024-03-25', 1000.0);
$adaptive = (new AdaptiveRotationBacktester($config))->run($bars, '2024-01-26', '2024-03-25', 1000.0);
adaptiveAssert($baseline['curve'] === $adaptive['curve'] && $baseline['next_target'] === $adaptive['next_target'], 'Disabled research must reproduce baseline exactly.');
$earlyConfig = array_replace($config, [
    'circuit_reentry_min_sessions' => 3, 'circuit_reentry_mode' => 'rebound',
    'circuit_reentry_return_3' => 0.02, 'circuit_reentry_trough_window' => 10,
    'circuit_reentry_trough_rebound' => 0.04, 'circuit_reentry_confirmation_sessions' => 2,
]);
$early = (new AdaptiveRotationBacktester($earlyConfig))->run($bars, '2024-01-26', '2024-03-25', 1000.0);
adaptiveAssert(count($early['reentry_events']) > 0, 'Fixture must actually release a circuit early.');
foreach ($early['reentry_events'] as $event) {
    adaptiveAssert($event['signal_date'] < $event['release_session'] && $event['elapsed_sessions'] > 3 && $event['saved_cooldown_ticks'] > 0, 'No same-session/future close or insufficient pause may authorize reentry.');
}
$release = $early['reentry_events'][0];
$prefixBars = HybridV4Research::truncateBars($bars, $release['signal_date']);
$prefix = (new AdaptiveRotationBacktester($earlyConfig))->run($prefixBars, '2024-01-26', $release['signal_date'], 1000.0);
$fullInputPrefix = (new AdaptiveRotationBacktester($earlyConfig))->run($bars, '2024-01-26', $release['signal_date'], 1000.0);
adaptiveAssert($prefix === $fullInputPrefix, 'Later bars must not affect any prefix result or target.');
adaptiveAssert($prefix['next_target']['cooldown_after_next_open_tick'] === 0 && $prefix['next_target']['symbol'] !== null, 'Next-target preview must predict an eligible early release.');
$halfConfig = array_replace($earlyConfig, ['circuit_reentry_initial_scale' => 0.5, 'circuit_reentry_ramp_sessions' => 5]);
$half = (new AdaptiveRotationBacktester($halfConfig))->run($bars, '2024-01-26', '2024-03-25', 1000.0);
$halfRows = array_column($half['curve'], null, 'date');
$fullRows = array_column($early['curve'], null, 'date');
adaptiveAssert($halfRows[$release['release_session']]['gross_close'] < $fullRows[$release['release_session']]['gross_close'] * 0.6, 'Partial reentry must actually use less capital.');
$halfPreview = (new AdaptiveRotationBacktester($halfConfig))->run($prefixBars, '2024-01-26', $release['signal_date'], 1000.0);
adaptiveAssert(abs($halfPreview['next_target']['gross'] - 0.4) < 1.0e-12, 'Preview must scale the entry once, not twice.');
$shortBaseConfig = array_replace($config, ['drawdown_cooldown_sessions' => 3]);
$shortEarlyConfig = array_replace($shortBaseConfig, $halfConfig, ['drawdown_cooldown_sessions' => 3]);
$shortBase = (new AdaptiveRotationBacktester($shortBaseConfig))->run($bars, '2024-01-26', '2024-03-25', 1000.0);
$shortEarly = (new AdaptiveRotationBacktester($shortEarlyConfig))->run($bars, '2024-01-26', '2024-03-25', 1000.0);
adaptiveAssert($shortEarly['reentry_events'] === [] && $shortBase['curve'] === $shortEarly['curve'], 'Natural cooldown expiry must not trigger an early-release ramp.');
$unreachable = (new AdaptiveRotationBacktester(array_replace($earlyConfig, ['circuit_reentry_return_1' => 0.99])))->run($bars, '2024-01-26', '2024-03-25', 1000.0);
adaptiveAssert($unreachable['reentry_events'] === [] && $unreachable['curve'] === $adaptive['curve'], 'An unreachable confidence threshold must be an explicit no-op.');
$profile = require dirname(__DIR__) . '/config/tactical_rotation.php';
$split = AdaptiveResearchFactory::make($profile, ['basket_size' => 2, 'phase_count' => 3], 30.0)->config();
adaptiveAssert(count($split) === 24 && abs(array_sum(array_column($split, 'allocation')) - 1.0) < 1.0e-12, 'Rank/phase expansion must conserve capital.');
foreach (['rank_hysteresis' => NAN, 'circuit_reentry_return_3' => INF, 'circuit_reentry_trough_window' => 11] as $key => $value) {
    $threw = false;
    try { new AdaptiveRotationBacktester(array_replace($config, [$key => $value])); }
    catch (InvalidArgumentException) { $threw = true; }
    adaptiveAssert($threw, 'Malformed adaptive setting must fail closed: ' . $key);
}
$dates = array_column($adaptive['curve'], 'date');
$neutralScale = array_fill_keys($dates, 1.0);
$neutral = (new AdaptiveRotationBacktester(array_replace($config, ['external_daily_scale' => $neutralScale])))
    ->run($bars, '2024-01-26', '2024-03-25', 1000.0);
adaptiveAssert($neutral['curve'] === $adaptive['curve'], 'Neutral external risk map must be bitwise baseline-equivalent.');
$halfScale = array_fill_keys($dates, 0.5);
$scaledConfig = array_replace($config, ['external_daily_scale' => $halfScale]);
$scaled = (new AdaptiveRotationBacktester($scaledConfig))->run($bars, '2024-01-26', '2024-03-25', 1000.0);
adaptiveAssert($scaled['curve'][1]['gross_close'] < $adaptive['curve'][1]['gross_close'], 'External risk affects actual positions, not post-hoc returns.');
$cut = '2024-02-10';
$scaledPrefix = (new AdaptiveRotationBacktester($scaledConfig))->run(HybridV4Research::truncateBars($bars, $cut), '2024-01-26', $cut, 1000.0);
adaptiveAssert($scaledPrefix['curve'] === array_values(array_filter($scaled['curve'], static fn ($r): bool => $r['date'] <= $cut)), 'External sizing replay has no future dependence.');
$missingScale = $neutralScale; unset($missingScale[$dates[0]]);
$threw = false;
try { (new AdaptiveRotationBacktester(array_replace($config, ['external_daily_scale' => $missingScale])))->run($bars, '2024-01-26', '2024-03-25', 1000.0); }
catch (RuntimeException) { $threw = true; }
adaptiveAssert($threw, 'Missing external observation cannot silently authorize a position.');
$zero = (new AdaptiveRotationBacktester(array_replace($config, ['external_daily_scale' => array_fill_keys($dates, 0.0)])))
    ->run($bars, '2024-01-26', '2024-03-25', 1000.0);
adaptiveAssert(end($zero['curve'])['equity'] === 1000.0, 'Zero risk holds cash with no manufactured P/L.');
echo "Adaptive rotation research tests OK\n";
