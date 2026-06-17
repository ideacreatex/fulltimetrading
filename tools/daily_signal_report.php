#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Backtest\PerformanceReport;
use FulltimeTrading\Backtest\PoosBacktester;
use FulltimeTrading\Data\AlpacaBarsProvider;
use FulltimeTrading\Data\CacheDirectoryMarketDataProvider;
use FulltimeTrading\Data\CachedMarketDataProvider;
use FulltimeTrading\Data\HttpClient;
use FulltimeTrading\Data\MarketDataProvider;
use FulltimeTrading\Data\StooqBarsProvider;
use FulltimeTrading\Data\YahooChartProvider;
use FulltimeTrading\Domain\Bar;
use FulltimeTrading\Domain\Signal;
use FulltimeTrading\Indicators\IndicatorCalculator;
use FulltimeTrading\Notifications\TelegramNotifier;
use FulltimeTrading\Storage\SqliteRepository;
use FulltimeTrading\Strategy\MarketRegime;
use FulltimeTrading\Strategy\MarketRegimeAnalyzer;
use FulltimeTrading\Strategy\PoosScanner;
use FulltimeTrading\Support\Config;
use FulltimeTrading\Trading\AlpacaPaperClient;

require __DIR__ . '/../bootstrap.php';

ini_set('memory_limit', '1G');

$options = [
    'provider' => 'yahoo',
    'start' => '2021-01-01',
    'end' => (new DateTimeImmutable('today'))->format('Y-m-d'),
    'as-of' => '',
    'lookback-days' => '5',
    'symbols-file' => __DIR__ . '/../var/reports/universe_leveraged_long_symbols.txt',
    'market' => '',
    'benchmark' => 'SPY',
    'output' => __DIR__ . '/../var/reports/daily/latest_signal_report.json',
    'text-output' => __DIR__ . '/../var/reports/daily/latest_signal_report.txt',
    'telegram' => 'false',
    'include-account' => 'false',
    'offline' => 'false',
    'cache-namespace' => '',
    'initial-cash' => '30000',
    'max-open-positions' => '4',
    'max-gross-exposure-pct' => '2.0',
    'family-cap' => '1.00',
    'support-min-touches' => '4',
    'support-min-success-rate' => '0.70',
    'support-require-close-above' => 'true',
    'support-touch-tolerance-pct' => '0.015',
    'support-near-atr-multiple' => '0.60',
    'support-stop-atr-multiple' => '1.50',
    'support-target-atr-multiple' => '3.00',
    'order-valid-bars' => '10',
    'order-fill-mode' => 'same_day_touch',
    'partial-take-profit-pct' => '0.25',
    'swing-stop-mode' => 'mental',
    'hard-stop-fill-mode' => 'gap_open',
    'break-even-profit-pct' => '0.02',
    'break-even-trigger-mode' => 'high',
    'break-even-stop-mode' => 'hard',
    'break-even-stop-offset-pct' => '0',
    'reentry-cooldown-days' => '0',
    'allow-same-strength-after-days' => '45',
    'layers' => '3',
    'require-green-garden' => 'true',
    'break-even-add-on-fraction' => '0',
    'unstable-market-position-pct' => '0.05',
    'stable-market-score-threshold' => '2.50',
];

foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
        continue;
    }
    [$key, $value] = explode('=', substr($arg, 2), 2);
    $options[$key] = $value;
}

$config = Config::fromFile(__DIR__ . '/../config/config.php');
$repo = new SqliteRepository((string) $config->get('database_path'));
$repo->migrate();
$http = new HttpClient();
$provider = providerFromOptions($config, $http, $options);
$symbols = symbolsFromOptions($options, $config);
$benchmark = strtoupper((string) $options['benchmark']);
$strategy = strategyFromOptions($config, $repo, $options);
$risk = riskFromOptions($config, $options);
$marketSymbols = marketSymbolsFromOptions($strategy, $options, $benchmark);

