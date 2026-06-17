<?php

declare(strict_types=1);

namespace FulltimeTrading\Strategy;

use FulltimeTrading\Domain\Bar;
use FulltimeTrading\Domain\Signal;
use FulltimeTrading\Indicators\IndicatorCalculator;

final class PoosScanner
{
    public function __construct(
        private readonly IndicatorCalculator $indicators,
        /** @var array<string, mixed> */
        private readonly array $config,
    ) {
    }

    /**
     * @param list<Bar> $bars
     * @param array<string, MarketRegime> $marketRegimes
     * @return list<Signal>
     */
    public function scan(string $symbol, array $bars, array $marketRegimes): array
    {
        $symbol = strtoupper($symbol);
        $emaPeriods = $this->indicatorPeriods();
        $indicatorMap = $this->indicators->forBars(
            $bars,
            $emaPeriods,
            (int) ($this->config['atr_period'] ?? 14),
            (int) ($this->config['volume_avg_period'] ?? 50),
        );

        $signals = array_merge(
            $this->scanSupportRegularity($symbol, $bars, $indicatorMap, $marketRegimes),
            $this->scanWeeklySupportRegularity($symbol, $bars, $indicatorMap, $marketRegimes),
            $this->scanResistanceRegularity($symbol, $bars, $indicatorMap, $marketRegimes),
        );
        $rocketLookback = (int) ($this->config['rocket_lookback'] ?? 63);
        $firstPullbackLookback = (int) ($this->config['first_pullback_lookback'] ?? 20);
        $touchTolerance = (float) ($this->config['ema_touch_tolerance_pct'] ?? 0.01);
        $maxDistance = (float) ($this->config['max_distance_to_ema_pct'] ?? 0.18);
        $minGain = (float) ($this->config['rocket_min_gain_pct'] ?? 0.60);
        $volumeSpike = (float) ($this->config['volume_spike_multiple'] ?? 1.8);
        $targetMultiple = (float) ($this->config['target_r_multiple'] ?? 1.5);

        $lastSignalDate = null;
        for ($i = 200; $i < count($bars); $i++) {
            $bar = $bars[$i];
            $date = $bar->time->format('Y-m-d');
            $regime = $marketRegimes[$date] ?? null;
            if (!$this->allowsLongSignal($symbol, $regime)) {
                continue;
            }
            if (!$this->passesExternalFilters($symbol, $date, 'long')) {
                continue;
            }
            if ($lastSignalDate !== null && $bar->time->diff($lastSignalDate)->days < $firstPullbackLookback) {
                continue;
            }

            $ema10 = $indicatorMap['ema10'][$i] ?? null;
            $ema20 = $indicatorMap['ema20'][$i] ?? null;
            $ema21 = $indicatorMap['ema21'][$i] ?? null;
            $ema50 = $indicatorMap['ema50'][$i] ?? null;
            $ema100 = $indicatorMap['ema100'][$i] ?? null;
            $ema200 = $indicatorMap['ema200'][$i] ?? null;
            $atr = $indicatorMap['atr'][$i] ?? null;
            $volumeSma = $indicatorMap['volume_sma'][$i] ?? null;

            if (in_array(null, [$ema10, $ema20, $ema21, $ema50, $ema100, $ema200, $atr, $volumeSma], true)) {
                continue;
            }

            $reasons = [];
            if (!$this->isHealthyTrend($bar, $ema20, $ema50, $ema100, $ema200)) {
                continue;
            }
            $reasons[] = 'healthy trend above EMA20/50/100/200';

            $rocket = $this->hasRocketMove($bars, $i, $rocketLookback, $minGain, $volumeSpike);
            if (!$rocket) {
                continue;
            }
            $reasons[] = 'prior rocket move with volume';

            if ($this->hadRecentEma20Touch($bars, $indicatorMap['ema20'], $i, $firstPullbackLookback, $touchTolerance)) {
                continue;
            }
            $reasons[] = 'first pullback candidate';

            $distance = ($bar->close - $ema20) / $ema20;
            if ($distance < 0 || $distance > $maxDistance) {
                continue;
            }
            $reasons[] = 'close within planned distance to EMA20';

            if ($bar->close < $ema21) {
                continue;
            }
            $reasons[] = 'gap guard: close above EMA21';

            $entry = $ema20;
            $stop = $entry - $atr;
            if ($stop <= 0 || $entry <= $stop) {
                continue;
            }
            $risk = $entry - $stop;
            $target = $entry + $risk * $targetMultiple;
            $score = 1.0 + min(2.0, $distance === 0.0 ? 2.0 : 0.10 / max($distance, 0.001));
            if ($regime !== null) {
                $score += $regime->score;
            }

            $signals[] = new Signal($symbol, $bar->time, 'POOS', $entry, $stop, $target, $risk, $score, $reasons, 'long', [
                'timeframe' => 'D',
                'ma_type' => 'ema',
                'ma_period' => 20,
                'setup_key' => $symbol . ':long:POOS:D:EMA20',
            ]);
            $lastSignalDate = $bar->time;
        }

        return $this->dedupeAndSortSignals($signals);
    }

