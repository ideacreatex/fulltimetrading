<?php

declare(strict_types=1);

namespace FulltimeTrading\Research;

use FulltimeTrading\Domain\Bar;

/** Read-only research transformations. Never supplies executable paper signals. */
final class DailyDataAudit
{
    public static function session(Bar $bar): string
    {
        return $bar->time->setTimezone(new \DateTimeZone('America/New_York'))->format('Y-m-d');
    }

    public static function encode(array $bars): array
    {
        $rows = [];
        foreach ($bars as $symbol => $series) {
            $rows[$symbol] = [];
            foreach ($series as $bar) {
                $rows[$symbol][] = ['t' => $bar->time->format(DATE_ATOM), 'o' => $bar->open,
                    'h' => $bar->high, 'l' => $bar->low, 'c' => $bar->close, 'v' => $bar->volume];
            }
        }
        ksort($rows, SORT_STRING);
        return $rows;
    }

    public static function decode(array $rows): array
    {
        $bars = [];
        foreach ($rows as $symbol => $series) {
            $bars[$symbol] = array_map(static fn (array $row): Bar => Bar::fromAlpaca($symbol, $row), $series);
        }
        return $bars;
    }

    public static function indexed(array $bars): array
    {
        $answer = [];
        foreach ($bars as $symbol => $series) {
            $answer[$symbol] = [];
            $previous = null;
            foreach ($series as $bar) {
                $date = self::session($bar);
                if ($bar->symbol !== $symbol || ($previous !== null && $date <= $previous)) {
                    throw new \RuntimeException('Wrong symbol, duplicate or unordered daily bar: ' . $symbol . '/' . $date);
                }
                foreach ([$bar->open, $bar->high, $bar->low, $bar->close] as $p) {
                    if (!is_finite($p) || $p <= 0.0) {
                        throw new \RuntimeException('Invalid daily price: ' . $symbol . '/' . $date);
                    }
                }
                // Providers can round OHLC independently; tolerate only floating-point dust.
                $epsilon = $bar->high * 1.0e-6;
                if ($bar->high + $epsilon < max($bar->open, $bar->close)
                    || $bar->low - $epsilon > min($bar->open, $bar->close)
                    || !is_finite($bar->volume) || $bar->volume < 0.0) {
                    throw new \RuntimeException('Invalid daily geometry: ' . $symbol . '/' . $date);
                }
                $answer[$symbol][$date] = $bar;
                $previous = $date;
            }
        }
        return $answer;
    }

    public static function coverage(array $bars, string $benchmark = 'SPY'): array
    {
        $index = self::indexed($bars);
        if (($index[$benchmark] ?? []) === []) {
            throw new \RuntimeException('Missing benchmark calendar.');
        }
        $calendar = array_keys($index[$benchmark]);
        $result = [];
        foreach ($index as $symbol => $series) {
            $first = array_key_first($series);
            $last = array_key_last($series);
            $expected = array_filter($calendar, static fn (string $d): bool => $first !== null && $d >= $first && $d <= $last);
            $result[$symbol] = ['bars' => count($series), 'first' => $first, 'last' => $last,
                'internal_missing_sessions' => array_values(array_diff($expected, array_keys($series))),
                'extra_sessions' => array_values(array_diff(array_keys($series), $calendar))];
        }
        return $result;
    }

    public static function quantile(array $values, float $p): ?float
    {
        if ($values === []) { return null; }
        sort($values, SORT_NUMERIC);
        $x = max(0.0, min(1.0, $p)) * (count($values) - 1);
        $lo = (int) floor($x);
        $hi = (int) ceil($x);
        return (float) $values[$lo] + ($x - $lo) * ($values[$hi] - $values[$lo]);
    }

    public static function compare(array $reference, array $candidate, ?string $start = null, ?string $end = null): array
    {
        $a = self::indexed($reference);
        $b = self::indexed($candidate);
        $report = ['matched_bars' => 0, 'missing_candidate' => [], 'extra_candidate' => [], 'symbols' => []];
        $all = $worst = [];
        foreach ($a as $symbol => $series) {
            $values = [];
            $dates = array_filter(array_keys($series), static fn (string $d): bool => ($start === null || $d >= $start) && ($end === null || $d <= $end));
            foreach ($dates as $date) {
                if (!isset($b[$symbol][$date])) {
                    $report['missing_candidate'][] = $symbol . '/' . $date;
                    continue;
                }
                $report['matched_bars']++;
                foreach (['open', 'high', 'low', 'close'] as $field) {
                    $delta = abs($b[$symbol][$date]->$field / $a[$symbol][$date]->$field - 1.0) * 10000.0;
                    $values[$field][] = $delta;
                    $all[$field][] = $delta;
                    if ($delta > 25.0) {
                        $worst[] = ['symbol' => $symbol, 'date' => $date, 'field' => $field, 'absolute_bps' => $delta,
                            'reference' => $a[$symbol][$date]->$field, 'candidate' => $b[$symbol][$date]->$field];
                    }
                }
                if ($a[$symbol][$date]->volume > 0.0) {
                    $all['volume_ratio'][] = $b[$symbol][$date]->volume / $a[$symbol][$date]->volume;
                }
            }
            $report['symbols'][$symbol] = self::summarize($values);
        }
        foreach ($b as $symbol => $series) {
            foreach ($series as $date => $bar) {
                if (($start === null || $date >= $start) && ($end === null || $date <= $end) && !isset($a[$symbol][$date])) {
                    $report['extra_candidate'][] = $symbol . '/' . $date;
                }
            }
        }
        usort($worst, static fn (array $x, array $y): int => $y['absolute_bps'] <=> $x['absolute_bps']);
        $report['summary'] = self::summarize($all);
        $report['largest_differences'] = array_slice($worst, 0, 30);
        return $report;
    }

