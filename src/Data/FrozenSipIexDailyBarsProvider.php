<?php

declare(strict_types=1);

namespace FulltimeTrading\Data;

use FulltimeTrading\Domain\Bar;

/**
 * Deterministically joins a verified frozen Alpaca SIP snapshot to a recent
 * completed Alpaca IEX daily tail at one explicit, inclusive SIP cutoff.
 */
final class FrozenSipIexDailyBarsProvider implements MarketDataProvider
{
    private const MARKET_TIMEZONE = 'America/New_York';

    /** @var ?array<string, mixed> */
    private ?array $lastProvenance = null;

    private readonly string $cutoff;

    /** @var array<string,mixed> */
    private readonly array $crossFeedAudit;

    public function __construct(
        private readonly VerifiedCacheSnapshotMarketDataProvider $frozenSip,
        private readonly MarketDataProvider $recentIex,
        string $cutoff,
        private readonly string $recentCacheNamespace,
        array $crossFeedAudit = [],
    ) {
        $this->cutoff = self::canonicalDate($cutoff, 'Frozen SIP cutoff');
        if (trim($this->recentCacheNamespace) === '') {
            throw new \InvalidArgumentException('Recent IEX cache namespace must not be empty.');
        }
        $this->crossFeedAudit = self::normalizeAuditContract($crossFeedAudit);
    }