    /** @return list<int> */
    private function indicatorPeriods(): array
    {
        $periods = $this->config['ema_periods'] ?? [10, 20, 21, 50, 100, 200];
        $supportConfig = $this->config['support_regularity'] ?? [];
        $supportPeriods = $supportConfig['periods'] ?? [];
        $shortConfig = $this->config['short_resistance'] ?? [];
        $shortPeriods = $shortConfig['periods'] ?? [];

        $merged = [];
        foreach (array_merge($periods, $supportPeriods, $shortPeriods) as $period) {
            $period = (int) $period;
            if ($period > 1) {
                $merged[$period] = true;
            }
        }

        $result = array_keys($merged);
        sort($result);

        return $result;
    }

    /**
     * @param list<Bar> $bars
     * @param array<string, list<float|null>> $indicatorMap
     * @param array<string, MarketRegime> $marketRegimes
     * @return list<Signal>
     */
    private function scanSupportRegularity(string $symbol, array $bars, array $indicatorMap, array $marketRegimes): array
    {
        $config = $this->config['support_regularity'] ?? [];
        if (!($config['enabled'] ?? false)) {
            return [];
        }

        $periods = array_values(array_filter(
            array_map('intval', $config['periods'] ?? [20, 50, 100, 200]),
            static fn (int $period): bool => $period > 1,
        ));
        $types = array_values(array_filter(
            array_map('strval', $config['types'] ?? ['ema', 'sma']),
            static fn (string $type): bool => in_array($type, ['ema', 'sma'], true),
        ));
        if ($periods === [] || $types === []) {
            return [];
        }

        $lookback = (int) ($config['lookback_bars'] ?? 504);
        $forwardBars = (int) ($config['forward_bars'] ?? 20);
        $minTouches = (int) ($config['min_touches'] ?? 3);
        $minSuccessRate = (float) ($config['min_success_rate'] ?? 0.60);
        $successMinReturn = (float) ($config['success_min_return_pct'] ?? 0.03);
        $touchTolerance = (float) ($config['touch_tolerance_pct'] ?? 0.015);
        $violationTolerance = (float) ($config['violation_tolerance_pct'] ?? 0.03);
        $nearPct = (float) ($config['near_pct'] ?? 0.025);
        $nearAtrMultiple = (float) ($config['near_atr_multiple'] ?? 0.60);
        $stopAtrMultiple = (float) ($config['stop_atr_multiple'] ?? 1.5);
        $targetAtrMultiple = (float) ($config['target_atr_multiple'] ?? 3.0);
        $cooldownBars = (int) ($config['cooldown_bars'] ?? 10);
        $requireCloseAboveSupport = (bool) ($config['require_close_above_support'] ?? false);

        $signals = [];
        $lastSignalIndexBySetup = [];
        $startIndex = max(220, max($periods) + $forwardBars + $minTouches);
        for ($i = $startIndex; $i < count($bars); $i++) {
            $bar = $bars[$i];
            $date = $bar->time->format('Y-m-d');
            $regime = $marketRegimes[$date] ?? null;
            if (!$this->allowsLongSignal($symbol, $regime)) {
                continue;
            }
            if (!$this->passesExternalFilters($symbol, $date, 'long')) {
                continue;
            }

            $atr = $indicatorMap['atr'][$i] ?? null;
            if ($atr === null || $atr <= 0.0) {
                continue;
            }

            $best = null;
            foreach ($types as $type) {
                foreach ($periods as $period) {
                    $key = $type . $period;
                    $setupKey = $symbol . ':long:SUPPORT_REGULARITY:D:' . strtoupper($type) . $period;
                    if (isset($lastSignalIndexBySetup[$setupKey]) && ($i - $lastSignalIndexBySetup[$setupKey]) < $cooldownBars) {
                        continue;
                    }
                    $level = $indicatorMap[$key][$i] ?? null;
                    if ($level === null || $level <= 0.0) {
                        continue;
                    }

                    if (!$this->isNearSupport($bar, $level, $atr, $touchTolerance, $violationTolerance, $nearPct, $nearAtrMultiple)) {
                        continue;
                    }
                    if ($requireCloseAboveSupport && $bar->close < $level) {
                        continue;
                    }

                    $stats = $this->supportStats(
                        $bars,
                        $indicatorMap[$key],
                        $i,
                        $lookback,
                        $forwardBars,
                        $touchTolerance,
                        $violationTolerance,
                        $successMinReturn,
                    );
                    if ($stats['touches'] < $minTouches || $stats['success_rate'] < $minSuccessRate) {
                        continue;
                    }

                    $entry = $level;
                    $stop = max(0.01, $level - $atr * $stopAtrMultiple);
                    if ($entry <= $stop) {
                        continue;
                    }

                    $risk = $entry - $stop;
                    $target = $entry + $atr * $targetAtrMultiple;
                    $distancePct = abs($bar->close - $level) / $level;
                    $score = 1.0
                        + $stats['success_rate'] * 3.0
                        + min(1.0, $stats['touches'] / 10.0)
                        + min(1.0, $stats['avg_forward_return'] * 10.0)
                        + max(0.0, 0.5 - min(0.5, $distancePct * 10.0));
                    if ($regime !== null) {
                        $score += $regime->score;
                    }

                    $candidate = new Signal(
                        $symbol,
                        $bar->time,
                        'SUPPORT_REGULARITY',
                        $entry,
                        $stop,
                        $target,
                        $risk,
                        $score,
                        [
                            strtoupper($type) . $period . ' support is in play',
                            'prior touches: ' . $stats['touches'],
                            'prior support success rate: ' . round($stats['success_rate'] * 100, 1) . '%',
                            'avg forward return after touch: ' . round($stats['avg_forward_return'] * 100, 2) . '%',
                        ],
                        'long',
                        [
                            'timeframe' => 'D',
                            'ma_type' => $type,
                            'ma_period' => $period,
                            'support_level' => $level,
                            'support_atr' => $atr,
                            'setup_touches' => $stats['touches'],
                            'setup_success_rate' => $stats['success_rate'],
                            'setup_avg_forward_return' => $stats['avg_forward_return'],
                            'setup_key' => $setupKey,
                        ],
                    );

                    if ($best === null || $candidate->score > $best->score) {
                        $best = $candidate;
                    }
                }
            }

            if ($best !== null) {
                $signals[] = $best;
                $lastSignalIndexBySetup[(string) $best->metadata['setup_key']] = $i;
            }
        }

        return $signals;
    }

