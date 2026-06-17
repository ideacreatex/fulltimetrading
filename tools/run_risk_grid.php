#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Backtest\PerformanceReport;
use FulltimeTrading\Backtest\PoosBacktester;
use FulltimeTrading\Data\CachedMarketDataProvider;
use FulltimeTrading\Data\HttpClient;
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
    'output-dir' => __DIR__ . '/../var/reports/risk_grid',
    'db' => __DIR__ . '/../var/db/trading.sqlite',
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
$provider = new CachedMarketDataProvider(
    new YahooChartProvider(new HttpClient()),
    (string) $config->get('cache_path'),
    'yahoo',
);
$repo = new SqliteRepository((string) $options['db']);
$repo->migrate();

$longLeverageSymbols = symbolsFromFile(__DIR__ . '/../var/reports/universe_leveraged_long_symbols.txt');
$symbols = array_values(array_intersect($longLeverageSymbols, ['USD', 'SOXL', 'TECL', 'TQQQ', 'UPRO']));
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
$variants = riskVariants($symbols);
$summaries = [];
$bestScore = -INF;
$bestScoreName = null;
$bestTargetScore = -INF;
$bestTargetName = null;
$bestLooseScore = -INF;
$bestLooseName = null;
$savedResults = [];

foreach ($variants as $name => $variant) {
    [$strategy, $risk] = configureRiskVariant($baseStrategy, $baseRisk, $variant);
    $variantBars = array_intersect_key($barsBySymbol, array_fill_keys($symbols, true));
    $marketAnalyzer = new MarketRegimeAnalyzer($indicatorCalculator, $strategy['market'] ?? []);
    $scanner = new PoosScanner($indicatorCalculator, $strategy);
    $backtester = new PoosBacktester($indicatorCalculator, $marketAnalyzer, $scanner, $strategy, $risk);
    $result = $backtester->run($variantBars, $marketBars);
    $report = (new PerformanceReport())->build($result, $marketBars[$benchmark] ?? [], $benchmark);
    $diagnostics = diagnostics($result->positionStates, $result->equityCurve);
    $report['diagnostics'] = $diagnostics;
    $summary = $report['summary'];
    $ann = (float) $summary['annualized_return_pct'];
    $dd = (float) $summary['max_drawdown_pct'];
    $score = $ann / max(0.01, abs($dd));

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
        'active_pct' => $diagnostics['active_pct'],
        'avg_gross_exposure_all_days' => $diagnostics['avg_gross_exposure_all_days'],
        'avg_gross_exposure_active_days' => $diagnostics['avg_gross_exposure_active_days'],
        'max_gross_exposure' => $diagnostics['max_gross_exposure'],
        'meets_40_35' => $ann >= 0.40 && $dd >= -0.35,
        'meets_40_40' => $ann >= 0.40 && $dd >= -0.40,
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
    if ($row['meets_40_40'] && $score > $bestLooseScore) {
        $bestLooseScore = $score;
        $bestLooseName = $name;
        $savedResults['best_40_40'] = [$name, $variant, $report, $result];
    }

    printf(
        "%s ann=%+.2f%% dd=%.2f%% return=%+.2f%% trades=%d score=%.3f\n",
        $name,
        $ann * 100,
        $dd * 100,
        ((float) $summary['return_pct']) * 100,
        (int) $summary['trades'],
        $score,
    );
}

