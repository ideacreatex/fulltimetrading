#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$options = [
    'telegram' => __DIR__ . '/../var/reports/telegram_setups.json',
    'signals' => __DIR__ . '/../var/reports/author_grid/best_signals.json',
    'output' => __DIR__ . '/../var/reports/author_grid/telegram_signal_comparison.json',
    'authors' => 'FTT_Admin Official',
    'window-days' => '3',
    'family-match' => '1',
    'classes' => '',
    'class-match' => 'any',
];

foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
        continue;
    }
    [$key, $value] = explode('=', substr($arg, 2), 2);
    $options[$key] = $value;
}

$telegram = json_decode((string) file_get_contents((string) $options['telegram']), true, 512, JSON_THROW_ON_ERROR);
$signalsPayload = json_decode((string) file_get_contents((string) $options['signals']), true, 512, JSON_THROW_ON_ERROR);
$events = $telegram['events'] ?? [];
$signals = $signalsPayload['signals'] ?? [];
if (!is_array($events) || !is_array($signals)) {
    throw new RuntimeException('Invalid input JSON.');
}

$authors = array_values(array_filter(array_map('trim', explode(',', (string) $options['authors']))));
$authorLookup = $authors !== [] ? array_fill_keys($authors, true) : [];
$windowDays = (int) $options['window-days'];
$familyMatchEnabled = !in_array(strtolower((string) $options['family-match']), ['0', 'false', 'no', 'off'], true);
$classLookup = classLookup((string) $options['classes']);
$classMatchMode = strtolower((string) $options['class-match']) === 'primary' ? 'primary' : 'any';

$signalsBySymbol = [];
$signalsByFamily = [];
foreach ($signals as $signal) {
    if (!is_array($signal) || !isset($signal['symbol'], $signal['date'])) {
        continue;
    }
    $symbol = strtoupper((string) $signal['symbol']);
    $signalsBySymbol[$symbol][] = $signal;
    $family = symbolFamily($symbol);
    if ($family !== null) {
        $signalsByFamily[$family][] = $signal;
    }
}

$rows = [];
$matchedEvents = 0;
$exactMatchedEvents = 0;
$familyMatchedEvents = 0;
$familyOnlyMatchedEvents = 0;
$eventCount = 0;
$tickerRows = 0;
$matchedTickerRows = 0;
$exactMatchedTickerRows = 0;
$familyMatchedTickerRows = 0;

foreach ($events as $event) {
    if (!is_array($event)) {
        continue;
    }
    $author = (string) ($event['author'] ?? '');
    if ($authorLookup !== [] && !isset($authorLookup[$author])) {
        continue;
    }
    if (!eventMatchesClasses($event, $classLookup, $classMatchMode)) {
        continue;
    }
    $tickers = array_values(array_unique(array_map('strtoupper', $event['tickers'] ?? [])));
    if ($tickers === []) {
        continue;
    }

    $eventCount++;
    $eventMatched = false;
    $exactEventMatched = false;
    $familyEventMatched = false;
    foreach ($tickers as $ticker) {
        $tickerRows++;
        $family = symbolFamily($ticker);
        $exactMatches = annotateMatches(
            matchingSignals($signalsBySymbol[$ticker] ?? [], (string) $event['date'], $windowDays),
            'exact',
            $family,
        );
        $familyMatches = [];
        if ($familyMatchEnabled && $family !== null) {
            $familyMatches = annotateMatches(
                matchingSignals($signalsByFamily[$family] ?? [], (string) $event['date'], $windowDays),
                'family',
                $family,
            );
        }
        $matches = mergeMatches($exactMatches, $familyMatches);
        $exactMatched = $exactMatches !== [];
        $familyMatched = $matches !== [] && !$exactMatched;
        if ($matches !== []) {
            $matchedTickerRows++;
            $eventMatched = true;
        }
        if ($exactMatched) {
            $exactMatchedTickerRows++;
            $exactEventMatched = true;
        }
        if ($familyMatched) {
            $familyMatchedTickerRows++;
            $familyEventMatched = true;
        }

        $rows[] = [
            'date' => $event['date'],
            'message_id' => $event['message_id'] ?? null,
            'author' => $author,
            'ticker' => $ticker,
            'family' => $family,
            'keywords' => $event['keywords'] ?? [],
            'support_mentions' => $event['support_mentions'] ?? [],
            'matched' => $matches !== [],
            'exact_matched' => $exactMatched,
            'family_matched' => $familyMatched,
            'matches' => array_slice($matches, 0, 5),
            'text_excerpt' => mb_substr(preg_replace('/\s+/u', ' ', (string) ($event['text'] ?? '')) ?? '', 0, 360),
        ];
    }
    if ($eventMatched) {
        $matchedEvents++;
    }
    if ($exactEventMatched) {
        $exactMatchedEvents++;
    }
    if ($familyEventMatched) {
        $familyMatchedEvents++;
    }
    if ($eventMatched && !$exactEventMatched) {
        $familyOnlyMatchedEvents++;
    }
}

