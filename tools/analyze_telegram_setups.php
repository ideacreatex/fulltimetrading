#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Data\CachedMarketDataProvider;
use FulltimeTrading\Data\HttpClient;
use FulltimeTrading\Data\YahooChartProvider;
use FulltimeTrading\Domain\Bar;
use FulltimeTrading\Indicators\IndicatorCalculator;
use FulltimeTrading\Support\Config;

require __DIR__ . '/../bootstrap.php';

ini_set('memory_limit', '512M');

$options = [
    'input' => __DIR__ . '/../var/reports/telegram_setups.json',
    'output' => __DIR__ . '/../var/reports/telegram_setup_analysis.json',
    'start' => '2021-01-01',
    'end' => (new DateTimeImmutable('today'))->format('Y-m-d'),
    'authors' => '',
    'max_events' => '0',
];

foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
        continue;
    }
    [$key, $value] = explode('=', substr($arg, 2), 2);
    $options[$key] = $value;
}

if (!is_file($options['input'])) {
    throw new RuntimeException('Input not found: ' . $options['input']);
}

$payload = json_decode((string) file_get_contents($options['input']), true, 512, JSON_THROW_ON_ERROR);
$events = $payload['events'] ?? [];
if (!is_array($events)) {
    throw new RuntimeException('Input has no events array.');
}

$authors = array_values(array_filter(array_map('trim', explode(',', (string) $options['authors']))));
if ($authors !== []) {
    $authorLookup = array_fill_keys($authors, true);
    $events = array_values(array_filter($events, static fn (array $event): bool => isset($authorLookup[(string) $event['author']])));
}

$maxEvents = (int) $options['max_events'];
if ($maxEvents > 0) {
    $events = array_slice($events, 0, $maxEvents);
}

$symbolMap = [
    'SPX' => '^GSPC',
    'SPY' => 'SPY',
    'QQQ' => 'QQQ',
    'IXIC' => '^IXIC',
    'NDX' => '^NDX',
    'VIX' => '^VIX',
    'VVIX' => '^VVIX',
    'DXY' => 'DX-Y.NYB',
    'ES1' => 'ES=F',
    'NQ1' => 'NQ=F',
    'YM1' => 'YM=F',
    'ES' => 'ES=F',
    'NQ' => 'NQ=F',
    'YM' => 'YM=F',
    'MAGS' => 'MAGS',
];

$symbols = [];
foreach ($events as $event) {
    foreach (($event['tickers'] ?? []) as $ticker) {
        $symbol = $symbolMap[$ticker] ?? $ticker;
        if ($thisCanBeYahoo = isLikelyYahooSymbol($symbol)) {
            $symbols[$symbol] = true;
        }
    }
}
$symbols = array_keys($symbols);
sort($symbols);

$config = Config::fromFile(__DIR__ . '/../config/config.php');
$provider = new CachedMarketDataProvider(
    new YahooChartProvider(new HttpClient()),
    (string) $config->get('cache_path'),
    'yahoo',
);

$barsBySymbol = [];
$skippedSymbols = [];
foreach ($symbols as $symbol) {
    try {
        $symbolBars = $provider->getBars([$symbol], '1Day', (string) $options['start'], (string) $options['end']);
        $barsBySymbol[$symbol] = $symbolBars[$symbol] ?? [];
    } catch (Throwable $e) {
        $skippedSymbols[$symbol] = $e->getMessage();
    }
}

$indicatorCalculator = new IndicatorCalculator();
$rows = [];
$noData = [];

foreach ($events as $event) {
    foreach (($event['tickers'] ?? []) as $ticker) {
        $symbol = $symbolMap[$ticker] ?? $ticker;
        if (!isset($barsBySymbol[$symbol]) || count($barsBySymbol[$symbol]) < 220) {
            $noData[$ticker] = $symbol;
            continue;
        }

        $bars = $barsBySymbol[$symbol];
        $index = indexAtOrBefore($bars, (string) $event['date']);
        if ($index === null || $index < 200) {
            continue;
        }

        $analysis = analyzeSymbolAtEvent(
            $ticker,
            $symbol,
            $bars,
            $index,
            $event,
            $indicatorCalculator,
        );
        $rows[] = $analysis;
    }
}