usort($summaries, static fn (array $a, array $b): int => $b['score_return_drawdown'] <=> $a['score_return_drawdown']);
$summaryPath = $outputDir . '/summary.json';
file_put_contents($summaryPath, json_encode([
    'generated_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
    'start' => $options['start'],
    'end' => $options['end'],
    'symbols' => $symbols,
    'variants' => count($variants),
    'best_score_variant' => $bestScoreName,
    'best_40_35_variant' => $bestTargetName,
    'best_40_40_variant' => $bestLooseName,
    'summaries' => $summaries,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

foreach ($savedResults as $label => [$name, $variant, $report, $result]) {
    $prefix = $outputDir . '/' . $label;
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

/** @param list<string> $symbols @return array<string, list<Bar>> */
function loadBarsSafely(CachedMarketDataProvider $provider, array $symbols, string $start, string $end, string $cachePath): array
{
    try {
        return $provider->getBars($symbols, '1Day', $start, $end);
    } catch (Throwable $e) {
        $cached = loadBarsFromCacheDirectory($cachePath, $symbols, $start, $end);
        if ($cached !== []) {
            return $cached;
        }

        throw $e;
    }
}

/** @param list<string> $symbols @return array<string, list<Bar>> */
function loadBarsFromCacheDirectory(string $cachePath, array $symbols, string $start, string $end): array
{
    $wanted = array_fill_keys(array_map('strtoupper', $symbols), true);
    $result = [];
    foreach (glob(rtrim($cachePath, '/') . '/*.json') ?: [] as $file) {
        $payload = json_decode((string) file_get_contents($file), true);
        if (!is_array($payload)) {
            continue;
        }

        foreach ($payload as $symbol => $series) {
            $symbol = strtoupper((string) $symbol);
            if (!isset($wanted[$symbol]) || !is_array($series)) {
                continue;
            }

            $bars = [];
            foreach ($series as $row) {
                if (!is_array($row) || !isset($row['time'], $row['open'], $row['high'], $row['low'], $row['close'])) {
                    continue;
                }
                $date = substr((string) $row['time'], 0, 10);
                if ($date < $start || $date > $end) {
                    continue;
                }
                $bars[] = new Bar(
                    $symbol,
                    new DateTimeImmutable($date),
                    (float) $row['open'],
                    (float) $row['high'],
                    (float) $row['low'],
                    (float) $row['close'],
                    (float) ($row['volume'] ?? 0),
                );
            }
            if (count($bars) > count($result[$symbol] ?? [])) {
                $result[$symbol] = $bars;
            }
        }
    }

    return $result;
}

/** @param list<string> $symbols @return array<string, array<string, mixed>> */
function riskVariants(array $symbols): array
{
    $variants = [];
    foreach ([1.5, 2.0, 2.5, 3.0] as $maxGross) {
        foreach ([0.75, 0.90, 1.00, 1.10, 1.25] as $familyCap) {
            foreach ([0, 3, 5, 10] as $cooldownDays) {
                foreach ([15, 30] as $allowSameAfterDays) {
                    $name = sprintf(
                        'risk_grid_g%.1f_cap%.2f_cd%d_same%d',
                        $maxGross,
                        $familyCap,
                        $cooldownDays,
                        $allowSameAfterDays,
                    );
                    $variants[$name] = [
                        'symbols' => $symbols,
                        'max_open' => $maxGross <= 2.0 ? 4 : 6,
                        'max_gross' => $maxGross,
                        'family_cap' => $familyCap,
                        'reentry_cooldown_days' => $cooldownDays,
                        'allow_same_strength_after_days' => $allowSameAfterDays,
                    ];
                }
            }
        }
    }

    return $variants;
}

/** @param array<string, mixed> $baseStrategy @param array<string, mixed> $baseRisk @param array<string, mixed> $variant */
function configureRiskVariant(array $baseStrategy, array $baseRisk, array $variant): array
{
    $strategy = $baseStrategy;
    $risk = $baseRisk;

    $strategy['support_regularity']['min_touches'] = 4;
    $strategy['support_regularity']['min_success_rate'] = 0.70;
    $strategy['support_regularity']['require_close_above_support'] = true;
    $strategy['support_regularity']['weekly_enabled'] = true;
    $strategy['short_resistance']['enabled'] = false;
    $strategy['short_symbols'] = [];
    $strategy['inverse_long_symbols'] = [];
    $strategy['order_fill_mode'] = 'same_day_touch';
    $strategy['club_rules']['max_gross_exposure_pct'] = (float) $variant['max_gross'];
    $strategy['club_rules']['unstable_market_position_pct'] = 0.05;
    $strategy['club_rules']['default_swing_stop_mode'] = 'hard';
    $strategy['club_rules']['hard_stop_fill_mode'] = 'gap_open';
    $strategy['layered_positions']['enabled'] = true;
    $strategy['layered_positions']['same_symbol_max_layers'] = 3;
    $strategy['layered_positions']['require_green_garden'] = true;
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
