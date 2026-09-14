<?php

declare(strict_types=1);

namespace FulltimeTrading\Research;

/** Isolated, long-only research. Close decisions execute at the following session's open. */
final class StandardEtfResearch
{
    private array $bars;
    private array $features = [];

    public static function definitions(): array
    {
        $baskets = ['us' => ['SPY', 'QQQ', 'IWM'], 'global' => ['SPY', 'EFA', 'EEM'],
            'diversified' => ['SPY', 'GLD', 'IEF', 'TLT'], 'sectors' => ['XLK', 'XLF', 'XLP', 'XLU']];
        $cases = [];
        foreach ($baskets as $name => $symbols) {
            foreach ([63, 126, 252] as $window) {
                foreach (['equal', 'inverse_vol'] as $weighting) {
                    $cases["trend_{$name}_{$window}_{$weighting}"] = ['family' => 'trend', 'symbols' => $symbols,
                        'window' => $window, 'weighting' => $weighting, 'rebalance' => 21];
                }
            }
            foreach ([20, 55, 100] as $window) {
                foreach ([10, 20] as $exit) {
                    $cases["donchian_{$name}_{$window}_{$exit}"] = ['family' => 'donchian', 'symbols' => $symbols,
                        'window' => $window, 'exit_window' => $exit, 'rebalance' => 21];
                }
            }
        }
        foreach (['global' => ['SPY', 'EFA', 'EEM', 'QQQ'], 'diversified' => ['SPY', 'GLD', 'TLT', 'HYG']] as $name => $symbols) {
            foreach ([63, 126, 252] as $window) {
                foreach ([1, 2] as $top) {
                    $cases["dual_{$name}_{$window}_{$top}"] = ['family' => 'dual', 'symbols' => $symbols,
                        'window' => $window, 'top' => $top, 'rebalance' => 21];
                }
            }
        }
        foreach (['SPY', 'QQQ', 'IWM'] as $symbol) {
            foreach ([5, 10, 20] as $entry) {
                foreach ([50, 70] as $exit) {
                    foreach ([5, 10] as $hold) {
                        $cases["rsi2_{$symbol}_{$entry}_{$exit}_{$hold}"] = ['family' => 'rsi', 'symbols' => [$symbol],
                            'entry' => $entry, 'exit' => $exit, 'max_hold' => $hold, 'rebalance' => 21];
                    }
                }
            }
        }
        foreach (['SPY', 'QQQ'] as $symbol) {
            foreach ([20, 60] as $window) {
                foreach ([0.10, 0.20, 0.30] as $target) {
                    $cases['vol_' . $symbol . '_' . $window . '_' . (int) round(100 * $target)] = ['family' => 'vol',
                        'symbols' => [$symbol], 'window' => $window, 'target_vol' => $target, 'rebalance' => 5];
                }
            }
        }
        return $cases;
    }

    public function __construct(array $bars)
    {
        $this->bars = DailyDataAudit::indexed($bars);
        if (empty($this->bars['SPY'])) { throw new \InvalidArgumentException('SPY calendar required.'); }
        foreach ($this->bars as $symbol => $series) {
            $closes = $highs = $lows = $returns = [];
            $gain = $loss = 0.0;
            foreach ($series as $date => $bar) {
                $i = count($closes);
                $delta = $i > 0 ? $bar->close - $closes[$i - 1] : 0.0;
                if ($i > 0) {
                    $returns[] = $bar->close / $closes[$i - 1] - 1;
                    if ($i <= 2) { $gain += max(0.0, $delta) / 2; $loss += max(0.0, -$delta) / 2; }
                    else { $gain = ($gain + max(0.0, $delta)) / 2; $loss = ($loss + max(0.0, -$delta)) / 2; }
                }
                $row = ['rsi' => $i < 2 ? null : ($gain + $loss > 0 ? 100 * $gain / ($gain + $loss) : 50.0)];
                foreach ([10, 20, 55, 100] as $window) {
                    $row['high_' . $window] = $i >= $window ? max(array_slice($highs, -$window)) : null;
                    $row['low_' . $window] = $i >= $window ? min(array_slice($lows, -$window)) : null;
                }
                foreach ([63, 126, 252] as $window) { $row['return_' . $window] = $i >= $window ? $bar->close / $closes[$i - $window] - 1 : null; }
                foreach ([20, 60] as $window) {
                    $r = array_slice($returns, -$window);
                    $mean = $r === [] ? 0.0 : array_sum($r) / count($r);
                    $row['vol_' . $window] = count($r) === $window ? sqrt(252 * array_sum(array_map(static fn ($v): float => ($v - $mean) ** 2, $r)) / ($window - 1)) : null;
                }
                $closes[] = $bar->close; $highs[] = $bar->high; $lows[] = $bar->low;
                $row['sma200'] = $i >= 199 ? array_sum(array_slice($closes, -200)) / 200 : null;
                $this->features[$symbol][$date] = $row;
            }
        }
    }

