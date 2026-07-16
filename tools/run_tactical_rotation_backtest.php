#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Backtest\CausalTacticalRotationBacktester;
use FulltimeTrading\Backtest\CausalTacticalRotationEnsembleBacktester;
use FulltimeTrading\Backtest\TacticalRotationQualification;
use FulltimeTrading\Data\AlpacaBarsProvider;
use FulltimeTrading\Data\CachedMarketDataProvider;
use FulltimeTrading\Data\HttpClient;
use FulltimeTrading\Domain\Bar;
use FulltimeTrading\Support\Config;
use FulltimeTrading\Trading\AlpacaPaperClient;
use FulltimeTrading\Trading\TacticalRotationShadowContext;

require dirname(__DIR__) . '/bootstrap.php';

$options = getopt('', [
    'data-start:',
    'trade-start:',
    'oos-start:',
    'validation-start:',
    'holdout-start:',
    'end:',
    'cost-bps:',
    'output:',
    'shadow-output:',
    'cache-namespace:',
    'include-curve',
    'include-robustness',
]);

$root = dirname(__DIR__);
$appConfig = Config::fromFile($root . '/config/config.php');
$profile = require $root . '/config/tactical_rotation.php';
if (($profile['production_approved'] ?? true) !== false
    || ($profile['order_submission_enabled'] ?? true) !== false
    || ($profile['paper_shadow_enabled'] ?? false) !== true) {
    throw new RuntimeException('Tactical rotation research profile must remain fail-closed and paper-shadow only.');
}
$dataStart = (string) ($options['data-start'] ?? '2020-01-01');
$tradeStart = (string) ($options['trade-start'] ?? $profile['validation']['train_start']);
$validationStart = (string) (
    $options['validation-start']
    ?? $options['oos-start']
    ?? $profile['validation']['validation_start']
);
$holdoutStart = (string) ($options['holdout-start'] ?? $profile['validation']['holdout_start']);
$newYork = new DateTimeZone('America/New_York');
$nowNewYork = new DateTimeImmutable('now', $newYork);
$today = $nowNewYork->format('Y-m-d');
$latestClosedDate = (int) $nowNewYork->format('G') >= 17
    ? $today
    : $nowNewYork->modify('-1 day')->format('Y-m-d');
$end = (string) ($options['end'] ?? $latestClosedDate);
if ($end > $latestClosedDate) {
    throw new RuntimeException('The replay end exceeds the latest conservatively closed New York daily bar.');
}
$costs = array_values(array_unique(array_map(
    'floatval',
    array_filter(explode(',', (string) ($options['cost-bps'] ?? '20,30,35,40,50')), 'strlen'),
)));
sort($costs, SORT_NUMERIC);
if ($costs === []) {
    throw new RuntimeException('At least one --cost-bps value is required.');
}

$symbols = profileSymbols($profile);
sort($symbols, SORT_STRING);
$cacheNamespace = (string) ($options['cache-namespace'] ?? 'alpaca-causal-tactical-rotation-v1-sip-split-1day');
$provider = new CachedMarketDataProvider(
    new AlpacaBarsProvider(
        new HttpClient(),
        (string) $appConfig->get('data.alpaca.base_url', 'https://data.alpaca.markets'),
        'sip',
        'split',
        10000,
    ),
    (string) $appConfig->get('cache_path'),
    $cacheNamespace,
);
$barsBySymbol = $provider->getBars($symbols, '1Day', $dataStart, $end);
$coverage = [];
foreach ($symbols as $symbol) {
    $bars = $barsBySymbol[$symbol] ?? [];
    $coverage[$symbol] = [
        'bars' => count($bars),
        'first' => $bars === [] ? null : $bars[0]->time->setTimezone($newYork)->format('Y-m-d'),
        'last' => $bars === [] ? null : $bars[array_key_last($bars)]->time->setTimezone($newYork)->format('Y-m-d'),
    ];
}

