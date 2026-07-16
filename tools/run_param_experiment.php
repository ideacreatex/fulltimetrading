#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Backtest\BacktestResult;
use FulltimeTrading\Backtest\BlockBootstrapAnalyzer;
use FulltimeTrading\Backtest\EntryDataQualityPeriod;
use FulltimeTrading\Backtest\IntradayTouchReclaimConfirmer;
use FulltimeTrading\Backtest\PerformanceReport;
use FulltimeTrading\Backtest\PoosBacktester;
use FulltimeTrading\Backtest\RobustnessAnalyzer;
use FulltimeTrading\Backtest\UsEquitySessionCalendar;
use FulltimeTrading\Backtest\WalkForwardSelector;
use FulltimeTrading\Data\AlpacaBarsProvider;
use FulltimeTrading\Data\AlpacaIntradayManifestVerifier;
use FulltimeTrading\Data\CacheDirectoryMarketDataProvider;
use FulltimeTrading\Data\CachedMarketDataProvider;
use FulltimeTrading\Data\HttpClient;
use FulltimeTrading\Data\MarketDataProvider;
use FulltimeTrading\Data\MarketDataCoverageAnalyzer;
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
    'cache-namespace' => 'alpaca-param-experiment-iex',
    'initial-cash' => null,
    'symbols' => 'USD,SOXL,TECL,TQQQ,UPRO',
    'robust-split-date' => '2024-01-01',
    'min-trades' => '50',
    'min-post-split-trades' => '10',
    'max-best-trade-share-pct' => '0.25',
    'max-top-5-trades-share-pct' => '0.60',
    'max-top-symbol-share-pct' => '0.65',
    'max-post-split-drawdown-pct' => '0.35',
    'min-pre-split-trades' => '50',
    'min-pre-split-annualized-return-pct' => '1.00',
    'max-pre-split-drawdown-pct' => '0.35',
    'min-acceptable-annualized-return-pct' => '1.00',
    'max-acceptable-drawdown-pct' => '0.35',
    'production-max-gross' => '1.25',
    'production-max-observed-gross' => '1.30',
    'production-max-open' => '4',
    'min-symbol-session-coverage-pct' => '0.98',
    'intraday-cache-namespace' => 'alpaca-causal-touch-reclaim-v1-feed-sip-adjustment-split-timeframe-5min',
    'intraday-cache-symbols' => 'SOXL,TECL,TQQQ,UPRO,USD',
    'intraday-timeframe' => '5Min',
    'intraday-snapshot-start' => '2021-01-01',
    'intraday-snapshot-end' => '2026-07-16',
    'intraday-feed' => 'sip',
    'intraday-adjustment' => 'split',
    'intraday-min-p10-bars-per-session' => '70',
    'intraday-touch-tolerance-pct' => '0.005',
    'intraday-reclaim-buffer-pct' => '0.0',
    'intraday-min-bounce-pct' => '0.0',
    'intraday-require-bullish-bar' => 'false',
    'intraday-max-bars-after-touch' => '6',
    'intraday-max-fill-delay-minutes' => '5',
    'intraday-max-chase-atr' => '0.25',
    'intraday-slippage-bps' => '10',
    'intraday-last-reclaim-bar-start' => '15:50',
    'intraday-target-mode' => 'rebase_distance',
    'intraday-reject-pre-entry-stop-breach' => 'true',
    'margin-interest-annual-pct' => '0.0625',
    'poos-base-enabled' => 'false',
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

$symbols = symbolsFromOption((string) $options['symbols']);
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
assertBarsAvailable($symbols, $barsBySymbol, 'experiment symbols');
assertBarsAvailable($marketSymbols, $marketBars, 'market symbols');
$dataCoverage = (new MarketDataCoverageAnalyzer())->analyze(
    array_merge($marketBars, $barsBySymbol),
    $marketBars[$benchmark] ?? [],
    (float) $options['min-symbol-session-coverage-pct'],
);

$indicatorCalculator = new IndicatorCalculator();
$robustnessAnalyzer = new RobustnessAnalyzer();
$walkForwardSelector = new WalkForwardSelector();
$robustnessPolicy = robustnessPolicy($options);
$walkForwardPolicy = walkForwardPolicy($options, $robustnessPolicy);
$productionEnvelope = [
    'max_gross' => (float) $options['production-max-gross'],
    'max_observed_gross' => (float) $options['production-max-observed-gross'],
    'max_open' => (int) $options['production-max-open'],
    'allowed_signal_fill_pairs' => ['advance_next_session:intraday_touch_reclaim'],
];
$generatedVariants = experimentVariants($symbols, $options);
$variants = array_filter($generatedVariants, 'variantModesCompatible');
$skippedIncompatibleVariants = count($generatedVariants) - count($variants);
if ($variants === []) {
    throw new InvalidArgumentException('No compatible signal/fill-mode variants remain after validation.');
}
$requiresIntraday = count(array_filter(
    $variants,
    static fn (array $variant): bool => ($variant['order_fill_mode'] ?? '') === 'intraday_touch_reclaim',
)) > 0;
[$intradayBarsBySymbol, $intradayDataCoverage] = $requiresIntraday
    ? loadChunkedIntradayBars($config, $symbols, $barsBySymbol, $options)
    : [[], null];
$summaries = [];
$rowsByVariant = [];
$trainCandidates = [];
$savedResults = [];
$bestScore = -INF;
$bestScoreName = null;
$bestTargetScore = -INF;
$bestTargetName = null;
$bestRobustScore = -INF;
$bestRobustName = null;
$bestTrainScore = -INF;
$bestTrainName = null;
$bestProductionTrainScore = -INF;
$bestProductionTrainName = null;
$minimumAcceptableAnnualized = (float) ($options['min-acceptable-annualized-return-pct'] ?? 1.0);
$maximumAcceptableDrawdown = abs((float) ($options['max-acceptable-drawdown-pct'] ?? 0.35));

