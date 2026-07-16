#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Backtest\BlockBootstrapAnalyzer;
use FulltimeTrading\Backtest\FixedShareExecutionEquityBuilder;
use FulltimeTrading\Data\AlpacaBarsProvider;
use FulltimeTrading\Data\CacheDirectoryMarketDataProvider;
use FulltimeTrading\Data\CachedMarketDataProvider;
use FulltimeTrading\Data\HttpClient;
use FulltimeTrading\Domain\Bar;
use FulltimeTrading\Support\Config;

require __DIR__ . '/../bootstrap.php';

ini_set('memory_limit', '2G');

$options = [
    'trades' => __DIR__ . '/../var/reports/param_experiment/best_consistent_40_35_trades.json',
    'signals' => __DIR__ . '/../var/reports/param_experiment/best_consistent_40_35_signals.json',
    'equity' => '',
    'output' => __DIR__ . '/../var/reports/param_experiment/intraday_trade_replay.json',
    'limit' => '50',
    'feed' => null,
    'session' => 'regular',
    'break-even-pct' => '0.01',
    'partial-pct' => '0.5',
    'skip-fetch-errors' => 'true',
    'offline' => 'false',
    'bulk-fetch' => 'true',
    'initial-stop-mode' => 'mental',
    'transaction-cost-bps' => '0',
];

foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
        continue;
    }
    [$key, $value] = explode('=', substr($arg, 2), 2);
    $options[$key] = $value;
}

if ((string) $options['equity'] !== '' && !boolOption((string) $options['bulk-fetch'])) {
    throw new InvalidArgumentException('Fixed-share equity reconstruction requires --bulk-fetch=true for complete daily minute marks.');
}
if ((string) $options['equity'] !== '' && strtolower((string) $options['limit']) !== 'all') {
    throw new InvalidArgumentException('Fixed-share equity reconstruction requires --limit=all; subset portfolio metrics are not valid.');
}