$byTicker = [];
foreach ($rows as $row) {
    $ticker = (string) $row['ticker'];
    if (!isset($byTicker[$ticker])) {
        $byTicker[$ticker] = ['rows' => 0, 'matched' => 0, 'exact_matched' => 0, 'family_matched' => 0];
    }
    $byTicker[$ticker]['rows']++;
    $byTicker[$ticker]['matched'] += $row['matched'] ? 1 : 0;
    $byTicker[$ticker]['exact_matched'] += $row['exact_matched'] ? 1 : 0;
    $byTicker[$ticker]['family_matched'] += $row['family_matched'] ? 1 : 0;
}
uasort($byTicker, static fn (array $a, array $b): int => $b['rows'] <=> $a['rows']);

$result = [
    'generated_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
    'telegram' => $options['telegram'],
    'signals' => $options['signals'],
    'signal_variant' => $signalsPayload['variant'] ?? null,
    'authors' => $authors,
    'window_days' => $windowDays,
    'family_match_enabled' => $familyMatchEnabled,
    'classes' => array_keys($classLookup),
    'class_match' => $classMatchMode,
    'summary' => [
        'events' => $eventCount,
        'matched_events' => $matchedEvents,
        'event_match_rate' => $eventCount > 0 ? $matchedEvents / $eventCount : 0.0,
        'exact_matched_events' => $exactMatchedEvents,
        'exact_event_match_rate' => $eventCount > 0 ? $exactMatchedEvents / $eventCount : 0.0,
        'family_matched_events' => $familyMatchedEvents,
        'family_event_match_rate' => $eventCount > 0 ? $familyMatchedEvents / $eventCount : 0.0,
        'family_only_matched_events' => $familyOnlyMatchedEvents,
        'family_only_event_match_rate' => $eventCount > 0 ? $familyOnlyMatchedEvents / $eventCount : 0.0,
        'ticker_rows' => $tickerRows,
        'matched_ticker_rows' => $matchedTickerRows,
        'ticker_match_rate' => $tickerRows > 0 ? $matchedTickerRows / $tickerRows : 0.0,
        'exact_matched_ticker_rows' => $exactMatchedTickerRows,
        'exact_ticker_match_rate' => $tickerRows > 0 ? $exactMatchedTickerRows / $tickerRows : 0.0,
        'family_only_matched_ticker_rows' => $familyMatchedTickerRows,
        'family_only_ticker_match_rate' => $tickerRows > 0 ? $familyMatchedTickerRows / $tickerRows : 0.0,
        'signals' => count($signals),
        'top_tickers' => array_slice($byTicker, 0, 30, true),
    ],
    'missed_examples' => array_slice(array_values(array_filter($rows, static fn (array $row): bool => !$row['matched'])), 0, 80),
    'matched_examples' => array_slice(array_values(array_filter($rows, static fn (array $row): bool => $row['matched'])), 0, 80),
];