    /**
     * @param list<Bar> $bars
     * @param array<string, list<float|null>> $dailyIndicatorMap
     * @param array<string, MarketRegime> $marketRegimes
     * @return list<Signal>
     */
    private function scanWeeklySupportRegularity(string $symbol, array $bars, array $dailyIndicatorMap, array $marketRegimes): array
    {
        $config = $this->config['support_regularity'] ?? [];
        if (!($config['enabled'] ?? false) || !($config['weekly_enabled'] ?? true)) {
            return [];
        }

        $periods = array_values(array_filter(
            array_map('intval', $config['weekly_periods'] ?? $config['periods'] ?? [10, 20, 50, 100, 200]),
            static fn (int $period): bool => $period > 1,
        ));
        $types = array_values(array_filter(
            array_map('strval', $config['types'] ?? ['ema', 'sma']),
            static fn (string $type): bool => in_array($type, ['ema', 'sma'], true),
        ));
        if ($periods === [] || $types === []) {
            return [];
        }

        $weeklyBars = $this->indicators->aggregateWeekly($bars);
        if (count($weeklyBars) < max($periods) + 20) {
            return [];
        }

        $weeklyIndicatorMap = $this->indicators->forBars(
            $weeklyBars,
            $periods,
            (int) ($this->config['atr_period'] ?? 14),
            (int) ($this->config['volume_avg_period'] ?? 50),
        );
        $weeklyIndexByCompletedWeek = $this->completedWeeklyIndexByDailyDate($weeklyBars);

        $lookback = (int) ($config['weekly_lookback_bars'] ?? 156);
        $forwardBars = (int) ($config['weekly_forward_bars'] ?? 8);
        $minTouches = (int) ($config['min_touches'] ?? 3);
        $minSuccessRate = (float) ($config['min_success_rate'] ?? 0.60);
        $successMinReturn = (float) ($config['success_min_return_pct'] ?? 0.03);
        $touchTolerance = (float) ($config['touch_tolerance_pct'] ?? 0.015);
        $violationTolerance = (float) ($config['violation_tolerance_pct'] ?? 0.03);
        $nearPct = (float) ($config['near_pct'] ?? 0.025);
        $nearAtrMultiple = (float) ($config['near_atr_multiple'] ?? 0.60);
        $stopAtrMultiple = (float) ($config['stop_atr_multiple'] ?? 1.5);
        $targetAtrMultiple = (float) ($config['target_atr_multiple'] ?? 3.0);
        $cooldownBars = (int) ($config['cooldown_bars'] ?? 10);
        $requireCloseAboveSupport = (bool) ($config['require_close_above_support'] ?? false);

        $signals = [];
        $lastSignalIndexBySetup = [];
        $startIndex = max(220, max($periods) * 5);
        for ($i = $startIndex; $i < count($bars); $i++) {
            $bar = $bars[$i];
            $date = $bar->time->format('Y-m-d');
            $regime = $marketRegimes[$date] ?? null;
            if (!$this->allowsLongSignal($symbol, $regime)) {
                continue;
            }
            if (!$this->passesExternalFilters($symbol, $date, 'long')) {
                continue;
            }

            $weeklyIndex = $weeklyIndexByCompletedWeek[$date] ?? null;
            if ($weeklyIndex === null || $weeklyIndex < max($periods)) {
                continue;
            }

            $dailyAtr = $dailyIndicatorMap['atr'][$i] ?? null;
            $weeklyAtr = $weeklyIndicatorMap['atr'][$weeklyIndex] ?? null;
            if ($dailyAtr === null || $dailyAtr <= 0.0 || $weeklyAtr === null || $weeklyAtr <= 0.0) {
                continue;
            }

            $best = null;
            foreach ($types as $type) {
                foreach ($periods as $period) {
                    $key = $type . $period;
                    $setupKey = $symbol . ':long:SUPPORT_REGULARITY:W:' . strtoupper($type) . $period;
                    if (isset($lastSignalIndexBySetup[$setupKey]) && ($i - $lastSignalIndexBySetup[$setupKey]) < $cooldownBars) {
                        continue;
                    }
                    $level = $weeklyIndicatorMap[$key][$weeklyIndex] ?? null;
                    if ($level === null || $level <= 0.0) {
                        continue;
                    }
                    if (!$this->isNearSupport($bar, $level, $dailyAtr, $touchTolerance, $violationTolerance, $nearPct, $nearAtrMultiple)) {
                        continue;
                    }
                    if ($requireCloseAboveSupport && $bar->close < $level) {
                        continue;
                    }

                    $stats = $this->supportStats(
                        $weeklyBars,
                        $weeklyIndicatorMap[$key],
                        $weeklyIndex,
                        $lookback,
                        $forwardBars,
                        $touchTolerance,
                        $violationTolerance,
                        $successMinReturn,
                    );
                    if ($stats['touches'] < $minTouches || $stats['success_rate'] < $minSuccessRate) {
                        continue;
                    }

                    $entry = $level;
                    $stop = max(0.01, $level - $weeklyAtr * $stopAtrMultiple);
                    if ($entry <= $stop) {
                        continue;
                    }

                    $risk = $entry - $stop;
                    $target = $entry + $weeklyAtr * $targetAtrMultiple;
                    $distancePct = abs($bar->close - $level) / $level;
                    $score = 1.7
                        + $stats['success_rate'] * 3.0
                        + min(1.0, $stats['touches'] / 10.0)
                        + min(1.0, $stats['avg_forward_return'] * 8.0)
                        + max(0.0, 0.5 - min(0.5, $distancePct * 10.0));
                    if ($regime !== null) {
                        $score += $regime->score;
                    }

                    $candidate = new Signal(
                        $symbol,
                        $bar->time,
                        'SUPPORT_REGULARITY',
                        $entry,
                        $stop,
                        $target,
                        $risk,
                        $score,
                        [
                            strtoupper($type) . $period . ' weekly support is in play',
                            'prior weekly touches: ' . $stats['touches'],
                            'prior weekly support success rate: ' . round($stats['success_rate'] * 100, 1) . '%',
                            'avg weekly forward return after touch: ' . round($stats['avg_forward_return'] * 100, 2) . '%',
                        ],
                        'long',
                        [
                            'timeframe' => 'W',
                            'ma_type' => $type,
                            'ma_period' => $period,
                            'support_level' => $level,
                            'support_atr' => $weeklyAtr,
                            'setup_touches' => $stats['touches'],
                            'setup_success_rate' => $stats['success_rate'],
                            'setup_avg_forward_return' => $stats['avg_forward_return'],
                            'setup_key' => $setupKey,
                        ],
                    );

                    if ($best === null || $candidate->score > $best->score) {
                        $best = $candidate;
                    }
                }
            }

            if ($best !== null) {
                $signals[] = $best;
                $lastSignalIndexBySetup[(string) $best->metadata['setup_key']] = $i;
            }
        }

        return $signals;
    }