foreach ($variants as $name => $variant) {
    [$strategy, $risk] = configureExperimentVariant($baseStrategy, $baseRisk, $variant);
    $marketAnalyzer = new MarketRegimeAnalyzer($indicatorCalculator, $strategy['market'] ?? []);
    $scanner = new PoosScanner($indicatorCalculator, $strategy);
    $intradayConfirmer = ($variant['order_fill_mode'] ?? '') === 'intraday_touch_reclaim'
        ? new IntradayTouchReclaimConfirmer(
            barMinutes: timeframeMinutes((string) ($options['intraday-timeframe'] ?? '5Min')),
            touchTolerancePct: (float) ($variant['intraday_touch_tolerance_pct'] ?? 0.005),
            reclaimBufferPct: (float) ($variant['intraday_reclaim_buffer_pct'] ?? 0.0),
            minBouncePct: (float) ($variant['intraday_min_bounce_pct'] ?? 0.0),
            requireBullishBar: (bool) ($variant['intraday_require_bullish_bar'] ?? false),
            maxBarsAfterTouch: (int) ($variant['intraday_max_bars_after_touch'] ?? 6),
            maxFillDelayMinutes: (int) ($variant['intraday_max_fill_delay_minutes'] ?? 5),
            maxChaseAtr: (float) ($variant['intraday_max_chase_atr'] ?? 0.25),
            slippageBps: (float) ($variant['intraday_slippage_bps'] ?? 10.0),
            lastReclaimBarStart: (string) ($variant['intraday_last_reclaim_bar_start'] ?? '15:50'),
            rejectPreEntryStopBreach: (bool) ($variant['intraday_reject_pre_entry_stop_breach'] ?? true),
        )
        : null;
    $backtester = new PoosBacktester(
        $indicatorCalculator,
        $marketAnalyzer,
        $scanner,
        $strategy,
        $risk,
        $intradayConfirmer,
    );
    $result = $backtester->run(
        array_intersect_key($barsBySymbol, array_fill_keys($symbols, true)),
        $marketBars,
        $intradayBarsBySymbol,
    );
    $report = (new PerformanceReport())->build($result, $marketBars[$benchmark] ?? [], $benchmark);
    $splitDate = (string) $options['robust-split-date'];
    $diagnostics = diagnostics($result->positionStates, $result->equityCurve);
    $intradayGrossUpperBound = (float) ($result->entryDiagnostics['max_intraday_gross_exposure_upper_bound'] ?? 0.0);
    $maxObservedOrBoundedGross = max((float) $diagnostics['max_gross_exposure'], $intradayGrossUpperBound);
    $trainingDiagnostics = diagnostics(
        array_values(array_filter(
            $result->positionStates,
            static fn (array $row): bool => (string) ($row['date'] ?? '') < $splitDate,
        )),
        array_values(array_filter(
            $result->equityCurve,
            static fn (array $row): bool => (string) ($row['date'] ?? '') < $splitDate,
        )),
    );
    $holdoutDiagnostics = diagnostics(
        array_values(array_filter(
            $result->positionStates,
            static fn (array $row): bool => (string) ($row['date'] ?? '') >= $splitDate,
        )),
        array_values(array_filter(
            $result->equityCurve,
            static fn (array $row): bool => (string) ($row['date'] ?? '') >= $splitDate,
        )),
    );
    $trainingEntryDiagnostics = periodEntryDiagnostics($result->entryDiagnostics, null, $splitDate);
    $holdoutEntryDiagnostics = periodEntryDiagnostics($result->entryDiagnostics, $splitDate, null);
    $trainingIntradayGrossUpperBound = (float) (
        $trainingEntryDiagnostics['max_intraday_gross_exposure_upper_bound'] ?? 0.0
    );
    $holdoutIntradayGrossUpperBound = (float) (
        $holdoutEntryDiagnostics['max_intraday_gross_exposure_upper_bound'] ?? 0.0
    );
    $trainingMaxObservedOrBoundedGross = max(
        (float) $trainingDiagnostics['max_gross_exposure'],
        $trainingIntradayGrossUpperBound,
    );
    $holdoutMaxObservedOrBoundedGross = max(
        (float) $holdoutDiagnostics['max_gross_exposure'],
        $holdoutIntradayGrossUpperBound,
    );
    $robustness = $robustnessAnalyzer->analyze(
        $result->trades,
        $result->equityCurve,
        $splitDate,
    );
    $robustValidation = $robustnessAnalyzer->validate($robustness, $robustnessPolicy);
    $holdoutValidation = $robustnessAnalyzer->validateHoldout($robustness, $robustnessPolicy);
    $report['diagnostics'] = $diagnostics;
    $report['robustness'] = array_merge($robustness, ['selection' => $robustValidation]);
    $report['intraday_entry_diagnostics'] = $result->entryDiagnostics;
    $variantDataQuality = variantDataQuality($dataCoverage, $intradayDataCoverage, $result->entryDiagnostics);
    $trainingVariantDataQuality = variantDataQuality(
        $dataCoverage,
        $intradayDataCoverage,
        $trainingEntryDiagnostics,
    );
    $holdoutVariantDataQuality = variantDataQuality(
        $dataCoverage,
        $intradayDataCoverage,
        $holdoutEntryDiagnostics,
    );
    $report['data_quality'] = $variantDataQuality;
    $report['data_quality_by_period'] = [
        'training' => $trainingVariantDataQuality,
        'holdout' => $holdoutVariantDataQuality,
    ];

    $summary = $report['summary'];
    $ann = (float) $summary['annualized_return_pct'];
    $dd = (float) $summary['max_drawdown_pct'];
    $score = $ann / max(0.01, abs($dd));
    $consistency = consistencyMetrics($report['years'] ?? []);
    $robustScore = robustScore($ann, $dd, $score, $consistency, $robustness);

    $row = [
        'variant' => $name,
        'params' => $variant,
        'return_pct' => (float) $summary['return_pct'],
        'annualized_return_pct' => $ann,
        'max_drawdown_pct' => $dd,
        'trades' => (int) $summary['trades'],
        'wins' => (int) ($summary['wins'] ?? 0),
        'losses' => (int) ($summary['losses'] ?? 0),
        'win_rate' => (float) ($summary['win_rate'] ?? 0.0),
        'profit_factor' => $summary['profit_factor'],
        'sharpe' => $summary['sharpe'],
        'score_return_drawdown' => $score,
        'robust_score' => $robustScore,
        'active_pct' => $diagnostics['active_pct'],
        'avg_open_positions' => $diagnostics['avg_open_positions'],
        'max_open_positions' => $diagnostics['max_open_positions'],
        'avg_gross_exposure_all_days' => $diagnostics['avg_gross_exposure_all_days'],
        'avg_gross_exposure_active_days' => $diagnostics['avg_gross_exposure_active_days'],
        'max_gross_exposure' => $diagnostics['max_gross_exposure'],
        'max_intraday_gross_exposure_upper_bound' => $intradayGrossUpperBound,
        'max_observed_or_bounded_gross_exposure' => $maxObservedOrBoundedGross,
        'intraday_entry_diagnostics' => $result->entryDiagnostics,
        'data_quality_passes' => $variantDataQuality['passes'],
        'data_quality_failures' => $variantDataQuality['failures'],
        'pre_split_data_quality_passes' => $trainingVariantDataQuality['passes'],
        'pre_split_data_quality_failures' => $trainingVariantDataQuality['failures'],
        'post_split_data_quality_passes' => $holdoutVariantDataQuality['passes'],
        'post_split_data_quality_failures' => $holdoutVariantDataQuality['failures'],
        'intraday_snapshot_namespace' => $intradayDataCoverage['namespace'] ?? null,
        'intraday_snapshot_coverage_passes' => $intradayDataCoverage['passes'] ?? null,
        'intraday_snapshot_warnings' => $intradayDataCoverage['warnings'] ?? [],
        'intraday_snapshot_density' => $variantDataQuality['intraday_snapshot_density'],
        'intraday_candidate_missing_sessions' => (int) ($result->entryDiagnostics['missing_candidate_sessions'] ?? 0),
        'margin_interest_annual_pct' => (float) ($result->entryDiagnostics['margin_interest_annual_pct'] ?? 0.0),
        'modeled_margin_interest' => (float) ($result->entryDiagnostics['modeled_margin_interest'] ?? 0.0),
        'worst_year_return_pct' => $consistency['worst_year_return_pct'],
        'median_year_return_pct' => $consistency['median_year_return_pct'],
        'negative_years' => $consistency['negative_years'],
        'best_year_contribution_pct' => $consistency['best_year_contribution_pct'],
        'min_trades_required' => $robustnessPolicy['min_trades'],
        'meets_min_trades' => (int) $summary['trades'] >= $robustnessPolicy['min_trades'],
        'best_trade_gross_profit_share_pct' => $robustness['best_trade_gross_profit_share_pct'],
        'top_5_trades_gross_profit_share_pct' => $robustness['top_5_trades_gross_profit_share_pct'],
        'pnl_without_best_trade' => $robustness['pnl_without_best_trade'],
        'pnl_without_top_5_trades' => $robustness['pnl_without_top_5_trades'],
        'top_symbol' => $robustness['top_symbol'],
        'top_symbol_pnl' => $robustness['top_symbol_pnl'],
        'top_symbol_gross_profit_share_pct' => $robustness['top_symbol_gross_profit_share_pct'],
        'pre_split_return_pct' => $robustness['pre_split']['return_pct'],
        'pre_split_annualized_return_pct' => $robustness['pre_split']['annualized_return_pct'],
        'pre_split_max_drawdown_pct' => $robustness['pre_split']['max_drawdown_pct'],
        'pre_split_trades' => $robustness['pre_split_closed_trades'],
        'pre_split_eod_max_gross_exposure' => $trainingDiagnostics['max_gross_exposure'],
        'pre_split_intraday_gross_exposure_upper_bound' => $trainingIntradayGrossUpperBound,
        'pre_split_max_gross_exposure' => $trainingMaxObservedOrBoundedGross,
        'pre_split_best_trade_gross_profit_share_pct' => $robustness['pre_split_trade_metrics']['best_trade_gross_profit_share_pct'],
        'pre_split_top_5_trades_gross_profit_share_pct' => $robustness['pre_split_trade_metrics']['top_5_trades_gross_profit_share_pct'],
        'pre_split_pnl_without_top_5_trades' => $robustness['pre_split_trade_metrics']['pnl_without_top_5_trades'],
        'pre_split_top_symbol' => $robustness['pre_split_trade_metrics']['top_symbol'],
        'pre_split_top_symbol_gross_profit_share_pct' => $robustness['pre_split_trade_metrics']['top_symbol_gross_profit_share_pct'],
        'post_split_return_pct' => $robustness['post_split']['return_pct'],
        'post_split_annualized_return_pct' => $robustness['post_split']['annualized_return_pct'],
        'post_split_max_drawdown_pct' => $robustness['post_split']['max_drawdown_pct'],
        'post_split_trades' => $robustness['post_split_closed_trades'],
        'post_split_eod_max_gross_exposure' => $holdoutDiagnostics['max_gross_exposure'],
        'post_split_intraday_gross_exposure_upper_bound' => $holdoutIntradayGrossUpperBound,
        'post_split_max_gross_exposure' => $holdoutMaxObservedOrBoundedGross,
        'post_split_best_trade_gross_profit_share_pct' => $robustness['post_split_trade_metrics']['best_trade_gross_profit_share_pct'],
        'post_split_top_5_trades_gross_profit_share_pct' => $robustness['post_split_trade_metrics']['top_5_trades_gross_profit_share_pct'],
        'post_split_pnl_without_top_5_trades' => $robustness['post_split_trade_metrics']['pnl_without_top_5_trades'],
        'post_split_top_symbol' => $robustness['post_split_trade_metrics']['top_symbol'],
        'post_split_top_symbol_gross_profit_share_pct' => $robustness['post_split_trade_metrics']['top_symbol_gross_profit_share_pct'],
        'meets_robust_validation' => $robustValidation['passes'],
        'robust_validation_failures' => $robustValidation['failures'],
        'meets_holdout_validation' => $holdoutValidation['passes'],
        'holdout_validation_failures' => $holdoutValidation['failures'],
        'meets_40_35' => $ann >= 0.40 && $dd >= -0.35,
        'meets_consistent_40_35' => $ann >= 0.40
            && $dd >= -0.35
            && $consistency['negative_years'] <= 1
            && $consistency['worst_year_return_pct'] >= -0.05
            && $consistency['median_year_return_pct'] >= 0.10,
        'meets_annualized_return_floor' => $ann >= $minimumAcceptableAnnualized,
        'meets_acceptable_drawdown' => $dd >= -$maximumAcceptableDrawdown,
        'meets_production_envelope' => (float) ($variant['max_gross'] ?? INF) <= $productionEnvelope['max_gross']
            && (int) ($variant['max_open'] ?? PHP_INT_MAX) <= $productionEnvelope['max_open']
            && $maxObservedOrBoundedGross <= $productionEnvelope['max_observed_gross']
            && productionExecutionAllowed($variant, $productionEnvelope),
    ];
    // Full-period rankings are useful diagnostics but can never become the
    // production answer: they have already observed the holdout interval.
    $row['meets_full_period_target_candidate'] = $row['data_quality_passes']
        && $row['meets_annualized_return_floor']
        && $row['meets_acceptable_drawdown']
        && $row['meets_min_trades']
        && $row['meets_robust_validation']
        && $row['meets_production_envelope'];
    $row['meets_qualified_candidate'] = false;
    $summaries[] = $row;
    $rowsByVariant[$name] = $row;

    $trainCandidate = [
        'variant' => $name,
        'params' => $variant,
        'training' => [
            'points' => $robustness['pre_split']['points'],
            'trades' => $robustness['pre_split_closed_trades'],
            'return_pct' => $robustness['pre_split']['return_pct'],
            'annualized_return_pct' => $robustness['pre_split']['annualized_return_pct'],
            'max_drawdown_pct' => $robustness['pre_split']['max_drawdown_pct'],
            'max_gross_exposure' => $trainingMaxObservedOrBoundedGross,
            'data_quality_passes' => $trainingVariantDataQuality['passes'],
            'data_quality_failures' => $trainingVariantDataQuality['failures'],
            'trade_metrics' => $robustness['pre_split_trade_metrics'],
        ],
    ];
    if ($trainingVariantDataQuality['passes']) {
        $trainCandidates[] = $trainCandidate;
        $trainEvaluation = $walkForwardSelector->evaluate($trainCandidate, $walkForwardPolicy);
        if ($trainEvaluation['passes'] && trainScoreWins((float) $trainEvaluation['score'], $name, $bestTrainScore, $bestTrainName)) {
            $bestTrainScore = (float) $trainEvaluation['score'];
            $bestTrainName = $name;
            $savedResults['walk_forward_train_selected_unconstrained'] = [$name, $variant, $report, $result];
        }
        $productionTrainEvaluation = $walkForwardSelector->evaluate($trainCandidate, $walkForwardPolicy, $productionEnvelope);
        if ($productionTrainEvaluation['passes'] && trainScoreWins((float) $productionTrainEvaluation['score'], $name, $bestProductionTrainScore, $bestProductionTrainName)) {
            $bestProductionTrainScore = (float) $productionTrainEvaluation['score'];
            $bestProductionTrainName = $name;
            $savedResults['walk_forward_train_selected_production'] = [$name, $variant, $report, $result];
        }
    }

    if ($row['data_quality_passes'] && $row['meets_min_trades'] && $score > $bestScore) {
        $bestScore = $score;
        $bestScoreName = $name;
        $savedResults['best_score'] = [$name, $variant, $report, $result];
    }

    if ($row['data_quality_passes'] && $row['meets_40_35'] && $row['meets_min_trades'] && $score > $bestTargetScore) {
        $bestTargetScore = $score;
        $bestTargetName = $name;
        $savedResults['best_40_35'] = [$name, $variant, $report, $result];
    }

    if ($row['data_quality_passes'] && $row['meets_consistent_40_35'] && $row['meets_robust_validation'] && $robustScore > $bestRobustScore) {
        $bestRobustScore = $robustScore;
        $bestRobustName = $name;
        $savedResults['best_consistent_40_35'] = [$name, $variant, $report, $result];
    }
    printf(
        "%s ann=%+.2f%% dd=%.2f%% trades=%d postAnn=%+.2f%% postDD=%.2f%% top1=%.1f%% top5=%.1f%% topSymbol=%s/%.1f%% robust=%s score=%.3f\n",
        $name,
        $ann * 100,
        $dd * 100,
        (int) $summary['trades'],
        (float) $robustness['post_split']['annualized_return_pct'] * 100,
        (float) $robustness['post_split']['max_drawdown_pct'] * 100,
        (float) $robustness['best_trade_gross_profit_share_pct'] * 100,
        (float) $robustness['top_5_trades_gross_profit_share_pct'] * 100,
        (string) ($robustness['top_symbol'] ?? '-'),
        (float) $robustness['top_symbol_gross_profit_share_pct'] * 100,
        $robustValidation['passes'] ? 'yes' : 'no',
        $robustScore,
    );
}

