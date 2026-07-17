<?php

declare(strict_types=1);

use FulltimeTrading\Data\FrozenSipIexDailyBarsProvider;
use FulltimeTrading\Data\MarketDataProvider;
use FulltimeTrading\Data\VerifiedCacheSnapshotMarketDataProvider;
use FulltimeTrading\Domain\Bar;

require __DIR__ . '/../bootstrap.php';

function stitchedAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function stitchedExpectFailure(callable $callback, string $messageFragment, string $message): void
{
    try {
        $callback();
    } catch (Throwable $e) {
        stitchedAssert(
            str_contains($e->getMessage(), $messageFragment),
            $message . ' Unexpected error: ' . $e->getMessage(),
        );

        return;
    }

    throw new RuntimeException($message . ' Expected an exception.');
}

function stitchedBar(
    string $symbol,
    string $session,
    float $open = 100.0,
    float $high = 102.0,
    float $low = 99.0,
    float $close = 101.0,
    float $volume = 1_000_000.0,
): Bar {
    return new Bar(
        $symbol,
        new DateTimeImmutable($session . ' 00:00:00', new DateTimeZone('America/New_York')),
        $open,
        $high,
        $low,
        $close,
        $volume,
    );
}

/** @param array<string, list<Bar>> $bars */
function stitchedPayload(array $bars): array
{
    $payload = [];
    foreach ($bars as $symbol => $series) {
        $payload[$symbol] = array_map(static fn (Bar $bar): array => [
            'symbol' => $bar->symbol,
            'time' => $bar->time->format(DateTimeInterface::ATOM),
            'open' => $bar->open,
            'high' => $bar->high,
            'low' => $bar->low,
            'close' => $bar->close,
            'volume' => $bar->volume,
        ], $series);
    }

    return $payload;
}

/**
 * @param array<string, list<Bar>> $bars
 * @param list<string> $symbols
 * @return array{provider:VerifiedCacheSnapshotMarketDataProvider,file:string,sha256:string}
 */
function stitchedFrozenProvider(
    string $directory,
    string $namespace,
    array $bars,
    array $symbols,
    string $start = '2026-07-14',
    string $cutoff = '2026-07-15',
): array {
    $symbols = array_values(array_unique(array_map('strtoupper', $symbols)));
    sort($symbols, SORT_STRING);
    $file = $directory . '/' . sha1(
        $namespace . '|' . implode(',', $symbols) . '|1Day|' . $start . '|' . $cutoff,
    ) . '.json';
    $json = json_encode(stitchedPayload($bars), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($file, $json) === false) {
        throw new RuntimeException('Unable to write frozen cache fixture.');
    }
    $sha256 = hash('sha256', $json);

    return [
        'provider' => new VerifiedCacheSnapshotMarketDataProvider(
            $directory,
            $namespace,
            $sha256,
            'Alpaca',
            'sip',
            'split',
        ),
        'file' => $file,
        'sha256' => $sha256,
    ];
}

final class StitchedFixtureProvider implements MarketDataProvider
{
    /** @var list<array{symbols:list<string>,timeframe:string,start:string,end:string}> */
    public array $calls = [];

    /** @param array<string, list<Bar>>|Throwable $result */
    public function __construct(private array|Throwable $result)
    {
    }

    public function getBars(array $symbols, string $timeframe, string $start, string $end): array
    {
        $this->calls[] = compact('symbols', 'timeframe', 'start', 'end');
        if ($this->result instanceof Throwable) {
            throw $this->result;
        }

        return $this->result;
    }
}

final class StitchedRangeFixtureProvider implements MarketDataProvider
{
    /** @var list<array{symbols:list<string>,timeframe:string,start:string,end:string}> */
    public array $calls = [];

    /** @param array<string,array<string,list<Bar>>|Throwable> $resultsByRange */
    public function __construct(private array $resultsByRange)
    {
    }

    public function getBars(array $symbols, string $timeframe, string $start, string $end): array
    {
        $this->calls[] = compact('symbols', 'timeframe', 'start', 'end');
        $result = $this->resultsByRange[$start . '|' . $end] ?? null;
        if ($result instanceof Throwable) {
            throw $result;
        }
        if (!is_array($result)) {
            throw new RuntimeException('Unexpected stitched fixture range ' . $start . '..' . $end . '.');
        }

        return $result;
    }
}

$tempDir = sys_get_temp_dir() . '/ftt-frozen-sip-iex-' . bin2hex(random_bytes(6));
if (!mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
    throw new RuntimeException('Unable to create stitched market-data test directory.');
}

