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
     * @param array<string, mixed> $envelope
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
     * @param array<string, mixed> $envelope
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
        if (
            isset($envelope['max_observed_gross'])
            && (float) ($training['max_gross_exposure'] ?? INF) > (float) $envelope['max_observed_gross']
        ) {
            $failures[] = 'production_max_observed_gross';
        }
        if (isset($envelope['allowed_signal_fill_pairs'])) {
            $allowedPairs = $envelope['allowed_signal_fill_pairs'];
            $signalTimingMode = (string) (
                $params['signal_timing_mode']
                ?? $params['support_entry_signal_mode']
                ?? ''
            );
            $fillMode = (string) ($params['fill_mode'] ?? $params['order_fill_mode'] ?? '');
            $executionPair = $signalTimingMode . ':' . $fillMode;

            if (!is_array($allowedPairs) || !in_array($executionPair, $allowedPairs, true)) {
                $failures[] = 'production_execution_contract';
            }
        }

        $score = null;
        if ($failures === []) {
            $score = (float) $training['annualized_return_pct']
                / max(0.10, abs((float) $training['max_drawdown_pct']));
        }

        return ['passes' => $failures === [], 'failures' => $failures, 'score' => $score];
    }

    /**
     * Qualify exactly the already-frozen train selection. Holdout failures can
     * reject it, but this method never receives or selects an alternative.
     *
     * @param array<string, mixed> $selection
     * @param array<string, mixed> $productionEnvelope
     * @return array<string, mixed>
     */
    public function qualifyFrozen(
        array $selection,
        float $minimumAnnualizedReturn,
        float $maximumDrawdown,
        array $productionEnvelope = [],
    ): array {
        $failures = [];
        $selected = is_string($selection['selected_variant'] ?? null)
            ? $selection['selected_variant']
            : null;
        $training = is_array($selection['selected_training'] ?? null)
            ? $selection['selected_training']
            : null;
        $holdout = is_array($selection['frozen_oos_evaluation'] ?? null)
            ? $selection['frozen_oos_evaluation']
            : null;

        if (($selection['data_quality_passes'] ?? false) !== true) {
            $failures[] = 'data_quality';
        }
        if (($selection['selected_full_period_data_quality_passes'] ?? false) !== true) {
            $failures[] = 'selected_full_period_data_quality';
            foreach (($selection['selected_full_period_data_quality_failures'] ?? []) as $failure) {
                if (is_string($failure) && $failure !== '') {
                    $failures[] = 'full_period_data_quality:' . $failure;
                }
            }
        }
        if ($selected === null || $training === null) {
            $failures[] = 'no_frozen_train_selection';
        } else {
            if ((float) ($training['annualized_return_pct'] ?? -INF) < $minimumAnnualizedReturn) {
                $failures[] = 'train_annualized_return_floor';
            }
            if ((float) ($training['max_drawdown_pct'] ?? -INF) < -abs($maximumDrawdown)) {
                $failures[] = 'train_drawdown_ceiling';
            }
        }
        if ($holdout === null) {
            $failures[] = 'missing_frozen_oos_evaluation';
        } else {
            if (($holdout['data_quality_passes'] ?? false) !== true) {
                $failures[] = 'holdout_data_quality';
                foreach (($holdout['data_quality_failures'] ?? []) as $failure) {
                    if (is_string($failure) && $failure !== '') {
                        $failures[] = 'holdout_data_quality:' . $failure;
                    }
                }
            }
            if (($holdout['passes'] ?? false) !== true) {
                $failures[] = 'holdout_robustness';
                foreach (($holdout['failures'] ?? []) as $failure) {
                    if (is_string($failure) && $failure !== '') {
                        $failures[] = 'holdout:' . $failure;
                    }
                }
            }
            if ((float) ($holdout['annualized_return_pct'] ?? -INF) < $minimumAnnualizedReturn) {
                $failures[] = 'holdout_annualized_return_floor';
            }
            if ((float) ($holdout['max_drawdown_pct'] ?? -INF) < -abs($maximumDrawdown)) {
                $failures[] = 'holdout_drawdown_ceiling';
            }
        }

        $maximumObservedGross = $productionEnvelope['max_observed_gross'] ?? null;
        $holdoutObservedGross = $holdout['max_observed_or_bounded_gross_exposure'] ?? null;
        $fullObservedGross = $selection['selected_full_period_max_observed_or_bounded_gross_exposure'] ?? null;
        if ($maximumObservedGross !== null) {
            $maximumObservedGross = (float) $maximumObservedGross;
            if (!is_numeric($holdoutObservedGross)) {
                $failures[] = 'missing_holdout_max_observed_gross';
            } elseif ((float) $holdoutObservedGross > $maximumObservedGross) {
                $failures[] = 'holdout_max_observed_gross';
            }
            if (!is_numeric($fullObservedGross)) {
                $failures[] = 'missing_full_period_max_observed_gross';
            } elseif ((float) $fullObservedGross > $maximumObservedGross) {
                $failures[] = 'full_period_max_observed_gross';
            }
        }

        $failures = array_values(array_unique($failures));
        $selection['historical_qualification_policy'] = [
            'selection_basis' => 'training_only_then_frozen_oos_no_fallback',
            'minimum_train_and_oos_annualized_return_pct' => $minimumAnnualizedReturn,
            'maximum_train_and_oos_drawdown_pct' => abs($maximumDrawdown),
            'maximum_train_oos_and_full_observed_gross' => $maximumObservedGross,
            'requires_training_and_holdout_data_quality' => true,
            'requires_holdout_robustness' => true,
        ];
        $selection['historical_qualification_observed_risk'] = [
            'holdout_max_observed_or_bounded_gross_exposure' => is_numeric($holdoutObservedGross)
                ? (float) $holdoutObservedGross
                : null,
            'full_period_max_observed_or_bounded_gross_exposure' => is_numeric($fullObservedGross)
                ? (float) $fullObservedGross
                : null,
        ];
        $selection['historical_qualification_failures'] = $failures;
        $selection['historically_qualified'] = $failures === [];
        $selection['historically_qualified_variant'] = $failures === [] ? $selected : null;

        return $selection;
    }
}