$barsBySymbol = loadBarsSafely($provider, $symbols, '1Day', (string) $options['start'], (string) $options['end'], (string) $config->get('cache_path'));
$marketBars = loadBarsSafely($provider, $marketSymbols, '1Day', (string) $options['start'], (string) $options['end'], (string) $config->get('cache_path'));
$loadedSymbols = array_keys(array_filter($barsBySymbol, static fn (array $bars): bool => count($bars) > 0));
$missingSymbols = array_values(array_diff($symbols, $loadedSymbols));
$barsBySymbol = array_intersect_key($barsBySymbol, array_fill_keys($loadedSymbols, true));

$indicatorCalculator = new IndicatorCalculator();
$marketAnalyzer = new MarketRegimeAnalyzer($indicatorCalculator, $strategy['market'] ?? []);
$scanner = new PoosScanner($indicatorCalculator, $strategy);
$marketRegimes = $marketAnalyzer->analyze($marketBars);
$asOf = resolveAsOfDate((string) $options['as-of'], $marketBars[$benchmark] ?? [], $barsBySymbol);
$regime = latestRegimeOnOrBefore($marketRegimes, $asOf);

$signals = [];
foreach ($barsBySymbol as $symbol => $bars) {
    $signals = array_merge($signals, $scanner->scan($symbol, $bars, $marketRegimes));
}
usort($signals, static fn (Signal $a, Signal $b): int => $b->score <=> $a->score);
$todaySignals = array_values(array_filter(
    $signals,
    static fn (Signal $signal): bool => $signal->createdAt->format('Y-m-d') === $asOf,
));
$recentSignals = recentSignals($signals, $asOf, (int) $options['lookback-days']);

$backtester = new PoosBacktester($indicatorCalculator, $marketAnalyzer, $scanner, $strategy, $risk);
$result = $backtester->run($barsBySymbol, $marketBars);
$report = (new PerformanceReport())->build($result, $marketBars[$benchmark] ?? [], $benchmark);
$currentPositions = currentPositionStates($result->positionStates, $result->openPositions, $asOf);
$account = boolOption((string) $options['include-account']) ? safePaperAccount($config, $http) : null;

$payload = [
    'generated_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
    'provider' => $options['provider'],
    'offline' => boolOption((string) $options['offline']),
    'as_of' => $asOf,
    'data' => [
        'symbols_requested' => count($symbols),
        'symbols_loaded' => count($loadedSymbols),
        'missing_symbols' => $missingSymbols,
        'market_symbols' => $marketSymbols,
        'benchmark' => $benchmark,
        'data_age_days' => dataAgeDays($asOf),
    ],
    'market' => serializeRegime($regime),
    'risk' => [
        'initial_cash' => (float) $risk['initial_cash'],
        'max_open_positions' => (int) $risk['max_open_positions'],
        'max_gross_exposure_pct' => (float) ($strategy['club_rules']['max_gross_exposure_pct'] ?? 1.0),
        'swing_stop_mode' => (string) ($strategy['club_rules']['default_swing_stop_mode'] ?? 'mental'),
        'break_even_profit_pct' => (float) ($strategy['club_rules']['break_even_profit_pct'] ?? 0.01),
    ],
    'model' => [
        'summary' => $report['summary'] ?? [],
        'benchmark' => $report['benchmark'] ?? [],
        'open_positions' => $currentPositions,
    ],
    'signals_today' => array_map(static fn (Signal $signal): array => serializeSignal($signal, $strategy), $todaySignals),
    'recent_signals' => array_map(static fn (Signal $signal): array => serializeSignal($signal, $strategy), array_slice($recentSignals, 0, 20)),
    'paper_account' => $account,
    'health' => healthRows($asOf, $missingSymbols, $regime, $account, boolOption((string) $options['offline'])),
];
$payload['action'] = actionFromPayload($payload);