$walkForwardUnconstrained = addFrozenHoldoutEvaluation(
    $walkForwardSelector->select($trainCandidates, $walkForwardPolicy),
    $rowsByVariant,
    (string) $options['robust-split-date'],
);
$walkForwardProduction = addFrozenHoldoutEvaluation(
    $walkForwardSelector->select($trainCandidates, $walkForwardPolicy, $productionEnvelope),
    $rowsByVariant,
    (string) $options['robust-split-date'],
);
$walkForwardUnconstrained = applyDataQualityGate($walkForwardUnconstrained, $dataCoverage);
$walkForwardProduction = applyDataQualityGate($walkForwardProduction, $dataCoverage);
$walkForwardProduction = qualifyFrozenSelection(
    $walkForwardProduction,
    $minimumAcceptableAnnualized,
    $maximumAcceptableDrawdown,
    $productionEnvelope,
);
$historicallyQualifiedName = is_string($walkForwardProduction['historically_qualified_variant'] ?? null)
    ? $walkForwardProduction['historically_qualified_variant']
    : null;
$paperExecutionParity = !$requiresIntraday;
$bestQualifiedName = $paperExecutionParity ? $historicallyQualifiedName : null;
$walkForwardProduction['paper_execution_parity_passes'] = $paperExecutionParity;
$walkForwardProduction['paper_execution_parity_reason'] = $paperExecutionParity
    ? null
    : 'intraday_touch_reclaim is research-only and has no paper planner/monitor executor yet';
$walkForwardProduction['paper_deployable_variant'] = $bestQualifiedName;

usort($summaries, static function (array $a, array $b): int {
    $scoreOrder = $b['robust_score'] <=> $a['robust_score'];

    return $scoreOrder !== 0 ? $scoreOrder : strcmp((string) $a['variant'], (string) $b['variant']);
});
$summaryPath = $outputDir . '/summary.json';
writeJson($summaryPath, [
    'generated_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
    'start' => $options['start'],
    'end' => $options['end'],
    'provider' => $options['provider'],
    'feed' => $options['feed'],
    'cache_namespace' => $options['cache-namespace'],
    'intraday_cache_namespace' => $requiresIntraday ? $options['intraday-cache-namespace'] : null,
    'intraday_timeframe' => $requiresIntraday ? $options['intraday-timeframe'] : null,
    'intraday_data_coverage' => $intradayDataCoverage,
    'symbols' => $symbols,
    'portfolio_sizing_basis' => 'marked_equity_and_current_market_value',
    'data_coverage' => $dataCoverage,
    'robust_split_date' => $options['robust-split-date'],
    'robustness_policy' => $robustnessPolicy,
    'variants' => count($variants),
    'skipped_incompatible_variants' => $skippedIncompatibleVariants,
    'minimum_acceptable_annualized_return_pct' => $minimumAcceptableAnnualized,
    'maximum_acceptable_drawdown_pct' => $maximumAcceptableDrawdown,
    'production_envelope' => $productionEnvelope,
    'best_score_variant' => $bestScoreName,
    'best_40_35_variant' => $bestTargetName,
    'best_consistent_40_35_variant' => $bestRobustName,
    'best_historically_qualified_variant' => $historicallyQualifiedName,
    'best_qualified_variant' => $bestQualifiedName,
    'qualified_variants' => $bestQualifiedName === null ? 0 : 1,
    'full_period_target_variants' => count(array_filter(
        $summaries,
        static fn (array $row): bool => ($row['meets_full_period_target_candidate'] ?? false) === true,
    )),
    'paper_execution_parity' => [
        'passes' => $paperExecutionParity,
        'reason' => $walkForwardProduction['paper_execution_parity_reason'],
    ],
    'exploratory_rankings_use_full_period_and_holdout' => true,
    'walk_forward_unconstrained' => $walkForwardUnconstrained,
    'walk_forward_production_envelope' => $walkForwardProduction,
    'summaries' => $summaries,
]);

foreach ($savedResults as $label => [$name, $variant, $report, $result]) {
    saveResult($outputDir . '/' . $label, $name, $variant, $report, $result);
}

echo "Summary: {$summaryPath}\n";

/** @return list<string> */
function symbolsFromOption(string $value): array
{
    $symbols = [];
    foreach (preg_split('/[\s,;]+/', $value) ?: [] as $rawSymbol) {
        $symbol = strtoupper(trim($rawSymbol));
        if ($symbol === '') {
            continue;
        }
        if (!preg_match('/^[A-Z][A-Z0-9.-]{0,9}$/', $symbol)) {
            throw new InvalidArgumentException('Invalid symbol in --symbols: ' . $rawSymbol);
        }
        $symbols[$symbol] = true;
    }

    if ($symbols === []) {
        throw new InvalidArgumentException('--symbols must contain at least one valid ticker.');
    }

    return array_keys($symbols);
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
        throw new RuntimeException(
            'Market-data request failed; refusing an implicit unscoped cache fallback: ' . $e->getMessage(),
            0,
            $e,
        );
    }
}

/**
 * Load an immutable full-range intraday snapshot independently of any trades
 * selected by the daily model. Exact per-symbol/year cache keys prevent the
 * broad cache-directory fallback from leaking post-selected replay windows.
 *
 * @param list<string> $symbols
 * @param array<string, list<Bar>> $dailyBarsBySymbol
 * @param array<string, string|null> $options
 * @return array{0:array<string, list<Bar>>,1:array<string, mixed>}
 */