    /** Solve target weights on equity AFTER fees, without borrowing to pay costs. */
    public static function rebalance(float $cash, array $shares, array $prices, array $target, float $fee): array
    {
        if (!is_finite($cash) || $cash < -1e-6 || !is_finite($fee) || $fee < 0 || $fee >= 1 || array_sum($target) > 1 + 1e-12) {
            throw new \InvalidArgumentException('Invalid cash, costs or gross exposure.');
        }
        $symbols = array_values(array_unique(array_merge(array_keys($shares), array_keys($target))));
        $equity = $cash;
        foreach ($symbols as $s) {
            if (!isset($prices[$s]) || !is_finite((float) $prices[$s]) || $prices[$s] <= 0
                || !is_finite((float) ($target[$s] ?? 0)) || ($target[$s] ?? 0) < 0
                || !is_finite((float) ($shares[$s] ?? 0)) || ($shares[$s] ?? 0) < 0) { throw new \InvalidArgumentException('Invalid target or price.'); }
            $equity += ($shares[$s] ?? 0) * $prices[$s];
        }
        if ($equity <= 0) { throw new \RuntimeException('Nonpositive equity.'); }
        $notional = static function (float $net) use ($symbols, $target, $shares, $prices): float {
            $total = 0.0;
            foreach ($symbols as $s) { $total += abs(($target[$s] ?? 0) * $net - ($shares[$s] ?? 0) * $prices[$s]); }
            return $total;
        };
        $lo = 0.0; $hi = $equity;
        for ($i = 0; $i < 60; $i++) {
            $mid = ($lo + $hi) / 2;
            if ($mid + $fee * $notional($mid) > $equity) { $hi = $mid; } else { $lo = $mid; }
        }
        $net = ($lo + $hi) / 2;
        $new = $deltas = [];
        foreach ($symbols as $s) {
            $qty = ($target[$s] ?? 0) * $net / $prices[$s];
            if ($qty > 1e-12) { $new[$s] = $qty; }
            $deltas[$s] = $qty - ($shares[$s] ?? 0);
        }
        return ['cash' => max(0.0, $net * (1 - array_sum($target))), 'shares' => $new, 'deltas' => $deltas,
            'turnover_dollars' => $notional($net), 'cost' => $equity - $net];
    }