$runs = [];
$baseSnapshot = null;
foreach ($costs as $costBps) {
    $backtester = tacticalBacktester($profile, $costBps);
    $result = $backtester->run($barsBySymbol, $tradeStart, $end);
    $curve = $result['curve'];
    $train = $backtester->metrics($curve, $tradeStart, $validationStart);
    $validationMetrics = $backtester->metrics($curve, $validationStart, $holdoutStart);
    $holdout = $backtester->metrics($curve, $holdoutStart, null);
    $full = $backtester->metrics($curve, $tradeStart, null);
    $rolling = rollingWindowAudit($backtester, $curve, [252, 504], 1.0);
    $annual = [];
    $firstYear = (int) substr($tradeStart, 0, 4);
    $lastYear = (int) substr($end, 0, 4);
    for ($year = $firstYear; $year <= $lastYear; $year++) {
        $annual[(string) $year] = $backtester->metrics(
            $curve,
            sprintf('%04d-01-01', $year),
            sprintf('%04d-01-01', $year + 1),
        );
    }
    $qualification = (new TacticalRotationQualification((array) $profile['validation']))
        ->evaluate($train, $validationMetrics, $holdout, $full, $annual);
    $key = rtrim(rtrim(sprintf('%.4F', $costBps), '0'), '.');
    $runs[$key] = [
        'cost_bps' => $costBps,
        'train' => $train,
        'validation_2024_2025' => $validationMetrics,
        'holdout_2026_ytd' => $holdout,
        'full' => $full,
        'rolling_windows' => $rolling,
        'annual' => $annual,
        'negative_years' => $qualification['negative_years'],
        'maximum_single_year_annualized_turnover' => $qualification['maximum_single_year_annualized_turnover'],
        'qualifies' => $qualification['qualifies'],
        'failed_gates' => $qualification['failed_gates'],
        'circuit_activations' => $result['circuit_activations'],
        'position_exit_activations' => $result['position_exit_activations'],
        'next_targets' => resultTargets($result),
    ];
    if (isset($options['include-curve'])) {
        $runs[$key]['curve'] = artifactCurve($curve);
    }
    if (abs($costBps - (float) $profile['cost_bps']) < 0.00001) {
        $baseSnapshot = [
            'features_as_of' => $result['features_as_of'] ?? null,
            'next_targets' => resultTargets($result),
        ];
    }
    unset($curve, $result, $backtester);
}

$baseKey = rtrim(rtrim(sprintf('%.4F', (float) $profile['cost_bps']), '0'), '.');
$stressKey = rtrim(rtrim(sprintf('%.4F', (float) $profile['validation']['required_cost_stress_bps']), '0'), '.');
$selected = isset($runs[$baseKey], $runs[$stressKey])
    && $runs[$baseKey]['qualifies'] === true
    && $runs[$stressKey]['qualifies'] === true;
$targets = resultTargets((array) ($baseSnapshot ?? []));
$executionContexts = paperExecutionContexts(
    $targets,
    $appConfig,
    $nowNewYork,
    $selected,
);
$paperShadow = [
    'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
    'profile' => (string) $profile['profile'],
    'as_of' => $baseSnapshot['features_as_of'] ?? $end,
    'targets' => $targets,
    'execution_contexts' => $executionContexts,
    'validation_selected' => $selected,
    'production_approved' => false,
    'paper_shadow_enabled' => (bool) $profile['paper_shadow_enabled'],
    'order_submission_enabled' => false,
    'order_submission_block_reason' => (string) $profile['order_submission_block_reason'],
];
$robustness = isset($options['include-robustness'])
    ? robustnessAudit(
        $profile,
        $barsBySymbol,
        $tradeStart,
        $validationStart,
        $holdoutStart,
        [(float) $profile['cost_bps'], (float) $profile['validation']['required_cost_stress_bps']],
    )
    : null;

