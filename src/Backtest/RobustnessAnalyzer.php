<?php

declare(strict_types=1);

namespace FulltimeTrading\Backtest;

use FulltimeTrading\Domain\Trade;

final class RobustnessAnalyzer
{
    /**
     * @param list<Trade> $trades
     * @param list<array{date:string, equity:float}> $equityCurve
     * @return array<string, mixed>
     */
    public function analyze(array $trades, array $equityCurve, string $splitDate = '2024-01-01'): array
    {
        $split = new \DateTimeImmutable($splitDate);
        $splitDate = $split->format('Y-m-d');

        $preSplitTrades = [];
        $postSplitTrades = [];
        foreach ($trades as $trade) {
            if ($trade->exitTime->format('Y-m-d') < $splitDate) {
                $preSplitTrades[] = $trade;
            } else {
                $postSplitTrades[] = $trade;
            }
        }

        $tradeMetrics = $this->tradeMetrics($trades);
        $preSplitTradeMetrics = $this->tradeMetrics($preSplitTrades);
        $postSplitTradeMetrics = $this->tradeMetrics($postSplitTrades);

        $curve = $this->normalizeCurve($equityCurve);
        $preSplitCurve = array_values(array_filter(
            $curve,
            static fn (array $point): bool => $point['date'] < $splitDate,
        ));
        $postSplitCurve = array_values(array_filter(
            $curve,
            static fn (array $point): bool => $point['date'] >= $splitDate,
        ));
        $postSplitAnchor = $preSplitCurve === [] ? null : $preSplitCurve[array_key_last($preSplitCurve)];

        return array_merge($tradeMetrics, [
            'split_date' => $splitDate,
            'pre_split_closed_trades' => count($preSplitTrades),
            'post_split_closed_trades' => count($postSplitTrades),
            'pre_split_trade_metrics' => $preSplitTradeMetrics,
            'post_split_trade_metrics' => $postSplitTradeMetrics,
            'pre_split' => $this->curveMetrics($preSplitCurve),
            'post_split' => $this->curveMetrics($postSplitCurve, $postSplitAnchor),
        ]);
    }

    /**
     * Validate the full result and the later holdout period. This method is for
     * evaluation only; it must not be used to select a variant on training data.
     *
     * @param array<string, mixed> $metrics
     * @param array<string, float|int> $policy
     * @return array{passes:bool, failures:list<string>}
     */
    public function validate(array $metrics, array $policy): array
    {
        $failures = [];
        if ((int) $metrics['closed_trades'] < (int) $policy['min_trades']) {
            $failures[] = 'min_trades';
        }
        if ((int) $metrics['pre_split_closed_trades'] < 1 || (int) $metrics['pre_split']['points'] < 2) {
            $failures[] = 'missing_pre_split_evidence';
        }
        if ((int) $metrics['post_split_closed_trades'] < (int) $policy['min_post_split_trades']) {
            $failures[] = 'min_post_split_trades';
        }
        if ((int) $metrics['post_split']['points'] < 2) {
            $failures[] = 'missing_post_split_equity';
        }
        if ((float) $metrics['post_split']['return_pct'] <= 0.0) {
            $failures[] = 'non_positive_post_split_return';
        }
        if ((float) $metrics['post_split']['max_drawdown_pct'] < -(float) $policy['max_post_split_drawdown_pct']) {
            $failures[] = 'post_split_drawdown';
        }
        if ((float) $metrics['best_trade_gross_profit_share_pct'] > (float) $policy['max_best_trade_gross_profit_share_pct']) {
            $failures[] = 'best_trade_concentration';
        }
        if ((float) $metrics['top_5_trades_gross_profit_share_pct'] > (float) $policy['max_top_5_trades_gross_profit_share_pct']) {
            $failures[] = 'top_5_trades_concentration';
        }
        if ((float) $metrics['top_symbol_gross_profit_share_pct'] > (float) $policy['max_top_symbol_gross_profit_share_pct']) {
            $failures[] = 'top_symbol_concentration';
        }
        if ((float) $metrics['pnl_without_top_5_trades'] <= 0.0) {
            $failures[] = 'non_positive_pnl_without_top_5_trades';
        }

        return ['passes' => $failures === [], 'failures' => $failures];
    }

