<?php

declare(strict_types=1);

namespace FulltimeTrading\Research;

/** Preference review is deliberately separate from qualification and execution gates. */
final class EconomicBenefitReview
{
    public const VERSION = 'capital-or-drawdown-v1';

    public static function compare(array $baseline, array $candidate, float $minimumCapitalGain = 0.10, float $minimumDrawdownPoints = 5.0): array
    {
        foreach ([$minimumCapitalGain, $minimumDrawdownPoints] as $threshold) {
            if (!is_finite($threshold) || $threshold <= 0) { throw new \InvalidArgumentException('Positive finite thresholds required.'); }
        }
        foreach (['start', 'end', 'initial_equity', 'data_contract', 'cost_bps', 'calendar_sha256'] as $key) {
            if (!isset($baseline[$key]) || !isset($candidate[$key]) || $baseline[$key] !== $candidate[$key]) {
                throw new \InvalidArgumentException('Non-comparable scenarios: ' . $key);
            }
        }
        foreach ([$baseline, $candidate] as $row) {
            foreach (['initial_equity', 'terminal_equity', 'cagr', 'max_drawdown'] as $key) {
                if (!is_numeric($row[$key] ?? null) || !is_finite((float) $row[$key])) {
                    throw new \InvalidArgumentException('Missing or non-finite metric: ' . $key);
                }
            }
            if ($row['initial_equity'] <= 0 || $row['terminal_equity'] <= 0 || $row['cagr'] <= -1
                || $row['max_drawdown'] > 0 || $row['max_drawdown'] <= -1) {
                throw new \InvalidArgumentException('Invalid capital, CAGR or signed drawdown.');
            }
        }
        $dollars = $candidate['terminal_equity'] - $baseline['terminal_equity'];
        $capitalGain = $candidate['terminal_equity'] / $baseline['terminal_equity'] - 1;
        $drawdownGain = 100 * ($candidate['max_drawdown'] - $baseline['max_drawdown']);
        $cagrGain = 100 * ($candidate['cagr'] - $baseline['cagr']);
        $moneyBenefit = $capitalGain >= $minimumCapitalGain - 1e-12;
        $riskBenefit = $drawdownGain >= $minimumDrawdownPoints - 1e-10;
        return ['policy' => self::VERSION, 'scope' => 'retrospective_preference_review_not_admission',
            'capital_delta_dollars' => $dollars, 'capital_delta_pct' => 100 * $capitalGain,
            'drawdown_reduction_pp' => $drawdownGain, 'cagr_delta_pp' => $cagrGain,
            'material_capital_benefit' => $moneyBenefit, 'material_risk_benefit' => $riskBenefit,
            'economically_interesting' => $moneyBenefit || $riskBenefit,
            'strictly_dominates' => $dollars >= -1e-8 && $drawdownGain >= -1e-10
                && ($dollars > 1e-8 || $drawdownGain > 1e-10),
            'tradeoff' => $dollars < -1e-8 ? 'less_ending_money_for_risk_reduction'
                : ($drawdownGain < -1e-10 ? 'more_drawdown_for_money' : 'no_worse_money_or_drawdown'),
            'recovery_from_max_drawdown_pct' => ['baseline' => 100 * (1 / (1 + $baseline['max_drawdown']) - 1),
                'candidate' => 100 * (1 / (1 + $candidate['max_drawdown']) - 1)],
            'baseline' => $baseline, 'candidate' => $candidate,
            'execution_authorized' => false, 'live_authorized' => false];
    }

    public static function cagr(float $start, float $end, float $years): float
    {
        if (!is_finite($start) || !is_finite($end) || !is_finite($years) || $start <= 0 || $end < 0 || $years <= 0) {
            throw new \InvalidArgumentException('CAGR requires positive starting capital/time and nonnegative ending capital.');
        }
        return ($end / $start) ** (1 / $years) - 1;
    }
}