try {
    $symbols = ['AAA', 'BBB'];
    $frozenBars = [
        'AAA' => [stitchedBar('AAA', '2026-07-14'), stitchedBar('AAA', '2026-07-15', 101, 103, 100, 102)],
        'BBB' => [stitchedBar('BBB', '2026-07-14', 50, 51, 49, 50.5), stitchedBar('BBB', '2026-07-15', 50.5, 52, 50, 51.5)],
    ];
    $freshBars = [
        'AAA' => [stitchedBar('AAA', '2026-07-16', 102, 104, 101, 103), stitchedBar('AAA', '2026-07-17', 103, 105, 102, 104)],
        'BBB' => [stitchedBar('BBB', '2026-07-16', 51.5, 53, 51, 52), stitchedBar('BBB', '2026-07-17', 52, 54, 51, 53)],
    ];
    $auditBars = [
        'AAA' => [
            stitchedBar('AAA', '2026-07-14', 100.20, 102.10, 99.10, 101.05, 30_000),
            stitchedBar('AAA', '2026-07-15', 101.10, 103.10, 100.10, 102.04, 35_000),
        ],
        'BBB' => [
            stitchedBar('BBB', '2026-07-14', 50.05, 51.05, 49.05, 50.51, 25_000),
            stitchedBar('BBB', '2026-07-15', 50.55, 52.05, 50.05, 51.51, 27_000),
        ],
    ];
    $auditContract = [
        'mode' => 'audit_only_cutoff_overlap_v1',
        'enabled' => true,
        'sessions' => 2,
        'price_tolerance_bps' => [
            'open' => 150.0,
            'high' => 150.0,
            'low' => 150.0,
            'close' => 50.0,
        ],
        'minimum_iex_to_sip_volume_ratio' => 0.001,
        'maximum_iex_to_sip_volume_ratio' => 0.50,
        'require_all_symbols' => true,
    ];
    $frozen = stitchedFrozenProvider($tempDir, 'sip-happy', $frozenBars, $symbols);
    $iex = new StitchedRangeFixtureProvider([
        '2026-07-14|2026-07-15' => $auditBars,
        '2026-07-16|2026-07-17' => $freshBars,
    ]);
    $provider = new FrozenSipIexDailyBarsProvider(
        $frozen['provider'],
        $iex,
        '2026-07-15',
        'iex-tail',
        $auditContract,
    );

    stitchedExpectFailure(
        static fn (): array => $provider->provenance(),
        'unavailable before a successful read',
        'Provenance must fail closed before a successful read.',
    );
    $merged = $provider->getBars(['bbb', 'AAA', 'AAA'], '1Day', '2026-07-14', '2026-07-17');
    stitchedAssert(array_keys($merged) === ['AAA', 'BBB'], 'Merged symbols must have a deterministic canonical order.');
    stitchedAssert(count($merged['AAA']) === 4 && count($merged['BBB']) === 4, 'Both source segments must be joined exactly once.');
    stitchedAssert(
        array_map(static fn (Bar $bar): string => $bar->time->format('Y-m-d'), $merged['AAA'])
            === ['2026-07-14', '2026-07-15', '2026-07-16', '2026-07-17'],
        'The SIP/IEX boundary must remain strictly ordered and gap-free in the fixture.',
    );
    stitchedAssert(
        $iex->calls === [
            [
                'symbols' => ['AAA', 'BBB'],
                'timeframe' => '1Day',
                'start' => '2026-07-14',
                'end' => '2026-07-15',
            ],
            [
                'symbols' => ['AAA', 'BBB'],
                'timeframe' => '1Day',
                'start' => '2026-07-16',
                'end' => '2026-07-17',
            ],
        ],
        'IEX audit and decision-tail reads must remain disjoint at the inclusive SIP cutoff.',
    );
    $provenance = $provider->provenance();
    stitchedAssert(
        ($provenance['segments']['frozen_sip']['sha256'] ?? null) === $frozen['sha256']
            && ($provenance['segments']['frozen_sip']['feed'] ?? null) === 'sip'
            && ($provenance['segments']['recent_iex']['feed'] ?? null) === 'iex'
            && ($provenance['boundary']['overlap_policy'] ?? null) === 'reject'
            && ($provenance['cross_feed_audit']['passed'] ?? null) === true
            && ($provenance['cross_feed_audit']['decision_data_usage'] ?? null) === 'none'
            && ($provenance['cross_feed_audit']['used_for_merged_bars'] ?? null) === false
            && ($provenance['cross_feed_audit']['compared_bars'] ?? null) === 4
            && ($provenance['merged']['effective_completed_session'] ?? null) === '2026-07-17',
        'Provenance must expose immutable decision feeds plus a passed, audit-only cutoff overlap.',
    );
    $firstHash = (string) $provenance['merged']['canonical_sha256'];
    $provider->getBars(['AAA', 'BBB'], '1Day', '2026-07-14', '2026-07-17');
    stitchedAssert(
        $provider->provenance()['merged']['canonical_sha256'] === $firstHash,
        'Identical inputs must produce a stable canonical merged hash.',
    );

    $noTail = new StitchedFixtureProvider(new RuntimeException('must not be called'));
    $historical = new FrozenSipIexDailyBarsProvider(
        stitchedFrozenProvider($tempDir, 'sip-historical', $frozenBars, $symbols)['provider'],
        $noTail,
        '2026-07-15',
        'iex-unused',
        $auditContract,
    );
    $historicalBars = $historical->getBars($symbols, '1Day', '2026-07-14', '2026-07-14');
    stitchedAssert(
        count($historicalBars['AAA']) === 1
            && $noTail->calls === []
            && $historical->provenance()['segments']['recent_iex']['used'] === false
            && $historical->provenance()['cross_feed_audit']['used'] === false
            && $historical->provenance()['cross_feed_audit']['skip_reason'] === 'request_ends_before_audit_cutoff',
        'A pre-cutoff replay must neither consume future IEX decisions nor be gated by a later overlap audit.',
    );

    $badAuditBars = $auditBars;
    $badAuditBars['AAA'][1] = stitchedBar('AAA', '2026-07-15', 101.1, 110.0, 100.1, 108.0, 35_000);
    $badAudit = new FrozenSipIexDailyBarsProvider(
        stitchedFrozenProvider($tempDir, 'sip-bad-audit', $frozenBars, $symbols)['provider'],
        new StitchedRangeFixtureProvider([
            '2026-07-14|2026-07-15' => $badAuditBars,
            '2026-07-16|2026-07-17' => $freshBars,
        ]),
        '2026-07-15',
        'iex-audit-failure',
        $auditContract,
    );
    stitchedExpectFailure(
        static fn (): array => $badAudit->getBars($symbols, '1Day', '2026-07-14', '2026-07-17'),
        'Cross-feed audit price tolerance exceeded',
        'An audit-only feed mismatch must veto the signal without substituting IEX for SIP.',
    );
    stitchedExpectFailure(
        static fn (): array => $badAudit->provenance(),
        'unavailable before a successful read',
        'A failed overlap audit must not publish trusted provenance.',
    );

    $wrongHash = new VerifiedCacheSnapshotMarketDataProvider(
        $tempDir,
        'sip-happy',
        str_repeat('0', 64),
        'Alpaca',
        'sip',
        'split',
    );
    stitchedExpectFailure(
        static fn (): array => $wrongHash->getBars($symbols, '1Day', '2026-07-14', '2026-07-15'),
        'SHA-256 mismatch',
        'A changed frozen snapshot must never be accepted.',
    );
    $wrongFeed = new FrozenSipIexDailyBarsProvider(
        new VerifiedCacheSnapshotMarketDataProvider(
            $tempDir,
            'sip-happy',
            $frozen['sha256'],
            'Alpaca',
            'iex',
            'split',
        ),
        new StitchedFixtureProvider($freshBars),
        '2026-07-15',
        'iex-tail',
    );
    stitchedExpectFailure(
        static fn (): array => $wrongFeed->getBars($symbols, '1Day', '2026-07-14', '2026-07-17'),
        'must be exact Alpaca SIP/split',
        'A frozen cache labelled as another feed must fail closed.',
    );
    stitchedExpectFailure(
        static fn (): array => (new VerifiedCacheSnapshotMarketDataProvider(
            $tempDir,
            'missing-snapshot',
            str_repeat('0', 64),
            'Alpaca',
            'sip',
            'split',
        ))->getBars($symbols, '1Day', '2026-07-14', '2026-07-15'),
        'missing',
        'A missing exact-key frozen snapshot must fail closed.',
    );
    stitchedExpectFailure(
        static fn (): array => $provider->getBars($symbols, '1Min', '2026-07-14', '2026-07-17'),
        'daily bars only',
        'The stitching provider must reject non-daily data.',
    );
    stitchedExpectFailure(
        static fn (): array => $provider->getBars($symbols, '1Day', '2026-07-16', '2026-07-17'),
        'include the frozen SIP boundary',
        'A request that bypasses the frozen boundary must fail closed.',
    );

    $missingCutoffBars = [
        'AAA' => [stitchedBar('AAA', '2026-07-14')],
        'BBB' => [stitchedBar('BBB', '2026-07-14')],
    ];
    $missingCutoff = new FrozenSipIexDailyBarsProvider(
        stitchedFrozenProvider($tempDir, 'sip-missing-cutoff', $missingCutoffBars, $symbols)['provider'],
        new StitchedFixtureProvider($freshBars),
        '2026-07-15',
        'iex-tail',
    );
    stitchedExpectFailure(
        static fn (): array => $missingCutoff->getBars($symbols, '1Day', '2026-07-14', '2026-07-17'),
        'configured cutoff',
        'Every frozen series must prove that it reaches the configured cutoff.',
    );

    $boundaryOverlap = [
        'AAA' => [stitchedBar('AAA', '2026-07-15'), stitchedBar('AAA', '2026-07-16'), stitchedBar('AAA', '2026-07-17')],
        'BBB' => [stitchedBar('BBB', '2026-07-15'), stitchedBar('BBB', '2026-07-16'), stitchedBar('BBB', '2026-07-17')],
    ];
    $overlapProvider = new FrozenSipIexDailyBarsProvider(
        stitchedFrozenProvider($tempDir, 'sip-overlap', $frozenBars, $symbols)['provider'],
        new StitchedFixtureProvider($boundaryOverlap),
        '2026-07-15',
        'iex-tail',
    );
    stitchedExpectFailure(
        static fn (): array => $overlapProvider->getBars($symbols, '1Day', '2026-07-14', '2026-07-17'),
        'out-of-range session 2026-07-15',
        'An IEX row on the SIP cutoff must be rejected instead of silently winning an overlap.',
    );

    $unevenFresh = $freshBars;
    array_pop($unevenFresh['BBB']);
    $unevenProvider = new FrozenSipIexDailyBarsProvider(
        stitchedFrozenProvider($tempDir, 'sip-uneven', $frozenBars, $symbols)['provider'],
        new StitchedFixtureProvider($unevenFresh),
        '2026-07-15',
        'iex-tail',
    );
    stitchedExpectFailure(
        static fn (): array => $unevenProvider->getBars($symbols, '1Day', '2026-07-14', '2026-07-17'),
        'coverage differs',
        'Fresh session coverage must be identical for every ranked symbol.',
    );

    $staleFresh = [
        'AAA' => [stitchedBar('AAA', '2026-07-16')],
        'BBB' => [stitchedBar('BBB', '2026-07-16')],
    ];
    $staleProvider = new FrozenSipIexDailyBarsProvider(
        stitchedFrozenProvider($tempDir, 'sip-stale', $frozenBars, $symbols)['provider'],
        new StitchedFixtureProvider($staleFresh),
        '2026-07-15',
        'iex-tail',
    );
    stitchedExpectFailure(
        static fn (): array => $staleProvider->getBars($symbols, '1Day', '2026-07-14', '2026-07-17'),
        'required completed session is 2026-07-17',
        'A uniformly stale IEX response must not create a stale fresh signal.',
    );

    $duplicateFresh = $freshBars;
    $duplicateFresh['AAA'][] = stitchedBar('AAA', '2026-07-17');
    $duplicateProvider = new FrozenSipIexDailyBarsProvider(
        stitchedFrozenProvider($tempDir, 'sip-duplicate', $frozenBars, $symbols)['provider'],
        new StitchedFixtureProvider($duplicateFresh),
        '2026-07-15',
        'iex-tail',
    );
    stitchedExpectFailure(
        static fn (): array => $duplicateProvider->getBars($symbols, '1Day', '2026-07-14', '2026-07-17'),
        'duplicated or out of order',
        'Duplicate fresh sessions must fail closed.',
    );

    $badOhlc = $freshBars;
    $badOhlc['AAA'][1] = stitchedBar('AAA', '2026-07-17', 106, 105, 102, 104);
    $badOhlcProvider = new FrozenSipIexDailyBarsProvider(
        stitchedFrozenProvider($tempDir, 'sip-bad-ohlc', $frozenBars, $symbols)['provider'],
        new StitchedFixtureProvider($badOhlc),
        '2026-07-15',
        'iex-tail',
    );
    stitchedExpectFailure(
        static fn (): array => $badOhlcProvider->getBars($symbols, '1Day', '2026-07-14', '2026-07-17'),
        'invalid OHLCV geometry',
        'Impossible OHLCV geometry must fail closed.',
    );

    $networkFailure = new FrozenSipIexDailyBarsProvider(
        stitchedFrozenProvider($tempDir, 'sip-network', $frozenBars, $symbols)['provider'],
        new StitchedFixtureProvider(new RuntimeException('network down')),
        '2026-07-15',
        'iex-tail',
    );
    stitchedExpectFailure(
        static fn (): array => $networkFailure->getBars($symbols, '1Day', '2026-07-14', '2026-07-17'),
        'unavailable',
        'An IEX transport failure must fail the fresh signal.',
    );
    stitchedExpectFailure(
        static fn (): array => $networkFailure->provenance(),
        'unavailable before a successful read',
        'A failed refresh must not expose stale provenance.',
    );
} finally {
    foreach (glob($tempDir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($tempDir);
}

echo "Frozen SIP/IEX daily bars provider OK\n";