    private static function summarize(array $series): array
    {
        $answer = [];
        foreach ($series as $field => $values) {
            $answer[$field] = ['n' => count($values), 'p50' => self::quantile($values, 0.5),
                'p95' => self::quantile($values, 0.95), 'max' => max($values),
                'above_25_bps' => $field === 'volume_ratio' ? null : count(array_filter($values, static fn (float $v): bool => $v > 25.0))];
        }
        return $answer;
    }

    /** Replace only predeclared fields on an identical calendar, never silently fill gaps. */
    public static function replaceFields(array $reference, array $candidate, array $fields, ?string $start = null): array
    {
        if (array_diff($fields, ['open', 'high', 'low', 'close', 'volume']) !== []) {
            throw new \InvalidArgumentException('Unknown daily field.');
        }
        $other = self::indexed($candidate);
        $result = [];
        foreach ($reference as $symbol => $series) {
            $result[$symbol] = [];
            foreach ($series as $bar) {
                $date = self::session($bar);
                if ($start !== null && $date < $start) {
                    $result[$symbol][] = $bar;
                    continue;
                }
                $replacement = $other[$symbol][$date] ?? null;
                if (!$replacement instanceof Bar) {
                    throw new \RuntimeException('Cannot replace missing daily bar: ' . $symbol . '/' . $date);
                }
                $p = [];
                foreach (['open', 'high', 'low', 'close', 'volume'] as $field) {
                    $p[$field] = in_array($field, $fields, true) ? $replacement->$field : $bar->$field;
                }
                // A mixed-feed execution price can lie outside the other feed's range.
                $result[$symbol][] = new Bar($symbol, $bar->time, $p['open'], max($p['high'], $p['open'], $p['close']),
                    min($p['low'], $p['open'], $p['close']), $p['close'], $p['volume']);
            }
        }
        return $result;
    }

    public static function withUniverse(array $profile, array $universe): array
    {
        $universe = array_values(array_unique($universe));
        sort($universe, SORT_STRING);
        if ($universe === []) { throw new \InvalidArgumentException('Empty universe.'); }
        $profile['universe'] = $universe;
        foreach ($profile['sleeves'] as $name => &$sleeve) {
            $sleeve['config']['universe'] = $name === 'qqq150_ex_crypto'
                ? array_values(array_diff($universe, ['MSTR', 'COIN'])) : $universe;
        }
        unset($sleeve);
        return $profile;
    }

    public static function availableBy(array $universe, array $completeHistory, string $end): array
    {
        $included = $excluded = [];
        foreach ($universe as $symbol) {
            $series = $completeHistory[$symbol] ?? [];
            if ($series === []) {
                throw new \RuntimeException('Cannot infer historical availability from missing data: ' . $symbol);
            }
            $first = self::session($series[0]);
            if ($first > $end) { $excluded[$symbol] = ['first_available_session' => $first]; }
            else { $included[] = $symbol; }
        }
        return ['included' => $included, 'not_yet_in_this_history' => $excluded];
    }

    /** Two corroborating feeds identify sensitivity cases, not an authoritative repaired tape. */
    public static function corroboratedOutliers(array $reference, array $second, array $third, float $disagreementBps = 100.0, float $agreementBps = 25.0): array
    {
        $a = self::indexed($reference);
        $b = self::indexed($second);
        $c = self::indexed($third);
        $events = [];
        $result = [];
        foreach ($a as $symbol => $series) {
            $result[$symbol] = [];
            foreach ($series as $date => $bar) {
                $values = [];
                foreach (['open', 'high', 'low', 'close'] as $field) {
                    $values[$field] = $bar->$field;
                    if (!isset($b[$symbol][$date], $c[$symbol][$date])) { continue; }
                    $secondPrice = $b[$symbol][$date]->$field;
                    $thirdPrice = $c[$symbol][$date]->$field;
                    if (abs($secondPrice / $thirdPrice - 1.0) * 10000.0 <= $agreementBps
                        && abs($secondPrice / $bar->$field - 1.0) * 10000.0 > $disagreementBps
                        && abs($thirdPrice / $bar->$field - 1.0) * 10000.0 > $disagreementBps) {
                        $values[$field] = $secondPrice;
                        $events[] = ['symbol' => $symbol, 'date' => $date, 'field' => $field,
                            'reference' => $bar->$field, 'second' => $secondPrice, 'third' => $thirdPrice];
                    }
                }
                $result[$symbol][] = new Bar($symbol, $bar->time, $values['open'], max($values), min($values), $values['close'], $bar->volume);
            }
        }
        return ['bars' => $result, 'events' => $events, 'disagreement_bps' => $disagreementBps, 'agreement_bps' => $agreementBps];
    }

    public static function missingExecutionBars(array $sleeveCurves, array $missing): array
    {
        $keys = array_fill_keys($missing, true);
        $affected = [];
        foreach ($sleeveCurves as $sleeve => $curve) {
            $previous = null;
            foreach ($curve as $row) {
                if (($row['turnover'] ?? 0.0) > 1.0e-10) {
                    foreach (array_unique(array_filter([$previous, $row['holding'] ?? null])) as $symbol) {
                        if (isset($keys[$symbol . '/' . $row['date']])) {
                            $affected[] = ['sleeve' => $sleeve, 'symbol' => $symbol, 'date' => $row['date']];
                        }
                    }
                }
                $previous = $row['holding'] ?? null;
            }
        }
        return $affected;
    }
}
