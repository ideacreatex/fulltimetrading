#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Data\AlpacaBarsProvider;
use FulltimeTrading\Data\CacheDirectoryMarketDataProvider;
use FulltimeTrading\Data\CachedMarketDataProvider;
use FulltimeTrading\Data\HttpClient;
use FulltimeTrading\Domain\Bar;
use FulltimeTrading\Support\Config;

require __DIR__ . '/../bootstrap.php';

$options = [
    'trades' => __DIR__ . '/../var/reports/param_experiment/best_consistent_40_35_trades.json',
    'signals' => __DIR__ . '/../var/reports/param_experiment/best_consistent_40_35_signals.json',
    'output' => __DIR__ . '/../var/reports/param_experiment/intraday_trade_replay.json',
    'limit' => '50',
    'feed' => null,
    'session' => 'regular',
    'break-even-pct' => '0.01',
    'partial-pct' => '0.5',
    'skip-fetch-errors' => 'true',
    'offline' => 'false',
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
$provider = boolOption((string) $options['offline'])
    ? new CacheDirectoryMarketDataProvider((string) $config->get('cache_path'))
    : new CachedMarketDataProvider(
        new AlpacaBarsProvider(
            new HttpClient(),
            (string) $config->get('data.alpaca.base_url', 'https://data.alpaca.markets'),
            $feed,
            (string) $config->get('data.alpaca.adjustment', 'split'),
            (int) $config->get('data.alpaca.limit', 10000),
        ),
        (string) $config->get('cache_path'),
        'alpaca-trade-intraday-replay-v1-' . $feed,
    );

$tradesPayload = json_decode((string) file_get_contents((string) $options['trades']), true, 512, JSON_THROW_ON_ERROR);
$signalsPayload = json_decode((string) file_get_contents((string) $options['signals']), true, 512, JSON_THROW_ON_ERROR);
$trades = $tradesPayload['trades'] ?? [];
$signals = $signalsPayload['signals'] ?? [];
if (!is_array($trades) || !is_array($signals)) {
    throw new RuntimeException('Input JSON files must contain trades/signals arrays.');
}

$signalIndex = buildSignalIndex($signals);
$replayTrades = array_values(array_filter($trades, static fn (mixed $trade): bool => is_array($trade)));
usort($replayTrades, static fn (array $a, array $b): int => (float) ($a['pnl'] ?? 0.0) <=> (float) ($b['pnl'] ?? 0.0));
if ((string) $options['limit'] !== 'all') {
    $replayTrades = array_slice($replayTrades, 0, max(1, (int) $options['limit']));
}

$rows = [];
$session = strtolower((string) $options['session']);
$summary = [
    'trades' => 0,
    'matched_signals' => 0,
    'minute_exits' => 0,
    'missing_minute_exit' => 0,
    'daily_pnl' => 0.0,
    'minute_pnl' => 0.0,
    'minute_minus_daily_pnl' => 0.0,
    'daily_losses' => 0,
    'minute_losses' => 0,
    'gap_below_stop_exits' => 0,
    'break_even_armed' => 0,
    'break_even_stop_exits' => 0,
    'fetch_errors' => 0,
    'missing_minute_bars' => 0,
    'unreplayable_trades' => 0,
];

foreach ($replayTrades as $trade) {
    $symbol = strtoupper((string) ($trade['symbol'] ?? ''));
    if ($symbol === '') {
        continue;
    }
    $plannedDate = plannedDate($trade['events'] ?? []) ?? (string) ($trade['entry_date'] ?? '');
    $entryDate = (string) ($trade['entry_date'] ?? '');
    $exitDate = (string) ($trade['exit_date'] ?? '');
    if ($plannedDate === '' || $entryDate === '' || $exitDate === '') {
        continue;
    }
    $signal = matchSignal($signalIndex, $trade, $plannedDate);
    if ($signal === null) {
        continue;
    }

    $start = $plannedDate;
    $end = (new DateTimeImmutable($exitDate))->modify('+1 day')->format('Y-m-d');
    try {
        $barsBySymbol = $provider->getBars([$symbol], '1Min', $start, $end);
    } catch (Throwable $e) {
        $summary['fetch_errors']++;
        $rows[] = [
            'symbol' => $symbol,
            'planned_date' => $plannedDate,
            'daily_entry_date' => $entryDate,
            'daily_exit_date' => $exitDate,
            'daily_entry' => (float) ($trade['entry'] ?? 0.0),
            'daily_exit' => (float) ($trade['exit'] ?? 0.0),
            'shares' => (float) ($trade['shares'] ?? 0.0),
            'daily_pnl' => (float) ($trade['pnl'] ?? 0.0),
            'fetch_error' => $e->getMessage(),
        ];
        if (boolOption((string) $options['skip-fetch-errors'])) {
            continue;
        }

        throw $e;
    }
    $bars = $barsBySymbol[$symbol] ?? [];
    if ($bars === []) {
        $summary['missing_minute_bars']++;
    }
    $replay = replayLongTrade($trade, $signal, $bars, (float) $options['break-even-pct'], (float) $options['partial-pct'], $session);
    if ($replay === null) {
        $summary['unreplayable_trades']++;
        continue;
    }

    $dailyPnl = (float) ($trade['pnl'] ?? 0.0);
    $minutePnl = (float) $replay['minute_pnl'];
    $summary['trades']++;
    $summary['matched_signals']++;
    $summary['daily_pnl'] += $dailyPnl;
    $summary['minute_pnl'] += $minutePnl;
    $summary['minute_minus_daily_pnl'] += $minutePnl - $dailyPnl;
    $summary['daily_losses'] += $dailyPnl < 0.0 ? 1 : 0;
    $summary['minute_losses'] += $minutePnl < 0.0 ? 1 : 0;
    $summary['minute_exits'] += $replay['minute_exit_time'] === null ? 0 : 1;
    $summary['missing_minute_exit'] += $replay['minute_exit_time'] === null ? 1 : 0;
    $summary['gap_below_stop_exits'] += ($replay['gap_below_stop'] ?? false) ? 1 : 0;
    $summary['break_even_armed'] += ($replay['break_even_armed_at'] ?? null) === null ? 0 : 1;
    $summary['break_even_stop_exits'] += ($replay['exit_after_break_even'] ?? false) ? 1 : 0;

    $rows[] = array_merge([
        'symbol' => $symbol,
        'planned_date' => $plannedDate,
        'daily_entry_date' => $entryDate,
        'daily_exit_date' => $exitDate,
        'daily_entry' => (float) ($trade['entry'] ?? 0.0),
        'daily_exit' => (float) ($trade['exit'] ?? 0.0),
        'shares' => (float) ($trade['shares'] ?? 0.0),
        'daily_pnl' => $dailyPnl,
    ], $replay);
}

$result = [
    'generated_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
    'feed' => $feed,
    'session' => $session,
    'offline' => boolOption((string) $options['offline']),
    'trades' => (string) $options['trades'],
    'signals' => (string) $options['signals'],
    'limit' => (string) $options['limit'],
    'summary' => $summary,
    'rows' => $rows,
];

$output = (string) $options['output'];
$dir = dirname($output);
if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
    throw new RuntimeException('Unable to create output dir: ' . $dir);
}
file_put_contents($output, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

echo json_encode([
    'summary' => $summary,
    'worst_rows' => array_slice($rows, 0, 10),
    'output' => $output,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

/** @param list<array<string, mixed>> $signals @return array<string, list<array<string, mixed>>> */
function buildSignalIndex(array $signals): array
{
    $index = [];
    foreach ($signals as $signal) {
        if (!is_array($signal)) {
            continue;
        }
        $key = signalKey((string) ($signal['date'] ?? ''), (string) ($signal['symbol'] ?? ''), (float) ($signal['entry'] ?? 0.0));
        $index[$key][] = $signal;
    }

    return $index;
}

/** @param array<string, list<array<string, mixed>>> $index @param array<string, mixed> $trade */
function matchSignal(array $index, array $trade, string $plannedDate): ?array
{
    $key = signalKey($plannedDate, (string) ($trade['symbol'] ?? ''), (float) ($trade['entry'] ?? 0.0));
    if (($index[$key] ?? []) !== []) {
        return $index[$key][0];
    }

    $symbol = strtoupper((string) ($trade['symbol'] ?? ''));
    $entry = (float) ($trade['entry'] ?? 0.0);
    foreach ($index as $signals) {
        foreach ($signals as $signal) {
            if (strtoupper((string) ($signal['symbol'] ?? '')) !== $symbol) {
                continue;
            }
            if (abs((float) ($signal['entry'] ?? 0.0) - $entry) <= max(0.01, $entry * 0.001)) {
                return $signal;
            }
        }
    }

    return null;
}

function signalKey(string $date, string $symbol, float $entry): string
{
    return $date . '|' . strtoupper($symbol) . '|' . round($entry, 3);
}

/** @param mixed $events */
function plannedDate(mixed $events): ?string
{
    if (!is_array($events)) {
        return null;
    }
    foreach ($events as $event) {
        if (!is_string($event) || !str_contains($event, 'planned')) {
            continue;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2}):/', $event, $matches) === 1) {
            return $matches[1];
        }
    }

    return null;
}

/** @param array<string, mixed> $trade @param array<string, mixed> $signal @param list<Bar> $bars */
function replayLongTrade(array $trade, array $signal, array $bars, float $breakEvenPct, float $partialPct, string $session): ?array
{
    $entryLimit = (float) ($signal['entry'] ?? $trade['entry'] ?? 0.0);
    $stop = (float) ($signal['stop'] ?? 0.0);
    $target = (float) ($signal['target'] ?? 0.0);
    $shares = (float) ($trade['shares'] ?? 0.0);
    if ($entryLimit <= 0.0 || $stop <= 0.0 || $shares <= 0.0) {
        return null;
    }

    $entryFill = null;
    $entryTime = null;
    $currentStop = $stop;
    $breakEvenArmedAt = null;
    $exitFill = null;
    $exitTime = null;
    $exitReason = null;
    $gapBelowStop = false;
    $exitAfterBreakEven = false;
    $partialRealized = 0.0;
    $remainingShares = $shares;
    $tookPartial = false;

    foreach ($bars as $bar) {
        if ($session === 'regular' && !isRegularSession($bar->time)) {
            continue;
        }
        if ($entryFill === null) {
            if ($bar->low > $entryLimit && $bar->open > $entryLimit) {
                continue;
            }
            $entryFill = $bar->open <= $entryLimit ? $bar->open : $entryLimit;
            $entryTime = $bar->time->format(DateTimeInterface::ATOM);
            if ($entryFill <= 0.0) {
                return null;
            }
        }

        if ($bar->low <= $currentStop || $bar->open <= $currentStop) {
            $exitFill = $bar->open < $currentStop ? $bar->open : $currentStop;
            $exitTime = $bar->time->format(DateTimeInterface::ATOM);
            $exitReason = 'minute_stop';
            $gapBelowStop = $bar->open < $currentStop;
            $exitAfterBreakEven = $breakEvenArmedAt !== null;
            break;
        }

        if ($breakEvenArmedAt === null && $bar->high >= $entryFill * (1.0 + $breakEvenPct)) {
            $currentStop = $entryFill;
            $breakEvenArmedAt = $bar->time->format(DateTimeInterface::ATOM);
        }

        if (!$tookPartial && $target > 0.0 && $bar->high >= $target) {
            $partialShares = $shares * max(0.0, min(1.0, $partialPct));
            $partialRealized += ($target - $entryFill) * $partialShares;
            $remainingShares -= $partialShares;
            $currentStop = $entryFill;
            $tookPartial = true;
            if ($breakEvenArmedAt === null) {
                $breakEvenArmedAt = $bar->time->format(DateTimeInterface::ATOM);
            }
        }
    }

    if ($entryFill === null) {
        return null;
    }
    if ($exitFill === null) {
        $exitFill = (float) ($trade['exit'] ?? 0.0);
        $exitTime = $trade['exit_date'] ?? null;
        $exitReason = 'daily_fallback';
    }

    $minutePnl = $partialRealized + ($exitFill - $entryFill) * $remainingShares;

    return [
        'minute_entry_time' => $entryTime,
        'minute_entry' => $entryFill,
        'initial_stop' => $stop,
        'break_even_armed_at' => $breakEvenArmedAt,
        'minute_exit_time' => $exitTime,
        'minute_exit' => $exitFill,
        'minute_exit_reason' => $exitReason,
        'minute_pnl' => $minutePnl,
        'minute_minus_daily_pnl' => $minutePnl - (float) ($trade['pnl'] ?? 0.0),
        'gap_below_stop' => $gapBelowStop,
        'exit_after_break_even' => $exitAfterBreakEven,
        'took_partial' => $tookPartial,
    ];
}

function isRegularSession(DateTimeImmutable $time): bool
{
    $ny = $time->setTimezone(new DateTimeZone('America/New_York'));
    $weekday = (int) $ny->format('N');
    if ($weekday > 5) {
        return false;
    }

    $clock = $ny->format('H:i');

    return $clock >= '09:30' && $clock < '16:00';
}

function boolOption(string $value): bool
{
    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'y', 'on'], true);
}