$config = Config::fromFile(__DIR__ . '/../config/config.php');
$feed = (string) ($options['feed'] ?: $config->get('data.alpaca.feed', 'iex'));
$cacheNamespace = 'alpaca-trade-intraday-replay-v1-' . $feed;
$provider = boolOption((string) $options['offline'])
    ? new CacheDirectoryMarketDataProvider((string) $config->get('cache_path'), $cacheNamespace)
    : new CachedMarketDataProvider(
        new AlpacaBarsProvider(
            new HttpClient(),
            (string) $config->get('data.alpaca.base_url', 'https://data.alpaca.markets'),
            $feed,
            (string) $config->get('data.alpaca.adjustment', 'split'),
            (int) $config->get('data.alpaca.limit', 10000),
        ),
        (string) $config->get('cache_path'),
        $cacheNamespace,
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

$bulkMinuteBars = [];
$bulkFetchErrors = [];
if (boolOption((string) $options['bulk-fetch'])) {
    $ranges = [];
    foreach ($replayTrades as $trade) {
        $symbol = strtoupper((string) ($trade['symbol'] ?? ''));
        $entryDate = (string) ($trade['entry_date'] ?? '');
        $exitDate = (string) ($trade['exit_date'] ?? '');
        if ($symbol === '' || $entryDate === '' || $exitDate === '') {
            continue;
        }
        $ranges[$symbol]['start'] = isset($ranges[$symbol]['start'])
            ? min((string) $ranges[$symbol]['start'], $entryDate)
            : $entryDate;
        $ranges[$symbol]['end'] = isset($ranges[$symbol]['end'])
            ? max((string) $ranges[$symbol]['end'], $exitDate)
            : $exitDate;
    }
    foreach ($ranges as $symbol => $range) {
        try {
            $end = (new DateTimeImmutable((string) $range['end']))->modify('+1 day')->format('Y-m-d');
            $barsBySymbol = $provider->getBars([$symbol], '1Min', (string) $range['start'], $end);
            $bulkMinuteBars[$symbol] = $barsBySymbol[$symbol] ?? [];
        } catch (Throwable $e) {
            $bulkFetchErrors[$symbol] = $e->getMessage();
        }
    }
}

$rows = [];
$session = strtolower((string) $options['session']);
$summary = [
    'input_trades' => count($replayTrades),
    'pnl_scope' => 'fully_modeled_minute_exits_only',
    'entry_replays' => 0,
    'trades' => 0,
    'matched_signals' => 0,
    'unmatched_signals' => 0,
    'unsupported_directions' => 0,
    'minute_exits' => 0,
    'missing_minute_exit' => 0,
    'daily_fallback_exits' => 0,
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
        $summary['unreplayable_trades']++;
        continue;
    }
    $plannedDate = plannedDate($trade['events'] ?? []) ?? (string) ($trade['entry_date'] ?? '');
    $entryDate = (string) ($trade['entry_date'] ?? '');
    $exitDate = (string) ($trade['exit_date'] ?? '');
    if ($plannedDate === '' || $entryDate === '' || $exitDate === '') {
        $summary['unreplayable_trades']++;
        continue;
    }
    $signal = matchSignal($signalIndex, $trade, $plannedDate);
    if ($signal === null) {
        $summary['unmatched_signals']++;
        $summary['unreplayable_trades']++;
        $rows[] = [
            'symbol' => $symbol,
            'planned_date' => $plannedDate,
            'daily_entry_date' => $entryDate,
            'daily_exit_date' => $exitDate,
            'daily_entry' => (float) ($trade['entry'] ?? 0.0),
            'daily_exit' => (float) ($trade['exit'] ?? 0.0),
            'shares' => (float) ($trade['shares'] ?? 0.0),
            'daily_pnl' => (float) ($trade['pnl'] ?? 0.0),
            'replay_error' => 'No exact signal match for the trade planning date.',
        ];
        continue;
    }
    if (strtolower((string) ($signal['direction'] ?? 'long')) !== 'long') {
        $summary['unsupported_directions']++;
        $summary['unreplayable_trades']++;
        $rows[] = [
            'symbol' => $symbol,
            'planned_date' => $plannedDate,
            'daily_entry_date' => $entryDate,
            'daily_exit_date' => $exitDate,
            'daily_entry' => (float) ($trade['entry'] ?? 0.0),
            'daily_exit' => (float) ($trade['exit'] ?? 0.0),
            'shares' => (float) ($trade['shares'] ?? 0.0),
            'daily_pnl' => (float) ($trade['pnl'] ?? 0.0),
            'replay_error' => 'Intraday replay currently supports long trades only.',
        ];
        continue;
    }
    $summary['matched_signals']++;

    $start = $entryDate;
    $end = (new DateTimeImmutable($exitDate))->modify('+1 day')->format('Y-m-d');
    try {
        if (boolOption((string) $options['bulk-fetch'])) {
            if (isset($bulkFetchErrors[$symbol])) {
                throw new RuntimeException((string) $bulkFetchErrors[$symbol]);
            }
            $bars = $bulkMinuteBars[$symbol] ?? [];
        } else {
            $barsBySymbol = $provider->getBars([$symbol], '1Min', $start, $end);
            $bars = $barsBySymbol[$symbol] ?? [];
        }
    } catch (Throwable $e) {
        $summary['fetch_errors']++;
        $summary['unreplayable_trades']++;
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
    if ($bars === []) {
        $summary['missing_minute_bars']++;
    }
    $replay = replayLongTrade(
        $trade,
        $signal,
        $bars,
        (float) $options['break-even-pct'],
        (float) $options['partial-pct'],
        $session,
        (string) $options['initial-stop-mode'],
        (float) $options['transaction-cost-bps'],
    );
    if ($replay === null) {
        $summary['unreplayable_trades']++;
        continue;
    }

    $dailyPnl = (float) ($trade['pnl'] ?? 0.0);
    $summary['entry_replays']++;
    $row = array_merge([
        'symbol' => $symbol,
        'planned_date' => $plannedDate,
        'daily_entry_date' => $entryDate,
        'daily_exit_date' => $exitDate,
        'daily_entry' => (float) ($trade['entry'] ?? 0.0),
        'daily_exit' => (float) ($trade['exit'] ?? 0.0),
        'shares' => (float) ($trade['shares'] ?? 0.0),
        'daily_pnl' => $dailyPnl,
    ], $replay);
    if (($replay['minute_exit_time'] ?? null) === null || !is_numeric($replay['minute_pnl'] ?? null)) {
        $summary['missing_minute_exit']++;
        $summary['unreplayable_trades']++;
        $rows[] = $row;
        continue;
    }

    $minutePnl = (float) $replay['minute_pnl'];
    $summary['trades']++;
    $summary['daily_pnl'] += $dailyPnl;
    $summary['minute_pnl'] += $minutePnl;
    $summary['minute_minus_daily_pnl'] += $minutePnl - $dailyPnl;
    $summary['daily_losses'] += $dailyPnl < 0.0 ? 1 : 0;
    $summary['minute_losses'] += $minutePnl < 0.0 ? 1 : 0;
    $summary['minute_exits']++;
    $summary['gap_below_stop_exits'] += ($replay['gap_below_stop'] ?? false) ? 1 : 0;
    $summary['break_even_armed'] += ($replay['break_even_armed_at'] ?? null) === null ? 0 : 1;
    $summary['break_even_stop_exits'] += ($replay['exit_after_break_even'] ?? false) ? 1 : 0;

    $rows[] = $row;
}

$result = [
    'generated_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
    'feed' => $feed,
    'session' => $session,
    'offline' => boolOption((string) $options['offline']),
    'bulk_fetch' => boolOption((string) $options['bulk-fetch']),
    'initial_stop_mode' => (string) $options['initial-stop-mode'],
    'transaction_cost_bps' => (float) $options['transaction-cost-bps'],
    'trades' => (string) $options['trades'],
    'signals' => (string) $options['signals'],
    'limit' => (string) $options['limit'],
    'summary' => $summary,
    'rows' => $rows,
];

$equityPath = (string) $options['equity'];
if ($equityPath !== '' && is_file($equityPath)) {
    $equityPayload = json_decode((string) file_get_contents($equityPath), true, 512, JSON_THROW_ON_ERROR);
    $dailyEquity = is_array($equityPayload['equity'] ?? null) ? $equityPayload['equity'] : [];
    $sessions = array_values(array_map(
        static fn (array $point): string => (string) ($point['date'] ?? ''),
        array_values(array_filter($dailyEquity, 'is_array')),
    ));
    $startingEquity = (float) ($dailyEquity[0]['equity'] ?? 0.0);
    $result['daily_equity_summary'] = equitySummary($dailyEquity);
    if ($summary['unreplayable_trades'] === 0 && $summary['trades'] === $summary['input_trades']) {
        $minuteAdjustedEquity = (new FixedShareExecutionEquityBuilder())->build(
            $sessions,
            $rows,
            regularSessionCloses($bulkMinuteBars),
            $startingEquity,
        );
        $result['equity_reconstruction_status'] = 'complete';
        $result['execution_stress_equity_method'] = 'fixed_historical_shares_with_actual_minute_event_dates_and_daily_marks';
        $result['minute_adjusted_equity_summary'] = equitySummary($minuteAdjustedEquity);
        $result['minute_adjusted_block_bootstrap'] = (new BlockBootstrapAnalyzer())->analyze(
            $minuteAdjustedEquity,
            1000,
            20,
            20260716,
        );
        $result['minute_adjusted_equity'] = $minuteAdjustedEquity;
    } else {
        $incompleteTrades = max(0, (int) $summary['input_trades'] - (int) $summary['trades']);
        $result['equity_reconstruction_status'] = 'blocked';
        $result['equity_reconstruction_block_reason'] = sprintf(
            '%d of %d trades lack a fully modeled minute exit; no partial portfolio metric was published.',
            $incompleteTrades,
            (int) $summary['input_trades'],
        );
    }
}

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
    $matches = $index[$key] ?? [];

    // A price-only fallback can silently attach a trade to the same level on
    // another date and inject the wrong stop/target. Replay is intentionally
    // fail-closed unless the planning date, symbol and entry all match.
    return count($matches) === 1 ? $matches[0] : null;
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
function replayLongTrade(
    array $trade,
    array $signal,
    array $bars,
    float $breakEvenPct,
    float $partialPct,
    string $session,
    string $initialStopMode,
    float $transactionCostBps,
): ?array {
    $entryLimit = (float) ($signal['entry'] ?? $trade['entry'] ?? 0.0);
    $stop = (float) ($signal['stop'] ?? 0.0);
    $target = (float) ($signal['target'] ?? 0.0);
    $shares = (float) ($trade['shares'] ?? 0.0);
    $entryDate = (string) ($trade['entry_date'] ?? '');
    $exitDate = (string) ($trade['exit_date'] ?? '');
    $initialStopMode = in_array($initialStopMode, ['hard', 'mental'], true) ? $initialStopMode : 'mental';
    if ($entryLimit <= 0.0 || $stop <= 0.0 || $shares <= 0.0 || $entryDate === '' || $exitDate === '') {
        return null;
    }

    $entryFill = null;
    $entryTime = null;
    $currentStop = $stop;
    $hardStopActive = $initialStopMode === 'hard';
    $mentalExitPending = false;
    $breakEvenArmedAt = null;
    $exitFill = null;
    $exitTime = null;
    $exitReason = null;
    $gapBelowStop = false;
    $exitAfterBreakEven = false;
    $partialRealized = 0.0;
    $partialTime = null;
    $partialFill = null;
    $partialSharesFilled = 0.0;
    $remainingShares = $shares;
    $tookPartial = false;
    $entryCost = 0.0;
    $exitCost = 0.0;
    $partialCosts = 0.0;
    $currentSessionDate = null;
    $lastSessionClose = null;
    $trailingStops = trailingStopSchedule($trade['events'] ?? []);

    foreach ($bars as $bar) {
        if ($session === 'regular' && !isRegularSession($bar->time)) {
            continue;
        }
        $sessionDate = $bar->time->setTimezone(new DateTimeZone('America/New_York'))->format('Y-m-d');
        if ($sessionDate < $entryDate || $sessionDate > $exitDate) {
            continue;
        }

        if ($currentSessionDate !== null && $sessionDate !== $currentSessionDate) {
            if (
                $entryFill !== null
                && !$hardStopActive
                && $lastSessionClose !== null
                && $lastSessionClose <= $stop
            ) {
                $mentalExitPending = true;
            }
            // The EMA10 trail is calculated from a completed daily session.
            // Activate its recorded level only at the next session boundary;
            // using it inside the source day would be another look-ahead.
            if ($tookPartial && isset($trailingStops[$currentSessionDate])) {
                $currentStop = $trailingStops[$currentSessionDate];
                $hardStopActive = true;
            }
            $currentSessionDate = $sessionDate;
        } elseif ($currentSessionDate === null) {
            $currentSessionDate = $sessionDate;
        }

        if ($entryFill !== null && $mentalExitPending) {
            $exitFill = $bar->open;
            $exitTime = $bar->time->format(DateTimeInterface::ATOM);
            $exitReason = 'mental_stop_next_open';
            $gapBelowStop = $bar->open < $stop;
            break;
        }

        $filledBelowOpenThisMinute = false;
        if ($entryFill === null) {
            // A next-session DAY limit cannot fill on its planning date. The
            // daily replay's entry_date is the only session in which this
            // already-observed order is allowed to become marketable.
            if ($sessionDate !== $entryDate || ($bar->low > $entryLimit && $bar->open > $entryLimit)) {
                $lastSessionClose = $bar->close;
                continue;
            }
            $entryFill = $bar->open <= $entryLimit ? $bar->open : $entryLimit;
            $entryTime = $bar->time->format(DateTimeInterface::ATOM);
            if ($entryFill <= 0.0) {
                return null;
            }
            $filledBelowOpenThisMinute = $bar->open > $entryLimit;
            $entryCost = modeledCost($entryFill * $shares, $transactionCostBps);
        }

        // Within one minute OHLC cannot reveal ordering. Stop-first is the
        // conservative convention whenever entry/stop/target share a bar.
        if ($hardStopActive && ($bar->low <= $currentStop || $bar->open <= $currentStop)) {
            $exitFill = $bar->open < $currentStop ? $bar->open : $currentStop;
            $exitTime = $bar->time->format(DateTimeInterface::ATOM);
            $exitReason = 'minute_stop';
            $gapBelowStop = $bar->open < $currentStop;
            $exitAfterBreakEven = $breakEvenArmedAt !== null;
            break;
        }

        // If the limit was reached below this minute's open, the printed high
        // may have happened before the fill. Do not use that unknowable high
        // to arm break-even or take profit. A lower hard stop remains valid:
        // reaching it after a higher buy limit has filled is path-consistent.
        if ($filledBelowOpenThisMinute) {
            $lastSessionClose = $bar->close;
            continue;
        }

        $armedBreakEvenThisMinute = false;
        if ($breakEvenArmedAt === null && $bar->high >= $entryFill * (1.0 + $breakEvenPct)) {
            $currentStop = $entryFill;
            $hardStopActive = true;
            $breakEvenArmedAt = $bar->time->format(DateTimeInterface::ATOM);
            $armedBreakEvenThisMinute = true;
        }

        if ($armedBreakEvenThisMinute && ($bar->low <= $currentStop || $bar->open <= $currentStop)) {
            // The open print precedes the high that armed BE. If it was below
            // the new stop, it cannot be a retroactive stop fill; use the stop
            // price for the conservative high-then-reversal path.
            $exitFill = $currentStop;
            $exitTime = $bar->time->format(DateTimeInterface::ATOM);
            $exitReason = 'minute_break_even_stop_ambiguous';
            $gapBelowStop = false;
            $exitAfterBreakEven = true;
            break;
        }

        if (!$tookPartial && $target > 0.0 && $bar->high >= $target) {
            $partialShares = $shares * max(0.0, min(1.0, $partialPct));
            $partialRealized += ($target - $entryFill) * $partialShares;
            $partialCosts += modeledCost($target * $partialShares, $transactionCostBps);
            $partialTime = $bar->time->format(DateTimeInterface::ATOM);
            $partialFill = $target;
            $partialSharesFilled = $partialShares;
            $remainingShares -= $partialShares;
            $currentStop = $entryFill;
            $hardStopActive = true;
            $tookPartial = true;
            if ($breakEvenArmedAt === null) {
                $breakEvenArmedAt = $bar->time->format(DateTimeInterface::ATOM);
            }
        }
        $lastSessionClose = $bar->close;
    }

    if ($entryFill === null) {
        return null;
    }
    if ($exitFill === null) {
        // A daily price/date fallback is not minute evidence. Publishing it as
        // a minute exit previously hid path divergences and allowed a partial
        // replay to produce a misleading portfolio CAGR/bootstrap.
        return [
            'minute_entry_time' => $entryTime,
            'minute_entry' => $entryFill,
            'initial_stop' => $stop,
            'initial_stop_mode' => $initialStopMode,
            'break_even_armed_at' => $breakEvenArmedAt,
            'minute_exit_time' => null,
            'minute_exit' => null,
            'minute_exit_reason' => 'unresolved_after_daily_exit',
            'minute_pnl' => null,
            'minute_minus_daily_pnl' => null,
            'modeled_entry_cost' => $entryCost,
            'modeled_partial_costs' => $partialCosts,
            'modeled_exit_cost' => null,
            'minute_partial_time' => $partialTime,
            'minute_partial_price' => $partialFill,
            'minute_partial_shares' => $partialSharesFilled,
            'gap_below_stop' => false,
            'exit_after_break_even' => false,
            'took_partial' => $tookPartial,
            'replay_error' => 'Minute rules did not produce an exit by the daily model exit date.',
        ];
    }
    $exitCost = modeledCost($exitFill * $remainingShares, $transactionCostBps);
    $minutePnl = $partialRealized
        + ($exitFill - $entryFill) * $remainingShares
        - $entryCost
        - $partialCosts
        - $exitCost;

    return [
        'minute_entry_time' => $entryTime,
        'minute_entry' => $entryFill,
        'initial_stop' => $stop,
        'initial_stop_mode' => $initialStopMode,
        'break_even_armed_at' => $breakEvenArmedAt,
        'minute_exit_time' => $exitTime,
        'minute_exit' => $exitFill,
        'minute_exit_reason' => $exitReason,
        'minute_pnl' => $minutePnl,
        'minute_minus_daily_pnl' => $minutePnl - (float) ($trade['pnl'] ?? 0.0),
        'modeled_entry_cost' => $entryCost,
        'modeled_partial_costs' => $partialCosts,
        'modeled_exit_cost' => $exitCost,
        'minute_partial_time' => $partialTime,
        'minute_partial_price' => $partialFill,
        'minute_partial_shares' => $partialSharesFilled,
        'gap_below_stop' => $gapBelowStop,
        'exit_after_break_even' => $exitAfterBreakEven,
        'took_partial' => $tookPartial,
    ];
}

function modeledCost(float $notional, float $basisPoints): float
{
    return max(0.0, $notional) * max(0.0, $basisPoints) / 10000.0;
}

/** @return array<string, float> */
function trailingStopSchedule(mixed $events): array
{
    if (!is_array($events)) {
        return [];
    }

    $schedule = [];
    foreach ($events as $event) {
        if (!is_string($event)) {
            continue;
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2}): trailed stop to EMA10 ([0-9]+(?:\.[0-9]+)?)/', $event, $matches) !== 1) {
            continue;
        }
        $level = (float) $matches[2];
        if ($level > 0.0) {
            $schedule[$matches[1]] = $level;
        }
    }
    ksort($schedule, SORT_STRING);

    return $schedule;
}

