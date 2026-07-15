<?php

declare(strict_types=1);

namespace FulltimeTrading\Backtest;

final class WalkForwardSelector
{
    /**
     * Select exactly one candidate using training-only fields. Callers should
     * evaluate the returned variant on holdout data without fallback selection.
     *
     * @param list<array{variant:string,params:array<string,mixed>,training:array<string,mixed>}> $candidates
     * @param array<string, float|int> $policy
     * @param array<string, float|int> $envelope
     * @return array<string, mixed>
     */
    public function select(array $candidates, array $policy, array $envelope = []): array
    {
        $eligible = [];
        foreach ($candidates as $candidate) {
            $evaluation = $this->evaluate($candidate, $policy, $envelope);
            if ($evaluation['passes']) {
                $eligible[] = array_merge($candidate, ['train_score' => $evaluation['score']]);
            }
        }

        usort($eligible, static function (array $a, array $b): int {
            $byScore = ((float) $b['train_score']) <=> ((float) $a['train_score']);

            return $byScore !== 0 ? $byScore : strcmp((string) $a['variant'], (string) $b['variant']);
        });
        $selected = $eligible[0] ?? null;

        return [
            'candidate_count' => count($candidates),
            'eligible_count' => count($eligible),
            'policy' => $policy,
            'production_envelope' => $envelope,
            'selected_variant' => $selected['variant'] ?? null,
            'selected_params' => $selected['params'] ?? null,
            'selected_training' => $selected['training'] ?? null,
            'train_score' => $selected['train_score'] ?? null,
        ];
    }

    /**
     * @param array{variant:string,params:array<string,mixed>,training:array<string,mixed>} $candidate
     * @param array<string, float|int> $policy
     * @param array<string, float|int> $envelope
     * @return array{passes:bool,failures:list<string>,score:?float}
     */
    public function evaluate(array $candidate, array $policy, array $envelope = []): array
    {
        $training = $candidate['training'];
        $tradeMetrics = is_array($training['trade_metrics'] ?? null) ? $training['trade_metrics'] : [];
        $params = $candidate['params'];
        $failures = [];

        if ((int) ($training['trades'] ?? 0) < (int) $policy['min_train_trades']) {
            $failures[] = 'min_train_trades';
        }
        if ((int) ($training['points'] ?? 0) < 2) {
            $failures[] = 'missing_train_equity';
        }
        if ((float) ($training['annualized_return_pct'] ?? 0.0) < (float) $policy['min_train_annualized_return_pct']) {
            $failures[] = 'train_return';
        }
        if ((float) ($training['max_drawdown_pct'] ?? 0.0) < -(float) $policy['max_train_drawdown_pct']) {
            $failures[] = 'train_drawdown';
        }
        if ((float) ($tradeMetrics['best_trade_gross_profit_share_pct'] ?? 0.0) > (float) $policy['max_best_trade_gross_profit_share_pct']) {
            $failures[] = 'train_best_trade_concentration';
        }
        if ((float) ($tradeMetrics['top_5_trades_gross_profit_share_pct'] ?? 0.0) > (float) $policy['max_top_5_trades_gross_profit_share_pct']) {
            $failures[] = 'train_top_5_trades_concentration';
        }
        if ((float) ($tradeMetrics['top_symbol_gross_profit_share_pct'] ?? 0.0) > (float) $policy['max_top_symbol_gross_profit_share_pct']) {
            $failures[] = 'train_top_symbol_concentration';
        }
        if ((float) ($tradeMetrics['pnl_without_top_5_trades'] ?? 0.0) <= 0.0) {
            $failures[] = 'non_positive_train_pnl_without_top_5_trades';
        }
        if (isset($envelope['max_gross']) && (float) ($params['max_gross'] ?? INF) > (float) $envelope['max_gross']) {
            $failures[] = 'production_max_gross';
        }
        if (isset($envelope['max_open']) && (int) ($params['max_open'] ?? PHP_INT_MAX) > (int) $envelope['max_open']) {
            $failures[] = 'production_max_open';
        }

        $score = null;
        if ($failures === []) {
            $score = (float) $training['annualized_return_pct']
                / max(0.10, abs((float) $training['max_drawdown_pct']));
        }

        return ['passes' => $failures === [], 'failures' => $failures, 'score' => $score];
    }
}