    /**
     * @param list<Bar> $bars
     * @param array<string, list<float|null>> $indicatorMap
     * @param array<string, MarketRegime> $marketRegimes
     * @return list<Signal>
     */
    private function scanResistanceRegularity(string $symbol, array $bars, array $indicatorMap, array $marketRegimes): array
    {
        $config = $this->config['short_resistance'] ?? [];
        if (!($config['enabled'] ?? false) || !$this->isShortableSymbol($symbol)) {
            return [];
        }

        $periods = array_values(array_filter(
            array_map('intval', $config['periods'] ?? [20, 50, 100, 200]),
            static fn (int $period): bool => $period > 1,
        ));
        $types = array_values(array_filter(
            array_map('strval', $config['types'] ?? ['ema', 'sma']),
            static fn (string $type): bool => in_array($type, ['ema', 'sma'], true),
        ));
        if ($periods === [] || $types === []) {
            return [];
        }

        $lookback = (int) ($config['lookback_bars'] ?? 504);
        $forwardBars = (int) ($config['forward_bars'] ?? 20);
        $minTouches = (int) ($config['min_touches'] ?? 3);
        $minSuccessRate = (float) ($config['min_success_rate'] ?? 0.60);
        $successMinReturn = (float) ($config['success_min_return_pct'] ?? 0.03);
        $touchTolerance = (float) ($config['touch_tolerance_pct'] ?? 0.015);
        $violationTolerance = (float) ($config['violation_tolerance_pct'] ?? 0.03);
        $nearPct = (float) ($config['near_pct'] ?? 0.025);
        $nearAtrMultiple = (float) ($config['near_atr_multiple'] ?? 0.60);
        $stopAtrMultiple = (float) ($config['stop_atr_multiple'] ?? 1.5);
        $targetAtrMultiple = (float) ($config['target_atr_multiple'] ?? 3.0);
        $cooldownBars = (int) ($config['cooldown_bars'] ?? 10);
        $requireCloseBelowResistance = (bool) ($config['require_close_below_resistance'] ?? false);

        $signals = [];
        $lastSignalIndexBySetup = [];
        $startIndex = max(220, max($periods) + $forwardBars + $minTouches);
        for ($i = $startIndex; $i < count($bars); $i++) {
            $bar = $bars[$i];
            $date = $bar->time->format('Y-m-d');
            $regime = $marketRegimes[$date] ?? null;
            if (!$this->allowsShortSignal($regime)) {
                continue;
            }
            if (!$this->passesExternalFilters($symbol, $date, 'short')) {
                continue;
            }

            $atr = $indicatorMap['atr'][$i] ?? null;
            if ($atr === null || $atr <= 0.0) {
                continue;
            }

            $best = null;
            foreach ($types as $type) {
                foreach ($periods as $period) {
                    $key = $type . $period;
                    $setupKey = $symbol . ':short:RESISTANCE_REGULARITY:D:' . strtoupper($type) . $period;
                    if (isset($lastSignalIndexBySetup[$setupKey]) && ($i - $lastSignalIndexBySetup[$setupKey]) < $cooldownBars) {
                        continue;
                    }
                    $level = $indicatorMap[$key][$i] ?? null;
                    if ($level === null || $level <= 0.0) {
                        continue;
                    }

                    if (!$this->isNearResistance($bar, $level, $atr, $touchTolerance, $violationTolerance, $nearPct, $nearAtrMultiple)) {
                        continue;
                    }
                    if ($requireCloseBelowResistance && $bar->close > $level) {
                        continue;
                    }

                    $stats = $this->resistanceStats(
                        $bars,
                        $indicatorMap[$key],
                        $i,
                        $lookback,
                        $forwardBars,
                        $touchTolerance,
                        $violationTolerance,
                        $successMinReturn,
                    );
                    if ($stats['touches'] < $minTouches || $stats['success_rate'] < $minSuccessRate) {
                        continue;
                    }

                    $entry = $level;
                    $stop = $level + $atr * $stopAtrMultiple;
                    $target = max(0.01, $level - $atr * $targetAtrMultiple);
                    if ($stop <= $entry || $entry <= $target) {
                        continue;
                    }

                    $risk = $stop - $entry;
                    $distancePct = abs($bar->close - $level) / $level;
                    $score = 1.0
                        + $stats['success_rate'] * 3.0
                        + min(1.0, $stats['touches'] / 10.0)
                        + min(1.0, $stats['avg_forward_return'] * 10.0)
                        + max(0.0, 0.5 - min(0.5, $distancePct * 10.0));
                    if ($regime !== null) {
                        $score += max(0.0, 3.0 - $regime->score);
                    }

                    $candidate = new Signal(
                        $symbol,
                        $bar->time,
                        'RESISTANCE_REGULARITY_SHORT',
                        $entry,
                        $stop,
                        $target,
                        $risk,
                        $score,
                        [
                            strtoupper($type) . $period . ' resistance is in play',
                            'prior touches: ' . $stats['touches'],
                            'prior resistance success rate: ' . round($stats['success_rate'] * 100, 1) . '%',
                            'avg forward downside after touch: ' . round($stats['avg_forward_return'] * 100, 2) . '%',
                        ],
                        'short',
                        [
                            'timeframe' => 'D',
                            'ma_type' => $type,
                            'ma_period' => $period,
                            'resistance_level' => $level,
                            'support_atr' => $atr,
                            'setup_touches' => $stats['touches'],
                            'setup_success_rate' => $stats['success_rate'],
                            'setup_avg_forward_return' => $stats['avg_forward_return'],
                            'setup_key' => $setupKey,
                        ],
                    );

                    if ($best === null || $candidate->score > $best->score) {
                        $best = $candidate;
                    }
                }
            }

            if ($best !== null) {
                $signals[] = $best;
                $lastSignalIndexBySetup[(string) $best->metadata['setup_key']] = $i;
            }
        }

        return $signals;
    }