/** @param array<string, list<Bar>> $barsBySymbol @return array<string, array<string, float>> */
function regularSessionCloses(array $barsBySymbol): array
{
    $closes = [];
    foreach ($barsBySymbol as $symbol => $bars) {
        foreach ($bars as $bar) {
            if (!$bar instanceof Bar || !isRegularSession($bar->time)) {
                continue;
            }
            $date = $bar->time->setTimezone(new DateTimeZone('America/New_York'))->format('Y-m-d');
            $closes[strtoupper((string) $symbol)][$date] = $bar->close;
        }
        if (isset($closes[strtoupper((string) $symbol)])) {
            ksort($closes[strtoupper((string) $symbol)], SORT_STRING);
        }
    }

    return $closes;
}

/** @param list<array{date:string,equity:float}> $equity */
function equitySummary(array $equity): array
{
    if (count($equity) < 2) {
        return ['points' => count($equity), 'return_pct' => 0.0, 'annualized_return_pct' => 0.0, 'max_drawdown_pct' => 0.0];
    }
    $first = $equity[0];
    $last = $equity[array_key_last($equity)];
    $starting = (float) ($first['equity'] ?? 0.0);
    $ending = (float) ($last['equity'] ?? 0.0);
    $return = $starting > 0.0 ? $ending / $starting - 1.0 : 0.0;
    $days = (new DateTimeImmutable((string) $first['date']))->diff(new DateTimeImmutable((string) $last['date']))->days;
    $annualized = $starting > 0.0 && $ending > 0.0 && $days > 0
        ? ($ending / $starting) ** (365.25 / $days) - 1.0
        : 0.0;
    $peak = null;
    $drawdown = 0.0;
    foreach ($equity as $point) {
        $value = (float) ($point['equity'] ?? 0.0);
        $peak = $peak === null ? $value : max($peak, $value);
        if ($peak > 0.0) {
            $drawdown = min($drawdown, ($value - $peak) / $peak);
        }
    }

    return [
        'points' => count($equity),
        'starting_equity' => $starting,
        'ending_equity' => $ending,
        'return_pct' => $return,
        'annualized_return_pct' => $annualized,
        'max_drawdown_pct' => $drawdown,
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