writeReport((string) $options['output'], json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
$text = formatTelegramText($payload);
writeReport((string) $options['text-output'], $text . "\n");

if (boolOption((string) $options['telegram'])) {
    $notifier = TelegramNotifier::fromEnv($http);
    if ($notifier === null) {
        echo "Telegram warning: missing TELEGRAM_BOT_TOKEN/TELEGRAM_CHAT_ID\n";
    } else {
        try {
            $notifier->sendMessage($text);
            echo "Telegram message sent\n";
        } catch (Throwable $e) {
            echo "Telegram warning: " . $e->getMessage() . "\n";
        }
    }
}

echo "Report: " . (string) $options['output'] . "\n";
echo "Text: " . (string) $options['text-output'] . "\n";
echo $text . "\n";

/** @param array<string, string> $options */
function providerFromOptions(Config $config, HttpClient $http, array $options): MarketDataProvider
{
    if (boolOption((string) ($options['offline'] ?? 'false'))) {
        return new CacheDirectoryMarketDataProvider(
            (string) $config->get('cache_path'),
            (string) ($options['cache-namespace'] ?? ''),
        );
    }

    return match ((string) $options['provider']) {
        'alpaca' => new CachedMarketDataProvider(
            new AlpacaBarsProvider(
                $http,
                (string) $config->get('data.alpaca.base_url'),
                (string) ($options['feed'] ?? $config->get('data.alpaca.feed', 'iex')),
                (string) ($options['adjustment'] ?? $config->get('data.alpaca.adjustment', 'split')),
                (int) $config->get('data.alpaca.limit', 10000),
            ),
            (string) $config->get('cache_path'),
            (string) ($options['cache-namespace'] ?? '') !== '' ? (string) $options['cache-namespace'] : 'alpaca',
        ),
        'stooq' => new CachedMarketDataProvider(
            new StooqBarsProvider($http, (string) $config->get('data.stooq.base_url')),
            (string) $config->get('cache_path'),
            'stooq',
        ),
        'yahoo' => new CachedMarketDataProvider(new YahooChartProvider($http), (string) $config->get('cache_path'), 'yahoo'),
        default => throw new RuntimeException('Unknown provider: ' . (string) $options['provider']),
    };
}

/** @param array<string, string> $options @return list<string> */
function symbolsFromOptions(array $options, Config $config): array
{
    if (($options['symbols'] ?? '') !== '') {
        return cleanSymbols(preg_split('/[\s,;]+/', (string) $options['symbols']) ?: []);
    }

    $file = (string) ($options['symbols-file'] ?? '');
    if ($file !== '' && is_file($file)) {
        return cleanSymbols(preg_split('/[\s,;]+/', (string) file_get_contents($file)) ?: []);
    }

    $watchlist = $config->get('watchlists.us_stocks', []);

    return is_array($watchlist) ? cleanSymbols($watchlist) : [];
}

/** @param list<string> $symbols @return list<string> */
function cleanSymbols(array $symbols): array
{
    $clean = [];
    foreach ($symbols as $symbol) {
        $symbol = strtoupper(trim($symbol));
        if ($symbol !== '' && preg_match('/^[A-Z][A-Z0-9.\-^=]{0,12}$/', $symbol)) {
            $clean[$symbol] = true;
        }
    }

    return array_keys($clean);
}

/** @param array<string, string> $options @return array<string, mixed> */
function strategyFromOptions(Config $config, SqliteRepository $repo, array $options): array
{
    $strategy = $config->get('strategy', []);
    if (!is_array($strategy)) {
        throw new RuntimeException('Invalid strategy config.');
    }

    $strategy['external_indicator_snapshots'] = $repo->loadExternalIndicatorSnapshots((string) $options['start'], (string) $options['end']);
    $strategy['support_regularity']['min_touches'] = (int) $options['support-min-touches'];
    $strategy['support_regularity']['min_success_rate'] = (float) $options['support-min-success-rate'];
    $strategy['support_regularity']['require_close_above_support'] = boolOption((string) $options['support-require-close-above']);
    $strategy['support_regularity']['touch_tolerance_pct'] = (float) $options['support-touch-tolerance-pct'];
    $strategy['support_regularity']['near_atr_multiple'] = (float) $options['support-near-atr-multiple'];
    $strategy['support_regularity']['stop_atr_multiple'] = (float) $options['support-stop-atr-multiple'];
    $strategy['support_regularity']['target_atr_multiple'] = (float) $options['support-target-atr-multiple'];
    $strategy['order_valid_bars'] = (int) $options['order-valid-bars'];
    $strategy['order_fill_mode'] = enumOption((string) $options['order-fill-mode'], ['same_day_touch', 'next_touch'], 'same_day_touch');
    $strategy['partial_take_profit_pct'] = (float) $options['partial-take-profit-pct'];
    $strategy['club_rules']['default_swing_stop_mode'] = enumOption((string) $options['swing-stop-mode'], ['hard', 'mental', 'hybrid'], 'mental');
    $strategy['club_rules']['hard_stop_fill_mode'] = enumOption((string) $options['hard-stop-fill-mode'], ['stop_price', 'gap_open'], 'gap_open');
    $strategy['club_rules']['max_gross_exposure_pct'] = (float) $options['max-gross-exposure-pct'];
    $strategy['club_rules']['break_even_profit_pct'] = (float) $options['break-even-profit-pct'];
    $strategy['club_rules']['break_even_trigger_mode'] = enumOption((string) $options['break-even-trigger-mode'], ['high', 'close'], 'high');
    $strategy['club_rules']['break_even_stop_mode'] = enumOption((string) $options['break-even-stop-mode'], ['hard', 'close'], 'hard');
    $strategy['club_rules']['break_even_stop_offset_pct'] = (float) $options['break-even-stop-offset-pct'];
    $strategy['club_rules']['unstable_market_position_pct'] = (float) $options['unstable-market-position-pct'];
    $strategy['club_rules']['stable_market_score_threshold'] = (float) $options['stable-market-score-threshold'];
    $strategy['layered_positions']['enabled'] = true;
    $strategy['layered_positions']['same_symbol_max_layers'] = (int) $options['layers'];
    $strategy['layered_positions']['require_green_garden'] = boolOption((string) $options['require-green-garden']);
    $strategy['layered_positions']['break_even_add_on']['enabled'] = (float) $options['break-even-add-on-fraction'] > 0.0;
    $strategy['layered_positions']['break_even_add_on']['position_fraction'] = (float) $options['break-even-add-on-fraction'];
    $strategy['family_exposure_caps']['enabled'] = true;
    $strategy['family_exposure_caps']['default_max_gross_exposure_pct'] = (float) $options['family-cap'];
    foreach (array_keys($strategy['family_exposure_caps']['caps'] ?? []) as $family) {
        $strategy['family_exposure_caps']['caps'][$family] = (float) $options['family-cap'];
    }
    $strategy['reentry_after_stop']['enabled'] = true;
    $strategy['reentry_after_stop']['cooldown_days'] = (int) $options['reentry-cooldown-days'];
    $strategy['reentry_after_stop']['require_stronger_support'] = true;
    $strategy['reentry_after_stop']['allow_same_strength_after_days'] = (int) $options['allow-same-strength-after-days'];

    return $strategy;
}

/** @param array<string, string> $options @return array<string, mixed> */
function riskFromOptions(Config $config, array $options): array
{
    $risk = $config->get('risk', []);
    if (!is_array($risk)) {
        throw new RuntimeException('Invalid risk config.');
    }
    $risk['initial_cash'] = (float) $options['initial-cash'];
    $risk['position_sizing_mode'] = 'capital_pct';
    $risk['max_open_positions'] = (int) $options['max-open-positions'];
    $risk['allow_fractional_shares'] = true;

    return $risk;
}

/** @param array<string, mixed> $strategy @param array<string, string> $options @return list<string> */
function marketSymbolsFromOptions(array $strategy, array $options, string $benchmark): array
{
    if (($options['market'] ?? '') !== '') {
        $symbols = cleanSymbols(preg_split('/[\s,;]+/', (string) $options['market']) ?: []);
    } else {
        $symbols = cleanSymbols($strategy['market']['symbols'] ?? ['SPY', 'QQQ', 'SMH']);
    }
    $symbols[] = $benchmark;

    return array_values(array_unique($symbols));
}

/** @param list<string> $allowed */
function enumOption(string $value, array $allowed, string $default): string
{
    $value = strtolower(trim($value));

    return in_array($value, $allowed, true) ? $value : $default;
}

function boolOption(string $value): bool
{
    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'y', 'on'], true);
}

