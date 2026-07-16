<?php

declare(strict_types=1);

namespace FulltimeTrading\Backtest;

/**
 * Builds a fail-closed view of entry-data diagnostics for one chronological
 * interval. Variant selection may use the training view; the frozen holdout
 * is checked separately so an OOS defect can reject, but never replace, the
 * train-selected variant.
 */
final class EntryDataQualityPeriod
{
    /**
     * @param array<string, mixed> $diagnostics
     * @return array<string, mixed>
     */
    public static function slice(
        array $diagnostics,
        ?string $startInclusive = null,
        ?string $endExclusive = null,
    ): array {
        self::assertBoundary($startInclusive, 'start');
        self::assertBoundary($endExclusive, 'end');
        if ($startInclusive !== null && $endExclusive !== null && $startInclusive >= $endExclusive) {
            throw new \InvalidArgumentException('Entry data-quality period end must be after start.');
        }

        $rawEvents = is_array($diagnostics['data_quality_events'] ?? null)
            ? $diagnostics['data_quality_events']
            : [];
        $validEvents = [];
        $unscopedFailures = [];
        foreach ($rawEvents as $event) {
            if (!is_array($event)) {
                $unscopedFailures[] = 'invalid_or_undated_data_quality_event';
                continue;
            }
            $date = (string) ($event['date'] ?? '');
            $failure = trim((string) ($event['failure'] ?? ''));
            if (!self::isDate($date) || $failure === '') {
                $unscopedFailures[] = 'invalid_or_undated_data_quality_event';
                continue;
            }
            $validEvents[] = [
                'date' => $date,
                'failure' => $failure,
                'example' => (string) ($event['example'] ?? ''),
            ];
        }

        $declaredFailures = array_values(array_filter(
            is_array($diagnostics['data_quality_failures'] ?? null)
                ? $diagnostics['data_quality_failures']
                : [],
            static fn (mixed $failure): bool => is_string($failure) && trim($failure) !== '',
        ));
        $eventFailures = array_values(array_unique(array_column($validEvents, 'failure')));
        if (array_diff($declaredFailures, $eventFailures) !== []) {
            $unscopedFailures[] = 'undated_data_quality_failure';
        }
        $declaredFailureCount = max(
            (int) ($diagnostics['missing_candidate_sessions'] ?? 0),
            (int) ($diagnostics['incomplete_candidate_sessions'] ?? 0),
        );
        if ($declaredFailureCount > count($validEvents)) {
            $unscopedFailures[] = 'undated_data_quality_failure';
        }
        if (($diagnostics['data_quality_passes'] ?? true) !== true && $validEvents === []) {
            $unscopedFailures[] = 'undated_data_quality_failure';
        }
        $unscopedFailures = array_values(array_unique($unscopedFailures));

        $events = array_values(array_filter(
            $validEvents,
            static fn (array $event): bool => ($startInclusive === null || $event['date'] >= $startInclusive)
                && ($endExclusive === null || $event['date'] < $endExclusive),
        ));
        $failures = array_values(array_unique(array_merge(
            array_column($events, 'failure'),
            $unscopedFailures,
        )));
        $examples = array_slice(array_values(array_unique(array_filter(
            array_column($events, 'example'),
            static fn (string $example): bool => $example !== '',
        ))), 0, 20);

        $bounds = [];
        foreach (($diagnostics['intraday_gross_exposure_upper_bound_by_session'] ?? []) as $date => $value) {
            $date = (string) $date;
            if (!self::isDate($date) || !is_numeric($value)) {
                $unscopedFailures[] = 'invalid_intraday_gross_exposure_bound';
                continue;
            }
            if (($startInclusive !== null && $date < $startInclusive)
                || ($endExclusive !== null && $date >= $endExclusive)) {
                continue;
            }
            $bounds[$date] = (float) $value;
        }
        if ($unscopedFailures !== []) {
            $failures = array_values(array_unique(array_merge($failures, $unscopedFailures)));
        }

        $result = $diagnostics;
        $result['period_start_inclusive'] = $startInclusive;
        $result['period_end_exclusive'] = $endExclusive;
        $result['data_quality_events'] = $events;
        $result['data_quality_failures'] = $failures;
        $result['data_quality_passes'] = $failures === [];
        $result['missing_candidate_sessions'] = count($events);
        $result['incomplete_candidate_sessions'] = count($events);
        $result['missing_candidate_session_examples'] = $examples;
        $result['incomplete_candidate_session_examples'] = $examples;
        $result['intraday_gross_exposure_upper_bound_by_session'] = $bounds;
        $result['max_intraday_gross_exposure_upper_bound'] = $bounds === [] ? 0.0 : max($bounds);
        $result['intraday_gross_upper_bound_calculable'] = count(array_filter(
            $failures,
            static fn (string $failure): bool => str_starts_with(
                $failure,
                'intraday_exposure_upper_bound:',
            ) || $failure === 'invalid_intraday_gross_exposure_bound',
        )) === 0;

        return $result;
    }

    private static function assertBoundary(?string $date, string $label): void
    {
        if ($date !== null && !self::isDate($date)) {
            throw new \InvalidArgumentException('Entry data-quality period ' . $label . ' must use YYYY-MM-DD.');
        }
    }

    private static function isDate(string $date): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) {
            return false;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();

        return $parsed !== false
            && ($errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0))
            && $parsed->format('Y-m-d') === $date;
    }
}