$output = (string) $options['output'];
$dir = dirname($output);
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    throw new RuntimeException('Unable to create output directory: ' . $dir);
}
file_put_contents($output, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

echo json_encode($result['summary'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
echo "Report: {$output}\n";

/** @param list<array<string, mixed>> $signals */
function matchingSignals(array $signals, string $eventDate, int $windowDays): array
{
    $event = new DateTimeImmutable($eventDate);
    $matches = [];
    foreach ($signals as $signal) {
        $signalDate = new DateTimeImmutable((string) $signal['date']);
        $diff = (int) $event->diff($signalDate)->format('%r%a');
        if (abs($diff) > $windowDays) {
            continue;
        }
        $signal['days_from_message'] = $diff;
        $matches[] = $signal;
    }

    usort($matches, static fn (array $a, array $b): int => abs($a['days_from_message']) <=> abs($b['days_from_message']));

    return $matches;
}

/** @param list<array<string, mixed>> $matches @return list<array<string, mixed>> */
function annotateMatches(array $matches, string $type, ?string $family): array
{
    return array_map(static function (array $match) use ($type, $family): array {
        $match['match_type'] = $type;
        $match['matched_family'] = $family;

        return $match;
    }, $matches);
}

/**
 * @param list<array<string, mixed>> ...$matchGroups
 * @return list<array<string, mixed>>
 */
function mergeMatches(array ...$matchGroups): array
{
    $merged = [];
    $seen = [];
    foreach ($matchGroups as $matches) {
        foreach ($matches as $match) {
            $key = implode('|', [
                (string) ($match['symbol'] ?? ''),
                (string) ($match['date'] ?? ''),
                (string) ($match['strategy'] ?? ''),
                (string) ($match['direction'] ?? ''),
                (string) ($match['metadata']['setup_key'] ?? ''),
            ]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $merged[] = $match;
        }
    }

    usort($merged, static fn (array $a, array $b): int => abs((int) $a['days_from_message']) <=> abs((int) $b['days_from_message']));

    return $merged;
}

function symbolFamily(string $symbol): ?string
{
    $symbol = strtoupper(trim($symbol));
    if ($symbol === '') {
        return null;
    }

    $families = [
        'SP500' => ['SPY', 'SPX', 'ES', 'MES', 'RSP', 'UPRO', 'SPXL', 'SPUU', 'SSO', 'SDS', 'SPXU', 'SH'],
        'NASDAQ_100' => ['QQQ', 'NDX', 'NQ', 'MNQ', 'TQQQ', 'QLD', 'SQQQ', 'QID', 'PSQ'],
        'SEMICONDUCTORS' => ['SMH', 'SOXX', 'SOX', 'SOXL', 'SOXS', 'USD', 'NVDA', 'AMD', 'MU', 'AVGO'],
        'TECH' => ['XLK', 'TECL', 'ROM', 'AAPL', 'MSFT', 'ORCL', 'CRM', 'ADBE', 'NOW'],
        'DOW' => ['DIA', 'DJI', 'YM', 'MYM', 'UDOW', 'DDM', 'SDOW', 'DOG'],
        'RUSSELL_2000' => ['IWM', 'RUT', 'RTY', 'M2K', 'TNA', 'URTY', 'TZA', 'TWM'],
        'FINANCIALS' => ['XLF', 'FAS', 'FAZ', 'JPM', 'V', 'MA'],
        'CONSUMER_DISCRETIONARY' => ['XLY', 'AMZN', 'TSLA'],
        'COMMUNICATIONS' => ['XLC', 'META', 'GOOGL', 'GOOG', 'NFLX'],
        'INDUSTRIALS' => ['XLI', 'CAT', 'GE', 'UBER'],
        'HEALTHCARE' => ['XLV', 'LLY', 'UNH'],
        'ENERGY' => ['XLE', 'XOM', 'SCO', 'UCO'],
        'VOLATILITY' => ['VIX', 'VVIX', 'VIXY', 'UVXY', 'SVIX', 'SVXY', 'SVYX'],
        'MEGA_GROWTH' => ['MAGS', 'FNGU', 'BULZ', 'AAPL', 'MSFT', 'NVDA', 'AMZN', 'META', 'GOOGL', 'TSLA'],
    ];

    foreach ($families as $family => $members) {
        if (in_array($symbol, $members, true)) {
            return $family;
        }
    }

    return null;
}

/** @return array<string, true> */
function classLookup(string $classes): array
{
    $items = array_values(array_filter(array_map(
        static fn (string $item): string => trim($item),
        explode(',', $classes),
    )));

    return $items !== [] ? array_fill_keys($items, true) : [];
}

/** @param array<string, mixed> $event @param array<string, true> $classLookup */
function eventMatchesClasses(array $event, array $classLookup, string $matchMode): bool
{
    if ($classLookup === []) {
        return true;
    }
    $primary = (string) ($event['message_type'] ?? '');
    if (isset($classLookup[$primary])) {
        return true;
    }
    if ($matchMode === 'primary') {
        return false;
    }
    foreach (($event['message_types'] ?? []) as $type) {
        if (isset($classLookup[(string) $type])) {
            return true;
        }
    }

    return false;
}