function loadChunkedIntradayBars(
    Config $config,
    array $symbols,
    array $dailyBarsBySymbol,
    array $options,
): array {
    $namespace = trim((string) ($options['intraday-cache-namespace'] ?? ''));
    if ($namespace === '') {
        throw new InvalidArgumentException('--intraday-cache-namespace is required for causal intraday runs.');
    }
    $timeframe = (string) ($options['intraday-timeframe'] ?? '5Min');
    timeframeMinutes($timeframe);

    $cacheSymbols = symbolsFromOption((string) ($options['intraday-cache-symbols'] ?? ''));
    $cacheSet = array_fill_keys($cacheSymbols, true);
    $missing = array_values(array_filter($symbols, static fn (string $symbol): bool => !isset($cacheSet[$symbol])));
    if ($missing !== []) {
        throw new RuntimeException('Intraday snapshot does not declare requested symbols: ' . implode(', ', $missing));
    }

    $snapshotStart = (string) ($options['intraday-snapshot-start'] ?? '2021-01-01');
    $snapshotEnd = (string) ($options['intraday-snapshot-end'] ?? '2026-07-16');
    $feed = strtolower((string) ($options['intraday-feed'] ?? 'sip'));
    $adjustment = strtolower((string) ($options['intraday-adjustment'] ?? 'split'));
    $requestedStart = (string) ($options['start'] ?? $snapshotStart);
    $requestedEnd = (string) ($options['end'] ?? $snapshotEnd);
    if ($requestedStart < $snapshotStart || $requestedEnd >= $snapshotEnd) {
        throw new RuntimeException(sprintf(
            'Requested intraday period %s..%s is outside exact end-exclusive snapshot %s..%s.',
            $requestedStart,
            $requestedEnd,
            $snapshotStart,
            $snapshotEnd,
        ));
    }

    $cachePath = (string) $config->get('cache_path');
    $manifestProvenance = AlpacaIntradayManifestVerifier::verify(
        cacheDir: $cachePath,
        namespace: $namespace,
        symbols: $cacheSymbols,
        timeframe: $timeframe,
        feed: $feed,
        adjustment: $adjustment,
        snapshotStart: $snapshotStart,
        snapshotEndExclusive: $snapshotEnd,
    );

    $provider = new CacheDirectoryMarketDataProvider($cachePath, $namespace);
    $ranges = yearlySnapshotRanges($snapshotStart, $snapshotEnd);
    $timezone = new DateTimeZone('America/New_York');
    $barsBySymbol = array_fill_keys($symbols, []);
    $seen = [];
    foreach ($symbols as $symbol) {
        foreach ($ranges as [$chunkStart, $chunkEnd]) {
            $chunk = $provider->getBars([$symbol], $timeframe, $chunkStart, $chunkEnd);
            foreach ($chunk[$symbol] ?? [] as $bar) {
                $session = $bar->time->setTimezone($timezone)->format('Y-m-d');
                if ($session < $requestedStart || $session > $requestedEnd) {
                    continue;
                }
                $timestamp = $bar->time->format(DATE_ATOM);
                if (isset($seen[$symbol][$timestamp])) {
                    $existing = $seen[$symbol][$timestamp];
                    if (
                        $existing->open !== $bar->open
                        || $existing->high !== $bar->high
                        || $existing->low !== $bar->low
                        || $existing->close !== $bar->close
                        || $existing->volume !== $bar->volume
                    ) {
                        throw new RuntimeException('Conflicting chunk boundary bar for ' . $symbol . ' at ' . $timestamp);
                    }
                    continue;
                }
                $seen[$symbol][$timestamp] = $bar;
                $barsBySymbol[$symbol][] = $bar;
            }
        }
        usort($barsBySymbol[$symbol], static fn (Bar $a, Bar $b): int => $a->time <=> $b->time);
    }

    $minimumCoverage = (float) ($options['min-symbol-session-coverage-pct'] ?? 0.98);
    $minimumP10BarsPerSession = max(
        1.0,
        (float) ($options['intraday-min-p10-bars-per-session'] ?? 70.0),
    );
    $coverage = [
        'passes' => true,
        'namespace' => $namespace,
        'timeframe' => $timeframe,
        'feed' => $feed,
        'adjustment' => $adjustment,
        'snapshot_start' => $snapshotStart,
        'snapshot_end_exclusive' => $snapshotEnd,
        'manifest_provenance' => $manifestProvenance,
        'minimum_session_coverage_pct' => $minimumCoverage,
        'minimum_p10_regular_bars_per_covered_session' => $minimumP10BarsPerSession,
        'failures' => [],
        'warnings' => [],
        'symbols' => [],
    ];
    foreach ($symbols as $symbol) {
        $expected = [];
        foreach ($dailyBarsBySymbol[$symbol] ?? [] as $bar) {
            $session = $bar->time->setTimezone($timezone)->format('Y-m-d');
            if ($session >= $requestedStart && $session <= $requestedEnd) {
                $expected[$session] = true;
            }
        }
        $observed = [];
        $regularBarsBySession = [];
        $lastTimestampBySession = [];
        $withinSessionGapsMinutes = [];
        $regularBars = 0;
        foreach ($barsBySymbol[$symbol] as $bar) {
            $local = $bar->time->setTimezone($timezone);
            $clock = $local->format('H:i');
            $session = $local->format('Y-m-d');
            if (!UsEquitySessionCalendar::isRegularBarStart($session, $clock)) {
                continue;
            }
            $observed[$session] = true;
            $regularBarsBySession[$session] = (int) ($regularBarsBySession[$session] ?? 0) + 1;
            if (isset($lastTimestampBySession[$session])) {
                $withinSessionGapsMinutes[] = ($bar->time->getTimestamp() - $lastTimestampBySession[$session]) / 60.0;
            }
            $lastTimestampBySession[$session] = $bar->time->getTimestamp();
            $regularBars++;
        }
        $covered = count(array_intersect_key($expected, $observed));
        $expectedCount = count($expected);
        $coveragePct = $expectedCount > 0 ? $covered / $expectedCount : 0.0;
        $sessionCoveragePasses = $expectedCount > 0 && $coveragePct >= $minimumCoverage;
        $p10BarsPerSession = numericPercentile(array_values($regularBarsBySession), 0.10);
        $densityPasses = $p10BarsPerSession !== null
            && $p10BarsPerSession >= $minimumP10BarsPerSession;
        $passes = $sessionCoveragePasses && $densityPasses;
        $coverage['symbols'][$symbol] = [
            'bars' => count($barsBySymbol[$symbol]),
            'regular_session_bars' => $regularBars,
            'expected_sessions' => $expectedCount,
            'covered_sessions' => $covered,
            'coverage_pct' => $coveragePct,
            'passes' => $passes,
            'session_coverage_passes' => $sessionCoveragePasses,
            'density_passes' => $densityPasses,
            'median_regular_bars_per_covered_session' => numericPercentile(array_values($regularBarsBySession), 0.50),
            'p10_regular_bars_per_covered_session' => $p10BarsPerSession,
            'median_within_session_gap_minutes' => numericPercentile($withinSessionGapsMinutes, 0.50),
            'p90_within_session_gap_minutes' => numericPercentile($withinSessionGapsMinutes, 0.90),
            'max_within_session_gap_minutes' => $withinSessionGapsMinutes !== [] ? max($withinSessionGapsMinutes) : null,
            'missing_session_examples' => array_slice(array_keys(array_diff_key($expected, $observed)), 0, 10),
            'special_close_sessions_observed' => count(array_filter(
                array_keys($regularBarsBySession),
                static fn (string $session): bool => UsEquitySessionCalendar::closeTime($session) !== '16:00',
            )),
        ];
        if (numericPercentile($withinSessionGapsMinutes, 0.90) > timeframeMinutes($timeframe) * 2.0) {
            $coverage['warnings'][] = 'sparse_intraday_feed:' . $symbol;
        }
        if (!$sessionCoveragePasses) {
            $coverage['passes'] = false;
            $coverage['failures'][] = sprintf(
                'intraday_session_coverage:%s:%.4f<%.4f',
                $symbol,
                $coveragePct,
                $minimumCoverage,
            );
        }
        if (!$densityPasses) {
            $coverage['passes'] = false;
            $coverage['failures'][] = sprintf(
                'intraday_density_p10:%s:%.2f<%.2f',
                $symbol,
                (float) ($p10BarsPerSession ?? 0.0),
                $minimumP10BarsPerSession,
            );
        }
    }

    return [$barsBySymbol, $coverage];
}

/** @return list<array{0:string,1:string}> */
function yearlySnapshotRanges(string $start, string $end): array
{
    $startDate = new DateTimeImmutable($start . 'T00:00:00+00:00');
    $endDate = new DateTimeImmutable($end . 'T00:00:00+00:00');
    if ($endDate <= $startDate) {
        throw new InvalidArgumentException('Intraday snapshot end must be after its start.');
    }

    $ranges = [];
    $cursor = $startDate;
    while ($cursor < $endDate) {
        $nextYear = $cursor->modify('first day of january next year');
        $chunkEnd = $nextYear < $endDate ? $nextYear : $endDate;
        $ranges[] = [$cursor->format('Y-m-d'), $chunkEnd->format('Y-m-d')];
        $cursor = $chunkEnd;
    }

    return $ranges;
}

function timeframeMinutes(string $timeframe): int
{
    if (!preg_match('/^(\d+)Min$/i', trim($timeframe), $matches)) {
        throw new InvalidArgumentException('Causal intraday timeframe must use NMin, for example 5Min.');
    }

    return max(1, (int) $matches[1]);
}

/** @param list<int|float> $values */
function numericPercentile(array $values, float $quantile): ?float
{
    if ($values === []) {
        return null;
    }
    $numbers = array_map('floatval', $values);
    sort($numbers, SORT_NUMERIC);
    $quantile = min(1.0, max(0.0, $quantile));
    $position = (count($numbers) - 1) * $quantile;
    $lower = (int) floor($position);
    $upper = (int) ceil($position);
    if ($lower === $upper) {
        return $numbers[$lower];
    }
    $weight = $position - $lower;

    return $numbers[$lower] * (1.0 - $weight) + $numbers[$upper] * $weight;
}