$artifact = [
    'schema' => 2,
    'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
    'profile' => (string) $profile['profile'],
    'causal_contract' => 'completed close D ranks symbols; target can execute only at open D+1',
    'data' => [
        'provider' => 'Alpaca',
        'feed' => 'sip',
        'adjustment' => 'split',
        'timeframe' => '1Day',
        'cache_namespace' => $cacheNamespace,
        'start' => $dataStart,
        'end' => $end,
        'coverage' => $coverage,
        'canonical_sha256' => canonicalBarsHash($barsBySymbol),
    ],
    'periods' => [
        'trade_start' => $tradeStart,
        'train_end_exclusive' => $validationStart,
        'validation_start' => $validationStart,
        'validation_end_exclusive' => $holdoutStart,
        'holdout_start' => $holdoutStart,
        'end' => $end,
    ],
    'strategy' => array_diff_key($profile, ['validation' => true]),
    'implementation' => implementationIdentity($root, $profile),
    'validation' => $profile['validation'],
    'cost_stress' => $runs,
    'selected' => $selected,
    'historical_gates_passed' => $selected,
    'production_approved' => false,
    'paper_shadow' => $paperShadow,
];
if ($robustness !== null) {
    $artifact['robustness'] = $robustness;
}

$output = (string) ($options['output'] ?? $root . '/var/reports/tactical_rotation/latest.json');
$shadowOutput = (string) ($options['shadow-output'] ?? $root . '/var/reports/daily/tactical_rotation_shadow.json');
writeJson($output, $artifact);
writeJson($shadowOutput, $paperShadow);

