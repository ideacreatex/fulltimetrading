<?php

declare(strict_types=1);

namespace FulltimeTrading\Data;

use FulltimeTrading\Domain\Bar;

final class MarketDataCoverageAnalyzer
{
    /**
     * @param array<string, list<Bar>> $barsBySymbol
     * @param list<Bar> $benchmarkBars
     * @return array<string, mixed>
     */
    public function analyze(array $barsBySymbol, array $benchmarkBars, float $minimumCoveragePct): array
    {
        $minimumCoveragePct = min(1.0, max(0.0, $minimumCoveragePct));
        $benchmarkDates = $this->dateSet($benchmarkBars);
        $expectedDates = array_keys($benchmarkDates);
        sort($expectedDates, SORT_STRING);
        $expectedCount = count($expectedDates);
        $symbols = [];
        $failures = [];

        ksort($barsBySymbol, SORT_STRING);
        foreach ($barsBySymbol as $symbol => $bars) {
            $dates = $this->dateSet($bars);
            $coveredDates = array_intersect_key($benchmarkDates, $dates);
            $missingDates = array_values(array_diff($expectedDates, array_keys($dates)));
            $extraDates = array_values(array_diff(array_keys($dates), $expectedDates));
            sort($missingDates, SORT_STRING);
            sort($extraDates, SORT_STRING);
            $coveragePct = $expectedCount > 0 ? (float) count($coveredDates) / $expectedCount : 0.0;
            $passes = $expectedCount > 0 && $coveragePct >= $minimumCoveragePct;
            if (!$passes) {
                $failures[] = sprintf(
                    '%s_session_coverage_%.4f_below_%.4f',
                    strtoupper($symbol),
                    $coveragePct,
                    $minimumCoveragePct,
                );
            }

            $symbolDates = array_keys($dates);
            sort($symbolDates, SORT_STRING);
            $symbols[strtoupper($symbol)] = [
                'bars' => count($bars),
                'unique_sessions' => count($dates),
                'expected_benchmark_sessions' => $expectedCount,
                'covered_benchmark_sessions' => count($coveredDates),
                'missing_sessions' => count($missingDates),
                'extra_sessions' => count($extraDates),
                'coverage_pct' => $coveragePct,
                'minimum_coverage_pct' => $minimumCoveragePct,
                'passes' => $passes,
                'first_session' => $symbolDates[0] ?? null,
                'last_session' => $symbolDates !== [] ? $symbolDates[array_key_last($symbolDates)] : null,
                'missing_session_examples' => array_slice($missingDates, 0, 10),
                'extra_session_examples' => array_slice($extraDates, 0, 10),
            ];
        }

        return [
            'passes' => $failures === [],
            'minimum_symbol_coverage_pct' => $minimumCoveragePct,
            'benchmark_sessions' => $expectedCount,
            'benchmark_first_session' => $expectedDates[0] ?? null,
            'benchmark_last_session' => $expectedDates !== [] ? $expectedDates[array_key_last($expectedDates)] : null,
            'failures' => $failures,
            'symbols' => $symbols,
        ];
    }

    /** @param list<Bar> $bars @return array<string, true> */
    private function dateSet(array $bars): array
    {
        $dates = [];
        foreach ($bars as $bar) {
            $dates[$bar->time->format('Y-m-d')] = true;
        }

        return $dates;
    }
}