/** @param list<string> $symbols @param array<string, list<Bar>> $barsBySymbol */
function assertBarsAvailable(array $symbols, array $barsBySymbol, string $label): void
{
    $missing = array_values(array_filter(
        $symbols,
        static fn (string $symbol): bool => ($barsBySymbol[$symbol] ?? []) === [],
    ));
    if ($missing !== []) {
        throw new RuntimeException(sprintf(
            'Missing daily bars for %s: %s. Refusing a partial-universe experiment.',
            $label,
            implode(', ', $missing),
        ));
    }
}

/** @param list<string> $symbols @param array<string, string> $options @return array<string, array<string, mixed>> */
function experimentVariants(array $symbols, array $options = []): array
{
    $base = [
        'symbols' => $symbols,
        'initial_cash' => (float) ($options['initial-cash'] ?? 1000.0),
        'max_open' => (int) ($options['max-open'] ?? 4),
        'max_gross' => (float) ($options['max-gross'] ?? 2.0),
        'family_cap' => (float) ($options['family-cap'] ?? 1.10),
        'reentry_cooldown_days' => (int) ($options['reentry-cooldown-days'] ?? 0),
        'allow_same_strength_after_days' => (int) ($options['same-after'] ?? 30),
        'min_touches' => (int) ($options['min-touches'] ?? 4),
        'min_success_rate' => (float) ($options['min-success-rate'] ?? 0.70),
        'require_close_above_support' => !isset($options['support-require-close-above'])
            || boolOption($options, 'support-require-close-above'),
        'touch_tolerance_pct' => (float) ($options['touch-tolerance-pct'] ?? 0.015),
        'near_atr_multiple' => (float) ($options['near-atr-multiple'] ?? 0.60),
        'stop_atr_multiple' => (float) ($options['stop-atr-multiple'] ?? 1.5),
        'target_atr_multiple' => (float) ($options['target-atr-multiple'] ?? 3.0),
        'signal_cooldown_bars' => (int) ($options['signal-cooldown-bars'] ?? 10),
        'support_entry_signal_mode' => enumOption(
            $options,
            'support-entry-signal-mode',
            ['touch_confirmed', 'advance_next_session'],
            'touch_confirmed',
        ),
        'support_weekly_enabled' => !isset($options['support-weekly-enabled'])
            || boolOption($options, 'support-weekly-enabled'),
        'advance_max_distance_pct' => (float) ($options['advance-max-distance-pct'] ?? 0.10),
        'advance_max_distance_atr' => (float) ($options['advance-max-distance-atr'] ?? 3.0),
        'advance_min_level_slope_pct' => (float) ($options['advance-min-level-slope-pct'] ?? -0.01),
        'advance_require_untouched' => !isset($options['advance-require-untouched'])
            || boolOption($options, 'advance-require-untouched'),
        'advance_level_projection' => enumOption(
            $options,
            'advance-level-projection',
            ['static', 'dynamic_exact', 'linear'],
            'static',
        ),
        'advance_max_projection_pct' => (float) ($options['advance-max-projection-pct'] ?? 0.01),
        'layers' => (int) ($options['layers'] ?? 3),
        'require_green_garden' => true,
        'break_even_add_on_fraction' => 0.0,
        'break_even_profit_pct' => (float) ($options['break-even-profit-pct'] ?? 0.01),
        'break_even_trigger_mode' => enumOption($options, 'break-even-trigger-mode', ['high', 'close'], 'high'),
        'break_even_stop_mode' => enumOption($options, 'break-even-stop-mode', ['hard', 'close'], 'hard'),
        'break_even_stop_offset_pct' => (float) ($options['break-even-stop-offset-pct'] ?? 0.0),
        'partial_take_profit_pct' => (float) ($options['partial-take-profit-pct'] ?? 0.5),
        'order_valid_bars' => (int) ($options['order-valid-bars'] ?? 1),
        'order_fill_mode' => enumOption(
            $options,
            'order-fill-mode',
            ['same_day_touch', 'next_touch', 'intraday_touch_reclaim'],
            'next_touch',
        ),
        'intraday_touch_tolerance_pct' => (float) ($options['intraday-touch-tolerance-pct'] ?? 0.005),
        'intraday_reclaim_buffer_pct' => (float) ($options['intraday-reclaim-buffer-pct'] ?? 0.0),
        'intraday_min_bounce_pct' => (float) ($options['intraday-min-bounce-pct'] ?? 0.0),
        'intraday_require_bullish_bar' => boolOption($options, 'intraday-require-bullish-bar'),
        'intraday_max_bars_after_touch' => (int) ($options['intraday-max-bars-after-touch'] ?? 6),
        'intraday_max_fill_delay_minutes' => (int) ($options['intraday-max-fill-delay-minutes'] ?? 5),
        'intraday_max_chase_atr' => (float) ($options['intraday-max-chase-atr'] ?? 0.25),
        'intraday_slippage_bps' => (float) ($options['intraday-slippage-bps'] ?? 10.0),
        'intraday_last_reclaim_bar_start' => (string) ($options['intraday-last-reclaim-bar-start'] ?? '15:50'),
        'intraday_target_mode' => enumOption(
            $options,
            'intraday-target-mode',
            ['rebase_distance', 'planned_price'],
            'rebase_distance',
        ),
        'intraday_reject_pre_entry_stop_breach' => !isset($options['intraday-reject-pre-entry-stop-breach'])
            || boolOption($options, 'intraday-reject-pre-entry-stop-breach'),
        'unstable_market_position_pct' => (float) ($options['unstable-market-position-pct'] ?? 0.05),
        'stable_market_score_threshold' => (float) ($options['stable-market-score-threshold'] ?? 2.5),
        'swing_stop_mode' => enumOption($options, 'swing-stop-mode', ['hard', 'mental', 'hybrid'], 'hard'),
        'hard_stop_fill_mode' => enumOption($options, 'hard-stop-fill-mode', ['gap_open', 'stop_price'], 'gap_open'),
        'transaction_cost_bps' => (float) ($options['transaction-cost-bps'] ?? 0.0),
        'margin_interest_annual_pct' => max(0.0, (float) ($options['margin-interest-annual-pct'] ?? 0.0625)),
        'poos_base_enabled' => boolOption($options, 'poos-base-enabled'),
    ];

    $variants = boolOption($options, 'risk-only') ? [] : ['baseline' => $base];
    $add = static function (string $prefix, array $overrides) use (&$variants, $base): void {
        $variant = array_merge($base, $overrides);
        $name = $prefix . '_' . shortParams($overrides);
        $variants[$name] = $variant;
    };

    if (boolOption($options, 'joint-grid')) {
        $dimensions = [
            'max_gross' => floatListOption($options, 'max-gross-values', [$base['max_gross']]),
            'family_cap' => floatListOption($options, 'family-cap-values', [$base['family_cap']]),
            'reentry_cooldown_days' => intListOption($options, 'cooldown-days', [$base['reentry_cooldown_days']]),
            'allow_same_strength_after_days' => intListOption($options, 'same-after-days', [$base['allow_same_strength_after_days']]),
            'min_touches' => intListOption($options, 'min-touches-values', [$base['min_touches']]),
            'min_success_rate' => floatListOption($options, 'min-success-rate-values', [$base['min_success_rate']]),
            'touch_tolerance_pct' => floatListOption($options, 'touch-tolerance-pct-values', [$base['touch_tolerance_pct']]),
            'near_atr_multiple' => floatListOption($options, 'near-atr-multiple-values', [$base['near_atr_multiple']]),
            'stop_atr_multiple' => floatListOption($options, 'stop-atr-multiple-values', [$base['stop_atr_multiple']]),
            'target_atr_multiple' => floatListOption($options, 'target-atr-multiple-values', [$base['target_atr_multiple']]),
            'signal_cooldown_bars' => intListOption($options, 'signal-cooldown-bars-values', [$base['signal_cooldown_bars']]),
            'support_entry_signal_mode' => stringListOption(
                $options,
                'support-entry-signal-modes',
                [$base['support_entry_signal_mode']],
                ['touch_confirmed', 'advance_next_session'],
            ),
            'support_weekly_enabled' => boolListOption(
                $options,
                'support-weekly-enabled-values',
                [$base['support_weekly_enabled']],
            ),
            'advance_max_distance_pct' => floatListOption(
                $options,
                'advance-max-distance-pct-values',
                [$base['advance_max_distance_pct']],
            ),
            'advance_max_distance_atr' => floatListOption(
                $options,
                'advance-max-distance-atr-values',
                [$base['advance_max_distance_atr']],
            ),
            'advance_min_level_slope_pct' => signedFloatListOption(
                $options,
                'advance-min-level-slope-pct-values',
                [$base['advance_min_level_slope_pct']],
            ),
            'advance_require_untouched' => boolListOption(
                $options,
                'advance-require-untouched-values',
                [$base['advance_require_untouched']],
            ),
            'advance_level_projection' => stringListOption(
                $options,
                'advance-level-projection-modes',
                [$base['advance_level_projection']],
                ['static', 'dynamic_exact', 'linear'],
            ),
            'advance_max_projection_pct' => nonNegativeFloatListOption(
                $options,
                'advance-max-projection-pct-values',
                [$base['advance_max_projection_pct']],
            ),
            'layers' => intListOption($options, 'layers-values', [$base['layers']]),
            'break_even_profit_pct' => nonNegativeFloatListOption(
                $options,
                'break-even-profit-pct-values',
                [$base['break_even_profit_pct']],
            ),
            'break_even_trigger_mode' => stringListOption(
                $options,
                'break-even-trigger-modes',
                [$base['break_even_trigger_mode']],
                ['high', 'close'],
            ),
            'break_even_stop_mode' => stringListOption(
                $options,
                'break-even-stop-modes',
                [$base['break_even_stop_mode']],
                ['hard', 'close'],
            ),
            'break_even_stop_offset_pct' => nonNegativeFloatListOption(
                $options,
                'break-even-stop-offset-pct-values',
                [$base['break_even_stop_offset_pct']],
            ),
            'partial_take_profit_pct' => nonNegativeFloatListOption(
                $options,
                'partial-take-profit-pct-values',
                [$base['partial_take_profit_pct']],
            ),
            'order_valid_bars' => intListOption($options, 'order-valid-bars-values', [$base['order_valid_bars']]),
            'order_fill_mode' => stringListOption(
                $options,
                'order-fill-modes',
                [$base['order_fill_mode']],
                ['same_day_touch', 'next_touch', 'intraday_touch_reclaim'],
            ),
            'intraday_touch_tolerance_pct' => nonNegativeFloatListOption(
                $options,
                'intraday-touch-tolerance-pct-values',
                [$base['intraday_touch_tolerance_pct']],
            ),
            'intraday_reclaim_buffer_pct' => nonNegativeFloatListOption(
                $options,
                'intraday-reclaim-buffer-pct-values',
                [$base['intraday_reclaim_buffer_pct']],
            ),
            'intraday_min_bounce_pct' => nonNegativeFloatListOption(
                $options,
                'intraday-min-bounce-pct-values',
                [$base['intraday_min_bounce_pct']],
            ),
            'intraday_require_bullish_bar' => boolListOption(
                $options,
                'intraday-require-bullish-bar-values',
                [$base['intraday_require_bullish_bar']],
            ),
            'intraday_max_bars_after_touch' => intListOption(
                $options,
                'intraday-max-bars-after-touch-values',
                [$base['intraday_max_bars_after_touch']],
            ),
            'intraday_max_fill_delay_minutes' => intListOption(
                $options,
                'intraday-max-fill-delay-minutes-values',
                [$base['intraday_max_fill_delay_minutes']],
            ),
            'intraday_max_chase_atr' => nonNegativeFloatListOption(
                $options,
                'intraday-max-chase-atr-values',
                [$base['intraday_max_chase_atr']],
            ),
            'intraday_slippage_bps' => nonNegativeFloatListOption(
                $options,
                'intraday-slippage-bps-values',
                [$base['intraday_slippage_bps']],
            ),
            'intraday_last_reclaim_bar_start' => timeListOption(
                $options,
                'intraday-last-reclaim-bar-start-values',
                [$base['intraday_last_reclaim_bar_start']],
            ),
            'intraday_target_mode' => stringListOption(
                $options,
                'intraday-target-modes',
                [$base['intraday_target_mode']],
                ['rebase_distance', 'planned_price'],
            ),
            'intraday_reject_pre_entry_stop_breach' => boolListOption(
                $options,
                'intraday-reject-pre-entry-stop-breach-values',
                [$base['intraday_reject_pre_entry_stop_breach']],
            ),
            'unstable_market_position_pct' => floatListOption(
                $options,
                'unstable-market-position-pct-values',
                [$base['unstable_market_position_pct']],
            ),
            'transaction_cost_bps' => nonNegativeFloatListOption(
                $options,
                'transaction-cost-bps-values',
                [$base['transaction_cost_bps']],
            ),
        ];

        $maxVariants = max(1, (int) ($options['joint-max-variants'] ?? 5000));
        $rawVariantCount = array_product(array_map('count', $dimensions));
        if ($rawVariantCount > $maxVariants * 20) {
            throw new InvalidArgumentException(sprintf(
                'Raw joint grid expands to %d rows before compatibility pruning; narrow dimensions deliberately.',
                $rawVariantCount,
            ));
        }

        $variants = [];
        $overrides = [[]];
        foreach ($dimensions as $key => $values) {
            $expanded = [];
            foreach ($overrides as $row) {
                $rowValues = $values;
                if (
                    str_starts_with($key, 'intraday_')
                    && ($row['order_fill_mode'] ?? null) !== 'intraday_touch_reclaim'
                ) {
                    $rowValues = [$base[$key]];
                }
                foreach ($rowValues as $value) {
                    $candidate = array_merge($row, [$key => $value]);
                    if ($key === 'order_fill_mode' && !variantModesCompatible($candidate)) {
                        continue;
                    }
                    $expanded[] = $candidate;
                }
            }
            $overrides = $expanded;
        }
        $overrides = array_values(array_filter($overrides, 'variantModesCompatible'));
        $variantCount = count($overrides);
        if ($variantCount > $maxVariants) {
            throw new InvalidArgumentException(sprintf(
                'Compatible joint grid expands to %d variants; raise --joint-max-variants above %d deliberately.',
                $variantCount,
                $maxVariants,
            ));
        }
        foreach ($overrides as $row) {
            $add('joint', $row);
        }

        return $variants;
    }

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
        (int) ($options['order-valid-bars'] ?? 1),
    ]);
    $orderFillModes = stringListOption(
        $options,
        'order-fill-modes',
        [(string) $base['order_fill_mode']],
        ['same_day_touch', 'next_touch', 'intraday_touch_reclaim'],
    );
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

