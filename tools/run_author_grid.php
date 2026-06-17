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
    'output-dir' => __DIR__ . '/../var/reports/author_grid',
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

$stockSymbols = symbolsFromFile(__DIR__ . '/../var/reports/universe_symbols.txt');
$longLeverageSymbols = symbolsFromFile(__DIR__ . '/../var/reports/universe_leveraged_long_symbols.txt');
$trendHedgeSymbols = array_values(array_diff(
    symbolsFromFile(__DIR__ . '/../var/reports/universe_symbols_with_trend_hedges.txt'),
    ['SVXY', 'SVIX', 'SVYX'],
));
$inverseSymbols = symbolsFromFile(__DIR__ . '/../var/reports/universe_inverse_hedge_symbols.txt');
$benchmark = 'SPY';

$baseStrategy = $config->get('strategy', []);
$baseRisk = $config->get('risk', []);
if (!is_array($baseStrategy) || !is_array($baseRisk)) {
    throw new RuntimeException('Invalid config.');
}
$baseStrategy['external_indicator_snapshots'] = $repo->loadExternalIndicatorSnapshots((string) $options['start'], (string) $options['end']);

$variants = [
    'stock_strict_weekly_layers' => [
        'symbols' => $stockSymbols,
        'shorts' => [],
        'inverse' => [],
        'max_open' => 4,
        'max_gross' => 1.0,
        'unstable' => 0.05,
        'weekly' => true,
        'same_day' => true,
        'layers' => 3,
        'require_green' => true,
    ],
    'long_leverage_strict_weekly_layers' => [
        'symbols' => $longLeverageSymbols,
        'shorts' => [],
        'inverse' => [],
        'max_open' => 4,
        'max_gross' => 2.0,
        'unstable' => 0.05,
        'weekly' => true,
        'same_day' => true,
        'layers' => 3,
        'require_green' => true,
    ],
    'long_leverage_2x_no_green_gate' => [
        'symbols' => $longLeverageSymbols,
        'shorts' => [],
        'inverse' => [],
        'max_open' => 6,
        'max_gross' => 2.0,
        'unstable' => 0.05,
        'weekly' => true,
        'same_day' => true,
        'layers' => 3,
        'require_green' => false,
    ],
    'long_leverage_3x' => [
        'symbols' => $longLeverageSymbols,
        'shorts' => [],
        'inverse' => [],
        'max_open' => 6,
        'max_gross' => 3.0,
        'unstable' => 0.05,
        'weekly' => true,
        'same_day' => true,
        'layers' => 3,
        'require_green' => true,
    ],
    'long_leverage_3x_no_green_gate' => [
        'symbols' => $longLeverageSymbols,
        'shorts' => [],
        'inverse' => [],
        'max_open' => 6,
        'max_gross' => 3.0,
        'unstable' => 0.05,
        'weekly' => true,
        'same_day' => true,
        'layers' => 3,
        'require_green' => false,
    ],
    'long_leverage_2x_unstable50' => [
        'symbols' => $longLeverageSymbols,
        'shorts' => [],
        'inverse' => [],
        'max_open' => 4,
        'max_gross' => 2.0,
        'unstable' => 0.50,
        'weekly' => true,
        'same_day' => true,
        'layers' => 3,
        'require_green' => true,
    ],
    'high_beta_leverage_2x' => [
        'symbols' => array_values(array_intersect($longLeverageSymbols, ['USD', 'SOXL', 'TECL', 'TQQQ', 'UPRO'])),
        'shorts' => [],
        'inverse' => [],
        'max_open' => 4,
        'max_gross' => 2.0,
        'unstable' => 0.05,
        'weekly' => true,
        'same_day' => true,
        'layers' => 3,
        'require_green' => true,
    ],
    'high_beta_leverage_3x' => [
        'symbols' => array_values(array_intersect($longLeverageSymbols, ['USD', 'SOXL', 'TECL', 'TQQQ', 'UPRO'])),
        'shorts' => [],
        'inverse' => [],
        'max_open' => 6,
        'max_gross' => 3.0,
        'unstable' => 0.05,
        'weekly' => true,
        'same_day' => true,
        'layers' => 3,
        'require_green' => true,
    ],
    'high_beta_leverage_3x_hard_stop' => [
        'symbols' => array_values(array_intersect($longLeverageSymbols, ['USD', 'SOXL', 'TECL', 'TQQQ', 'UPRO'])),
        'shorts' => [],
        'inverse' => [],
        'max_open' => 6,
        'max_gross' => 3.0,
        'unstable' => 0.05,
        'weekly' => true,
        'same_day' => true,
        'layers' => 3,
        'require_green' => true,
        'stop_mode' => 'hard',
    ],
    'high_beta_leverage_3x_hard_stop_caps' => [
        'symbols' => array_values(array_intersect($longLeverageSymbols, ['USD', 'SOXL', 'TECL', 'TQQQ', 'UPRO'])),
        'shorts' => [],
        'inverse' => [],
        'max_open' => 6,
        'max_gross' => 3.0,
        'unstable' => 0.05,
        'weekly' => true,
        'same_day' => true,
        'layers' => 3,
        'require_green' => true,
        'stop_mode' => 'hard',
        'family_caps' => true,
        'family_cap' => 1.25,
    ],
    'high_beta_leverage_3x_hard_stop_caps_reentry' => [
        'symbols' => array_values(array_intersect($longLeverageSymbols, ['USD', 'SOXL', 'TECL', 'TQQQ', 'UPRO'])),
        'shorts' => [],
        'inverse' => [],
        'max_open' => 6,
        'max_gross' => 3.0,
        'unstable' => 0.05,
        'weekly' => true,
        'same_day' => true,
        'layers' => 3,
        'require_green' => true,
        'stop_mode' => 'hard',
        'family_caps' => true,
        'family_cap' => 1.25,
        'reentry' => true,
    ],
    'high_beta_leverage_2x_hard_stop_caps_reentry' => [
        'symbols' => array_values(array_intersect($longLeverageSymbols, ['USD', 'SOXL', 'TECL', 'TQQQ', 'UPRO'])),
        'shorts' => [],
        'inverse' => [],
        'max_open' => 4,
        'max_gross' => 2.0,
        'unstable' => 0.05,
        'weekly' => true,
        'same_day' => true,
        'layers' => 3,
        'require_green' => true,
        'stop_mode' => 'hard',
        'family_caps' => true,
        'family_cap' => 1.10,
        'reentry' => true,
    ],
    'high_beta_leverage_3x_hybrid_caps_reentry' => [
        'symbols' => array_values(array_intersect($longLeverageSymbols, ['USD', 'SOXL', 'TECL', 'TQQQ', 'UPRO'])),
        'shorts' => [],
        'inverse' => [],
        'max_open' => 6,
        'max_gross' => 3.0,
        'unstable' => 0.05,
        'weekly' => true,
        'same_day' => true,
        'layers' => 3,
        'require_green' => true,
        'stop_mode' => 'hybrid',
        'family_caps' => true,
        'family_cap' => 1.25,
        'reentry' => true,
    ],
    'author_trend_hedges_2x' => [
        'symbols' => $trendHedgeSymbols,
        'shorts' => $stockSymbols,
        'inverse' => $inverseSymbols,
        'max_open' => 4,
        'max_gross' => 2.0,
        'unstable' => 0.05,
        'weekly' => true,
        'same_day' => true,
        'layers' => 3,
        'require_green' => true,
    ],
    'author_trend_hedges_2x_unstable50' => [
        'symbols' => $trendHedgeSymbols,
        'shorts' => $stockSymbols,
        'inverse' => $inverseSymbols,
        'max_open' => 4,
        'max_gross' => 2.0,
        'unstable' => 0.50,
        'weekly' => true,
        'same_day' => true,
        'layers' => 3,
        'require_green' => true,
    ],
    'author_trend_hedges_3x' => [
        'symbols' => $trendHedgeSymbols,
        'shorts' => $stockSymbols,
        'inverse' => $inverseSymbols,
        'max_open' => 6,
        'max_gross' => 3.0,
        'unstable' => 0.05,
        'weekly' => true,
        'same_day' => true,
        'layers' => 3,
        'require_green' => true,
    ],
    'author_trend_hedges_3x_hybrid_caps_reentry' => [
        'symbols' => $trendHedgeSymbols,
        'shorts' => $stockSymbols,
        'inverse' => $inverseSymbols,
        'max_open' => 6,
        'max_gross' => 3.0,
        'unstable' => 0.05,
        'weekly' => true,
        'same_day' => true,
        'layers' => 3,
        'require_green' => true,
        'stop_mode' => 'hybrid',
        'family_caps' => true,
        'family_cap' => 1.25,
        'reentry' => true,
    ],
    'author_trend_hedges_2x_hard_stop_caps_reentry' => [
        'symbols' => $trendHedgeSymbols,
        'shorts' => $stockSymbols,
        'inverse' => $inverseSymbols,
        'max_open' => 4,
        'max_gross' => 2.0,
        'unstable' => 0.05,
        'weekly' => true,
        'same_day' => true,
        'layers' => 3,
        'require_green' => true,
        'stop_mode' => 'hard',
        'family_caps' => true,
        'family_cap' => 1.10,
        'reentry' => true,
    ],
    'author_trend_hedges_no_green_gate' => [
        'symbols' => $trendHedgeSymbols,
        'shorts' => $stockSymbols,
        'inverse' => $inverseSymbols,
        'max_open' => 6,
        'max_gross' => 2.0,
        'unstable' => 0.05,
        'weekly' => true,
        'same_day' => true,
        'layers' => 3,
        'require_green' => false,
    ],
    'author_without_weekly' => [
        'symbols' => $trendHedgeSymbols,
        'shorts' => $stockSymbols,
        'inverse' => $inverseSymbols,
        'max_open' => 4,
        'max_gross' => 2.0,
        'unstable' => 0.05,
        'weekly' => false,
        'same_day' => true,
        'layers' => 3,
        'require_green' => true,
    ],
    'author_next_touch_only' => [
        'symbols' => $trendHedgeSymbols,
        'shorts' => $stockSymbols,
        'inverse' => $inverseSymbols,
        'max_open' => 4,
        'max_gross' => 2.0,
        'unstable' => 0.05,
        'weekly' => true,
        'same_day' => false,
        'layers' => 3,
        'require_green' => true,
    ],
];

