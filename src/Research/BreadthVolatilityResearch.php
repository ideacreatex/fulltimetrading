<?php

declare(strict_types=1);

namespace FulltimeTrading\Research;

final class BreadthVolatilityResearch
{
    public const EVENTS = ['low_touch', 'low_close', 'low_reclaim11', 'low_reclaim20',
        'high_touch', 'high_close', 'high_fade80', 'high_hold2'];

    public static function validateBreadth(array $series, array $calendar): array
    {
        $aligned = [];
        foreach ($calendar as $date) {
            $row = $series[$date] ?? null;
            if (!is_array($row)) { throw new \RuntimeException('Missing S5TW session: ' . $date); }
            foreach (['open', 'high', 'low', 'close'] as $field) {
                if (!isset($row[$field]) || !is_numeric($row[$field]) || !is_finite((float) $row[$field])
                    || $row[$field] < 0 || $row[$field] > 100) { throw new \RuntimeException('Invalid breadth ' . $date . '/' . $field); }
            }
            if ($row['high'] < max($row['open'], $row['close'], $row['low'])
                || $row['low'] > min($row['open'], $row['close'])) { throw new \RuntimeException('Inconsistent breadth OHLC: ' . $date); }
            $aligned[$date] = $row;
        }
        return $aligned;
    }

    public static function events(array $series, float $low = 11, float $high = 80): array
    {
        if ($low < 0 || $low + 9 >= $high || $high > 100) { throw new \InvalidArgumentException('Invalid breadth thresholds.'); }
        $result = array_fill_keys(self::EVENTS, []);
        $previous = null;
        $lastLow = -100;
        $highStreak = 0;
        $index = 0;
        foreach ($series as $date => $row) {
            if ($row['low'] <= $low) { $lastLow = $index; }
            $highStreak = $row['close'] >= $high ? $highStreak + 1 : 0;
            $signals = [
                'low_touch' => $row['low'] <= $low && $previous !== null && $previous['low'] > $low,
                'low_close' => $row['close'] <= $low && $previous !== null && $previous['close'] > $low,
                'low_reclaim11' => $row['close'] > $low && $previous !== null && $previous['close'] <= $low,
                'low_reclaim20' => $row['close'] > $low + 9 && $previous !== null && $previous['close'] <= $low + 9 && $index - $lastLow <= 10,
                'high_touch' => $row['high'] >= $high && $previous !== null && $previous['high'] < $high,
                'high_close' => $row['close'] >= $high && $previous !== null && $previous['close'] < $high,
                'high_fade80' => $row['close'] < $high && $previous !== null && $previous['close'] >= $high,
                'high_hold2' => $highStreak === 2,
            ];
            foreach ($signals as $event => $signal) { $result[$event][$date] = $signal; }
            $previous = $row;
            $index++;
        }
        return $result;
    }

    public static function windowScale(array $signals, int $sessions, float $inside): array
    {
        if ($sessions < 1 || !is_finite($inside) || $inside < 0 || $inside > 2) { throw new \InvalidArgumentException('Invalid scale window.'); }
        $result = [];
        $left = 0;
        foreach ($signals as $date => $signal) {
            if ($signal) { $left = $sessions; }
            $result[$date] = $left > 0 ? $inside : 1.0;
            $left = max(0, $left - 1);
        }
        return $result;
    }

    public static function trend(array $bars, int $window, string $mode = 'sma'): array
    {
        if ($window < 2 || !in_array($mode, ['sma', 'return'], true)) { throw new \InvalidArgumentException('Invalid trend definition.'); }
        $result = $closes = [];
        foreach ($bars as $bar) {
            $i = count($closes);
            $closes[] = $bar->close;
            $result[DailyDataAudit::session($bar)] = $mode === 'sma'
                ? ($i >= $window - 1 && $bar->close > array_sum(array_slice($closes, -$window)) / $window)
                : ($i >= $window && $bar->close > $closes[$i - $window]);
        }
        return $result;
    }