/** @param list<string> $symbols @return array<string, list<Bar>> */
function loadBarsSafely(MarketDataProvider $provider, array $symbols, string $timeframe, string $start, string $end, string $cachePath): array
{
    try {
        return $provider->getBars($symbols, $timeframe, $start, $end);
    } catch (Throwable $e) {
        $cached = (new CacheDirectoryMarketDataProvider($cachePath))->getBars($symbols, $timeframe, $start, $end);
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
        foreach ($payload as $symbol => $rows) {
            $symbol = strtoupper((string) $symbol);
            if (!isset($wanted[$symbol]) || !is_array($rows)) {
                continue;
            }
            $bars = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $date = substr((string) ($row['time'] ?? ''), 0, 10);
                if ($date < $start || $date > $end) {
                    continue;
                }
                $bars[] = new Bar(
                    $symbol,
                    new DateTimeImmutable((string) $row['time']),
                    (float) $row['open'],
                    (float) $row['high'],
                    (float) $row['low'],
                    (float) $row['close'],
                    (float) $row['volume'],
                );
            }
            if (count($bars) > count($result[$symbol] ?? [])) {
                $result[$symbol] = $bars;
            }
        }
    }

    return $result;
}

/** @param list<Bar> $benchmarkBars @param array<string, list<Bar>> $barsBySymbol */
function resolveAsOfDate(string $option, array $benchmarkBars, array $barsBySymbol): string
{
    if ($option !== '') {
        return (new DateTimeImmutable($option))->format('Y-m-d');
    }
    if ($benchmarkBars !== []) {
        return $benchmarkBars[array_key_last($benchmarkBars)]->time->format('Y-m-d');
    }
    foreach ($barsBySymbol as $bars) {
        if ($bars !== []) {
            return $bars[array_key_last($bars)]->time->format('Y-m-d');
        }
    }

    throw new RuntimeException('Unable to resolve as-of date from loaded bars.');
}