$summary = summarizeRows($rows);
$result = [
    'generated_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
    'input' => $options['input'],
    'start' => $options['start'],
    'end' => $options['end'],
    'events_scanned' => count($events),
    'symbols_requested' => count($symbols),
    'symbols_loaded' => count(array_filter($barsBySymbol, static fn (array $bars): bool => count($bars) > 0)),
    'skipped_symbols' => $skippedSymbols,
    'no_data_tickers' => $noData,
    'summary' => $summary,
    'rows' => $rows,
];

$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    throw new RuntimeException('Unable to encode JSON: ' . json_last_error_msg());
}

$outputDir = dirname((string) $options['output']);
if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
    throw new RuntimeException('Unable to create output directory: ' . $outputDir);
}
file_put_contents((string) $options['output'], $json);

echo 'Analyzed setup rows: ' . count($rows) . "\n";
echo 'Report: ' . $options['output'] . "\n";
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

function isLikelyYahooSymbol(string $symbol): bool
{
    return !in_array($symbol, ['US20Y', 'PCC', 'PCSP', 'M2SL', 'S5FD', 'S5TW', 'S5FI', 'S5OH', 'S5TH'], true);
}

/** @param list<Bar> $bars */
function indexAtOrBefore(array $bars, string $date): ?int
{
    $target = new DateTimeImmutable($date);
    $found = null;
    foreach ($bars as $i => $bar) {
        if ($bar->time > $target) {
            break;
        }
        $found = $i;
    }

    return $found;
}

/**
 * @param list<Bar> $bars
 * @param array<string, mixed> $event
 * @return array<string, mixed>
 */
function analyzeSymbolAtEvent(
    string $ticker,
    string $symbol,
    array $bars,
    int $index,
    array $event,
    IndicatorCalculator $indicatorCalculator,
): array {
    $periods = [5, 10, 20, 30, 50, 100, 150, 200];
    $daily = $indicatorCalculator->forBars($bars, $periods, 14, 50);
    $weeklyBars = aggregateWeekly($bars);
    $weeklyIndex = indexAtOrBefore($weeklyBars, $bars[$index]->time->format('Y-m-d'));
    $weekly = $weeklyIndex !== null ? $indicatorCalculator->forBars($weeklyBars, $periods, 14, 50) : [];

    $bar = $bars[$index];
    $atr = $daily['atr'][$index] ?? null;
    $dailySupports = supportRows($bars, $daily, $index, 'D', $periods);
    $weeklySupports = $weeklyIndex !== null ? supportRows($weeklyBars, $weekly, $weeklyIndex, 'W', $periods) : [];
    $nearest = nearestSupports(array_merge($dailySupports, $weeklySupports), 8);
    $mentioned = mentionedSupports($event['support_mentions'] ?? [], $dailySupports, $weeklySupports);

    $best = $mentioned[0] ?? $nearest[0] ?? null;
    $regularity = $best !== null ? priorReactionStats($bars, $daily, $index, $best) : null;
    $forward = forwardStats($bars, $index);
    $rsi = $daily['rsi14'][$index] ?? null;
    $macdHist = $daily['macd_histogram'][$index] ?? null;

    return [
        'date' => $bar->time->format('Y-m-d'),
        'message_id' => $event['message_id'],
        'author' => $event['author'],
        'ticker' => $ticker,
        'yahoo_symbol' => $symbol,
        'close' => $bar->close,
        'atr14' => $atr,
        'rsi14' => $rsi,
        'macd_histogram' => $macdHist,
        'keywords' => $event['keywords'],
        'support_mentions' => $event['support_mentions'],
        'mentioned_supports' => $mentioned,
        'nearest_supports' => $nearest,
        'best_support' => $best,
        'prior_reaction' => $regularity,
        'forward' => $forward,
        'verdict' => verdict($best, $regularity, $forward),
        'text_excerpt' => mb_substr(preg_replace('/\s+/u', ' ', (string) $event['text']) ?? (string) $event['text'], 0, 420),
    ];
}

/**
 * @param list<Bar> $bars
 * @return list<Bar>
 */