    private function isHealthyTrend(Bar $bar, float $ema20, float $ema50, float $ema100, float $ema200): bool
    {
        return $bar->close > $ema20
            && $bar->close > $ema50
            && $bar->close > $ema100
            && $bar->close > $ema200;
    }

    /** @param list<Bar> $bars */
    private function hasRocketMove(array $bars, int $index, int $lookback, float $minGain, float $volumeSpike): bool
    {
        $start = max(0, $index - $lookback);
        $slice = array_slice($bars, $start, $index - $start + 1);
        if (count($slice) < 10) {
            return false;
        }

        $minLow = min(array_map(static fn (Bar $bar): float => $bar->low, $slice));
        $maxHigh = max(array_map(static fn (Bar $bar): float => $bar->high, $slice));
        if ($minLow <= 0 || (($maxHigh - $minLow) / $minLow) < $minGain) {
            return false;
        }

        $recent = array_slice($slice, -20);
        $prior = array_slice($slice, 0, max(1, count($slice) - 20));
        $recentMaxVol = max(array_map(static fn (Bar $bar): float => $bar->volume, $recent));
        $priorAvgVol = array_sum(array_map(static fn (Bar $bar): float => $bar->volume, $prior)) / max(1, count($prior));

        return $priorAvgVol > 0 && ($recentMaxVol / $priorAvgVol) >= $volumeSpike;
    }