/** @param array<string, MarketRegime> $regimes */
function latestRegimeOnOrBefore(array $regimes, string $asOf): ?MarketRegime
{
    ksort($regimes);
    $latest = null;
    foreach ($regimes as $date => $regime) {
        if ($date > $asOf) {
            break;
        }
        $latest = $regime;
    }

    return $latest;
}

/** @param list<Signal> $signals @return list<Signal> */
function recentSignals(array $signals, string $asOf, int $lookbackDays): array
{
    $from = (new DateTimeImmutable($asOf))->modify('-' . max(0, $lookbackDays) . ' days')->format('Y-m-d');

    return array_values(array_filter($signals, static function (Signal $signal) use ($from, $asOf): bool {
        $date = $signal->createdAt->format('Y-m-d');

        return $date >= $from && $date <= $asOf;
    }));
}

/**
 * @param list<array<string, mixed>> $positionStates
 * @param list<string> $openPositionKeys
 * @return list<array<string, mixed>>
 */
function currentPositionStates(array $positionStates, array $openPositionKeys, string $asOf): array
{
    if ($positionStates === [] || $openPositionKeys === []) {
        return [];
    }

    $open = array_fill_keys($openPositionKeys, true);
    $latestDate = null;
    foreach ($positionStates as $state) {
        $date = (string) ($state['date'] ?? '');
        $key = (string) ($state['key'] ?? '');
        if ($date === '' || $date > $asOf || !isset($open[$key])) {
            continue;
        }
        $latestDate = $latestDate === null ? $date : max($latestDate, $date);
    }
    if ($latestDate === null) {
        return [];
    }

    $latest = [];
    foreach ($positionStates as $state) {
        $key = (string) ($state['key'] ?? '');
        if (($state['date'] ?? '') === $latestDate && isset($open[$key])) {
            $latest[$key] = $state;
        }
    }

    return array_values($latest);
}

