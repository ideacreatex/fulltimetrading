#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Backtest\BacktestResult;
use FulltimeTrading\Backtest\PerformanceReport;
use FulltimeTrading\Backtest\PoosBacktester;
use FulltimeTrading\Data\AlpacaBarsProvider;
use FulltimeTrading\Data\CacheDirectoryMarketDataProvider;
use FulltimeTrading\Data\CachedMarketDataProvider;
use FulltimeTrading\Data\HttpClient;
use FulltimeTrading\Data\MarketDataProvider;
use FulltimeTrading\Data\YahooChartProvider;
use FulltimeTrading\Domain\Bar;
use FulltimeTrading\Domain\Signal;
use FulltimeTrading\Domain\Trade;
use FulltimeTrading\Indicators\IndicatorCalculator;
use FulltimeTrading\Storage\SqliteRepository;
use FulltimeTrading\Strategy\MarketRegimeAnalyzer;
use FulltimeTrading\Strategy\PoosScanner;
use FulltimeTrading\Support\Config;

require __DIR__ . '/../bootstrap.php';

ini_set('memory_limit', '1G');

$options = [
    'start' => '2021-01-01',
    'end' => '2026-06-13',
    'output-dir' => __DIR__ . '/../var/reports/param_experiment',
    'db' => __DIR__ . '/../var/db/trading.sqlite',
    'provider' => 'yahoo',
    'feed' => null,
    'cache-namespace' => null,
    'initial-cash' => null,
];

foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
        continue;
    }
    [$key, $value] = explode('=', substr($arg, 2), 2);
    $options[$key] = $value;
}

$outputDir = (string) $options['output-dir'];
if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
    throw new RuntimeException('Unable to create output dir: ' . $outputDir);
}

$config = Config::fromFile(__DIR__ . '/../config/config.php');
$provider = providerFromOptions($config, $options);
$repo = new SqliteRepository((string) $options['db']);
$repo->migrate();

$symbols = array_values(array_intersect(
    symbolsFromFile(__DIR__ . '/../var/reports/universe_leveraged_long_symbols.txt'),
    ['USD', 'SOXL', 'TECL', 'TQQQ', 'UPRO'],
));
$benchmark = 'SPY';

$baseStrategy = $config->get('strategy', []);
$baseRisk = $config->get('risk', []);
if (!is_array($baseStrategy) || !is_array($baseRisk)) {
    throw new RuntimeException('Invalid config.');
}
$baseStrategy['external_indicator_snapshots'] = $repo->loadExternalIndicatorSnapshots((string) $options['start'], (string) $options['end']);

$marketSymbols = array_values(array_unique(array_merge($baseStrategy['market']['symbols'] ?? ['SPY', 'QQQ', 'SMH'], [$benchmark])));
$barsBySymbol = loadBarsSafely($provider, $symbols, (string) $options['start'], (string) $options['end'], (string) $config->get('cache_path'));
$marketBars = loadBarsSafely($provider, $marketSymbols, (string) $options['start'], (string) $options['end'], (string) $config->get('cache_path'));
$symbols = array_values(array_intersect($symbols, array_keys($barsBySymbol)));

$indicatorCalculator = new IndicatorCalculator();
$variants = experimentVariants($symbols, $options);
$summaries = [];
$savedResults = [];
$bestScore = -INF;
$bestScoreName = null;
$bestTargetScore = -INF;
$bestTargetName = null;
$bestRobustScore = -INF;
$bestRobustName = null;