/** @param array<string, string> $options @param list<float> $default @return list<float> */
function signedFloatListOption(array $options, string $key, array $default): array
{
    if (!isset($options[$key])) {
        return $default;
    }

    $values = array_map(
        static fn (string $value): float => (float) trim($value),
        explode(',', (string) $options[$key]),
    );

    return $values === [] ? $default : array_values(array_unique($values, SORT_REGULAR));
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

/** @param array<string, string> $options @param list<bool> $default @return list<bool> */
function boolListOption(array $options, string $key, array $default): array
{
    if (!isset($options[$key])) {
        return $default;
    }

    $values = [];
    foreach (explode(',', (string) $options[$key]) as $value) {
        $normalized = strtolower(trim($value));
        if (in_array($normalized, ['1', 'true', 'yes', 'y', 'on'], true)) {
            $values['true'] = true;
        } elseif (in_array($normalized, ['0', 'false', 'no', 'n', 'off'], true)) {
            $values['false'] = false;
        }
    }

    return $values === [] ? $default : array_values($values);
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

/** @param array<string, string> $options @param list<string> $default @return list<string> */
function timeListOption(array $options, string $key, array $default): array
{
    if (!isset($options[$key])) {
        return $default;
    }

    $values = [];
    foreach (explode(',', (string) $options[$key]) as $value) {
        $value = trim($value);
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value)) {
            throw new InvalidArgumentException('--' . $key . ' contains an invalid HH:MM value: ' . $value);
        }
        $values[$value] = $value;
    }

    return $values === [] ? $default : array_values($values);
}

/** @param array<string, mixed> $variant */
function variantModesCompatible(array $variant): bool
{
    $signalMode = (string) ($variant['support_entry_signal_mode'] ?? 'touch_confirmed');
    $fillMode = (string) ($variant['order_fill_mode'] ?? 'next_touch');

    if ($fillMode === 'intraday_touch_reclaim') {
        return $signalMode === 'advance_next_session';
    }

    return !($fillMode === 'same_day_touch' && $signalMode === 'advance_next_session');
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

    $strategy['poos_base_enabled'] = (bool) ($variant['poos_base_enabled'] ?? false);
    $strategy['support_regularity']['min_touches'] = (int) $variant['min_touches'];
    $strategy['support_regularity']['min_success_rate'] = (float) $variant['min_success_rate'];
    $strategy['support_regularity']['require_close_above_support'] = (bool) ($variant['require_close_above_support'] ?? true);
    $strategy['support_regularity']['weekly_enabled'] = (bool) ($variant['support_weekly_enabled'] ?? true);
    $strategy['support_regularity']['touch_tolerance_pct'] = (float) $variant['touch_tolerance_pct'];
    $strategy['support_regularity']['near_atr_multiple'] = (float) $variant['near_atr_multiple'];
    $strategy['support_regularity']['stop_atr_multiple'] = (float) $variant['stop_atr_multiple'];
    $strategy['support_regularity']['target_atr_multiple'] = (float) $variant['target_atr_multiple'];
    $strategy['support_regularity']['cooldown_bars'] = (int) ($variant['signal_cooldown_bars'] ?? 10);
    $strategy['support_regularity']['entry_signal_mode'] = (string) ($variant['support_entry_signal_mode'] ?? 'touch_confirmed');
    $strategy['support_regularity']['advance_max_distance_pct'] = (float) ($variant['advance_max_distance_pct'] ?? 0.10);
    $strategy['support_regularity']['advance_max_distance_atr'] = (float) ($variant['advance_max_distance_atr'] ?? 3.0);
    $strategy['support_regularity']['advance_min_level_slope_pct'] = (float) ($variant['advance_min_level_slope_pct'] ?? -0.01);
    $strategy['support_regularity']['advance_require_untouched'] = (bool) ($variant['advance_require_untouched'] ?? true);
    $strategy['support_regularity']['advance_level_projection'] = (string) ($variant['advance_level_projection'] ?? 'static');
    $strategy['support_regularity']['advance_max_projection_pct'] = (float) ($variant['advance_max_projection_pct'] ?? 0.01);
    $strategy['short_resistance']['enabled'] = false;
    $strategy['short_symbols'] = [];
    $strategy['inverse_long_symbols'] = [];
    $strategy['order_valid_bars'] = (int) ($variant['order_valid_bars'] ?? $strategy['order_valid_bars'] ?? 10);
    $strategy['order_fill_mode'] = (string) ($variant['order_fill_mode'] ?? 'next_touch');
    $strategy['intraday_touch_reclaim'] = [
        'touch_tolerance_pct' => (float) ($variant['intraday_touch_tolerance_pct'] ?? 0.005),
        'reclaim_buffer_pct' => (float) ($variant['intraday_reclaim_buffer_pct'] ?? 0.0),
        'min_bounce_pct' => (float) ($variant['intraday_min_bounce_pct'] ?? 0.0),
        'require_bullish_bar' => (bool) ($variant['intraday_require_bullish_bar'] ?? false),
        'max_bars_after_touch' => (int) ($variant['intraday_max_bars_after_touch'] ?? 6),
        'max_fill_delay_minutes' => (int) ($variant['intraday_max_fill_delay_minutes'] ?? 5),
        'max_chase_atr' => (float) ($variant['intraday_max_chase_atr'] ?? 0.25),
        'slippage_bps' => (float) ($variant['intraday_slippage_bps'] ?? 10.0),
        'last_reclaim_bar_start' => (string) ($variant['intraday_last_reclaim_bar_start'] ?? '15:50'),
        'target_mode' => (string) ($variant['intraday_target_mode'] ?? 'rebase_distance'),
        'reject_pre_entry_stop_breach' => (bool) ($variant['intraday_reject_pre_entry_stop_breach'] ?? true),
    ];
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
    $risk['transaction_cost_bps'] = (float) ($variant['transaction_cost_bps'] ?? 0.0);
    $risk['margin_interest_annual_pct'] = max(0.0, (float) ($variant['margin_interest_annual_pct'] ?? 0.0));

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
    $maxOpen = 0;
    foreach ($curve as $point) {
        $date = (string) $point['date'];
        $equity = (float) $point['equity'];
        $openPositions = $positionsByDate[$date] ?? [];
        $open = count($openPositions);
        $maxOpen = max($maxOpen, $open);
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
        'max_open_positions' => $maxOpen,
        'avg_gross_exposure_all_days' => $sumExposure / $days,
        'avg_gross_exposure_active_days' => $exposureDays > 0 ? $sumExposure / $exposureDays : 0.0,
        'max_gross_exposure' => $maxExposure,
    ];
}