    /**
     * @param list<string> $symbols
     * @return array<string, list<Bar>>
     */
    public function getBars(array $symbols, string $timeframe, string $start, string $end): array
    {
        $this->lastProvenance = null;
        if (!in_array(strtolower(trim($timeframe)), ['1day', '1d', 'd', 'day'], true)) {
            throw new \InvalidArgumentException('Frozen SIP/IEX stitching accepts daily bars only.');
        }
        $start = self::canonicalDate($start, 'Market-data start');
        $end = self::canonicalDate($end, 'Market-data end');
        if ($start > $end) {
            throw new \InvalidArgumentException('Market-data start must not be later than end.');
        }
        if ($start > $this->cutoff) {
            throw new \InvalidArgumentException('A stitched request must include the frozen SIP boundary.');
        }
        $symbols = $this->canonicalSymbols($symbols);

        // Always verify the complete frozen snapshot through the configured
        // cutoff, even when a historical replay asks to use an earlier end.
        $frozenSnapshot = $this->frozenSip->getBars($symbols, $timeframe, $start, $this->cutoff);
        $frozenProvenance = $this->frozenSip->provenance();
        if (($frozenProvenance['provider'] ?? null) !== 'Alpaca'
            || ($frozenProvenance['feed'] ?? null) !== 'sip'
            || ($frozenProvenance['adjustment'] ?? null) !== 'split') {
            throw new \RuntimeException('Frozen history provenance must be exact Alpaca SIP/split daily data.');
        }
        $frozenSnapshot = $this->validatePayload(
            $frozenSnapshot,
            $symbols,
            $start,
            $this->cutoff,
            'frozen SIP snapshot',
        );
        foreach ($symbols as $symbol) {
            $last = $this->lastSession($frozenSnapshot[$symbol]);
            if ($last !== $this->cutoff) {
                throw new \RuntimeException(sprintf(
                    'Frozen SIP snapshot for %s ends at %s instead of configured cutoff %s.',
                    $symbol,
                    $last ?? 'missing',
                    $this->cutoff,
                ));
            }
        }

        // This is deliberately a separate read whose terminal session is the
        // frozen cutoff. It can veto a corrupt splice, but none of its IEX
        // bars can enter frozenUsed, recent, merged, sizing, or ranking data.
        $crossFeedAudit = $this->auditFrozenCutoffOverlap(
            $frozenSnapshot,
            $symbols,
            $timeframe,
            $end,
        );

        $frozenUsedEnd = min($end, $this->cutoff);
        $frozenUsed = [];
        foreach ($symbols as $symbol) {
            $frozenUsed[$symbol] = array_values(array_filter(
                $frozenSnapshot[$symbol],
                fn (Bar $bar): bool => $this->session($bar) <= $frozenUsedEnd,
            ));
        }

        $freshStart = (new \DateTimeImmutable($this->cutoff, new \DateTimeZone(self::MARKET_TIMEZONE)))
            ->modify('+1 day')
            ->format('Y-m-d');
        $recent = array_fill_keys($symbols, []);
        if ($end > $this->cutoff) {
            try {
                $recent = $this->recentIex->getBars($symbols, $timeframe, $freshStart, $end);
            } catch (\Throwable $e) {
                throw new \RuntimeException('Recent completed IEX daily bars are unavailable.', 0, $e);
            }
            $recent = $this->validatePayload($recent, $symbols, $freshStart, $end, 'recent IEX tail');
            $this->assertIdenticalFreshSessions($recent, $symbols);
        }

        $merged = [];
        foreach ($symbols as $symbol) {
            $bySession = [];
            foreach ($frozenUsed[$symbol] as $bar) {
                $bySession[$this->session($bar)] = $bar;
            }
            foreach ($recent[$symbol] as $bar) {
                $session = $this->session($bar);
                if (isset($bySession[$session])) {
                    throw new \RuntimeException(sprintf(
                        'SIP/IEX overlap is ambiguous for %s on %s.',
                        $symbol,
                        $session,
                    ));
                }
                $bySession[$session] = $bar;
            }
            ksort($bySession, SORT_STRING);
            $merged[$symbol] = array_values($bySession);
            $last = $this->lastSession($merged[$symbol]);
            if ($last !== $end) {
                throw new \RuntimeException(sprintf(
                    'Merged daily bars for %s end at %s; required completed session is %s.',
                    $symbol,
                    $last ?? 'missing',
                    $end,
                ));
            }
        }

        $recentUsed = $end > $this->cutoff;
        $this->lastProvenance = [
            'schema' => 1,
            'mode' => 'frozen_alpaca_sip_plus_completed_alpaca_iex',
            'request' => [
                'symbols' => $symbols,
                'timeframe' => '1Day',
                'start' => $start,
                'end' => $end,
            ],
            'boundary' => [
                'frozen_sip_cutoff_inclusive' => $this->cutoff,
                'recent_iex_start_inclusive' => $freshStart,
                'overlap_policy' => 'reject',
                'overlap_sessions' => 0,
            ],
            'cross_feed_audit' => $crossFeedAudit,
            'segments' => [
                'frozen_sip' => array_merge($frozenProvenance, [
                    'snapshot_coverage' => $this->coverage($frozenSnapshot),
                    'snapshot_canonical_sha256' => $this->canonicalHash($frozenSnapshot),
                    'used_through' => $frozenUsedEnd,
                    'used_coverage' => $this->coverage($frozenUsed),
                    'used_canonical_sha256' => $this->canonicalHash($frozenUsed),
                ]),
                'recent_iex' => [
                    'provider' => 'Alpaca',
                    'feed' => 'iex',
                    'adjustment' => 'split',
                    'storage' => 'atomic_refreshable_cache',
                    'namespace' => $this->recentCacheNamespace,
                    'used' => $recentUsed,
                    'request' => $recentUsed ? [
                        'symbols' => $symbols,
                        'timeframe' => '1Day',
                        'start' => $freshStart,
                        'end' => $end,
                    ] : null,
                    'coverage' => $this->coverage($recent),
                    'canonical_sha256' => $this->canonicalHash($recent),
                ],
            ],
            'merged' => [
                'effective_completed_session' => $end,
                'coverage' => $this->coverage($merged),
                'canonical_sha256' => $this->canonicalHash($merged),
            ],
        ];

        return $merged;
    }

    /** @return array<string, mixed> */
    public function provenance(): array
    {
        if ($this->lastProvenance === null) {
            throw new \LogicException('Stitched market-data provenance is unavailable before a successful read.');
        }

        return $this->lastProvenance;
    }