    /** Independent, unlevered cash/ETF account: close signals, next-open orders, no overlapping entries. */
    public static function eventReplay(array $bars, array $signals, int $hold, float $costBps, string $start, string $end): array
    {
        if ($hold < 1 || $costBps < 0 || $costBps >= 10000) { throw new \InvalidArgumentException('Invalid replay settings.'); }
        $symbol = $bars[0]->symbol ?? throw new \RuntimeException('Missing asset bars.');
        $indexed = DailyDataAudit::indexed([$symbol => $bars])[$symbol];
        $dates = array_values(array_filter(array_keys($indexed), static fn ($date): bool => $date >= $start && $date <= $end));
        if (count($dates) < 2) { throw new \RuntimeException('Insufficient event replay history.'); }
        $cash = $equity = 30000.0;
        $shares = 0.0;
        $entryIndex = null;
        $entry = null;
        $curve = $trades = [];
        $fee = $costBps / 10000;
        foreach ($dates as $i => $date) {
            if (!array_key_exists($date, $signals)) { throw new \RuntimeException('Missing event signal: ' . $date); }
            $bar = $indexed[$date];
            $before = $equity;
            $openEquity = $cash + $shares * $bar->open;
            $turnover = 0.0;
            if ($shares > 0 && $i >= $entryIndex + $hold) {
                $turnover += $shares * $bar->open;
                $cash += $shares * $bar->open * (1 - $fee);
                $trades[] = $entry + ['exit' => $date, 'exit_price' => $bar->open,
                    'net_return' => $cash / $entry['capital'] - 1, 'sessions' => $i - $entryIndex];
                $shares = 0.0;
            }
            if ($shares === 0.0 && $i > 0 && $signals[$dates[$i - 1]]) {
                $entryIndex = $i;
                $entry = ['signal' => $dates[$i - 1], 'entry' => $date, 'entry_price' => $bar->open, 'capital' => $cash];
                $shares = $cash / ($bar->open * (1 + $fee));
                $turnover += $shares * $bar->open;
                $cash = 0.0;
            }
            $equity = $cash + $shares * $bar->close;
            $curve[] = ['date' => $date, 'period_start_date' => $i > 0 ? $dates[$i - 1] : $date,
                'start_equity' => $before, 'equity' => $equity,
                'equity_low' => min($before, $openEquity, $cash + $shares * $bar->low),
                'equity_high' => max($before, $openEquity, $cash + $shares * $bar->high),
                'turnover' => $turnover / $before, 'invested' => $shares > 0];
        }
        return ['curve' => $curve, 'trades' => $trades, 'open_trade' => $shares > 0 ? $entry : null];
    }

    public static function metrics(array $curve, ?string $start = null, ?string $end = null): array
    {
        $rows = array_values(array_filter($curve, static fn ($r): bool => ($start === null || $r['date'] >= $start) && ($end === null || $r['date'] < $end)));
        if ($rows === []) { return ['sessions' => 0, 'cagr' => 0.0, 'return' => 0.0, 'max_drawdown' => 0.0]; }
        $initial = $rows[0]['start_equity'];
        $peak = $initial;
        $dd = 0.0;
        foreach ($rows as $row) {
            $peak = max($peak, $row['equity_high']);
            $dd = min($dd, $row['equity_low'] / $peak - 1);
        }
        $ratio = end($rows)['equity'] / $initial;
        $days = max(1, (int) (new \DateTimeImmutable($rows[0]['period_start_date']))->diff(new \DateTimeImmutable(end($rows)['date']))->days);
        return ['sessions' => count($rows), 'return' => $ratio - 1, 'cagr' => $ratio ** (365.25 / $days) - 1,
            'max_drawdown' => $dd, 'terminal_equity' => end($rows)['equity'], 'turnover' => array_sum(array_column($rows, 'turnover'))];
    }

    /** Static initial capital sleeves; no implicit daily transfer or free rebalancing. */
    public static function mix(array $base, array $satellite, float $allocation): array
    {
        if (!is_finite($allocation) || $allocation < 0 || $allocation > 1 || count($base) !== count($satellite)) {
            throw new \InvalidArgumentException('Invalid static capital mix.');
        }
        $result = [];
        foreach ($base as $i => $control) {
            $point = $satellite[$i];
            if ($point['date'] !== $control['date']) { throw new \RuntimeException('Unaligned static capital sleeves.'); }
            $mixed = $point;
            foreach (['start_equity', 'equity', 'equity_low', 'equity_high'] as $field) {
                $mixed[$field] = (1 - $allocation) * $control[$field] + $allocation * $point[$field];
            }
            $mixed['turnover'] = ((1 - $allocation) * $control['start_equity'] * $control['turnover']
                + $allocation * $point['start_equity'] * $point['turnover']) / $mixed['start_equity'];
            $result[] = $mixed;
        }
        return $result;
    }
}