/** @return array<string, mixed> */
function serializeSignal(Signal $signal, array $strategy): array
{
    $directionMultiplier = $signal->direction === 'short' ? -1.0 : 1.0;
    $riskPct = $signal->entry > 0.0 ? abs($signal->entry - $signal->stop) / $signal->entry : 0.0;
    $targetPct = $signal->entry > 0.0 ? abs($signal->target - $signal->entry) / $signal->entry : 0.0;
    $breakEvenPct = (float) ($strategy['club_rules']['break_even_profit_pct'] ?? 0.01);
    $breakEvenTrigger = $signal->entry * (1.0 + $directionMultiplier * $breakEvenPct);

    return [
        'date' => $signal->createdAt->format('Y-m-d'),
        'symbol' => $signal->symbol,
        'strategy' => $signal->strategy,
        'direction' => $signal->direction,
        'entry' => round($signal->entry, 4),
        'stop' => round($signal->stop, 4),
        'break_even_trigger' => round($breakEvenTrigger, 4),
        'target' => round($signal->target, 4),
        'risk_pct' => $riskPct,
        'target_pct' => $targetPct,
        'reward_r' => $signal->riskPerShare > 0.0 ? abs($signal->target - $signal->entry) / $signal->riskPerShare : 0.0,
        'score' => $signal->score,
        'setup_success_rate' => isset($signal->metadata['setup_success_rate']) ? (float) $signal->metadata['setup_success_rate'] : null,
        'setup_touches' => isset($signal->metadata['setup_touches']) ? (int) $signal->metadata['setup_touches'] : null,
        'setup_avg_forward_return' => isset($signal->metadata['setup_avg_forward_return']) ? (float) $signal->metadata['setup_avg_forward_return'] : null,
        'timeframe' => $signal->metadata['timeframe'] ?? null,
        'ma_type' => $signal->metadata['ma_type'] ?? null,
        'ma_period' => $signal->metadata['ma_period'] ?? null,
        'reasons' => $signal->reasons,
    ];
}

/** @return array<string, mixed>|null */
function serializeRegime(?MarketRegime $regime): ?array
{
    if ($regime === null) {
        return null;
    }

    return [
        'date' => $regime->date->format('Y-m-d'),
        'allows_long_risk' => $regime->allowsLongRisk,
        'score' => $regime->score,
        'warnings' => $regime->warnings,
        'spy_drawdown_pct' => $regime->spyDrawdownPct,
        'spy_rsi14' => $regime->spyRsi14,
    ];
}