    /**
     * @param list<Bar> $bars
     * @param list<float|null> $ema20
     */
    private function hadRecentEma20Touch(array $bars, array $ema20, int $index, int $lookback, float $tolerance): bool
    {
        $start = max(0, $index - $lookback);
        for ($i = $start; $i < $index; $i++) {
            if (($ema20[$i] ?? null) === null) {
                continue;
            }
            if ($bars[$i]->low <= $ema20[$i] * (1 + $tolerance)) {
                return true;
            }
        }

        return false;
    }

    private function isNearSupport(
        Bar $bar,
        float $level,
        float $atr,
        float $touchTolerance,
        float $violationTolerance,
        float $nearPct,
        float $nearAtrMultiple,
    ): bool {
        $touched = $bar->low <= $level * (1.0 + $touchTolerance);
        $notBroken = $bar->close >= $level * (1.0 - $violationTolerance);
        if ($touched && $notBroken) {
            return true;
        }

        $distancePct = abs($bar->close - $level) / $level;
        $atrDistancePct = ($atr * $nearAtrMultiple) / $bar->close;

        return $notBroken && $distancePct <= max($nearPct, $atrDistancePct);
    }

    private function isNearResistance(
        Bar $bar,
        float $level,
        float $atr,
        float $touchTolerance,
        float $violationTolerance,
        float $nearPct,
        float $nearAtrMultiple,
    ): bool {
        $touched = $bar->high >= $level * (1.0 - $touchTolerance);
        $notBroken = $bar->close <= $level * (1.0 + $violationTolerance);
        if ($touched && $notBroken) {
            return true;
        }

        $distancePct = abs($bar->close - $level) / $level;
        $atrDistancePct = ($atr * $nearAtrMultiple) / $bar->close;

        return $notBroken && $distancePct <= max($nearPct, $atrDistancePct);
    }

