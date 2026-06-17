#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Data\AlpacaBarsProvider;
use FulltimeTrading\Data\CachedMarketDataProvider;
use FulltimeTrading\Data\HttpClient;
use FulltimeTrading\Support\Config;

require __DIR__ . '/../bootstrap.php';

$options = [
    'trades' => __DIR__ . '/../var/reports/param_experiment/best_consistent_40_35_trades.json',
    'output' => __DIR__ . '/../var/reports/param_experiment/break_even_stop_intraday_replay.json',
    'limit' => '20',
    'feed' => null,
];

foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
        continue;
    }
    [$key, $value] = explode('=', substr($arg, 2), 2);
    $options[$key] = $value;
}

$config = Config::fromFile(__DIR__ . '/../config/config.php');
$feed = (string) ($options['feed'] ?: $config->get('data.alpaca.feed', 'iex'));
$provider = new CachedMarketDataProvider(
    new AlpacaBarsProvider(
        new HttpClient(),
        (string) $config->get('data.alpaca.base_url', 'https://data.alpaca.markets'),
        $feed,
        (string) $config->get('data.alpaca.adjustment', 'split'),
        (int) $config->get('data.alpaca.limit', 10000),
    ),
    (string) $config->get('cache_path'),
    'alpaca-intraday-replay-v2-' . $feed,
);

$payload = json_decode((string) file_get_contents((string) $options['trades']), true, 512, JSON_THROW_ON_ERROR);
$trades = $payload['trades'] ?? [];
if (!is_array($trades)) {
    throw new RuntimeException('Trades JSON must contain trades array.');
}

$candidates = [];
foreach ($trades as $trade) {
    if (!is_array($trade) || (float) ($trade['pnl'] ?? 0.0) >= 0.0 || (string) ($trade['direction'] ?? 'long') === 'short') {
        continue;
    }
    $breakEvenDate = breakEvenDate($trade['events'] ?? []);
    if ($breakEvenDate === null) {
        continue;
    }
    $trade['break_even_date'] = $breakEvenDate;
    $candidates[] = $trade;
}
usort($candidates, static fn (array $a, array $b): int => (float) $a['pnl'] <=> (float) $b['pnl']);
$candidates = array_slice($candidates, 0, max(1, (int) $options['limit']));

$rows = [];
foreach ($candidates as $trade) {
    $symbol = strtoupper((string) $trade['symbol']);
    $entry = (float) $trade['entry'];
    $breakEvenDate = (string) $trade['break_even_date'];
    $exitDate = (string) $trade['exit_date'];
    $end = (new DateTimeImmutable($exitDate))->modify('+1 day')->format('Y-m-d');
    $barsBySymbol = $provider->getBars([$symbol], '1Min', $breakEvenDate, $end);
    $bars = $barsBySymbol[$symbol] ?? [];
    $minuteFill = null;
    $breakEvenArmedAt = null;
    $stopActive = false;
    foreach ($bars as $bar) {
        $date = $bar->time->format('Y-m-d');
        if ($date < $breakEvenDate || $date > $exitDate) {
            continue;
        }
        if (!$stopActive && $date > $breakEvenDate) {
            $stopActive = true;
        }
        if (!$stopActive && $date === $breakEvenDate && $bar->high >= $entry * 1.01) {
            $stopActive = true;
            $breakEvenArmedAt = $bar->time->format(DateTimeInterface::ATOM);
            continue;
        }
        if (!$stopActive) {
            continue;
        }
        if ($bar->low <= $entry) {
            $minuteFill = [
                'time' => $bar->time->format(DateTimeInterface::ATOM),
                'open' => $bar->open,
                'low' => $bar->low,
                'fill' => $bar->open < $entry ? $bar->open : $entry,
                'gap_below_break_even' => $bar->open < $entry,
            ];
            break;
        }
    }

    $rows[] = [
        'symbol' => $symbol,
        'entry_date' => $trade['entry_date'] ?? null,
        'break_even_date' => $breakEvenDate,
        'daily_exit_date' => $exitDate,
        'entry' => $entry,
        'daily_exit' => (float) ($trade['exit'] ?? 0.0),
        'daily_pnl' => (float) ($trade['pnl'] ?? 0.0),
        'break_even_armed_at' => $breakEvenArmedAt,
        'minute_break_even_fill' => $minuteFill,
        'daily_exit_minus_minute_fill' => $minuteFill === null ? null : (float) ($trade['exit'] ?? 0.0) - (float) $minuteFill['fill'],
    ];
}

$result = [
    'generated_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
    'trades' => (string) $options['trades'],
    'feed' => $feed,
    'limit' => count($rows),
    'rows' => $rows,
];

$output = (string) $options['output'];
$dir = dirname($output);
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    throw new RuntimeException('Unable to create output dir: ' . $dir);
}
file_put_contents($output, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

echo json_encode([
    'feed' => $feed,
    'rows' => $rows,
    'output' => $output,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

/** @param mixed $events */
function breakEvenDate(mixed $events): ?string
{
    if (!is_array($events)) {
        return null;
    }
    foreach ($events as $event) {
        if (!is_string($event) || !str_contains($event, 'stop moved to breakeven')) {
            continue;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2}):/', $event, $matches) === 1) {
            return $matches[1];
        }
    }

    return null;
}