foreach ($variants as $name => $variant) {
    [$strategy, $risk] = configureExperimentVariant($baseStrategy, $baseRisk, $variant);
    $marketAnalyzer = new MarketRegimeAnalyzer($indicatorCalculator, $strategy['market'] ?? []);
    $scanner = new PoosScanner($indicatorCalculator, $strategy);
    $backtester = new PoosBacktester($indicatorCalculator, $marketAnalyzer, $scanner, $strategy, $risk);
    $result = $backtester->run(array_intersect_key($barsBySymbol, array_fill_keys($symbols, true)), $marketBars);
    $report = (new PerformanceReport())->build($result, $marketBars[$benchmark] ?? [], $benchmark);
    $diagnostics = diagnostics($result->positionStates, $result->equityCurve);
    $report['diagnostics'] = $diagnostics;

    $summary = $report['summary'];
    $ann = (float) $summary['annualized_return_pct'];
    $dd = (float) $summary['max_drawdown_pct'];
    $score = $ann / max(0.01, abs($dd));
    $consistency = consistencyMetrics($report['years'] ?? []);
    $robustScore = robustScore($ann, $dd, $score, $consistency);

    $row = [
        'variant' => $name,
        'params' => $variant,
        'return_pct' => (float) $summary['return_pct'],
        'annualized_return_pct' => $ann,
        'max_drawdown_pct' => $dd,
        'trades' => (int) $summary['trades'],
        'profit_factor' => $summary['profit_factor'],
        'sharpe' => $summary['sharpe'],
        'score_return_drawdown' => $score,
        'robust_score' => $robustScore,
        'active_pct' => $diagnostics['active_pct'],
        'avg_gross_exposure_all_days' => $diagnostics['avg_gross_exposure_all_days'],
        'avg_gross_exposure_active_days' => $diagnostics['avg_gross_exposure_active_days'],
        'max_gross_exposure' => $diagnostics['max_gross_exposure'],
        'worst_year_return_pct' => $consistency['worst_year_return_pct'],
        'median_year_return_pct' => $consistency['median_year_return_pct'],
        'negative_years' => $consistency['negative_years'],
        'best_year_contribution_pct' => $consistency['best_year_contribution_pct'],
        'meets_40_35' => $ann >= 0.40 && $dd >= -0.35,
        'meets_consistent_40_35' => $ann >= 0.40
            && $dd >= -0.35
            && $consistency['negative_years'] <= 1
            && $consistency['worst_year_return_pct'] >= -0.05
            && $consistency['median_year_return_pct'] >= 0.10,
    ];
    $summaries[] = $row;

    if ((int) $summary['trades'] >= 50 && $score > $bestScore) {
        $bestScore = $score;
        $bestScoreName = $name;
        $savedResults['best_score'] = [$name, $variant, $report, $result];
    }

    if ($row['meets_40_35'] && $score > $bestTargetScore) {
        $bestTargetScore = $score;
        $bestTargetName = $name;
        $savedResults['best_40_35'] = [$name, $variant, $report, $result];
    }

    if ($row['meets_consistent_40_35'] && $robustScore > $bestRobustScore) {
        $bestRobustScore = $robustScore;
        $bestRobustName = $name;
        $savedResults['best_consistent_40_35'] = [$name, $variant, $report, $result];
    }

    printf(
        "%s ann=%+.2f%% dd=%.2f%% worstY=%.2f%% medY=%.2f%% negY=%d bestYShare=%.1f%% score=%.3f robust=%.3f\n",
        $name,
        $ann * 100,
        $dd * 100,
        $consistency['worst_year_return_pct'] * 100,
        $consistency['median_year_return_pct'] * 100,
        $consistency['negative_years'],
        $consistency['best_year_contribution_pct'] * 100,
        $score,
        $robustScore,
    );
}