/** @param array<string, string|null> $options @return array<string, float|int> */
function robustnessPolicy(array $options): array
{
    return [
        'min_trades' => max(1, (int) ($options['min-trades'] ?? 50)),
        'min_post_split_trades' => max(1, (int) ($options['min-post-split-trades'] ?? 10)),
        'max_best_trade_gross_profit_share_pct' => boundedShare(
            (float) ($options['max-best-trade-share-pct'] ?? 0.25),
        ),
        'max_top_5_trades_gross_profit_share_pct' => boundedShare(
            (float) ($options['max-top-5-trades-share-pct'] ?? 0.60),
        ),
        'max_top_symbol_gross_profit_share_pct' => boundedShare(
            (float) ($options['max-top-symbol-share-pct'] ?? 0.65),
        ),
        'max_post_split_drawdown_pct' => abs((float) ($options['max-post-split-drawdown-pct'] ?? 0.35)),
    ];
}

/**
 * @param array<string, string|null> $options
 * @param array<string, float|int> $robustnessPolicy
 * @return array<string, float|int>
 */
function walkForwardPolicy(array $options, array $robustnessPolicy): array
{
    return [
        'min_train_trades' => max(1, (int) ($options['min-pre-split-trades'] ?? 50)),
        'min_train_annualized_return_pct' => (float) ($options['min-pre-split-annualized-return-pct'] ?? 0.40),
        'max_train_drawdown_pct' => abs((float) ($options['max-pre-split-drawdown-pct'] ?? 0.35)),
        'max_best_trade_gross_profit_share_pct' => (float) $robustnessPolicy['max_best_trade_gross_profit_share_pct'],
        'max_top_5_trades_gross_profit_share_pct' => (float) $robustnessPolicy['max_top_5_trades_gross_profit_share_pct'],
        'max_top_symbol_gross_profit_share_pct' => (float) $robustnessPolicy['max_top_symbol_gross_profit_share_pct'],
    ];
}

function boundedShare(float $value): float
{
    return min(1.0, max(0.0, $value));
}

/**
 * Training decisions use score and variant name only. Post/full-period data is
 * deliberately not accepted by this helper.
 */
function trainScoreWins(float $score, string $name, float $bestScore, ?string $bestName): bool
{
    if ($score > $bestScore) {
        return true;
    }

    return $score === $bestScore && ($bestName === null || strcmp($name, $bestName) < 0);
}

/**
 * Production selection is intentionally narrower than the exploratory grid.
 * A risk-compatible result is still ineligible when its signal/fill pair
 * cannot be known and executed causally.
 *
 * @param array<string, mixed> $variant
 * @param array<string, mixed> $envelope
 */
function productionExecutionAllowed(array $variant, array $envelope): bool
{
    $allowed = $envelope['allowed_signal_fill_pairs'] ?? [];
    if (!is_array($allowed) || $allowed === []) {
        return false;
    }

    $signalMode = (string) (
        $variant['signal_timing_mode']
        ?? $variant['support_entry_signal_mode']
        ?? ''
    );
    $fillMode = (string) ($variant['fill_mode'] ?? $variant['order_fill_mode'] ?? '');

    return in_array($signalMode . ':' . $fillMode, $allowed, true);
}

/**
 * @param array<string, mixed> $entryDiagnostics
 * @return array<string, mixed>
 */
function periodEntryDiagnostics(
    array $entryDiagnostics,
    ?string $startInclusive,
    ?string $endExclusive,
): array
{
    return EntryDataQualityPeriod::slice($entryDiagnostics, $startInclusive, $endExclusive);
}

/**
 * The variant is frozen using training data before this function sees the
 * holdout. There is deliberately no fallback to a different variant after an
 * OOS failure.
 *
 * @param array<string, mixed> $selection
 * @return array<string, mixed>
 */
function qualifyFrozenSelection(
    array $selection,
    float $minimumAnnualizedReturn,
    float $maximumDrawdown,
    array $productionEnvelope = [],
): array {
    return (new WalkForwardSelector())->qualifyFrozen(
        $selection,
        $minimumAnnualizedReturn,
        $maximumDrawdown,
        $productionEnvelope,
    );
}

/**
 * Attach post-split facts only after the train-only choice has been frozen.
 *
 * @param array<string, mixed> $selection
 * @param array<string, array<string, mixed>> $rowsByVariant
 * @return array<string, mixed>
 */
function addFrozenHoldoutEvaluation(array $selection, array $rowsByVariant, string $splitDate): array
{
    $name = is_string($selection['selected_variant'] ?? null) ? $selection['selected_variant'] : '';
    $row = $name !== '' ? ($rowsByVariant[$name] ?? null) : null;
    if (!is_array($row)) {
        $selection['frozen_oos_evaluation'] = null;
        $selection['selected_full_period_data_quality_passes'] = false;
        $selection['selected_full_period_data_quality_failures'] = ['missing_selected_variant_row'];
        $selection['selected_full_period_max_observed_or_bounded_gross_exposure'] = null;

        return $selection;
    }

    $selection['selected_full_period_data_quality_passes'] = ($row['data_quality_passes'] ?? false) === true;
    $selection['selected_full_period_data_quality_failures'] = is_array($row['data_quality_failures'] ?? null)
        ? array_values($row['data_quality_failures'])
        : ['selected_full_period_data_quality_unavailable'];
    $selection['selected_full_period_max_observed_or_bounded_gross_exposure'] =
        $row['max_observed_or_bounded_gross_exposure'] ?? null;

    $selection['frozen_oos_evaluation'] = [
        'split_date' => $splitDate,
        'return_pct' => $row['post_split_return_pct'],
        'annualized_return_pct' => $row['post_split_annualized_return_pct'],
        'max_drawdown_pct' => $row['post_split_max_drawdown_pct'],
        'trades' => $row['post_split_trades'],
        'best_trade_gross_profit_share_pct' => $row['post_split_best_trade_gross_profit_share_pct'],
        'top_5_trades_gross_profit_share_pct' => $row['post_split_top_5_trades_gross_profit_share_pct'],
        'pnl_without_top_5_trades' => $row['post_split_pnl_without_top_5_trades'],
        'top_symbol' => $row['post_split_top_symbol'],
        'top_symbol_gross_profit_share_pct' => $row['post_split_top_symbol_gross_profit_share_pct'],
        'data_quality_passes' => ($row['post_split_data_quality_passes'] ?? false) === true,
        'data_quality_failures' => is_array($row['post_split_data_quality_failures'] ?? null)
            ? array_values($row['post_split_data_quality_failures'])
            : ['holdout_data_quality_unavailable'],
        'max_observed_or_bounded_gross_exposure' => $row['post_split_max_gross_exposure'] ?? null,
        'passes' => $row['meets_holdout_validation'],
        'failures' => $row['holdout_validation_failures'],
    ];

    return $selection;
}

/**
 * Market-data gaps can manufacture signals and fills. Preserve the strategy-
 * only selector diagnostics, but never expose a production candidate when the
 * requested universe does not meet the declared session-coverage floor.
 *
 * @param array<string, mixed> $selection
 * @param array<string, mixed> $dataCoverage
 * @return array<string, mixed>
 */