$allSymbols = [];
foreach ($variants as $variant) {
    foreach ($variant['symbols'] as $symbol) {
        $allSymbols[$symbol] = true;
    }
}
$allSymbols = array_keys($allSymbols);
sort($allSymbols);

$marketSymbols = array_values(array_unique(array_merge($baseStrategy['market']['symbols'] ?? ['SPY', 'QQQ', 'SMH'], [$benchmark])));
$barsBySymbol = loadBarsSafely($provider, $allSymbols, (string) $options['start'], (string) $options['end'], (string) $config->get('cache_path'));
$marketBars = loadBarsSafely($provider, $marketSymbols, (string) $options['start'], (string) $options['end'], (string) $config->get('cache_path'));
$missingSymbols = array_values(array_diff($allSymbols, array_keys(array_filter($barsBySymbol, static fn (array $bars): bool => count($bars) > 0))));
if ($missingSymbols !== []) {
    echo 'Missing cached symbols skipped: ' . implode(',', $missingSymbols) . "\n";
}
$indicatorCalculator = new IndicatorCalculator();

$summaries = [];
$bestName = null;
$bestScore = -INF;
$bestSignals = [];
$bestPositionStates = [];
$bestTrades = [];
$bestEquityCurve = [];

foreach ($variants as $name => $variant) {
    [$strategy, $risk] = configureVariant($baseStrategy, $baseRisk, $variant);
    $symbols = array_values(array_intersect($variant['symbols'], array_keys($barsBySymbol)));
    $variantBars = array_intersect_key($barsBySymbol, array_fill_keys($symbols, true));

    $marketAnalyzer = new MarketRegimeAnalyzer($indicatorCalculator, $strategy['market'] ?? []);
    $scanner = new PoosScanner($indicatorCalculator, $strategy);
    $backtester = new PoosBacktester($indicatorCalculator, $marketAnalyzer, $scanner, $strategy, $risk);
    $result = $backtester->run($variantBars, $marketBars);
    $report = (new PerformanceReport())->build($result, $marketBars[$benchmark] ?? [], $benchmark);
    $diagnostics = diagnostics($result->positionStates, $result->equityCurve);
    $report['diagnostics'] = $diagnostics;

    $path = $outputDir . '/' . $name . '.json';
    file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    $variantSignalsPath = $outputDir . '/' . $name . '_signals.json';
    file_put_contents($variantSignalsPath, json_encode([
        'variant' => $name,
        'signals' => serializeSignals($result->signals),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    $variantPositionsPath = $outputDir . '/' . $name . '_positions.json';
    file_put_contents($variantPositionsPath, json_encode([
        'variant' => $name,
        'positions' => serializePositionStates($result->positionStates),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    $variantTradesPath = $outputDir . '/' . $name . '_trades.json';
    file_put_contents($variantTradesPath, json_encode([
        'variant' => $name,
        'trades' => serializeTrades($result->trades),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    $variantEquityPath = $outputDir . '/' . $name . '_equity.json';
    file_put_contents($variantEquityPath, json_encode([
        'variant' => $name,
        'equity' => $result->equityCurve,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

    $summary = $report['summary'];
    $row = [
        'variant' => $name,
        'return_pct' => $summary['return_pct'],
        'annualized_return_pct' => $summary['annualized_return_pct'],
        'max_drawdown_pct' => $summary['max_drawdown_pct'],
        'trades' => $summary['trades'],
        'signals' => $summary['signals'],
        'profit_factor' => $summary['profit_factor'],
        'sharpe' => $summary['sharpe'],
        'active_pct' => $diagnostics['active_pct'],
        'avg_gross_exposure_all_days' => $diagnostics['avg_gross_exposure_all_days'],
        'report' => $path,
        'signals_report' => $variantSignalsPath,
        'positions_report' => $variantPositionsPath,
        'trades_report' => $variantTradesPath,
        'equity_report' => $variantEquityPath,
    ];
    $summaries[] = $row;

    $score = (float) $summary['annualized_return_pct'] / max(0.01, abs((float) $summary['max_drawdown_pct']));
    if ($score > $bestScore && (int) $summary['trades'] >= 20) {
        $bestScore = $score;
        $bestName = $name;
        $bestSignals = $result->signals;
        $bestPositionStates = $result->positionStates;
        $bestTrades = $result->trades;
        $bestEquityCurve = $result->equityCurve;
    }

    printf(
        "%s return=%+.2f%% annualized=%+.2f%% dd=%.2f%% trades=%d signals=%d active=%.1f%% avgGross=%.1f%%\n",
        $name,
        $summary['return_pct'] * 100,
        $summary['annualized_return_pct'] * 100,
        $summary['max_drawdown_pct'] * 100,
        $summary['trades'],
        $summary['signals'],
        $diagnostics['active_pct'] * 100,
        $diagnostics['avg_gross_exposure_all_days'] * 100,
    );
}

usort($summaries, static fn (array $a, array $b): int => $b['annualized_return_pct'] <=> $a['annualized_return_pct']);
$summaryPath = $outputDir . '/summary.json';
file_put_contents($summaryPath, json_encode([
    'generated_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
    'start' => $options['start'],
    'end' => $options['end'],
    'best_risk_adjusted_variant' => $bestName,
    'missing_symbols' => $missingSymbols,
    'summaries' => $summaries,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

$signalsPath = $outputDir . '/best_signals.json';
file_put_contents($signalsPath, json_encode([
    'variant' => $bestName,
    'signals' => serializeSignals($bestSignals),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
$positionsPath = $outputDir . '/best_positions.json';
file_put_contents($positionsPath, json_encode([
    'variant' => $bestName,
    'positions' => serializePositionStates($bestPositionStates),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
$tradesPath = $outputDir . '/best_trades.json';
file_put_contents($tradesPath, json_encode([
    'variant' => $bestName,
    'trades' => serializeTrades($bestTrades),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
$equityPath = $outputDir . '/best_equity.json';
file_put_contents($equityPath, json_encode([
    'variant' => $bestName,
    'equity' => $bestEquityCurve,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

echo "Summary: {$summaryPath}\n";
echo "Best signals: {$signalsPath}\n";
echo "Best positions: {$positionsPath}\n";
echo "Best trades: {$tradesPath}\n";
echo "Best equity: {$equityPath}\n";

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

/** @param list<array<string, mixed>> $positionStates @return list<array<string, mixed>> */
function serializePositionStates(array $positionStates): array
{
    return array_values($positionStates);
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

/** @param array<string, mixed> $baseStrategy @param array<string, mixed> $baseRisk @param array<string, mixed> $variant */
function configureVariant(array $baseStrategy, array $baseRisk, array $variant): array
{
    $strategy = $baseStrategy;
    $risk = $baseRisk;

    $strategy['support_regularity']['min_touches'] = 4;
    $strategy['support_regularity']['min_success_rate'] = 0.70;
    $strategy['support_regularity']['require_close_above_support'] = true;
    $strategy['support_regularity']['weekly_enabled'] = (bool) $variant['weekly'];
    $strategy['short_resistance']['enabled'] = $variant['shorts'] !== [];
    $strategy['short_resistance']['min_touches'] = 4;
    $strategy['short_resistance']['min_success_rate'] = 0.70;
    $strategy['short_resistance']['require_close_below_resistance'] = true;
    $strategy['short_symbols'] = $variant['shorts'];
    $strategy['inverse_long_symbols'] = $variant['inverse'];
    $strategy['order_fill_mode'] = $variant['same_day'] ? 'same_day_touch' : 'next_touch';
    $strategy['club_rules']['max_gross_exposure_pct'] = (float) $variant['max_gross'];
    $strategy['club_rules']['unstable_market_position_pct'] = (float) $variant['unstable'];
    $strategy['club_rules']['default_swing_stop_mode'] = (string) ($variant['stop_mode'] ?? 'mental');
    $strategy['club_rules']['hard_stop_fill_mode'] = (string) ($variant['stop_fill'] ?? 'gap_open');
    $strategy['layered_positions']['enabled'] = true;
    $strategy['layered_positions']['same_symbol_max_layers'] = (int) $variant['layers'];
    $strategy['layered_positions']['require_green_garden'] = (bool) $variant['require_green'];
    $strategy['family_exposure_caps']['enabled'] = (bool) ($variant['family_caps'] ?? false);
    if (isset($variant['family_cap'])) {
        $familyCap = (float) $variant['family_cap'];
        $strategy['family_exposure_caps']['default_max_gross_exposure_pct'] = $familyCap;
        foreach (array_keys($strategy['family_exposure_caps']['caps'] ?? []) as $family) {
            $strategy['family_exposure_caps']['caps'][$family] = $familyCap;
        }
    }
    $strategy['reentry_after_stop']['enabled'] = (bool) ($variant['reentry'] ?? false);
    $strategy['reentry_after_stop']['cooldown_days'] = (int) ($variant['reentry_cooldown_days'] ?? 3);
    $strategy['reentry_after_stop']['allow_same_strength_after_days'] = (int) ($variant['allow_same_strength_after_days'] ?? 30);

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