echo json_encode([
    'output' => $output,
    'shadow_output' => $shadowOutput,
    'selected' => $selected,
    'base' => $runs[$baseKey] ?? null,
    'required_stress' => $runs[$stressKey] ?? null,
    'paper_shadow' => $paperShadow,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";

/** @param array<string,mixed> $profile @return list<string> */
function profileSymbols(array $profile): array
{
    $symbols = [];
    $configs = isset($profile['sleeves']) && is_array($profile['sleeves'])
        ? array_map(
            static fn (array $sleeve): array => array_replace(
                $profile,
                (array) ($sleeve['config'] ?? []),
            ),
            array_filter($profile['sleeves'], 'is_array'),
        )
        : [$profile];
    foreach ($configs as $config) {
        $signalFilter = (array) ($config['signal_market_filter'] ?? []);
        $symbols = array_merge(
            $symbols,
            [(string) ($config['benchmark'] ?? '')],
            [(string) (($config['market_context']['symbol'] ?? ''))],
            [(string) ($signalFilter['symbol'] ?? ($config['benchmark'] ?? ''))],
            (array) ($config['universe'] ?? []),
        );
    }
    $symbols = array_values(array_unique(array_filter(array_map(
        static fn (mixed $symbol): string => strtoupper(trim((string) $symbol)),
        $symbols,
    ))));
    sort($symbols, SORT_STRING);

    return $symbols;
}

/**
 * Build either the legacy single book or independent static-capital sleeves.
 * Profile metadata is harmless to child validation, but is removed so the
 * implementation identity and frozen trading inputs remain easy to inspect.
 *
 * @param array<string,mixed> $profile
 */
function tacticalBacktester(
    array $profile,
    float $costBps,
): CausalTacticalRotationBacktester|CausalTacticalRotationEnsembleBacktester {
    $baseConfig = array_diff_key($profile, [
        'sleeves' => true,
        'validation' => true,
        'profile' => true,
        'status' => true,
        'production_approved' => true,
        'paper_shadow_enabled' => true,
        'order_submission_enabled' => true,
        'order_submission_block_reason' => true,
    ]);
    if (!isset($profile['sleeves']) || !is_array($profile['sleeves'])) {
        return new CausalTacticalRotationBacktester(array_replace(
            $baseConfig,
            ['cost_bps' => $costBps],
        ));
    }

    $sleeves = [];
    foreach ($profile['sleeves'] as $name => $definition) {
        if (!is_string($name) || !is_array($definition)) {
            throw new RuntimeException('Tactical ensemble sleeves must be a keyed map.');
        }
        $sleeves[$name] = [
            'allocation' => (float) ($definition['allocation'] ?? 0.0),
            'config' => array_replace(
                $baseConfig,
                (array) ($definition['config'] ?? []),
                ['cost_bps' => $costBps],
            ),
        ];
    }

    return new CausalTacticalRotationEnsembleBacktester($sleeves);
}

/**
 * The aggregate ensemble row already contains all portfolio-level accounting.
 * Child rows are embedded only so in-memory metrics can reconstruct sleeve
 * episodes; duplicating them in every JSON cost run can exceed PHP's default
 * memory limit. Keep the diagnostic export useful without serializing that
 * redundant internal structure.
 *
 * @param list<array<string,mixed>> $curve
 * @return list<array<string,mixed>>
 */
function artifactCurve(array $curve): array
{
    $exportedFields = array_fill_keys([
        'date',
        'start_equity',
        'equity',
        'equity_low',
        'equity_high',
        'gross_close',
        'gross_bound',
        'turnover',
        'holdings',
        'return_symbols',
        'invested_sleeves',
        'rebalance',
        'risk_signals',
    ], true);

    return array_map(
        static fn (array $row): array => array_intersect_key($row, $exportedFields),
        $curve,
    );
}

/** @param array<string,mixed> $result @return array<string,array<string,mixed>> */
function resultTargets(array $result): array
{
    if (isset($result['next_targets']) && is_array($result['next_targets'])) {
        return array_filter($result['next_targets'], 'is_array');
    }
    if (isset($result['next_target']) && is_array($result['next_target'])) {
        return ['primary' => $result['next_target']];
    }

    return [];
}

/** @param array<string,list<Bar>> $barsBySymbol */
function canonicalBarsHash(array $barsBySymbol): string
{
    ksort($barsBySymbol, SORT_STRING);
    $hash = hash_init('sha256');
    foreach ($barsBySymbol as $symbol => $bars) {
        usort($bars, static fn (Bar $a, Bar $b): int => $a->time <=> $b->time);
        foreach ($bars as $bar) {
            hash_update($hash, implode('|', [
                $symbol,
                $bar->time->format(DATE_ATOM),
                sprintf('%.10F', $bar->open),
                sprintf('%.10F', $bar->high),
                sprintf('%.10F', $bar->low),
                sprintf('%.10F', $bar->close),
                sprintf('%.4F', $bar->volume),
            ]) . "\n");
        }
    }

    return hash_final($hash);
}

/**
 * Session-count rolling windows expose whether a headline CAGR is persistent
 * across start dates. They are reported diagnostically, never used to choose a
 * different parameter set after viewing the later window.
 *
 * @param list<array<string,mixed>> $curve
 * @param list<int> $windows
 * @return array<string,array<string,int|float>>
 */
function rollingWindowAudit(
    CausalTacticalRotationBacktester|CausalTacticalRotationEnsembleBacktester $backtester,
    array $curve,
    array $windows,
    float $cagrThreshold,
): array {
    $audit = [];
    foreach ($windows as $sessions) {
        $cagrs = [];
        $drawdowns = [];
        for ($start = 0; $start + $sessions <= count($curve); $start++) {
            $metrics = $backtester->metrics(array_slice($curve, $start, $sessions));
            $cagrs[] = (float) $metrics['cagr'];
            $drawdowns[] = (float) $metrics['max_drawdown'];
        }
        sort($cagrs, SORT_NUMERIC);
        sort($drawdowns, SORT_NUMERIC);
        $count = count($cagrs);
        if ($count === 0) {
            continue;
        }
        $middle = intdiv($count, 2);
        $median = $count % 2 === 1
            ? $cagrs[$middle]
            : ($cagrs[$middle - 1] + $cagrs[$middle]) / 2.0;
        $passing = count(array_filter(
            $cagrs,
            static fn (float $cagr): bool => $cagr >= $cagrThreshold,
        ));
        $audit[$sessions . '_sessions'] = [
            'count' => $count,
            'minimum_cagr' => $cagrs[0],
            'median_cagr' => $median,
            'maximum_cagr' => $cagrs[$count - 1],
            'share_cagr_at_least_threshold' => $passing / $count,
            'cagr_threshold' => $cagrThreshold,
            'worst_max_drawdown' => $drawdowns[0],
        ];
    }

    return $audit;
}

/** @param array<string,mixed> $payload */
function writeJson(string $path, array $payload): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create report directory: ' . $directory);
    }
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
    if (file_put_contents($temporary, $json) === false || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Unable to atomically write report: ' . $path);
    }
}