function applyDataQualityGate(array $selection, array $dataCoverage): array
{
    $selection['strategy_eligible_count'] = (int) ($selection['eligible_count'] ?? 0);
    $selection['strategy_selected_variant'] = $selection['selected_variant'] ?? null;
    $selection['data_quality_passes'] = ($dataCoverage['passes'] ?? false) === true;
    $selection['data_quality_failures'] = is_array($dataCoverage['failures'] ?? null)
        ? array_values($dataCoverage['failures'])
        : ['data_coverage_unavailable'];
    if ($selection['data_quality_passes']) {
        return $selection;
    }

    $selection['eligible_count'] = 0;
    $selection['selected_variant'] = null;
    $selection['selected_params'] = null;
    $selection['selected_training'] = null;
    $selection['train_score'] = null;
    $selection['frozen_oos_evaluation'] = null;

    return $selection;
}

/**
 * Data eligibility is variant-specific: a daily-only variant must not inherit
 * an unrelated intraday snapshot failure, while every causal intraday variant
 * must pass daily coverage, snapshot coverage, and candidate-session checks.
 *
 * @param array<string, mixed> $dailyCoverage
 * @param array<string, mixed>|null $intradayCoverage
 * @param array<string, mixed> $entryDiagnostics
 * @return array<string, mixed>
 */
function variantDataQuality(
    array $dailyCoverage,
    ?array $intradayCoverage,
    array $entryDiagnostics,
): array {
    $intradayApplicable = ($entryDiagnostics['mode'] ?? '') === 'intraday_touch_reclaim';
    $failures = is_array($dailyCoverage['failures'] ?? null)
        ? array_values($dailyCoverage['failures'])
        : ['daily_coverage_unavailable'];
    $passes = ($dailyCoverage['passes'] ?? false) === true;

    if ($intradayApplicable) {
        if (($intradayCoverage['passes'] ?? false) !== true) {
            $passes = false;
            $failures = array_merge(
                $failures,
                is_array($intradayCoverage['failures'] ?? null)
                    ? $intradayCoverage['failures']
                    : ['intraday_snapshot_coverage_unavailable'],
            );
        }
        if (($entryDiagnostics['data_quality_passes'] ?? false) !== true) {
            $passes = false;
            $failures = array_merge(
                $failures,
                is_array($entryDiagnostics['data_quality_failures'] ?? null)
                    ? $entryDiagnostics['data_quality_failures']
                    : ['intraday_candidate_quality_unavailable'],
            );
        }
        if ((int) ($entryDiagnostics['missing_candidate_sessions'] ?? 0) > 0) {
            $passes = false;
            $failures[] = 'missing_candidate_session_data';
        }
    }

    return [
        'passes' => $passes,
        'failures' => array_values(array_unique($failures)),
        'intraday_applicable' => $intradayApplicable,
        'density_is_warning_only' => false,
        'intraday_snapshot_density' => compactIntradayCoverage($intradayApplicable ? $intradayCoverage : null),
        'missing_candidate_sessions' => (int) ($entryDiagnostics['missing_candidate_sessions'] ?? 0),
        'missing_candidate_session_examples' => $entryDiagnostics['missing_candidate_session_examples'] ?? [],
        'daily_coverage' => $dailyCoverage,
        'intraday_snapshot_coverage' => $intradayApplicable ? $intradayCoverage : null,
        'entry_diagnostics' => $intradayApplicable ? $entryDiagnostics : null,
    ];
}

/** @param array<string, mixed>|null $coverage @return array<string, mixed>|null */
function compactIntradayCoverage(?array $coverage): ?array
{
    if ($coverage === null) {
        return null;
    }

    $sessionCoverages = [];
    $p10Bars = [];
    $medianBars = [];
    $p90Gaps = [];
    foreach (($coverage['symbols'] ?? []) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $sessionCoverages[] = (float) ($row['coverage_pct'] ?? 0.0);
        if (is_numeric($row['p10_regular_bars_per_covered_session'] ?? null)) {
            $p10Bars[] = (float) $row['p10_regular_bars_per_covered_session'];
        }
        if (is_numeric($row['median_regular_bars_per_covered_session'] ?? null)) {
            $medianBars[] = (float) $row['median_regular_bars_per_covered_session'];
        }
        if (is_numeric($row['p90_within_session_gap_minutes'] ?? null)) {
            $p90Gaps[] = (float) $row['p90_within_session_gap_minutes'];
        }
    }

    return [
        'namespace' => $coverage['namespace'] ?? null,
        'passes_intraday_quality' => ($coverage['passes'] ?? false) === true,
        'minimum_required_p10_bars_per_session' => $coverage['minimum_p10_regular_bars_per_covered_session'] ?? null,
        'minimum_symbol_session_coverage_pct' => $sessionCoverages !== [] ? min($sessionCoverages) : null,
        'minimum_symbol_p10_bars_per_session' => $p10Bars !== [] ? min($p10Bars) : null,
        'minimum_symbol_median_bars_per_session' => $medianBars !== [] ? min($medianBars) : null,
        'maximum_symbol_p90_gap_minutes' => $p90Gaps !== [] ? max($p90Gaps) : null,
        'warnings' => $coverage['warnings'] ?? [],
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

/** @param array<string, float|int> $consistency @param array<string, mixed> $robustness */
function robustScore(float $ann, float $dd, float $score, array $consistency, array $robustness): float
{
    $penalty = 0.0;
    $penalty += ((int) $consistency['negative_years']) * 0.25;
    $penalty += max(0.0, ((float) $consistency['best_year_contribution_pct']) - 0.55) * 1.5;
    $penalty += max(0.0, -0.05 - ((float) $consistency['worst_year_return_pct'])) * 2.0;
    $penalty += $dd < -0.35 ? abs($dd + 0.35) * 2.0 : 0.0;
    $penalty += (float) $robustness['best_trade_gross_profit_share_pct'] * 0.50;
    $penalty += (float) $robustness['top_5_trades_gross_profit_share_pct'] * 0.35;
    $penalty += (float) $robustness['top_symbol_gross_profit_share_pct'] * 0.50;
    if ((float) $robustness['pnl_without_top_5_trades'] <= 0.0) {
        $penalty += 2.0;
    }

    $postSplit = $robustness['post_split'];
    $postSplitAnnualized = (float) $postSplit['annualized_return_pct'];
    $postSplitDrawdown = abs((float) $postSplit['max_drawdown_pct']);
    if ((int) $postSplit['points'] < 2 || (float) $postSplit['return_pct'] <= 0.0) {
        $penalty += 2.0;
    }
    $postSplitQuality = $postSplitAnnualized / max(0.10, $postSplitDrawdown);
    $postSplitQuality = min(2.0, max(-2.0, $postSplitQuality));
    $bonus = max(0.0, ((float) $consistency['median_year_return_pct']) - 0.10);
    $bonus += $postSplitQuality * 0.15;

    return $score + $bonus + $ann * 0.10 - $penalty;
}

function saveResult(string $prefix, string $name, array $variant, array $report, BacktestResult $result): void
{
    $report['block_bootstrap'] = (new BlockBootstrapAnalyzer())->analyze(
        $result->equityCurve,
        1000,
        20,
        20260716,
    );
    writeJson($prefix . '_report.json', [
        'variant' => $name,
        'params' => $variant,
        'report' => $report,
    ]);
    writeJson($prefix . '_trades.json', [
        'variant' => $name,
        'params' => $variant,
        'trades' => serializeTrades($result->trades),
    ]);
    writeJson($prefix . '_signals.json', [
        'variant' => $name,
        'params' => $variant,
        'signals' => serializeSignals($result->signals),
    ]);
    writeJson($prefix . '_positions.json', [
        'variant' => $name,
        'params' => $variant,
        'positions' => array_values($result->positionStates),
    ]);
    writeJson($prefix . '_equity.json', [
        'variant' => $name,
        'params' => $variant,
        'equity' => $result->equityCurve,
    ]);
}

/** @param list<Trade> $trades @return list<array<string, mixed>> */
function serializeTrades(array $trades): array
{
    return array_map(static fn (Trade $trade): array => [
        'symbol' => $trade->symbol,
        'strategy' => $trade->strategy,
        'entry_date' => $trade->entryTime->format('Y-m-d'),
        'exit_date' => $trade->exitTime->format('Y-m-d'),
        'entry_at' => $trade->entryTime->format(DATE_ATOM),
        'exit_at' => $trade->exitTime->format(DATE_ATOM),
        'entry' => $trade->entry,
        'exit' => $trade->exit,
        'shares' => $trade->shares,
        'pnl' => $trade->pnl,
        'r_multiple' => $trade->rMultiple,
        'exit_reason' => $trade->exitReason,
        'events' => $trade->events,
        'metadata' => $trade->metadata,
    ], $trades);
}

/** @param list<Signal> $signals @return list<array<string, mixed>> */
function serializeSignals(array $signals): array
{
    return array_map(static fn (Signal $signal): array => [
        'date' => $signal->createdAt->format('Y-m-d'),
        'created_at' => $signal->createdAt->format(DATE_ATOM),
        'symbol' => $signal->symbol,
        'strategy' => $signal->strategy,
        'direction' => $signal->direction,
        'entry' => $signal->entry,
        'stop' => $signal->stop,
        'target' => $signal->target,
        'risk_per_share' => $signal->riskPerShare,
        'score' => $signal->score,
        'reasons' => $signal->reasons,
        'metadata' => $signal->metadata,
    ], $signals);
}

/** @param array<string, mixed> $payload */
function writeJson(string $path, array $payload): void
{
    $json = json_encode(
        $payload,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    ) . "\n";
    $temporary = $path . '.tmp.' . getmypid();
    if (file_put_contents($temporary, $json, LOCK_EX) === false || !rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Unable to write JSON artifact atomically: ' . $path);
    }
}
