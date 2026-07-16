<?php

declare(strict_types=1);

namespace FulltimeTrading\Backtest;

use FulltimeTrading\Domain\Bar;
use FulltimeTrading\Domain\Signal;

final readonly class IntradayTouchReclaimConfirmer
{
    public function __construct(
        private int $barMinutes = 5,
        private float $touchTolerancePct = 0.005,
        private float $reclaimBufferPct = 0.0,
        private float $minBouncePct = 0.0,
        private bool $requireBullishBar = false,
        private int $maxBarsAfterTouch = 6,
        private int $maxFillDelayMinutes = 5,
        private float $maxChaseAtr = 0.25,
        private float $slippageBps = 10.0,
        private string $lastReclaimBarStart = '15:50',
        private bool $rejectPreEntryStopBreach = true,
        private bool $requireCompleteSession = true,
        private float $minimumSessionCoveragePct = 1.0,
        private int $maximumSessionGapBars = 1,
    ) {
        if ($this->barMinutes <= 0) {
            throw new \InvalidArgumentException('Intraday bar minutes must be positive.');
        }
        if ($this->touchTolerancePct < 0.0 || $this->reclaimBufferPct < 0.0 || $this->minBouncePct < 0.0) {
            throw new \InvalidArgumentException('Intraday confirmation percentages cannot be negative.');
        }
        if ($this->maxBarsAfterTouch < 0 || $this->maxFillDelayMinutes < 0) {
            throw new \InvalidArgumentException('Intraday confirmation windows cannot be negative.');
        }
        if ($this->maxChaseAtr < 0.0 || $this->slippageBps < 0.0) {
            throw new \InvalidArgumentException('Chase and slippage assumptions cannot be negative.');
        }
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $this->lastReclaimBarStart)) {
            throw new \InvalidArgumentException('Last reclaim bar start must use HH:MM.');
        }
        if ($this->minimumSessionCoveragePct <= 0.0 || $this->minimumSessionCoveragePct > 1.0) {
            throw new \InvalidArgumentException('Minimum candidate-session coverage must be in (0, 1].');
        }
        if ($this->maximumSessionGapBars < 1) {
            throw new \InvalidArgumentException('Maximum candidate-session gap must be at least one bar.');
        }
    }

    /**
     * Resolve a next-session long entry using only completed intraday bars.
     * A reclaim decision made at a bar close always executes at the next
     * observed bar open, never at the support, low, or reclaim close.
     *
     * @param list<Bar> $bars
     */
    public function resolve(Signal $planned, string $activationSession, array $bars): IntradayEntryDecision
    {
        if (
            $planned->direction !== 'long'
            || (string) ($planned->metadata['entry_signal_mode'] ?? '') !== 'advance_next_session'
        ) {
            return new IntradayEntryDecision(
                'invalid_signal',
                $activationSession,
                reason: 'Only advance_next_session long candidates are supported.',
            );
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $activationSession)) {
            return new IntradayEntryDecision(
                'invalid_session',
                $activationSession,
                reason: 'Activation session must use YYYY-MM-DD.',
            );
        }

        $level = (float) ($planned->metadata['planned_entry_level'] ?? $planned->entry);
        $atr = (float) ($planned->metadata['support_atr'] ?? 0.0);
        if ($level <= 0.0 || $atr <= 0.0) {
            return new IntradayEntryDecision(
                'invalid_signal',
                $activationSession,
                reason: 'A frozen positive entry level and ATR are required.',
            );
        }

        $sessionValidation = $this->validateRegularSessionPath(
            $planned->symbol,
            $activationSession,
            $bars,
        );
        $sessionBars = $sessionValidation['bars'];
        $conflict = array_values(array_filter(
            $sessionValidation['failures'],
            static fn (string $failure): bool => str_starts_with($failure, 'conflicting_duplicate:'),
        ));
        if ($conflict !== []) {
            return new IntradayEntryDecision(
                'conflicting_duplicate',
                $activationSession,
                reason: substr($conflict[0], strlen('conflicting_duplicate:')),
            );
        }
        if ($sessionBars === []) {
            return new IntradayEntryDecision(
                'missing_session_data',
                $activationSession,
                reason: 'No regular-session intraday bars are available for the candidate session.',
            );
        }
        if ($this->requireCompleteSession) {
            $sessionQualityFailures = array_values(array_filter(
                $sessionValidation['failures'],
                static fn (string $failure): bool => $failure !== 'missing_regular_session',
            ));
            if ($sessionQualityFailures !== []) {
                return new IntradayEntryDecision(
                    'incomplete_session_data',
                    $activationSession,
                    reason: 'Candidate-session path is incomplete: ' . implode('; ', $sessionQualityFailures),
                );
            }
        }

        $touchThreshold = $level * (1.0 + $this->touchTolerancePct);
        $reclaimThreshold = $level * (1.0 + $this->reclaimBufferPct);
        $touchIndex = null;
        $touchTime = null;
        $touchLow = INF;
        $sawTouch = false;
        $sawExpiredTouch = false;
        $sawLateReclaim = false;
        $preEntryStopBreach = false;
        $lastReclaimBarStart = UsEquitySessionCalendar::lastReclaimBarStart(
            $activationSession,
            $this->barMinutes,
            $this->lastReclaimBarStart,
        );

        foreach ($sessionBars as $index => $bar) {
            $local = $bar->time->setTimezone(new \DateTimeZone('America/New_York'));
            $localTime = $local->format('H:i');

            if ($bar->low <= $planned->stop) {
                $preEntryStopBreach = true;
            }

            $touchElapsedSeconds = $touchTime instanceof \DateTimeImmutable
                ? $bar->time->getTimestamp() - $touchTime->getTimestamp()
                : 0;
            if (
                $touchIndex !== null
                && $touchElapsedSeconds > $this->maxBarsAfterTouch * $this->barMinutes * 60
            ) {
                $sawExpiredTouch = true;
                $touchIndex = null;
                $touchTime = null;
                $touchLow = INF;
            }

            if ($touchIndex === null && $bar->low <= $touchThreshold) {
                $touchIndex = $index;
                $touchTime = $bar->time;
                $touchLow = $bar->low;
                $sawTouch = true;
            } elseif ($touchIndex !== null) {
                $touchLow = min($touchLow, $bar->low);
            }

            if ($touchIndex === null) {
                continue;
            }

            $bouncePct = $touchLow > 0.0 ? ($bar->close / $touchLow) - 1.0 : 0.0;
            $reclaimed = $bar->close >= $reclaimThreshold
                && $bouncePct >= $this->minBouncePct
                && (!$this->requireBullishBar || $bar->close > $bar->open);
            if (!$reclaimed) {
                continue;
            }
            if ($localTime > $lastReclaimBarStart) {
                $sawLateReclaim = true;
                continue;
            }
            if ($preEntryStopBreach && $this->rejectPreEntryStopBreach) {
                return new IntradayEntryDecision(
                    'pre_entry_stop_breached',
                    $activationSession,
                    $touchTime,
                    $bar->time,
                    $bar->time->modify('+' . $this->barMinutes . ' minutes'),
                    preEntryStopBreach: true,
                    reason: 'The frozen setup stop was breached before a causal entry became executable.',
                );
            }

            $decisionAt = $bar->time->modify('+' . $this->barMinutes . ' minutes');
            $fillBar = $sessionBars[$index + 1] ?? null;
            if (!$fillBar instanceof Bar) {
                return new IntradayEntryDecision(
                    'missing_next_bar',
                    $activationSession,
                    $touchTime,
                    $bar->time,
                    $decisionAt,
                    preEntryStopBreach: $preEntryStopBreach,
                    reason: 'The reclaim has no later regular-session bar for execution.',
                );
            }

            $fillLocal = $fillBar->time->setTimezone(new \DateTimeZone('America/New_York'));
            $fillDelaySeconds = $fillBar->time->getTimestamp() - $decisionAt->getTimestamp();
            $fillDelayMinutes = (int) round($fillDelaySeconds / 60);
            if (
                $fillLocal->format('Y-m-d') !== $activationSession
                || !UsEquitySessionCalendar::isRegularBarStart(
                    $activationSession,
                    $fillLocal->format('H:i'),
                )
                || $fillDelaySeconds < 0
                || $fillDelayMinutes > $this->maxFillDelayMinutes
            ) {
                return new IntradayEntryDecision(
                    'fill_delay_exceeded',
                    $activationSession,
                    $touchTime,
                    $bar->time,
                    $decisionAt,
                    $fillBar->time,
                    $fillBar->open,
                    fillDelayMinutes: $fillDelayMinutes,
                    preEntryStopBreach: $preEntryStopBreach,
                    reason: 'The next observed bar is too late or outside the activation session.',
                );
            }

            $fillPrice = $fillBar->open * (1.0 + $this->slippageBps / 10000.0);
            $maxFillPrice = $level + $atr * $this->maxChaseAtr;
            if ($fillPrice > $maxFillPrice) {
                return new IntradayEntryDecision(
                    'chase_cap_exceeded',
                    $activationSession,
                    $touchTime,
                    $bar->time,
                    $decisionAt,
                    $fillBar->time,
                    $fillBar->open,
                    $fillPrice,
                    $fillDelayMinutes,
                    $preEntryStopBreach,
                    'The executable next-bar price exceeds the frozen ATR chase cap.',
                );
            }

            return new IntradayEntryDecision(
                'filled',
                $activationSession,
                $touchTime,
                $bar->time,
                $decisionAt,
                $fillBar->time,
                $fillBar->open,
                $fillPrice,
                $fillDelayMinutes,
                $preEntryStopBreach,
                'Touch and completed-bar reclaim confirmed; filled at the next observed bar open.',
            );
        }

        $status = match (true) {
            !$sawTouch => 'no_touch',
            $sawLateReclaim => 'late_reclaim',
            $sawExpiredTouch => 'reclaim_window_expired',
            default => 'no_reclaim',
        };

        return new IntradayEntryDecision(
            $status,
            $activationSession,
            $touchTime,
            preEntryStopBreach: $preEntryStopBreach,
            reason: match ($status) {
                'no_touch' => 'The frozen support was not touched during the activation session.',
                'late_reclaim' => 'A reclaim occurred after the last executable reclaim bar.',
                'reclaim_window_expired' => 'No reclaim completed inside the configured post-touch window.',
                default => 'Support was touched but never reclaimed on a completed eligible bar.',
            },
        );
    }

    /** @return array{required:bool,bar_minutes:int,minimum_coverage_pct:float,maximum_gap_bars:int} */
    public function sessionQualityPolicy(): array
    {
        return [
            'required' => $this->requireCompleteSession,
            'bar_minutes' => $this->barMinutes,
            'minimum_coverage_pct' => $this->minimumSessionCoveragePct,
            'maximum_gap_bars' => $this->maximumSessionGapBars,
        ];
    }

    /**
     * Normalize and validate an entire regular-session path independently of
     * any entry signal. Exposure bounds and entry confirmation must consume
     * the same fail-closed evidence: every expected bar is present, both
     * boundary bars exist, timestamps stay on-grid, and no interval is
     * skipped. Identical duplicate bars are deterministically deduplicated.
     *
     * @param list<Bar> $bars
     * @return array{passes:bool,bars:list<Bar>,failures:list<string>}
     */
    public function validateRegularSessionPath(string $symbol, string $session, array $bars): array
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $session) !== 1) {
            return [
                'passes' => false,
                'bars' => [],
                'failures' => ['invalid_session'],
            ];
        }

        try {
            $sessionBars = $this->regularSessionBars($symbol, $session, $bars);
        } catch (\UnexpectedValueException $e) {
            return [
                'passes' => false,
                'bars' => [],
                'failures' => ['conflicting_duplicate:' . $e->getMessage()],
            ];
        }

        if ($sessionBars === []) {
            return [
                'passes' => false,
                'bars' => [],
                'failures' => ['missing_regular_session'],
            ];
        }

        $failures = $this->regularSessionQualityFailures($session, $sessionBars);

        return [
            'passes' => $failures === [],
            'bars' => $sessionBars,
            'failures' => $failures,
        ];
    }

    /**
     * @param list<Bar> $bars
     * @return list<Bar>
     */
    private function regularSessionBars(string $symbol, string $session, array $bars): array
    {
        $timezone = new \DateTimeZone('America/New_York');
        $byTimestamp = [];
        foreach ($bars as $bar) {
            if (!$bar instanceof Bar || strtoupper($bar->symbol) !== strtoupper($symbol)) {
                continue;
            }
            $local = $bar->time->setTimezone($timezone);
            $time = $local->format('H:i');
            if (
                $local->format('Y-m-d') !== $session
                || !UsEquitySessionCalendar::isRegularBarStart($session, $time)
            ) {
                continue;
            }

            $key = (string) $bar->time->getTimestamp();
            if (isset($byTimestamp[$key])) {
                $existing = $byTimestamp[$key];
                if (!$this->sameBar($existing, $bar)) {
                    throw new \UnexpectedValueException('Conflicting intraday bars share timestamp ' . $bar->time->format(DATE_ATOM) . '.');
                }
                continue;
            }
            $byTimestamp[$key] = $bar;
        }

        ksort($byTimestamp, SORT_NUMERIC);

        return array_values($byTimestamp);
    }

    private function sameBar(Bar $a, Bar $b): bool
    {
        return $a->symbol === $b->symbol
            && $a->open === $b->open
            && $a->high === $b->high
            && $a->low === $b->low
            && $a->close === $b->close
            && $a->volume === $b->volume;
    }

    /**
     * Entry-day stops, break-even rules, partial exits, and exposure all use
     * the complete regular-session path. A plausible opening reclaim is not
     * enough when the remaining bars are absent: accepting such a day would
     * silently hide adverse post-fill prices.
     *
     * @param list<Bar> $sessionBars
     * @return list<string>
     */
    private function regularSessionQualityFailures(string $session, array $sessionBars): array
    {
        $timezone = new \DateTimeZone('America/New_York');
        $open = new \DateTimeImmutable(
            $session . ' ' . UsEquitySessionCalendar::openTime($session),
            $timezone,
        );
        $close = new \DateTimeImmutable(
            $session . ' ' . UsEquitySessionCalendar::closeTime($session),
            $timezone,
        );
        $barSeconds = $this->barMinutes * 60;
        $expectedBars = intdiv($close->getTimestamp() - $open->getTimestamp(), $barSeconds);
        $minimumBars = (int) ceil($expectedBars * $this->minimumSessionCoveragePct);
        $failures = [];

        $first = $sessionBars[0] ?? null;
        $last = $sessionBars[count($sessionBars) - 1] ?? null;
        $expectedLastStart = $close->modify('-' . $this->barMinutes . ' minutes');
        if (!$first instanceof Bar || $first->time->getTimestamp() !== $open->getTimestamp()) {
            $failures[] = 'missing_open_bar';
        }
        if (!$last instanceof Bar || $last->time->getTimestamp() !== $expectedLastStart->getTimestamp()) {
            $failures[] = 'missing_close_bar';
        }
        if (count($sessionBars) < $minimumBars) {
            $failures[] = sprintf(
                'insufficient_bars:%d<%d_of_%d',
                count($sessionBars),
                $minimumBars,
                $expectedBars,
            );
        }

        $previousTimestamp = null;
        foreach ($sessionBars as $bar) {
            $timestamp = $bar->time->getTimestamp();
            $offsetSeconds = $timestamp - $open->getTimestamp();
            if ($offsetSeconds < 0 || $offsetSeconds % $barSeconds !== 0) {
                $failures[] = 'off_grid_bar:' . $bar->time->format(DATE_ATOM);
            }
            if (
                $previousTimestamp !== null
                && $timestamp - $previousTimestamp > $barSeconds * $this->maximumSessionGapBars
            ) {
                $failures[] = sprintf(
                    'gap_minutes:%d',
                    intdiv($timestamp - $previousTimestamp, 60),
                );
            }
            $previousTimestamp = $timestamp;
        }

        return array_values(array_unique($failures));
    }
}