    /**
     * Compare a small, fully historical overlap without changing which feed
     * supplies any decision bar. The audit window never extends past cutoff.
     *
     * @param array<string,list<Bar>> $frozenSnapshot
     * @param list<string> $symbols
     * @return array<string,mixed>
     */
    private function auditFrozenCutoffOverlap(
        array $frozenSnapshot,
        array $symbols,
        string $timeframe,
        string $requestEnd,
    ): array {
        $base = [
            'mode' => (string) $this->crossFeedAudit['mode'],
            'enabled' => (bool) $this->crossFeedAudit['enabled'],
            'used' => false,
            'passed' => null,
            'role' => 'audit_only_not_decision_data',
            'decision_data_usage' => 'none',
            'used_for_merged_bars' => false,
            'contract' => $this->crossFeedAudit,
        ];
        if (($this->crossFeedAudit['enabled'] ?? false) !== true) {
            return $base;
        }
        // Running the cutoff audit for an earlier historical request would
        // let later observations influence whether that replay is admitted.
        if ($requestEnd < $this->cutoff) {
            $base['skip_reason'] = 'request_ends_before_audit_cutoff';

            return $base;
        }

        $sessionCount = (int) $this->crossFeedAudit['sessions'];
        $expectedSessions = null;
        foreach ($symbols as $symbol) {
            $sessions = array_map(fn (Bar $bar): string => $this->session($bar), $frozenSnapshot[$symbol]);
            $sessions = array_slice($sessions, -$sessionCount);
            if (count($sessions) !== $sessionCount) {
                throw new \RuntimeException('Frozen SIP cross-feed audit window is incomplete.');
            }
            if ($expectedSessions === null) {
                $expectedSessions = $sessions;
            } elseif ($sessions !== $expectedSessions) {
                throw new \RuntimeException('Frozen SIP cross-feed audit sessions differ across symbols.');
            }
        }
        if (!is_array($expectedSessions) || $expectedSessions === []) {
            throw new \RuntimeException('Frozen SIP cross-feed audit has no sessions.');
        }
        $auditStart = $expectedSessions[0];
        if ($expectedSessions[array_key_last($expectedSessions)] !== $this->cutoff) {
            throw new \RuntimeException('Frozen SIP cross-feed audit does not terminate at the cutoff.');
        }

        try {
            $iexAudit = $this->recentIex->getBars(
                $symbols,
                $timeframe,
                $auditStart,
                $this->cutoff,
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException('Audit-only IEX cutoff overlap is unavailable.', 0, $e);
        }
        $iexAudit = $this->validatePayload(
            $iexAudit,
            $symbols,
            $auditStart,
            $this->cutoff,
            'audit-only IEX cutoff overlap',
        );
        $this->assertIdenticalFreshSessions($iexAudit, $symbols);

        $frozenAudit = [];
        $priceTolerance = (array) $this->crossFeedAudit['price_tolerance_bps'];
        $observedPrice = array_fill_keys(['open', 'high', 'low', 'close'], 0.0);
        $observedMinimumVolumeRatio = INF;
        $observedMaximumVolumeRatio = 0.0;
        $comparedBars = 0;
        foreach ($symbols as $symbol) {
            $iexSessions = array_map(fn (Bar $bar): string => $this->session($bar), $iexAudit[$symbol]);
            if ($iexSessions !== $expectedSessions) {
                throw new \RuntimeException('Audit-only IEX sessions do not exactly match frozen SIP sessions.');
            }
            $sipBySession = [];
            foreach ($frozenSnapshot[$symbol] as $bar) {
                $session = $this->session($bar);
                if ($session >= $auditStart && $session <= $this->cutoff) {
                    $sipBySession[$session] = $bar;
                }
            }
            if (array_keys($sipBySession) !== $expectedSessions) {
                throw new \RuntimeException('Frozen SIP audit subset is not exact.');
            }
            $frozenAudit[$symbol] = array_values($sipBySession);
            foreach ($iexAudit[$symbol] as $index => $iexBar) {
                $sipBar = $frozenAudit[$symbol][$index];
                $session = $expectedSessions[$index];
                foreach (['open', 'high', 'low', 'close'] as $field) {
                    $sipPrice = (float) $sipBar->{$field};
                    $deviationBps = abs((float) $iexBar->{$field} - $sipPrice) / $sipPrice * 10000.0;
                    $observedPrice[$field] = max($observedPrice[$field], $deviationBps);
                    if ($deviationBps > (float) $priceTolerance[$field] + 1.0e-9) {
                        throw new \RuntimeException(sprintf(
                            'Cross-feed audit price tolerance exceeded for %s %s %s.',
                            $symbol,
                            $session,
                            $field,
                        ));
                    }
                }
                if ($sipBar->volume <= 0.0) {
                    throw new \RuntimeException('Cross-feed audit requires positive frozen SIP volume.');
                }
                $volumeRatio = $iexBar->volume / $sipBar->volume;
                $observedMinimumVolumeRatio = min($observedMinimumVolumeRatio, $volumeRatio);
                $observedMaximumVolumeRatio = max($observedMaximumVolumeRatio, $volumeRatio);
                if ($volumeRatio + 1.0e-12 < (float) $this->crossFeedAudit['minimum_iex_to_sip_volume_ratio']
                    || $volumeRatio > (float) $this->crossFeedAudit['maximum_iex_to_sip_volume_ratio'] + 1.0e-12) {
                    throw new \RuntimeException(sprintf(
                        'Cross-feed audit volume tolerance exceeded for %s %s.',
                        $symbol,
                        $session,
                    ));
                }
                $comparedBars++;
            }
        }

        return array_merge($base, [
            'used' => true,
            'passed' => true,
            'feeds' => ['reference' => 'sip', 'candidate' => 'iex'],
            'cache_namespace' => $this->recentCacheNamespace,
            'request' => [
                'symbols' => $symbols,
                'timeframe' => '1Day',
                'start' => $auditStart,
                'end' => $this->cutoff,
            ],
            'window' => [
                'start' => $auditStart,
                'end' => $this->cutoff,
                'sessions' => $expectedSessions,
            ],
            'compared_symbols' => $symbols,
            'compared_sessions' => count($expectedSessions),
            'compared_bars' => $comparedBars,
            'violations' => 0,
            'observed' => [
                'maximum_price_deviation_bps' => $observedPrice,
                'minimum_iex_to_sip_volume_ratio' => $observedMinimumVolumeRatio,
                'maximum_iex_to_sip_volume_ratio' => $observedMaximumVolumeRatio,
            ],
            'canonical_sha256' => [
                'frozen_sip' => $this->canonicalHash($frozenAudit),
                'audit_iex' => $this->canonicalHash($iexAudit),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $symbols
     * @return array<string, list<Bar>>
     */
    private function validatePayload(
        array $payload,
        array $symbols,
        string $minimumSession,
        string $maximumSession,
        string $label,
    ): array {
        $actualSymbols = array_keys($payload);
        if (count(array_filter($actualSymbols, 'is_string')) !== count($actualSymbols)) {
            throw new \RuntimeException(ucfirst($label) . ' contains a non-string symbol key.');
        }
        $actualSymbols = array_map(static fn (string $symbol): string => strtoupper(trim($symbol)), $actualSymbols);
        sort($actualSymbols, SORT_STRING);
        if ($actualSymbols !== $symbols) {
            throw new \RuntimeException(sprintf(
                '%s symbol set mismatch: expected %s, got %s.',
                ucfirst($label),
                implode(',', $symbols),
                implode(',', $actualSymbols),
            ));
        }

        $validated = [];
        foreach ($symbols as $symbol) {
            $series = $payload[$symbol] ?? null;
            if (!is_array($series) || !array_is_list($series)) {
                throw new \RuntimeException(ucfirst($label) . ' series is malformed for ' . $symbol . '.');
            }
            $previousSession = null;
            $validated[$symbol] = [];
            foreach ($series as $bar) {
                if (!$bar instanceof Bar) {
                    throw new \RuntimeException(ucfirst($label) . ' contains a non-Bar row for ' . $symbol . '.');
                }
                if ($bar->symbol !== $symbol) {
                    throw new \RuntimeException(sprintf(
                        '%s symbol mismatch: key %s contains %s.',
                        ucfirst($label),
                        $symbol,
                        $bar->symbol,
                    ));
                }
                $session = $this->session($bar);
                if ($session < $minimumSession || $session > $maximumSession) {
                    throw new \RuntimeException(sprintf(
                        '%s returned out-of-range session %s for %s; allowed %s..%s.',
                        ucfirst($label),
                        $session,
                        $symbol,
                        $minimumSession,
                        $maximumSession,
                    ));
                }
                if ($previousSession !== null && $session <= $previousSession) {
                    throw new \RuntimeException(sprintf(
                        '%s sessions are duplicated or out of order for %s at %s.',
                        ucfirst($label),
                        $symbol,
                        $session,
                    ));
                }
                $this->assertValidOhlcv($bar, $label);
                $validated[$symbol][] = $bar;
                $previousSession = $session;
            }
        }

        return $validated;
    }

    /** @param array<string, list<Bar>> $recent @param list<string> $symbols */
    private function assertIdenticalFreshSessions(array $recent, array $symbols): void
    {
        $expected = null;
        foreach ($symbols as $symbol) {
            $sessions = array_map(fn (Bar $bar): string => $this->session($bar), $recent[$symbol]);
            if ($expected === null) {
                if ($sessions === []) {
                    throw new \RuntimeException('Recent IEX tail contains no completed sessions.');
                }
                $expected = $sessions;
                continue;
            }
            if ($sessions !== $expected) {
                throw new \RuntimeException('Recent IEX session coverage differs across requested symbols.');
            }
        }
    }

    private function assertValidOhlcv(Bar $bar, string $label): void
    {
        foreach ([$bar->open, $bar->high, $bar->low, $bar->close] as $price) {
            if (!is_finite($price) || $price <= 0.0) {
                throw new \RuntimeException(ucfirst($label) . ' contains a non-positive or non-finite price for ' . $bar->symbol . '.');
            }
        }
        if ($bar->high < max($bar->open, $bar->close)
            || $bar->low > min($bar->open, $bar->close)
            || !is_finite($bar->volume)
            || $bar->volume < 0.0) {
            throw new \RuntimeException(ucfirst($label) . ' contains invalid OHLCV geometry for ' . $bar->symbol . '.');
        }
    }

    /** @param list<Bar> $bars */
    private function lastSession(array $bars): ?string
    {
        return $bars === [] ? null : $this->session($bars[array_key_last($bars)]);
    }

    private function session(Bar $bar): string
    {
        return $bar->time
            ->setTimezone(new \DateTimeZone(self::MARKET_TIMEZONE))
            ->format('Y-m-d');
    }

    /** @param array<string, list<Bar>> $barsBySymbol @return array<string, array<string, int|string|null>> */
    private function coverage(array $barsBySymbol): array
    {
        $coverage = [];
        ksort($barsBySymbol, SORT_STRING);
        foreach ($barsBySymbol as $symbol => $bars) {
            $coverage[$symbol] = [
                'bars' => count($bars),
                'first_session' => $bars === [] ? null : $this->session($bars[0]),
                'last_session' => $this->lastSession($bars),
            ];
        }

        return $coverage;
    }

    /** @param array<string, list<Bar>> $barsBySymbol */
    private function canonicalHash(array $barsBySymbol): string
    {
        ksort($barsBySymbol, SORT_STRING);
        $hash = hash_init('sha256');
        foreach ($barsBySymbol as $symbol => $bars) {
            hash_update($hash, 'symbol=' . $symbol . "\n");
            foreach ($bars as $bar) {
                hash_update($hash, implode('|', [
                    $symbol,
                    $bar->time->format(\DateTimeInterface::ATOM),
                    sprintf('%.10F', $bar->open),
                    sprintf('%.10F', $bar->high),
                    sprintf('%.10F', $bar->low),
                    sprintf('%.10F', $bar->close),
                    sprintf('%.4F', $bar->volume),
                ]) . "\n");
            }
        }

        return hash_final($hash);
    }

    /** @param list<string> $symbols @return list<string> */
    private function canonicalSymbols(array $symbols): array
    {
        $canonical = [];
        foreach ($symbols as $symbol) {
            $symbol = strtoupper(trim($symbol));
            if ($symbol === '' || preg_match('/^[A-Z0-9.\-]+$/D', $symbol) !== 1) {
                throw new \InvalidArgumentException('Stitched market-data request contains an invalid symbol.');
            }
            $canonical[$symbol] = true;
        }
        if ($canonical === []) {
            throw new \InvalidArgumentException('Stitched market-data request must contain at least one symbol.');
        }
        $symbols = array_keys($canonical);
        sort($symbols, SORT_STRING);

        return $symbols;
    }

    private static function canonicalDate(string $value, string $label): string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            throw new \InvalidArgumentException($label . ' must use YYYY-MM-DD.');
        }
        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d',
            $value,
            new \DateTimeZone(self::MARKET_TIMEZONE),
        );
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException($label . ' is not a valid calendar date.');
        }

        return $value;
    }

    /** @param array<string,mixed> $contract @return array<string,mixed> */
    private static function normalizeAuditContract(array $contract): array
    {
        if ($contract === [] || ($contract['enabled'] ?? false) === false) {
            return [
                'mode' => 'disabled',
                'enabled' => false,
            ];
        }
        if (($contract['enabled'] ?? null) !== true
            || ($contract['mode'] ?? null) !== 'audit_only_cutoff_overlap_v1'
            || ($contract['require_all_symbols'] ?? null) !== true) {
            throw new \InvalidArgumentException('Cross-feed audit must use the fail-closed cutoff-only contract.');
        }
        $sessions = $contract['sessions'] ?? null;
        if (!is_int($sessions) || $sessions < 2 || $sessions > 20) {
            throw new \InvalidArgumentException('Cross-feed audit sessions must be an integer from 2 through 20.');
        }
        $prices = $contract['price_tolerance_bps'] ?? null;
        if (!is_array($prices) || array_keys($prices) !== ['open', 'high', 'low', 'close']) {
            throw new \InvalidArgumentException('Cross-feed audit requires exact OHLC tolerance fields.');
        }
        foreach ($prices as $value) {
            if (!is_int($value) && !is_float($value)) {
                throw new \InvalidArgumentException('Cross-feed audit price tolerances must be numeric.');
            }
            $value = (float) $value;
            if (!is_finite($value) || $value <= 0.0 || $value > 1000.0) {
                throw new \InvalidArgumentException('Cross-feed audit price tolerance is outside the safe envelope.');
            }
        }
        $minimumVolume = $contract['minimum_iex_to_sip_volume_ratio'] ?? null;
        $maximumVolume = $contract['maximum_iex_to_sip_volume_ratio'] ?? null;
        if ((!is_int($minimumVolume) && !is_float($minimumVolume))
            || (!is_int($maximumVolume) && !is_float($maximumVolume))) {
            throw new \InvalidArgumentException('Cross-feed audit volume tolerances must be numeric.');
        }
        $minimumVolume = (float) $minimumVolume;
        $maximumVolume = (float) $maximumVolume;
        if (!is_finite($minimumVolume) || !is_finite($maximumVolume)
            || $minimumVolume <= 0.0 || $maximumVolume <= $minimumVolume || $maximumVolume > 2.0) {
            throw new \InvalidArgumentException('Cross-feed audit volume tolerances are outside the safe envelope.');
        }

        return [
            'mode' => 'audit_only_cutoff_overlap_v1',
            'enabled' => true,
            'sessions' => $sessions,
            'price_tolerance_bps' => array_map('floatval', $prices),
            'minimum_iex_to_sip_volume_ratio' => $minimumVolume,
            'maximum_iex_to_sip_volume_ratio' => $maximumVolume,
            'require_all_symbols' => true,
        ];
    }
}
