<?php

declare(strict_types=1);

use FulltimeTrading\Backtest\EntryDataQualityPeriod;

require __DIR__ . '/../bootstrap.php';

function periodQualityAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$diagnostics = [
    'mode' => 'intraday_touch_reclaim',
    'data_quality_passes' => false,
    'data_quality_failures' => [
        'incomplete_candidate_intraday_session:missing_next_bar',
        'intraday_exposure_upper_bound:missing_regular_path:USD',
    ],
    'data_quality_events' => [
        [
            'date' => '2023-12-29',
            'failure' => 'incomplete_candidate_intraday_session:missing_next_bar',
            'example' => 'TQQQ 2023-12-29',
        ],
        [
            'date' => '2024-01-03',
            'failure' => 'intraday_exposure_upper_bound:missing_regular_path:USD',
            'example' => '2024-01-03',
        ],
    ],
    'missing_candidate_sessions' => 2,
    'incomplete_candidate_sessions' => 2,
    'intraday_gross_exposure_upper_bound_by_session' => [
        '2023-12-29' => 1.12,
        '2024-01-03' => 1.42,
    ],
];

$training = EntryDataQualityPeriod::slice($diagnostics, null, '2024-01-01');
$holdout = EntryDataQualityPeriod::slice($diagnostics, '2024-01-01', null);
periodQualityAssert($training['data_quality_passes'] === false, 'Training must retain its own dated DQ failure.');
periodQualityAssert($holdout['data_quality_passes'] === false, 'Holdout must retain its own dated DQ failure.');
periodQualityAssert(
    $training['data_quality_failures'] === ['incomplete_candidate_intraday_session:missing_next_bar'],
    'A holdout DQ failure must not leak into training eligibility.',
);
periodQualityAssert(
    $holdout['data_quality_failures'] === ['intraday_exposure_upper_bound:missing_regular_path:USD'],
    'A training DQ failure must not be attributed to holdout.',
);
periodQualityAssert(
    abs((float) $training['max_intraday_gross_exposure_upper_bound'] - 1.12) < 1.0e-9,
    'Training gross bound must use training sessions only.',
);
periodQualityAssert(
    abs((float) $holdout['max_intraday_gross_exposure_upper_bound'] - 1.42) < 1.0e-9,
    'Holdout gross bound must use holdout sessions only.',
);

$holdoutOnlyDefect = $diagnostics;
$holdoutOnlyDefect['data_quality_failures'] = [
    'intraday_exposure_upper_bound:missing_regular_path:USD',
];
$holdoutOnlyDefect['data_quality_events'] = [$diagnostics['data_quality_events'][1]];
$holdoutOnlyDefect['missing_candidate_sessions'] = 1;
$holdoutOnlyDefect['incomplete_candidate_sessions'] = 1;
$cleanTraining = EntryDataQualityPeriod::slice($holdoutOnlyDefect, null, '2024-01-01');
periodQualityAssert(
    $cleanTraining['data_quality_passes'] === true,
    'An OOS-only defect must not remove a candidate before train-only selection is frozen.',
);

$legacyUndated = [
    'mode' => 'intraday_touch_reclaim',
    'data_quality_passes' => false,
    'data_quality_failures' => ['legacy_gap'],
    'missing_candidate_sessions' => 1,
    'incomplete_candidate_sessions' => 1,
];
$legacyTraining = EntryDataQualityPeriod::slice($legacyUndated, null, '2024-01-01');
periodQualityAssert(
    $legacyTraining['data_quality_passes'] === false
        && in_array('undated_data_quality_failure', $legacyTraining['data_quality_failures'], true),
    'Legacy or unscoped defects must fail every period closed.',
);

echo "Entry data-quality period OK\n";