/**
 * Resolve the one session for which a close-D signal was intended. Any error
 * is fail-closed: the historical report remains usable, but the shadow cannot
 * be mistaken for a current order instruction.
 *
 * @param array<string,mixed> $target
 * @return array<string,mixed>
 */
function paperExecutionContexts(
    array $targets,
    Config $appConfig,
    DateTimeImmutable $nowNewYork,
    bool $validationSelected,
): array
{
    $calendar = null;
    $asset = null;
    if ((getenv('APCA_PAPER_API_KEY_ID') ?: '') !== '' && (getenv('APCA_PAPER_API_SECRET_KEY') ?: '') !== '') {
        try {
            $client = new AlpacaPaperClient(
                new HttpClient(),
                (string) $appConfig->get('trading.alpaca.paper_base_url', 'https://paper-api.alpaca.markets/v2'),
            );
            $calendar = $client->calendar(...);
            $asset = $client->asset(...);
        } catch (Throwable) {
            return array_fill_keys(array_keys($targets), [
                'status' => 'blocked_broker_check_failed',
                'order_eligible' => false,
                'no_chase' => true,
            ]);
        }
    }

    $resolver = new TacticalRotationShadowContext($calendar, $asset);
    $contexts = [];
    foreach ($targets as $name => $target) {
        $contexts[$name] = $resolver->resolve((array) $target, $nowNewYork, $validationSelected);
    }

    return $contexts;
}