/** @return array<string, mixed>|null */
function safePaperAccount(Config $config, HttpClient $http): ?array
{
    try {
        $baseUrl = getenv('APCA_PAPER_BASE_URL') ?: (string) $config->get('trading.alpaca.paper_base_url', 'https://paper-api.alpaca.markets/v2');
        $account = (new AlpacaPaperClient($http, $baseUrl))->account();

        return [
            'status' => $account['status'] ?? null,
            'currency' => $account['currency'] ?? null,
            'cash' => isset($account['cash']) ? (float) $account['cash'] : null,
            'equity' => isset($account['equity']) ? (float) $account['equity'] : (isset($account['portfolio_value']) ? (float) $account['portfolio_value'] : null),
            'buying_power' => isset($account['buying_power']) ? (float) $account['buying_power'] : null,
            'multiplier' => $account['multiplier'] ?? null,
            'shorting_enabled' => $account['shorting_enabled'] ?? null,
            'trading_blocked' => $account['trading_blocked'] ?? null,
            'account_blocked' => $account['account_blocked'] ?? null,
        ];
    } catch (Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

function dataAgeDays(string $asOf): int
{
    return (new DateTimeImmutable($asOf))->diff(new DateTimeImmutable('today'))->days;
}

/** @return list<array{level:string, message:string}> */
function healthRows(string $asOf, array $missingSymbols, ?MarketRegime $regime, ?array $account, bool $offline): array
{
    $rows = [];
    if ($offline) {
        $rows[] = ['level' => 'warning', 'message' => 'offline cache mode: no fresh network validation'];
    }
    $age = dataAgeDays($asOf);
    $rows[] = [
        'level' => $age <= 5 ? 'ok' : 'warning',
        'message' => 'market data age: ' . $age . ' days',
    ];
    if ($missingSymbols !== []) {
        $rows[] = ['level' => 'warning', 'message' => 'missing symbols: ' . implode(',', array_slice($missingSymbols, 0, 20))];
    }
    if ($regime === null) {
        $rows[] = ['level' => 'warning', 'message' => 'market regime unavailable'];
    } elseif (!$regime->allowsLongRisk) {
        $rows[] = ['level' => 'warning', 'message' => 'market regime blocks new long risk'];
    }
    if (is_array($account) && isset($account['error'])) {
        $rows[] = ['level' => 'warning', 'message' => 'paper account unavailable: ' . (string) $account['error']];
    }

    return $rows;
}

/** @param array<string, mixed> $payload */
function actionFromPayload(array $payload): string
{
    $signals = $payload['signals_today'] ?? [];
    $market = $payload['market'] ?? null;
    if (is_array($market) && ($market['allows_long_risk'] ?? false) === false) {
        return $signals === [] ? 'WAIT_MARKET_BLOCKED' : 'REVIEW_SIGNALS_MARKET_BLOCKED';
    }
    if (is_array($signals) && count($signals) > 0) {
        return 'REVIEW_NEW_SIGNALS';
    }

    return 'NO_NEW_SIGNALS_CHECK_OPEN_POSITIONS';
}

/** @param array<string, mixed> $payload */
function formatTelegramText(array $payload): string
{
    $market = is_array($payload['market'] ?? null) ? $payload['market'] : [];
    $summary = is_array($payload['model']['summary'] ?? null) ? $payload['model']['summary'] : [];
    $account = is_array($payload['paper_account'] ?? null) ? $payload['paper_account'] : null;
    $signals = is_array($payload['signals_today'] ?? null) ? $payload['signals_today'] : [];
    $recent = is_array($payload['recent_signals'] ?? null) ? $payload['recent_signals'] : [];
    $positions = is_array($payload['model']['open_positions'] ?? null) ? $payload['model']['open_positions'] : [];
    $warnings = array_values(array_filter($payload['health'] ?? [], static fn (array $row): bool => ($row['level'] ?? '') !== 'ok'));

    $lines = [];
    $lines[] = 'FTT daily status ' . (string) $payload['as_of'];
    $lines[] = 'Action: ' . (string) $payload['action'];
    $lines[] = 'Mode: ' . (($payload['offline'] ?? false) ? 'offline cache' : 'online/provider cache');
    $lines[] = 'Data: loaded ' . (int) ($payload['data']['symbols_loaded'] ?? 0) . '/' . (int) ($payload['data']['symbols_requested'] ?? 0)
        . ', age ' . (int) ($payload['data']['data_age_days'] ?? 0) . 'd';
    $lines[] = 'Market: ' . (($market['allows_long_risk'] ?? false) ? 'risk allowed' : 'risk blocked')
        . ', score ' . number((float) ($market['score'] ?? 0.0))
        . ', SPY DD ' . pct((float) ($market['spy_drawdown_pct'] ?? 0.0))
        . ', RSI ' . number((float) ($market['spy_rsi14'] ?? 0.0));
    if (($market['warnings'] ?? []) !== []) {
        $lines[] = 'Warnings: ' . implode('; ', array_slice($market['warnings'], 0, 5));
    }
    if ($account !== null) {
        if (isset($account['error'])) {
            $lines[] = 'Paper: unavailable';
        } else {
            $lines[] = 'Paper: equity $' . money((float) ($account['equity'] ?? 0.0))
                . ', cash $' . money((float) ($account['cash'] ?? 0.0))
                . ', BP $' . money((float) ($account['buying_power'] ?? 0.0))
                . ', mult ' . (string) ($account['multiplier'] ?? 'n/a');
        }
    }
    $lines[] = 'Model: return ' . pct((float) ($summary['return_pct'] ?? 0.0))
        . ', ann ' . pct((float) ($summary['annualized_return_pct'] ?? 0.0))
        . ', DD ' . pct((float) ($summary['max_drawdown_pct'] ?? 0.0))
        . ', open ' . count($positions);
    $lines[] = '';

    if ($signals === []) {
        $lines[] = 'Signals today: none.';
        if ($recent !== []) {
            $lines[] = 'Recent watchlist signals:';
            foreach (array_slice($recent, 0, 5) as $signal) {
                $lines[] = signalLine($signal);
            }
        }
    } else {
        $lines[] = 'Signals today: ' . count($signals);
        foreach (array_slice($signals, 0, 8) as $signal) {
            $lines[] = signalLine($signal);
        }
    }

    if ($positions !== []) {
        $lines[] = '';
        $lines[] = 'Model open positions:';
        foreach (array_slice($positions, 0, 8) as $position) {
            $lines[] = '- ' . (string) ($position['symbol'] ?? 'n/a')
                . ' pnl ' . pct((float) ($position['pnl_pct'] ?? 0.0))
                . ', stop ' . price((float) ($position['stop'] ?? 0.0))
                . ', BE ' . (($position['break_even_armed'] ?? false) ? 'yes' : 'no');
        }
    }

    if ($warnings !== []) {
        $lines[] = '';
        $lines[] = 'Health warnings:';
        foreach (array_slice($warnings, 0, 5) as $warning) {
            $lines[] = '- ' . (string) ($warning['message'] ?? '');
        }
    }

    return implode("\n", $lines);
}

/** @param array<string, mixed> $signal */
function signalLine(array $signal): string
{
    $setup = trim((string) ($signal['timeframe'] ?? '') . ' ' . strtoupper((string) ($signal['ma_type'] ?? '')) . (string) ($signal['ma_period'] ?? ''));
    $probability = $signal['setup_success_rate'] === null ? 'n/a' : pct((float) $signal['setup_success_rate']);

    return '- ' . (string) $signal['symbol'] . ' ' . strtoupper((string) $signal['direction'])
        . ' ' . $setup
        . ': entry ' . price((float) $signal['entry'])
        . ', stop ' . price((float) $signal['stop'])
        . ', BE ' . price((float) $signal['break_even_trigger'])
        . ', target ' . price((float) $signal['target'])
        . ', risk ' . pct((float) $signal['risk_pct'])
        . ', target ' . pct((float) $signal['target_pct'])
        . ', setup success ' . $probability
        . ', score ' . number((float) $signal['score']);
}

function pct(float $value): string
{
    return sprintf('%+.2f%%', $value * 100.0);
}

function number(float $value): string
{
    return sprintf('%.2f', $value);
}

function price(float $value): string
{
    return sprintf('%.2f', $value);
}

function money(float $value): string
{
    return number_format($value, 2, '.', '');
}

function writeReport(string $path, string $content): void
{
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create report dir: ' . $dir);
    }
    file_put_contents($path, $content);
}