function aggregateWeekly(array $bars): array
{
    $weeks = [];
    foreach ($bars as $bar) {
        $key = $bar->time->format('o-W');
        if (!isset($weeks[$key])) {
            $weeks[$key] = [
                'symbol' => $bar->symbol,
                'time' => $bar->time,
                'open' => $bar->open,
                'high' => $bar->high,
                'low' => $bar->low,
                'close' => $bar->close,
                'volume' => $bar->volume,
            ];
            continue;
        }

        $weeks[$key]['time'] = $bar->time;
        $weeks[$key]['high'] = max($weeks[$key]['high'], $bar->high);
        $weeks[$key]['low'] = min($weeks[$key]['low'], $bar->low);
        $weeks[$key]['close'] = $bar->close;
        $weeks[$key]['volume'] += $bar->volume;
    }

    return array_map(static fn (array $row): Bar => new Bar(
        $row['symbol'],
        $row['time'],
        $row['open'],
        $row['high'],
        $row['low'],
        $row['close'],
        $row['volume'],
    ), array_values($weeks));
}

/**
 * @param list<Bar> $bars
 * @param array<string, list<float|null>> $indicators
 * @param list<int> $periods
 * @return list<array<string, mixed>>
 */
function supportRows(array $bars, array $indicators, int $index, string $timeframe, array $periods): array
{
    $bar = $bars[$index];
    $atr = $indicators['atr'][$index] ?? null;
    $rows = [];
    foreach (['ema', 'sma'] as $type) {
        foreach ($periods as $period) {
            $level = $indicators[$type . $period][$index] ?? null;
            if ($level === null || $level <= 0.0) {
                continue;
            }

            $distancePct = ($bar->close - $level) / $level;
            $touch = $bar->low <= $level && $bar->high >= $level;
            $nearThreshold = max(0.025, $atr !== null && $bar->close > 0.0 ? ($atr * 0.6) / $bar->close : 0.0);
            $near = abs($distancePct) <= $nearThreshold || $touch;
            $rows[] = [
                'timeframe' => $timeframe,
                'type' => strtoupper($type),
                'period' => $period,
                'level' => $level,
                'distance_pct' => $distancePct,
                'touch' => $touch,
                'near' => $near,
                'above' => $bar->close >= $level,
            ];
        }
    }

    usort($rows, static fn (array $a, array $b): int => abs($a['distance_pct']) <=> abs($b['distance_pct']));

    return $rows;
}

/** @param list<array<string, mixed>> $supports */
function nearestSupports(array $supports, int $limit): array
{
    return array_slice(array_values(array_filter(
        $supports,
        static fn (array $support): bool => (bool) $support['near'],
    )), 0, $limit);
}

/**
 * @param list<array<string, mixed>> $mentions
 * @param list<array<string, mixed>> $dailySupports
 * @param list<array<string, mixed>> $weeklySupports
 * @return list<array<string, mixed>>
 */
function mentionedSupports(array $mentions, array $dailySupports, array $weeklySupports): array
{
    $supports = array_merge($dailySupports, $weeklySupports);
    $result = [];
    foreach ($mentions as $mention) {
        $tf = normalizeMentionTimeframe((string) ($mention['timeframe'] ?? ''));
        $type = normalizeMentionType((string) ($mention['type'] ?? 'EMA'));
        foreach ($supports as $support) {
            if ($support['period'] !== (int) $mention['period']) {
                continue;
            }
            if ($tf !== '' && $support['timeframe'] !== $tf) {
                continue;
            }
            if ($type !== 'MA' && $support['type'] !== $type) {
                continue;
            }
            $result[] = $support;
        }
    }

    usort($result, static fn (array $a, array $b): int => abs($a['distance_pct']) <=> abs($b['distance_pct']));

    return array_slice($result, 0, 8);
}

function normalizeMentionTimeframe(string $value): string
{
    $value = strtoupper($value);
    return match ($value) {
        'D', 'Д' => 'D',
        'W', 'Н' => 'W',
        default => '',
    };
}

function normalizeMentionType(string $value): string
{
    $value = strtoupper(str_replace(['ЕМА', 'МА'], ['EMA', 'MA'], $value));
    return match ($value) {
        'SMA' => 'SMA',
        'EMA' => 'EMA',
        default => 'MA',
    };
}

/**
 * @param list<Bar> $bars
 * @param array<string, list<float|null>> $daily
 * @param array<string, mixed> $support
 * @return array<string, mixed>
 */