usort($summaries, static fn (array $a, array $b): int => $b['robust_score'] <=> $a['robust_score']);
$summaryPath = $outputDir . '/summary.json';
file_put_contents($summaryPath, json_encode([
    'generated_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
    'start' => $options['start'],
    'end' => $options['end'],
    'provider' => $options['provider'],
    'feed' => $options['feed'],
    'symbols' => $symbols,
    'variants' => count($variants),
    'best_score_variant' => $bestScoreName,
    'best_40_35_variant' => $bestTargetName,
    'best_consistent_40_35_variant' => $bestRobustName,
    'summaries' => $summaries,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

foreach ($savedResults as $label => [$name, $variant, $report, $result]) {
    saveResult($outputDir . '/' . $label, $name, $variant, $report, $result);
}

echo "Summary: {$summaryPath}\n";

/** @return list<string> */
function symbolsFromFile(string $file): array
{
    if (!is_file($file)) {
        return [];
    }

    return array_values(array_filter(array_map(
        static fn (string $symbol): string => strtoupper(trim($symbol)),
        preg_split('/[\s,;]+/', (string) file_get_contents($file)) ?: [],
    )));
}

/** @param array<string, string|null> $options */
function providerFromOptions(Config $config, array $options): MarketDataProvider
{
    $provider = (string) ($options['provider'] ?? 'yahoo');
    $cachePath = (string) $config->get('cache_path');
    $http = new HttpClient();

    return match ($provider) {
        'alpaca' => new CachedMarketDataProvider(
            new AlpacaBarsProvider(
                $http,
                (string) $config->get('data.alpaca.base_url', 'https://data.alpaca.markets'),
                (string) ($options['feed'] ?: $config->get('data.alpaca.feed', 'iex')),
                (string) $config->get('data.alpaca.adjustment', 'split'),
                (int) $config->get('data.alpaca.limit', 10000),
            ),
            $cachePath,
            'alpaca-param-experiment-' . (string) ($options['feed'] ?: $config->get('data.alpaca.feed', 'iex')),
        ),
        'offline-cache' => new CacheDirectoryMarketDataProvider(
            $cachePath,
            (string) ($options['cache-namespace'] ?? ''),
        ),
        'yahoo' => new CachedMarketDataProvider(new YahooChartProvider($http), $cachePath, 'yahoo'),
        default => throw new RuntimeException('Unknown provider: ' . $provider),
    };
}

/** @param list<string> $symbols @return array<string, list<Bar>> */
function loadBarsSafely(MarketDataProvider $provider, array $symbols, string $start, string $end, string $cachePath): array
{
    try {
        return $provider->getBars($symbols, '1Day', $start, $end);
    } catch (Throwable $e) {
        $cached = (new CacheDirectoryMarketDataProvider($cachePath))->getBars($symbols, '1Day', $start, $end);
        if ($cached !== []) {
            return $cached;
        }

        throw $e;
    }
}

/** @param list<string> $symbols @param array<string, string> $options @return array<string, array<string, mixed>> */
function experimentVariants(array $symbols, array $options = []): array
{
    $base = [
        'symbols' => $symbols,
        'initial_cash' => (float) ($options['initial-cash'] ?? 1000.0),
        'max_open' => 4,
        'max_gross' => 2.0,
        'family_cap' => 1.10,
        'reentry_cooldown_days' => 0,
        'allow_same_strength_after_days' => 30,
        'min_touches' => 4,
        'min_success_rate' => 0.70,
        'touch_tolerance_pct' => 0.015,
        'near_atr_multiple' => 0.60,
        'stop_atr_multiple' => 1.5,
        'target_atr_multiple' => 3.0,
        'layers' => 3,
        'require_green_garden' => true,
        'break_even_add_on_fraction' => 0.0,
        'break_even_profit_pct' => (float) ($options['break-even-profit-pct'] ?? 0.01),
        'break_even_trigger_mode' => enumOption($options, 'break-even-trigger-mode', ['high', 'close'], 'high'),
        'break_even_stop_mode' => enumOption($options, 'break-even-stop-mode', ['hard', 'close'], 'hard'),
        'break_even_stop_offset_pct' => (float) ($options['break-even-stop-offset-pct'] ?? 0.0),
        'partial_take_profit_pct' => (float) ($options['partial-take-profit-pct'] ?? 0.5),
        'order_valid_bars' => (int) ($options['order-valid-bars'] ?? 10),
        'order_fill_mode' => enumOption($options, 'order-fill-mode', ['same_day_touch', 'next_touch'], 'same_day_touch'),
        'unstable_market_position_pct' => 0.05,
        'stable_market_score_threshold' => 2.5,
        'swing_stop_mode' => enumOption($options, 'swing-stop-mode', ['hard', 'mental', 'hybrid'], 'hard'),
        'hard_stop_fill_mode' => enumOption($options, 'hard-stop-fill-mode', ['gap_open', 'stop_price'], 'gap_open'),
    ];

    $variants = ['baseline' => $base];
    $add = static function (string $prefix, array $overrides) use (&$variants, $base): void {
        $variant = array_merge($base, $overrides);
        $name = $prefix . '_' . shortParams($overrides);
        $variants[$name] = $variant;
    };

    $maxGrossValues = floatListOption($options, 'max-gross-values', [1.75, 2.0, 2.25, 2.5]);
    $familyCapValues = floatListOption($options, 'family-cap-values', [0.75, 0.85, 0.90, 1.00, 1.10, 1.20]);
    $cooldownValues = intListOption($options, 'cooldown-days', [0, 2, 5]);
    $sameAfterValues = intListOption($options, 'same-after-days', [15, 30, 45]);
    $breakEvenAddOnFractions = nonNegativeFloatListOption($options, 'break-even-add-on-fractions', [0.0]);
    $breakEvenProfitPcts = nonNegativeFloatListOption($options, 'break-even-profit-pct-values', [
        (float) ($options['break-even-profit-pct'] ?? 0.01),
    ]);
    $breakEvenTriggerModes = stringListOption($options, 'break-even-trigger-modes', [(string) $base['break_even_trigger_mode']], ['high', 'close']);
    $breakEvenStopModes = stringListOption($options, 'break-even-stop-modes', [(string) $base['break_even_stop_mode']], ['hard', 'close']);
    $breakEvenStopOffsetPcts = nonNegativeFloatListOption($options, 'break-even-stop-offset-pct-values', [
        (float) ($options['break-even-stop-offset-pct'] ?? 0.0),
    ]);
    $partialTakeProfitPcts = nonNegativeFloatListOption($options, 'partial-take-profit-pct-values', [
        (float) ($options['partial-take-profit-pct'] ?? 0.5),
    ]);
    $orderValidBarsValues = intListOption($options, 'order-valid-bars-values', [
        (int) ($options['order-valid-bars'] ?? 10),
    ]);
    $orderFillModes = stringListOption($options, 'order-fill-modes', [(string) $base['order_fill_mode']], ['same_day_touch', 'next_touch']);
    $maxOpenOverride = isset($options['max-open']) ? (int) $options['max-open'] : null;
    foreach ($maxGrossValues as $maxGross) {
        foreach ($familyCapValues as $familyCap) {
            foreach ($cooldownValues as $cooldownDays) {
                foreach ($sameAfterValues as $sameAfter) {
                    foreach ($breakEvenAddOnFractions as $breakEvenAddOnFraction) {
                        foreach ($breakEvenProfitPcts as $breakEvenProfitPct) {
                            foreach ($breakEvenTriggerModes as $breakEvenTriggerMode) {
                                foreach ($breakEvenStopModes as $breakEvenStopMode) {
                                    foreach ($breakEvenStopOffsetPcts as $breakEvenStopOffsetPct) {
                                        foreach ($partialTakeProfitPcts as $partialTakeProfitPct) {
                                            foreach ($orderValidBarsValues as $orderValidBars) {
                                                foreach ($orderFillModes as $orderFillMode) {
                                                    $add('risk', [
                                                        'max_gross' => $maxGross,
                                                        'max_open' => $maxOpenOverride ?? ($maxGross <= 2.0 ? 4 : 5),
                                                        'family_cap' => $familyCap,
                                                        'reentry_cooldown_days' => $cooldownDays,
                                                        'allow_same_strength_after_days' => $sameAfter,
                                                        'break_even_add_on_fraction' => $breakEvenAddOnFraction,
                                                        'break_even_profit_pct' => $breakEvenProfitPct,
                                                        'break_even_trigger_mode' => $breakEvenTriggerMode,
                                                        'break_even_stop_mode' => $breakEvenStopMode,
                                                        'break_even_stop_offset_pct' => $breakEvenStopOffsetPct,
                                                        'partial_take_profit_pct' => $partialTakeProfitPct,
                                                        'order_valid_bars' => $orderValidBars,
                                                        'order_fill_mode' => $orderFillMode,
                                                    ]);
                                                }
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    if (boolOption($options, 'leverage-only')) {
        return $variants;
    }

    foreach ([3, 4, 5] as $minTouches) {
        foreach ([0.60, 0.65, 0.70, 0.75] as $minSuccess) {
            $add('regularity', [
                'min_touches' => $minTouches,
                'min_success_rate' => $minSuccess,
            ]);
        }
    }

    foreach ([0.010, 0.015, 0.020] as $touchTolerance) {
        foreach ([0.45, 0.60, 0.75, 0.90] as $nearAtr) {
            $add('entry', [
                'touch_tolerance_pct' => $touchTolerance,
                'near_atr_multiple' => $nearAtr,
            ]);
        }
    }

    foreach ([1.0, 1.25, 1.5, 1.75, 2.0] as $stopAtr) {
        foreach ([2.0, 2.5, 3.0, 3.5, 4.0] as $targetAtr) {
            $add('stop_target', [
                'stop_atr_multiple' => $stopAtr,
                'target_atr_multiple' => $targetAtr,
            ]);
        }
    }

    foreach ([1, 2, 3, 4] as $layers) {
        foreach ([true, false] as $requireGreen) {
            $add('layer', [
                'layers' => $layers,
                'max_open' => max(4, $layers + 1),
                'require_green_garden' => $requireGreen,
            ]);
        }
    }

    foreach ([2.0, 2.5, 3.0] as $stableScoreThreshold) {
        foreach ([0.03, 0.05, 0.08, 0.10] as $unstablePct) {
            $add('market', [
                'stable_market_score_threshold' => $stableScoreThreshold,
                'unstable_market_position_pct' => $unstablePct,
            ]);
        }
    }

    return $variants;
}

/** @param array<string, string> $options @param list<float> $default @return list<float> */
function floatListOption(array $options, string $key, array $default): array
{
    if (!isset($options[$key])) {
        return $default;
    }

    $values = array_values(array_filter(array_map(
        static fn (string $value): float => (float) trim($value),
        explode(',', (string) $options[$key]),
    ), static fn (float $value): bool => $value > 0.0));

    return $values === [] ? $default : $values;
}

/** @param array<string, string> $options @param list<float> $default @return list<float> */
function nonNegativeFloatListOption(array $options, string $key, array $default): array
{
    if (!isset($options[$key])) {
        return $default;
    }

    $values = array_values(array_filter(array_map(
        static fn (string $value): float => (float) trim($value),
        explode(',', (string) $options[$key]),
    ), static fn (float $value): bool => $value >= 0.0));

    return $values === [] ? $default : $values;
}

/** @param array<string, string> $options @param list<int> $default @return list<int> */
function intListOption(array $options, string $key, array $default): array
{
    if (!isset($options[$key])) {
        return $default;
    }

    $values = array_values(array_filter(array_map(
        static fn (string $value): int => (int) trim($value),
        explode(',', (string) $options[$key]),
    ), static fn (int $value): bool => $value >= 0));

    return $values === [] ? $default : $values;
}

/** @param array<string, string> $options @param list<string> $default @param list<string> $allowed @return list<string> */
function stringListOption(array $options, string $key, array $default, array $allowed): array
{
    if (!isset($options[$key])) {
        return $default;
    }

    $values = [];
    foreach (explode(',', (string) $options[$key]) as $value) {
        $value = strtolower(trim($value));
        if (in_array($value, $allowed, true)) {
            $values[] = $value;
        }
    }

    return $values === [] ? $default : array_values(array_unique($values));
}

/** @param array<string, string> $options */
function boolOption(array $options, string $key): bool
{
    if (!isset($options[$key])) {
        return false;
    }

    return in_array(strtolower((string) $options[$key]), ['1', 'true', 'yes', 'y', 'on'], true);
}

/** @param array<string, string> $options @param list<string> $allowed */
function enumOption(array $options, string $key, array $allowed, string $default): string
{
    if (!isset($options[$key])) {
        return $default;
    }

    $value = strtolower(trim((string) $options[$key]));

    return in_array($value, $allowed, true) ? $value : $default;
}

/** @param array<string, mixed> $params */
function shortParams(array $params): string
{
    $parts = [];
    foreach ($params as $key => $value) {
        if (is_bool($value)) {
            $value = $value ? 'yes' : 'no';
        } elseif (is_float($value)) {
            $value = rtrim(rtrim(sprintf('%.3f', $value), '0'), '.');
        }
        $parts[] = preg_replace('/[^a-z0-9]+/i', '', $key) . (string) $value;
    }

    return implode('_', $parts);
}

/** @param array<string, mixed> $baseStrategy @param array<string, mixed> $baseRisk @param array<string, mixed> $variant */
function configureExperimentVariant(array $baseStrategy, array $baseRisk, array $variant): array
{
    $strategy = $baseStrategy;
    $risk = $baseRisk;

    $strategy['support_regularity']['min_touches'] = (int) $variant['min_touches'];
    $strategy['support_regularity']['min_success_rate'] = (float) $variant['min_success_rate'];
    $strategy['support_regularity']['require_close_above_support'] = true;
    $strategy['support_regularity']['weekly_enabled'] = true;
    $strategy['support_regularity']['touch_tolerance_pct'] = (float) $variant['touch_tolerance_pct'];
    $strategy['support_regularity']['near_atr_multiple'] = (float) $variant['near_atr_multiple'];
    $strategy['support_regularity']['stop_atr_multiple'] = (float) $variant['stop_atr_multiple'];
    $strategy['support_regularity']['target_atr_multiple'] = (float) $variant['target_atr_multiple'];
    $strategy['short_resistance']['enabled'] = false;
    $strategy['short_symbols'] = [];
    $strategy['inverse_long_symbols'] = [];
    $strategy['order_valid_bars'] = (int) ($variant['order_valid_bars'] ?? $strategy['order_valid_bars'] ?? 10);
    $strategy['order_fill_mode'] = (string) ($variant['order_fill_mode'] ?? 'same_day_touch');
    $strategy['partial_take_profit_pct'] = (float) ($variant['partial_take_profit_pct'] ?? $strategy['partial_take_profit_pct'] ?? 0.5);
    $strategy['club_rules']['max_gross_exposure_pct'] = (float) $variant['max_gross'];
    $strategy['club_rules']['break_even_profit_pct'] = (float) $variant['break_even_profit_pct'];
    $strategy['club_rules']['break_even_trigger_mode'] = (string) ($variant['break_even_trigger_mode'] ?? 'high');
    $strategy['club_rules']['break_even_stop_mode'] = (string) ($variant['break_even_stop_mode'] ?? 'hard');
    $strategy['club_rules']['break_even_stop_offset_pct'] = (float) ($variant['break_even_stop_offset_pct'] ?? 0.0);
    $strategy['club_rules']['unstable_market_position_pct'] = (float) $variant['unstable_market_position_pct'];
    $strategy['club_rules']['stable_market_score_threshold'] = (float) $variant['stable_market_score_threshold'];
    $strategy['club_rules']['default_swing_stop_mode'] = (string) ($variant['swing_stop_mode'] ?? 'hard');
    $strategy['club_rules']['hard_stop_fill_mode'] = (string) ($variant['hard_stop_fill_mode'] ?? 'gap_open');
    $strategy['layered_positions']['enabled'] = true;
    $strategy['layered_positions']['same_symbol_max_layers'] = (int) $variant['layers'];
    $strategy['layered_positions']['require_green_garden'] = (bool) $variant['require_green_garden'];
    $strategy['layered_positions']['break_even_add_on']['enabled'] = (float) ($variant['break_even_add_on_fraction'] ?? 0.0) > 0.0;
    $strategy['layered_positions']['break_even_add_on']['position_fraction'] = (float) ($variant['break_even_add_on_fraction'] ?? 0.0);
    $strategy['family_exposure_caps']['enabled'] = true;
    $strategy['family_exposure_caps']['default_max_gross_exposure_pct'] = (float) $variant['family_cap'];
    foreach (array_keys($strategy['family_exposure_caps']['caps'] ?? []) as $family) {
        $strategy['family_exposure_caps']['caps'][$family] = (float) $variant['family_cap'];
    }
    $strategy['reentry_after_stop']['enabled'] = true;
    $strategy['reentry_after_stop']['cooldown_days'] = (int) $variant['reentry_cooldown_days'];
    $strategy['reentry_after_stop']['require_stronger_support'] = true;
    $strategy['reentry_after_stop']['allow_same_strength_after_days'] = (int) $variant['allow_same_strength_after_days'];

    $risk['initial_cash'] = 1000.0;
    $risk['initial_cash'] = (float) ($variant['initial_cash'] ?? $risk['initial_cash']);
    $risk['position_sizing_mode'] = 'capital_pct';
    $risk['fixed_position_usd'] = 0.0;
    $risk['allow_fractional_shares'] = true;
    $risk['max_open_positions'] = (int) $variant['max_open'];

    return [$strategy, $risk];
}

/** @param list<array<string, mixed>> $positionStates @param list<array{date:string, equity:float}> $curve */
function diagnostics(array $positionStates, array $curve): array
{
    $positionsByDate = [];
    foreach ($positionStates as $positionState) {
        $date = (string) ($positionState['date'] ?? '');
        if ($date === '') {
            continue;
        }
        $positionsByDate[$date][] = $positionState;
    }

    $activeDays = 0;
    $sumOpen = 0;
    $sumExposure = 0.0;
    $exposureDays = 0;
    $maxExposure = 0.0;
    foreach ($curve as $point) {
        $date = (string) $point['date'];
        $equity = (float) $point['equity'];
        $openPositions = $positionsByDate[$date] ?? [];
        $open = count($openPositions);
        $notional = 0.0;
        foreach ($openPositions as $positionState) {
            $notional += abs((float) ($positionState['market_value'] ?? 0.0));
        }
        if ($open > 0) {
            $activeDays++;
        }
        $sumOpen += $open;
        if ($equity > 0.0) {
            $exposure = $notional / $equity;
            $sumExposure += $exposure;
            $maxExposure = max($maxExposure, $exposure);
            if ($notional > 0.0) {
                $exposureDays++;
            }
        }
    }

    $days = max(1, count($curve));

    return [
        'days' => $days,
        'active_days' => $activeDays,
        'active_pct' => $activeDays / $days,
        'avg_open_positions' => $sumOpen / $days,
        'avg_gross_exposure_all_days' => $sumExposure / $days,
        'avg_gross_exposure_active_days' => $exposureDays > 0 ? $sumExposure / $exposureDays : 0.0,
        'max_gross_exposure' => $maxExposure,
    ];
}

/** @param array<string, array<string, mixed>> $years @return array<string, float|int> */
function consistencyMetrics(array $years): array
{
    $returns = [];
    foreach ($years as $year => $row) {
        if (!is_array($row) || (string) $year === '2026') {
            continue;
        }
        $returns[] = (float) ($row['strategy_return_pct'] ?? 0.0);
    }

    if ($returns === []) {
        return [
            'worst_year_return_pct' => 0.0,
            'median_year_return_pct' => 0.0,
            'negative_years' => 0,
            'best_year_contribution_pct' => 0.0,
        ];
    }

    sort($returns);
    $positive = array_values(array_filter($returns, static fn (float $return): bool => $return > 0.0));
    $positiveSum = array_sum($positive);
    $best = $positive === [] ? 0.0 : max($positive);

    return [
        'worst_year_return_pct' => min($returns),
        'median_year_return_pct' => median($returns),
        'negative_years' => count(array_filter($returns, static fn (float $return): bool => $return < 0.0)),
        'best_year_contribution_pct' => $positiveSum > 0.0 ? $best / $positiveSum : 0.0,
    ];
}

/** @param list<float> $values */
function median(array $values): float
{
    $count = count($values);
    if ($count === 0) {
        return 0.0;
    }
    sort($values);
    $mid = intdiv($count, 2);
    if ($count % 2 === 1) {
        return $values[$mid];
    }

    return ($values[$mid - 1] + $values[$mid]) / 2.0;
}

/** @param array<string, float|int> $consistency */
function robustScore(float $ann, float $dd, float $score, array $consistency): float
{
    $penalty = 0.0;
    $penalty += ((int) $consistency['negative_years']) * 0.25;
    $penalty += max(0.0, ((float) $consistency['best_year_contribution_pct']) - 0.55) * 1.5;
    $penalty += max(0.0, -0.05 - ((float) $consistency['worst_year_return_pct'])) * 2.0;
    $penalty += $dd < -0.35 ? abs($dd + 0.35) * 2.0 : 0.0;
    $bonus = max(0.0, ((float) $consistency['median_year_return_pct']) - 0.10);

    return $score + $bonus + $ann * 0.10 - $penalty;
}

function saveResult(string $prefix, string $name, array $variant, array $report, BacktestResult $result): void
{
    file_put_contents($prefix . '_report.json', json_encode([
        'variant' => $name,
        'params' => $variant,
        'report' => $report,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    file_put_contents($prefix . '_trades.json', json_encode([
        'variant' => $name,
        'params' => $variant,
        'trades' => serializeTrades($result->trades),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    file_put_contents($prefix . '_signals.json', json_encode([
        'variant' => $name,
        'params' => $variant,
        'signals' => serializeSignals($result->signals),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    file_put_contents($prefix . '_positions.json', json_encode([
        'variant' => $name,
        'params' => $variant,
        'positions' => array_values($result->positionStates),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    file_put_contents($prefix . '_equity.json', json_encode([
        'variant' => $name,
        'params' => $variant,
        'equity' => $result->equityCurve,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
}

/** @param list<Trade> $trades @return list<array<string, mixed>> */
function serializeTrades(array $trades): array
{
    return array_map(static fn (Trade $trade): array => [
        'symbol' => $trade->symbol,
        'strategy' => $trade->strategy,
        'entry_date' => $trade->entryTime->format('Y-m-d'),
        'exit_date' => $trade->exitTime->format('Y-m-d'),
        'entry' => $trade->entry,
        'exit' => $trade->exit,
        'shares' => $trade->shares,
        'pnl' => $trade->pnl,
        'r_multiple' => $trade->rMultiple,
        'exit_reason' => $trade->exitReason,
        'events' => $trade->events,
    ], $trades);
}

/** @param list<Signal> $signals @return list<array<string, mixed>> */
function serializeSignals(array $signals): array
{
    return array_map(static fn (Signal $signal): array => [
        'date' => $signal->createdAt->format('Y-m-d'),
        'symbol' => $signal->symbol,
        'strategy' => $signal->strategy,
        'direction' => $signal->direction,
        'entry' => $signal->entry,
        'stop' => $signal->stop,
        'target' => $signal->target,
        'score' => $signal->score,
        'reasons' => $signal->reasons,
        'metadata' => $signal->metadata,
    ], $signals);
}