    public function run(array $config, string $start, string $end, float $costBps = 30.0): array
    {
        if (!in_array($config, self::definitions(), true) || !is_finite($costBps) || $costBps < 0 || $costBps >= 10000) {
            throw new \InvalidArgumentException('Unknown configuration or invalid costs.');
        }
        $dates = array_values(array_filter(array_keys($this->bars['SPY']), static fn ($d): bool => $d >= $start && $d <= $end));
        if (count($dates) < 2) { throw new \RuntimeException('Insufficient calendar.'); }
        $cash = $equity = 30000.0;
        $shares = $active = $curve = $trades = $entries = $orders = [];
        $pending = null;
        foreach ($dates as $i => $date) {
            $prices = [];
            foreach (array_unique(array_merge($config['symbols'], ['SHY'])) as $s) {
                if (!isset($this->bars[$s][$date])) { throw new \RuntimeException('Missing ETF session: ' . $s . '/' . $date); }
                $prices[$s] = $this->bars[$s][$date]->open;
            }
            $before = $equity;
            $openEquity = $cash;
            foreach ($shares as $s => $qty) { $openEquity += $qty * $prices[$s]; }
            $turnover = 0.0;
            if ($pending !== null) {
                $execution = self::rebalance($cash, $shares, $prices, $pending, $costBps / 10000);
                foreach ($execution['deltas'] as $s => $delta) {
                    if (abs($delta * $prices[$s]) < 1e-8) { continue; }
                    $orders[] = ['signal_date' => $dates[$i - 1], 'date' => $date, 'symbol' => $s, 'qty' => $delta, 'price' => $prices[$s]];
                    if (!isset($shares[$s]) && isset($execution['shares'][$s])) { $entries[$s] = ['symbol' => $s, 'entry' => $date]; }
                    if (isset($shares[$s]) && !isset($execution['shares'][$s])) { $trades[] = $entries[$s] + ['exit' => $date]; unset($entries[$s]); }
                }
                $cash = $execution['cash']; $shares = $execution['shares']; $turnover = $execution['turnover_dollars'] / $before;
            }
            $equity = $low = $high = $cash;
            foreach ($shares as $s => $qty) {
                $bar = $this->bars[$s][$date];
                $equity += $qty * $bar->close; $low += $qty * $bar->low; $high += $qty * $bar->high;
            }
            $curve[] = ['date' => $date, 'period_start_date' => $i > 0 ? $dates[$i - 1] : $date,
                'start_equity' => $before, 'equity' => $equity, 'equity_low' => min($before, $openEquity, $low),
                'equity_high' => max($before, $openEquity, $high), 'turnover' => $turnover];
            $target = $this->target($config, $date, $i, $active);
            $held = array_keys($shares); $wanted = array_keys($target); sort($held); sort($wanted);
            // Breakout/RSI exits are daily; scheduled resizing does not reset holding age.
            $dailySwitch = in_array($config['family'], ['donchian', 'rsi'], true) && $held !== $wanted;
            $pending = $dailySwitch || $i % $config['rebalance'] === 0 ? $target : null;
        }
        return ['curve' => $curve, 'trades' => $trades, 'orders' => $orders, 'next_target' => $pending,
            'open_positions' => $shares, 'cash' => $cash, 'orders_submitted' => 0];
    }

    private function target(array $c, string $date, int $i, array &$active): array
    {
        $scores = [];
        foreach ($c['symbols'] as $s) {
            $f = $this->features[$s][$date]; $close = $this->bars[$s][$date]->close;
            if ($c['family'] === 'donchian') {
                if (isset($active[$s]) && $f['low_' . $c['exit_window']] !== null && $close < $f['low_' . $c['exit_window']]) { unset($active[$s]); }
                elseif (!isset($active[$s]) && $f['high_' . $c['window']] !== null && $close > $f['high_' . $c['window']]) { $active[$s] = $i; }
                if (isset($active[$s])) { $scores[$s] = 1.0; }
            } elseif ($c['family'] === 'rsi') {
                if (isset($active[$s])) {
                    if ($f['rsi'] >= $c['exit'] || $i - $active[$s] >= $c['max_hold']) { unset($active[$s]); }
                } elseif ($f['sma200'] !== null && $close > $f['sma200'] && $f['rsi'] < $c['entry']) { $active[$s] = $i; }
                if (isset($active[$s])) { $scores[$s] = 1.0; }
            } elseif ($c['family'] === 'vol') {
                if ($f['sma200'] !== null && $close > $f['sma200'] && $f['vol_' . $c['window']] !== null) {
                    return [$s => min(1.0, $c['target_vol'] / max(0.001, $f['vol_' . $c['window']]))];
                }
            } else {
                $r = $f['return_' . $c['window']];
                $cashReturn = $this->features['SHY'][$date]['return_' . $c['window']];
                if ($c['family'] === 'dual' && $cashReturn === null) { continue; }
                if ($r !== null && $r > ($c['family'] === 'dual' ? max(0.0, $cashReturn) : 0.0)) { $scores[$s] = $r; }
            }
        }
        if ($scores === []) { return []; }
        if ($c['family'] === 'dual') { arsort($scores, SORT_NUMERIC); $scores = array_slice($scores, 0, $c['top'], true); }
        $weights = [];
        foreach ($scores as $s => $score) {
            $vol = $this->features[$s][$date]['vol_20'];
            if (($c['weighting'] ?? 'equal') === 'inverse_vol' && $vol === null) { return []; }
            $weights[$s] = ($c['weighting'] ?? 'equal') === 'inverse_vol' ? 1 / max(0.01, $vol) : 1.0;
        }
        $total = array_sum($weights);
        return array_map(static fn ($v): float => $v / $total, $weights);
    }
}