    /**
     * @param list<Bar> $bars
     * @param list<float|null> $levels
     * @return array{touches:int, success_rate:float, avg_forward_return:float}
     */
    private function supportStats(
        array $bars,
        array $levels,
        int $index,
        int $lookback,
        int $forwardBars,
        float $touchTolerance,
        float $violationTolerance,
        float $successMinReturn,
    ): array {
        $start = max(0, $index - $lookback);
        $end = max($start, $index - $forwardBars - 1);
        $touches = 0;
        $successes = 0;
        $forwardReturnSum = 0.0;

        for ($i = $start; $i <= $end; $i++) {
            $level = $levels[$i] ?? null;
            if ($level === null || $level <= 0.0) {
                continue;
            }

            $bar = $bars[$i];
            if ($bar->low > $level * (1.0 + $touchTolerance)) {
                continue;
            }
            if ($bar->close < $level * (1.0 - $violationTolerance)) {
                continue;
            }

            $touches++;
            $maxClose = $bar->close;
            $forwardEnd = min(count($bars) - 1, $i + $forwardBars);
            for ($j = $i + 1; $j <= $forwardEnd; $j++) {
                $maxClose = max($maxClose, $bars[$j]->close);
            }

            $forwardReturn = $bar->close > 0.0 ? ($maxClose - $bar->close) / $bar->close : 0.0;
            $forwardReturnSum += $forwardReturn;
            if ($forwardReturn >= $successMinReturn) {
                $successes++;
            }
        }

        return [
            'touches' => $touches,
            'success_rate' => $touches > 0 ? $successes / $touches : 0.0,
            'avg_forward_return' => $touches > 0 ? $forwardReturnSum / $touches : 0.0,
        ];
    }

    /**
     * @param list<Bar> $bars
     * @param list<float|null> $levels
     * @return array{touches:int, success_rate:float, avg_forward_return:float}
     */
    private function resistanceStats(
        array $bars,
        array $levels,
        int $index,
        int $lookback,
        int $forwardBars,
        float $touchTolerance,
        float $violationTolerance,
        float $successMinReturn,
    ): array {
        $start = max(0, $index - $lookback);
        $end = max($start, $index - $forwardBars - 1);
        $touches = 0;
        $successes = 0;
        $forwardReturnSum = 0.0;

        for ($i = $start; $i <= $end; $i++) {
            $level = $levels[$i] ?? null;
            if ($level === null || $level <= 0.0) {
                continue;
            }

            $bar = $bars[$i];
            if ($bar->high < $level * (1.0 - $touchTolerance)) {
                continue;
            }
            if ($bar->close > $level * (1.0 + $violationTolerance)) {
                continue;
            }

            $touches++;
            $minClose = $bar->close;
            $forwardEnd = min(count($bars) - 1, $i + $forwardBars);
            for ($j = $i + 1; $j <= $forwardEnd; $j++) {
                $minClose = min($minClose, $bars[$j]->close);
            }

            $forwardReturn = $bar->close > 0.0 ? ($bar->close - $minClose) / $bar->close : 0.0;
            $forwardReturnSum += $forwardReturn;
            if ($forwardReturn >= $successMinReturn) {
                $successes++;
            }
        }

        return [
            'touches' => $touches,
            'success_rate' => $touches > 0 ? $successes / $touches : 0.0,
            'avg_forward_return' => $touches > 0 ? $forwardReturnSum / $touches : 0.0,
        ];
    }

    private function allowsLongSignal(string $symbol, ?MarketRegime $regime): bool
    {
        if ($regime === null) {
            return true;
        }
        if ($this->isInverseLongSymbol($symbol)) {
            $config = $this->config['inverse_long'] ?? [];

            return !$regime->allowsLongRisk || $regime->score <= (float) ($config['max_market_score'] ?? 2.0);
        }
        $dipConfig = $this->config['market_dip_entry'] ?? [];
        if (($dipConfig['only'] ?? false)) {
            return $this->allowsMarketDipEntry($regime);
        }
        if ($this->allowsMarketDipEntry($regime)) {
            return true;
        }

        return $regime->allowsLongRisk;
    }

