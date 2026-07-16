<?php

declare(strict_types=1);

namespace FulltimeTrading\Backtest;

/** Applies the frozen historical acceptance gates without broker side effects. */
final readonly class TacticalRotationQualification
{
    /** @param array<string,mixed> $validation */
    public function __construct(private array $validation)
    {
    }

    /**
     * @param array<string,mixed> $train
     * @param array<string,mixed> $validation
     * @param array<string,mixed> $holdout
     * @param array<string,mixed> $full
     * @param array<string,array<string,mixed>> $annual
     * @return array{qualifies:bool,failed_gates:list<string>,negative_years:int,maximum_single_year_annualized_turnover:float}
     */
    public function evaluate(
        array $train,
        array $validation,
        array $holdout,
        array $full,
        array $annual,
    ): array {
        $negativeYears = count(array_filter(
            $annual,
            static fn (array $metrics): bool => (float) ($metrics['return'] ?? 0.0) < 0.0,
        ));
        $maximumSingleYearTurnover = $annual === [] ? 0.0 : max(array_map(
            static fn (array $metrics): float => (float) ($metrics['annualized_turnover'] ?? 0.0),
            $annual,
        ));
        $gate = $this->validation;
        $checks = [
            'train_cagr' => (float) $train['cagr'] >= (float) $gate['minimum_train_cagr'],
            'validation_cagr' => (float) $validation['cagr'] >= (float) $gate['minimum_validation_cagr'],
            'holdout_cagr' => (float) $holdout['cagr'] >= (float) $gate['minimum_holdout_cagr'],
            'train_drawdown' => (float) $train['max_drawdown'] >= -(float) $gate['maximum_drawdown'],
            'validation_drawdown' => (float) $validation['max_drawdown'] >= -(float) $gate['maximum_drawdown'],
            'holdout_drawdown' => (float) $holdout['max_drawdown'] >= -(float) $gate['maximum_drawdown'],
            'full_drawdown' => (float) $full['max_drawdown'] >= -(float) $gate['maximum_drawdown'],
            'full_gross_bound' => (float) $full['max_gross_bound'] <= (float) $gate['maximum_gross_bound'],
            'negative_years' => $negativeYears <= (int) $gate['maximum_negative_years'],
            'full_top1_day_share' => (float) $full['top1_positive_day_share'] <= (float) $gate['maximum_full_top1_positive_day_share'],
            'full_top5_day_share' => (float) $full['top5_positive_day_share'] <= (float) $gate['maximum_full_top5_positive_day_share'],
            'holdout_top1_day_share' => (float) $holdout['top1_positive_day_share'] <= (float) $gate['maximum_holdout_top1_positive_day_share'],
            'holdout_top5_day_share' => (float) $holdout['top5_positive_day_share'] <= (float) $gate['maximum_holdout_top5_positive_day_share'],
            'train_ex_top5_days_cagr' => (float) $train['ex_top5_days_cagr'] >= (float) $gate['minimum_train_ex_top5_days_cagr'],
            'validation_ex_top5_days_cagr' => (float) $validation['ex_top5_days_cagr'] >= (float) $gate['minimum_validation_ex_top5_days_cagr'],
            'holdout_ex_top5_days_cagr' => (float) $holdout['ex_top5_days_cagr'] >= (float) $gate['minimum_holdout_ex_top5_days_cagr'],
            'train_positive_episodes' => (int) $train['positive_holding_episodes'] >= (int) $gate['minimum_train_positive_holding_episodes'],
            'validation_positive_episodes' => (int) $validation['positive_holding_episodes'] >= (int) $gate['minimum_validation_positive_holding_episodes'],
            'full_positive_episodes' => (int) $full['positive_holding_episodes'] >= (int) $gate['minimum_full_positive_holding_episodes'],
            'train_top1_episode_share' => (float) $train['top1_positive_episode_share'] <= (float) $gate['maximum_train_top1_positive_episode_share'],
            'validation_top1_episode_share' => (float) $validation['top1_positive_episode_share'] <= (float) $gate['maximum_validation_top1_positive_episode_share'],
            'full_top1_episode_share' => (float) $full['top1_positive_episode_share'] <= (float) $gate['maximum_full_top1_positive_episode_share'],
            'train_return_symbols' => (int) $train['return_symbols'] >= (int) $gate['minimum_train_return_symbols'],
            'validation_return_symbols' => (int) $validation['return_symbols'] >= (int) $gate['minimum_validation_return_symbols'],
            'full_return_symbols' => (int) $full['return_symbols'] >= (int) $gate['minimum_full_return_symbols'],
            'full_turnover' => (float) $full['annualized_turnover'] <= (float) $gate['maximum_full_annualized_turnover'],
            'single_year_turnover' => $maximumSingleYearTurnover <= (float) $gate['maximum_single_year_annualized_turnover'],
        ];
        $failed = array_keys(array_filter($checks, static fn (bool $passed): bool => !$passed));

        return [
            'qualifies' => $failed === [],
            'failed_gates' => $failed,
            'negative_years' => $negativeYears,
            'maximum_single_year_annualized_turnover' => $maximumSingleYearTurnover,
        ];
    }
}