    /**
     * Validate only the period after split_date, including concentration using
     * only trades closed in that period. Use this after a train-only selection.
     *
     * @param array<string, mixed> $metrics
     * @param array<string, float|int> $policy
     * @return array{passes:bool, failures:list<string>}
     */
    public function validateHoldout(array $metrics, array $policy): array
    {
        $period = is_array($metrics['post_split'] ?? null) ? $metrics['post_split'] : [];
        $trades = is_array($metrics['post_split_trade_metrics'] ?? null)
            ? $metrics['post_split_trade_metrics']
            : [];
        $failures = [];
        if ((int) ($metrics['post_split_closed_trades'] ?? 0) < (int) $policy['min_post_split_trades']) {
            $failures[] = 'min_post_split_trades';
        }
        if ((int) ($period['points'] ?? 0) < 2) {
            $failures[] = 'missing_post_split_equity';
        }
        if ((float) ($period['return_pct'] ?? 0.0) <= 0.0) {
            $failures[] = 'non_positive_post_split_return';
        }
        if ((float) ($period['max_drawdown_pct'] ?? 0.0) < -(float) $policy['max_post_split_drawdown_pct']) {
            $failures[] = 'post_split_drawdown';
        }
        if ((float) ($trades['best_trade_gross_profit_share_pct'] ?? 0.0) > (float) $policy['max_best_trade_gross_profit_share_pct']) {
            $failures[] = 'post_best_trade_concentration';
        }
        if ((float) ($trades['top_5_trades_gross_profit_share_pct'] ?? 0.0) > (float) $policy['max_top_5_trades_gross_profit_share_pct']) {
            $failures[] = 'post_top_5_trades_concentration';
        }
        if ((float) ($trades['top_symbol_gross_profit_share_pct'] ?? 0.0) > (float) $policy['max_top_symbol_gross_profit_share_pct']) {
            $failures[] = 'post_top_symbol_concentration';
        }
        if ((float) ($trades['pnl_without_top_5_trades'] ?? 0.0) <= 0.0) {
            $failures[] = 'non_positive_post_pnl_without_top_5_trades';
        }

        return ['passes' => $failures === [], 'failures' => $failures];
    }

    /**
     * @param list<Trade> $trades
     * @return array<string, float|int|string|null>
     */
    private function tradeMetrics(array $trades): array
    {
        $closedPnl = array_sum(array_map(static fn (Trade $trade): float => $trade->pnl, $trades));
        $winningTrades = array_values(array_filter(
            $trades,
            static fn (Trade $trade): bool => $trade->pnl > 0.0,
        ));
        usort($winningTrades, static function (Trade $a, Trade $b): int {
            $byPnl = $b->pnl <=> $a->pnl;
            if ($byPnl !== 0) {
                return $byPnl;
            }

            $byExit = $a->exitTime <=> $b->exitTime;

            return $byExit !== 0 ? $byExit : strcmp($a->symbol, $b->symbol);
        });

        $grossProfit = array_sum(array_map(static fn (Trade $trade): float => $trade->pnl, $winningTrades));
        $bestTradePnl = $winningTrades[0]->pnl ?? 0.0;
        $topFiveTradePnl = array_sum(array_map(
            static fn (Trade $trade): float => $trade->pnl,
            array_slice($winningTrades, 0, 5),
        ));

        $symbols = [];
        foreach ($trades as $trade) {
            $symbols[$trade->symbol] ??= ['symbol' => $trade->symbol, 'pnl' => 0.0, 'gross_profit' => 0.0, 'trades' => 0];
            $symbols[$trade->symbol]['pnl'] += $trade->pnl;
            $symbols[$trade->symbol]['gross_profit'] += max(0.0, $trade->pnl);
            $symbols[$trade->symbol]['trades']++;
        }
        $symbolRows = array_values($symbols);
        usort($symbolRows, static function (array $a, array $b): int {
            $byGrossProfit = ((float) $b['gross_profit']) <=> ((float) $a['gross_profit']);
            if ($byGrossProfit !== 0) {
                return $byGrossProfit;
            }
            $byPnl = ((float) $b['pnl']) <=> ((float) $a['pnl']);

            return $byPnl !== 0 ? $byPnl : strcmp((string) $a['symbol'], (string) $b['symbol']);
        });
        $topSymbol = $symbolRows[0] ?? null;
        $positiveSymbolPnl = array_sum(array_map(
            static fn (array $row): float => max(0.0, (float) $row['pnl']),
            $symbolRows,
        ));

        return [
            'closed_trades' => count($trades),
            'closed_pnl' => $closedPnl,
            'gross_profit' => $grossProfit,
            'best_trade_pnl' => $bestTradePnl,
            'top_5_trades_pnl' => $topFiveTradePnl,
            'best_trade_gross_profit_share_pct' => $grossProfit > 0.0 ? $bestTradePnl / $grossProfit : 0.0,
            'top_5_trades_gross_profit_share_pct' => $grossProfit > 0.0 ? $topFiveTradePnl / $grossProfit : 0.0,
            'pnl_without_best_trade' => $closedPnl - $bestTradePnl,
            'pnl_without_top_5_trades' => $closedPnl - $topFiveTradePnl,
            'top_symbol' => $topSymbol['symbol'] ?? null,
            'top_symbol_trades' => (int) ($topSymbol['trades'] ?? 0),
            'top_symbol_pnl' => (float) ($topSymbol['pnl'] ?? 0.0),
            'top_symbol_gross_profit' => (float) ($topSymbol['gross_profit'] ?? 0.0),
            'top_symbol_gross_profit_share_pct' => $grossProfit > 0.0
                ? (float) ($topSymbol['gross_profit'] ?? 0.0) / $grossProfit
                : 0.0,
            'top_symbol_positive_pnl_share_pct' => $positiveSymbolPnl > 0.0
                ? max(0.0, (float) ($topSymbol['pnl'] ?? 0.0)) / $positiveSymbolPnl
                : 0.0,
        ];
    }