/** @param array<string,mixed> $profile @return array<string,mixed> */
function implementationIdentity(string $root, array $profile): array
{
    $paths = [
        'config/tactical_rotation.php',
        'src/Backtest/CausalTacticalRotationBacktester.php',
        'src/Backtest/CausalTacticalRotationEnsembleBacktester.php',
        'src/Backtest/TacticalRotationQualification.php',
        'src/Trading/TacticalRotationShadowContext.php',
        'tools/run_tactical_rotation_backtest.php',
    ];
    $files = [];
    foreach ($paths as $relative) {
        $hash = hash_file('sha256', $root . '/' . $relative);
        if (!is_string($hash)) {
            throw new RuntimeException('Unable to hash implementation file: ' . $relative);
        }
        $files[$relative] = $hash;
    }

    return [
        'files_sha256' => $files,
        'profile_sha256' => hash('sha256', json_encode($profile, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        'combined_sha256' => hash('sha256', implode("\n", array_map(
            static fn (string $path, string $hash): string => $path . '=' . $hash,
            array_keys($files),
            array_values($files),
        ))),
    ];
}

/**
 * Re-run the frozen profile without each symbol and on the complete-at-start
 * universe. This is diagnostic only and never changes the selected config.
 *
 * @param array<string,mixed> $profile
 * @param array<string,list<Bar>> $barsBySymbol
 * @param list<float> $costs
 * @return array<string,mixed>
 */
function robustnessAudit(
    array $profile,
    array $barsBySymbol,
    string $tradeStart,
    string $validationStart,
    string $holdoutStart,
    array $costs,
): array {
    $leaveOneOut = [];
    foreach ((array) $profile['universe'] as $excluded) {
        $excludedProfile = mapProfileUniverses(
            $profile,
            static fn (array $universe): array => array_values(array_filter(
                $universe,
                static fn (string $symbol): bool => $symbol !== $excluded,
            )),
        );
        $runs = robustnessRuns(
            $excludedProfile,
            $barsBySymbol,
            $tradeStart,
            $validationStart,
            $holdoutStart,
            $costs,
        );
        $leaveOneOut[] = [
            'excluded' => $excluded,
            'costs' => $runs,
            'passes_all_costs' => count(array_filter(
                $runs,
                static fn (array $run): bool => $run['passes_core_gates'] === true,
            )) === count($runs),
        ];
    }

    $timezone = new DateTimeZone('America/New_York');
    $completeAtStart = array_values(array_filter(
        (array) $profile['universe'],
        static function (string $symbol) use ($barsBySymbol, $timezone): bool {
            $bars = $barsBySymbol[$symbol] ?? [];
            return $bars !== [] && $bars[0]->time->setTimezone($timezone)->format('Y-m-d') <= '2020-01-03';
        },
    ));
    $stableProfile = mapProfileUniverses(
        $profile,
        static fn (array $universe): array => array_values(array_intersect($universe, $completeAtStart)),
    );
    $stableRuns = robustnessRuns(
        $stableProfile,
        $barsBySymbol,
        $tradeStart,
        $validationStart,
        $holdoutStart,
        $costs,
    );

    return [
        'selection_use' => 'diagnostic_only',
        'leave_one_out' => $leaveOneOut,
        'leave_one_out_passes_all_costs' => count(array_filter(
            $leaveOneOut,
            static fn (array $row): bool => $row['passes_all_costs'] === true,
        )),
        'leave_one_out_total' => count($leaveOneOut),
        'complete_at_2020_start' => [
            'universe' => $completeAtStart,
            'costs' => $stableRuns,
        ],
    ];
}

/**
 * @param array<string,mixed> $profile
 * @param array<string,list<Bar>> $barsBySymbol
 * @param list<float> $costs
 * @return array<string,array<string,mixed>>
 */
function robustnessRuns(
    array $profile,
    array $barsBySymbol,
    string $tradeStart,
    string $validationStart,
    string $holdoutStart,
    array $costs,
): array {
    $runs = [];
    $benchmarkBars = $barsBySymbol[(string) $profile['benchmark']] ?? [];
    if ($benchmarkBars === []) {
        throw new RuntimeException('Robustness audit is missing benchmark bars.');
    }
    $lastBenchmarkBar = $benchmarkBars[array_key_last($benchmarkBars)];
    $tradeEnd = $lastBenchmarkBar->time
        ->setTimezone(new DateTimeZone('America/New_York'))
        ->format('Y-m-d');
    foreach ($costs as $cost) {
        $backtester = tacticalBacktester($profile, $cost);
        $result = $backtester->run(
            $barsBySymbol,
            $tradeStart,
            $tradeEnd,
        );
        $train = $backtester->metrics($result['curve'], $tradeStart, $validationStart);
        $validation = $backtester->metrics($result['curve'], $validationStart, $holdoutStart);
        $holdout = $backtester->metrics($result['curve'], $holdoutStart, null);
        $full = $backtester->metrics($result['curve']);
        $maximumDrawdown = (float) $profile['validation']['maximum_drawdown'];
        $passes = (float) $train['cagr'] >= (float) $profile['validation']['minimum_train_cagr']
            && (float) $validation['cagr'] >= (float) $profile['validation']['minimum_validation_cagr']
            && (float) $train['max_drawdown'] >= -$maximumDrawdown
            && (float) $validation['max_drawdown'] >= -$maximumDrawdown
            && (float) $full['max_drawdown'] >= -$maximumDrawdown
            && (float) $full['max_gross_bound'] <= (float) $profile['validation']['maximum_gross_bound'];
        $key = rtrim(rtrim(sprintf('%.4F', $cost), '0'), '.');
        $runs[$key] = [
            'train' => $train,
            'validation_2024_2025' => $validation,
            'holdout_2026_ytd' => $holdout,
            'full' => $full,
            'passes_core_gates' => $passes,
        ];
        unset($result, $backtester);
    }

    return $runs;
}

/**
 * Apply the same robustness universe transform to the profile and every child
 * sleeve. This prevents an excluded symbol from leaking back through a sleeve
 * override during leave-one-out or complete-at-start diagnostics.
 *
 * @param array<string,mixed> $profile
 * @param callable(list<string>):list<string> $transform
 * @return array<string,mixed>
 */
function mapProfileUniverses(array $profile, callable $transform): array
{
    $profile['universe'] = $transform(array_values(array_filter(
        (array) ($profile['universe'] ?? []),
        'is_string',
    )));
    if (isset($profile['sleeves']) && is_array($profile['sleeves'])) {
        foreach ($profile['sleeves'] as $name => $definition) {
            if (!is_array($definition)) {
                continue;
            }
            $config = (array) ($definition['config'] ?? []);
            $universe = array_values(array_filter(
                (array) ($config['universe'] ?? $profile['universe']),
                'is_string',
            ));
            $config['universe'] = $transform($universe);
            $profile['sleeves'][$name]['config'] = $config;
        }
    }

    return $profile;
}