function priorReactionStats(array $bars, array $daily, int $index, array $support): array
{
    if ($support['timeframe'] !== 'D') {
        return ['touches' => 0, 'successes' => 0, 'success_rate' => 0.0, 'note' => 'weekly prior reactions not scored yet'];
    }

    $series = strtolower((string) $support['type']) . (string) $support['period'];
    $start = max(200, $index - 504);
    $touches = 0;
    $successes = 0;
    $examples = [];
    for ($i = $start; $i < $index - 20; $i++) {
        $level = $daily[$series][$i] ?? null;
        if ($level === null || $level <= 0.0) {
            continue;
        }
        if ($bars[$i]->low > $level * 1.015 || $bars[$i]->close < $level * 0.97) {
            continue;
        }

        $touches++;
        $future = array_slice($bars, $i + 1, 20);
        $maxClose = max(array_map(static fn (Bar $bar): float => $bar->close, $future));
        $return = ($maxClose - $bars[$i]->close) / $bars[$i]->close;
        if ($return >= 0.03) {
            $successes++;
        }
        if (count($examples) < 5) {
            $examples[] = [
                'date' => $bars[$i]->time->format('Y-m-d'),
                'forward_20_max_return_pct' => $return,
            ];
        }
    }

    return [
        'support' => strtoupper($series),
        'touches' => $touches,
        'successes' => $successes,
        'success_rate' => $touches > 0 ? $successes / $touches : 0.0,
        'examples' => $examples,
    ];
}

/** @param list<Bar> $bars */
function forwardStats(array $bars, int $index): array
{
    $close = $bars[$index]->close;
    $stats = [];
    foreach ([5, 10, 20, 63] as $days) {
        $futureIndex = min(count($bars) - 1, $index + $days);
        $stats['return_' . $days . 'd_pct'] = $close > 0.0 ? ($bars[$futureIndex]->close - $close) / $close : 0.0;
    }
    $future = array_slice($bars, $index + 1, 63);
    if ($future === []) {
        $stats['max_favorable_63d_pct'] = 0.0;
        $stats['max_adverse_63d_pct'] = 0.0;
        return $stats;
    }

    $stats['max_favorable_63d_pct'] = (max(array_map(static fn (Bar $bar): float => $bar->high, $future)) - $close) / $close;
    $stats['max_adverse_63d_pct'] = (min(array_map(static fn (Bar $bar): float => $bar->low, $future)) - $close) / $close;

    return $stats;
}

/** @param array<string, mixed>|null $support @param array<string, mixed>|null $regularity @param array<string, mixed> $forward */
function verdict(?array $support, ?array $regularity, array $forward): string
{
    if ($support === null) {
        return 'no_near_support';
    }
    if (!($support['near'] ?? false)) {
        return 'support_mentioned_but_not_near';
    }
    if ($regularity !== null && ($regularity['touches'] ?? 0) >= 2 && ($regularity['success_rate'] ?? 0.0) >= 0.55) {
        return 'clear_prior_regularity';
    }
    if (($forward['return_20d_pct'] ?? 0.0) > 0.03) {
        return 'worked_forward_without_prior_score';
    }

    return 'near_support_low_prior_score';
}

/** @param list<array<string, mixed>> $rows */
function summarizeRows(array $rows): array
{
    $byVerdict = [];
    $byTicker = [];
    foreach ($rows as $row) {
        $byVerdict[$row['verdict']] = ($byVerdict[$row['verdict']] ?? 0) + 1;
        $ticker = (string) $row['ticker'];
        if (!isset($byTicker[$ticker])) {
            $byTicker[$ticker] = ['events' => 0, 'clear_prior_regularity' => 0, 'avg_20d_return_pct' => 0.0];
        }
        $byTicker[$ticker]['events']++;
        if ($row['verdict'] === 'clear_prior_regularity') {
            $byTicker[$ticker]['clear_prior_regularity']++;
        }
        $byTicker[$ticker]['avg_20d_return_pct'] += (float) ($row['forward']['return_20d_pct'] ?? 0.0);
    }

    foreach ($byTicker as $ticker => $stats) {
        $byTicker[$ticker]['avg_20d_return_pct'] = $stats['events'] > 0 ? $stats['avg_20d_return_pct'] / $stats['events'] : 0.0;
    }
    uasort($byTicker, static fn (array $a, array $b): int => $b['events'] <=> $a['events']);

    return [
        'rows' => count($rows),
        'by_verdict' => $byVerdict,
        'top_tickers' => array_slice($byTicker, 0, 30, true),
    ];
}