    /**
     * @param list<array{date:string, equity:float}> $curve
     * @return list<array{date:string, equity:float}>
     */
    private function normalizeCurve(array $curve): array
    {
        $byDate = [];
        foreach ($curve as $point) {
            $date = substr((string) ($point['date'] ?? ''), 0, 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }
            $byDate[$date] = ['date' => $date, 'equity' => (float) ($point['equity'] ?? 0.0)];
        }
        ksort($byDate);

        return array_values($byDate);
    }

    /**
     * @param list<array{date:string, equity:float}> $curve
     * @param array{date:string, equity:float}|null $anchor
     * @return array<string, float|int|string|null>
     */
    private function curveMetrics(array $curve, ?array $anchor = null): array
    {
        if ($curve === []) {
            return [
                'points' => 0,
                'start_date' => null,
                'end_date' => null,
                'anchor_date' => null,
                'starting_equity' => 0.0,
                'ending_equity' => 0.0,
                'return_pct' => 0.0,
                'annualized_return_pct' => 0.0,
                'max_drawdown_pct' => 0.0,
            ];
        }

        $first = $curve[0];
        $last = $curve[array_key_last($curve)];
        $startingPoint = $anchor ?? $first;
        $startingEquity = (float) $startingPoint['equity'];
        $endingEquity = (float) $last['equity'];
        $return = $startingEquity > 0.0 ? ($endingEquity - $startingEquity) / $startingEquity : 0.0;

        $annualized = 0.0;
        $days = (new \DateTimeImmutable((string) $startingPoint['date']))
            ->diff(new \DateTimeImmutable((string) $last['date']))
            ->days;
        if ((count($curve) >= 2 || $anchor !== null) && $days > 0 && $startingEquity > 0.0 && $endingEquity > 0.0) {
            $years = max(1.0 / 365.25, $days / 365.25);
            $annualized = ($endingEquity / $startingEquity) ** (1.0 / $years) - 1.0;
        }

        $peak = $anchor === null ? null : (float) $anchor['equity'];
        $maxDrawdown = 0.0;
        foreach ($curve as $point) {
            $equity = (float) $point['equity'];
            $peak = $peak === null ? $equity : max($peak, $equity);
            if ($peak > 0.0) {
                $maxDrawdown = min($maxDrawdown, ($equity - $peak) / $peak);
            }
        }

        return [
            'points' => count($curve),
            'start_date' => $first['date'],
            'end_date' => $last['date'],
            'anchor_date' => $anchor['date'] ?? null,
            'starting_equity' => $startingEquity,
            'ending_equity' => $endingEquity,
            'return_pct' => $return,
            'annualized_return_pct' => $annualized,
            'max_drawdown_pct' => $maxDrawdown,
        ];
    }
}