    private function allowsMarketDipEntry(MarketRegime $regime): bool
    {
        $config = $this->config['market_dip_entry'] ?? [];
        if (!($config['enabled'] ?? false)) {
            return false;
        }

        $drawdown = abs(min(0.0, $regime->spyDrawdownPct));
        if ($drawdown < (float) ($config['min_spy_drawdown_pct'] ?? 0.08)) {
            return false;
        }
        if ($drawdown > (float) ($config['max_spy_drawdown_pct'] ?? 0.30)) {
            return false;
        }
        if ($regime->score > (float) ($config['max_market_score'] ?? 2.5)) {
            return false;
        }
        $maxRsi = $config['max_spy_rsi14'] ?? null;
        if ($maxRsi !== null && $regime->spyRsi14 !== null && $regime->spyRsi14 > (float) $maxRsi) {
            return false;
        }

        return true;
    }

    private function allowsShortSignal(?MarketRegime $regime): bool
    {
        $config = $this->config['short_resistance'] ?? [];
        if ($regime === null) {
            return true;
        }

        return !$regime->allowsLongRisk || $regime->score <= (float) ($config['max_market_score'] ?? 2.0);
    }

    private function isShortableSymbol(string $symbol): bool
    {
        $symbols = array_map('strtoupper', $this->config['short_symbols'] ?? []);

        return in_array(strtoupper($symbol), $symbols, true);
    }

    private function isInverseLongSymbol(string $symbol): bool
    {
        $symbols = array_map('strtoupper', $this->config['inverse_long_symbols'] ?? []);

        return in_array(strtoupper($symbol), $symbols, true);
    }

    /**
     * @param list<Bar> $weeklyBars
     * @return array<string, int>
     */
    private function completedWeeklyIndexByDailyDate(array $weeklyBars): array
    {
        $result = [];
        $completed = [];
        foreach ($weeklyBars as $index => $bar) {
            $completed[$bar->time->format('o-W')] = $index;
        }

        foreach ($weeklyBars as $index => $bar) {
            $weekStart = $bar->time->modify('monday this week');
            for ($i = 0; $i < 5; $i++) {
                $date = $weekStart->modify('+' . $i . ' days');
                $previousWeek = $date->modify('-1 week')->format('o-W');
                if (isset($completed[$previousWeek])) {
                    $result[$date->format('Y-m-d')] = $completed[$previousWeek];
                }
            }
            $result[$bar->time->modify('+1 day')->format('Y-m-d')] = $index;
        }

        return $result;
    }

    private function passesExternalFilters(string $symbol, string $date, string $direction): bool
    {
        $filters = $this->config['external_filters'] ?? [];
        if (!($filters['enabled'] ?? false)) {
            return true;
        }

        $snapshots = $this->config['external_indicator_snapshots'] ?? [];
        if (!is_array($snapshots) || $snapshots === []) {
            return !($filters['require_history'] ?? false);
        }

        $required = array_map('strtolower', $filters['required_indicators'] ?? []);
        if ($required === []) {
            return true;
        }

        $timeframes = array_map('strtoupper', $filters['timeframes'] ?? ['1D', 'D']);
        $allowedSignals = array_map('strtolower', $filters['bullish_signals'] ?? ['buy', 'long', 'bull', 'green', 'ok']);
        $symbolSnapshots = $snapshots[strtoupper($symbol)][$date] ?? $snapshots['MARKET'][$date] ?? $snapshots['SPY'][$date] ?? null;
        if (!is_array($symbolSnapshots)) {
            return !($filters['require_history'] ?? false);
        }

        foreach ($required as $indicator) {
            $matched = false;
            foreach ($timeframes as $timeframe) {
                $row = $symbolSnapshots[$timeframe][$indicator] ?? null;
                if (!is_array($row)) {
                    continue;
                }

                $signal = strtolower((string) ($row['signal'] ?? ''));
                if ($direction === 'short') {
                    $matched = $signal === '' || !in_array($signal, $allowedSignals, true);
                } else {
                    $matched = $signal === '' || in_array($signal, $allowedSignals, true);
                }
                if ($matched) {
                    break;
                }
            }
            if (!$matched) {
                return false;
            }
        }

        return true;
    }

    /** @param list<Signal> $signals @return list<Signal> */
    private function dedupeAndSortSignals(array $signals): array
    {
        $byDate = [];
        foreach ($signals as $signal) {
            $key = $signal->createdAt->format('Y-m-d') . ':' . ($signal->metadata['setup_key'] ?? $signal->strategy);
            if (!isset($byDate[$key]) || $signal->score > $byDate[$key]->score) {
                $byDate[$key] = $signal;
            }
        }

        $deduped = array_values($byDate);
        usort($deduped, static function (Signal $a, Signal $b): int {
            $dateCompare = $a->createdAt <=> $b->createdAt;
            if ($dateCompare !== 0) {
                return $dateCompare;
            }

            return $b->score <=> $a->score;
        });

        return $deduped;
    }
}
