<?php

declare(strict_types=1);

use FulltimeTrading\Data\AlpacaIntradaySnapshotStore;

require __DIR__ . '/../bootstrap.php';

function snapshotToolAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function snapshotToolSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%s: expected %s, got %s',
            $message,
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}

function snapshotToolFailure(callable $callback, string $contains, string $message): void
{
    try {
        $callback();
    } catch (Throwable $e) {
        snapshotToolAssert(str_contains($e->getMessage(), $contains), $message . ': wrong failure: ' . $e->getMessage());

        return;
    }

    throw new RuntimeException($message . ': no failure was raised.');
}

/** @return array<string, mixed> */
function snapshotToolBar(string $time, float $open = 100.0, float $high = 101.0, float $low = 99.0, float $close = 100.5): array
{
    return ['t' => $time, 'o' => $open, 'h' => $high, 'l' => $low, 'c' => $close, 'v' => 1234];
}

function snapshotToolRemoveDirectory(string $path): void
{
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        $target = $path . '/' . $entry;
        if (is_dir($target)) {
            snapshotToolRemoveDirectory($target);
        } else {
            unlink($target);
        }
    }
    rmdir($path);
}

$expectedKey = sha1('causal-fixture|SOXL|5Min|2021-01-01|2022-01-01');
snapshotToolSame(
    $expectedKey,
    AlpacaIntradaySnapshotStore::canonicalCacheKey('causal-fixture', 'soxl', '5Min', '2021-01-01', '2022-01-01'),
    'Date-only canonical cache key changed.',
);
snapshotToolSame(
    $expectedKey,
    AlpacaIntradaySnapshotStore::canonicalCacheKey(
        'causal-fixture',
        'SOXL',
        '5Min',
        '2021-01-01T00:00:00Z',
        '2022-01-01T00:00:00+00:00',
    ),
    'RFC3339 API boundaries must map to the same date-only canonical cache key.',
);
snapshotToolSame('2021-01-01T00:00:00Z', AlpacaIntradaySnapshotStore::apiBoundary('2021-01-01'), 'API boundary must be explicit RFC3339.');
snapshotToolFailure(
    static fn (): string => AlpacaIntradaySnapshotStore::canonicalDate('2021-01-01T00:00:01Z'),
    'UTC-midnight',
    'A non-midnight timestamp must not be silently collapsed into a cache date.',
);
snapshotToolSame([
    ['start' => '2021-06-15', 'end' => '2022-01-01'],
    ['start' => '2022-01-01', 'end' => '2023-01-01'],
    ['start' => '2023-01-01', 'end' => '2023-02-10'],
], AlpacaIntradaySnapshotStore::yearlyRanges('2021-06-15', '2023-02-10'), 'Yearly chunk boundaries mismatch.');

$rowsByTimestamp = [];
$merge = AlpacaIntradaySnapshotStore::mergeApiRows($rowsByTimestamp, 'SOXL', [
    snapshotToolBar('2020-12-31T23:55:00Z'),
    snapshotToolBar('2021-01-01T00:00:00Z'),
    snapshotToolBar('2021-12-31T23:55:00Z'),
    snapshotToolBar('2022-01-01T00:00:00Z'), // Alpaca may include end; cache must not.
], '2021-01-01', '2022-01-01');
snapshotToolSame(2, $merge['accepted'], 'In-range bar count mismatch.');
snapshotToolSame(2, $merge['filtered_out_of_bounds'], 'Both pre-start and exact-end bars must be removed.');
snapshotToolSame([
    '2021-01-01T00:00:00+00:00',
    '2021-12-31T23:55:00+00:00',
], array_keys($rowsByTimestamp), 'Strict [start,end) filtering mismatch.');

$duplicate = AlpacaIntradaySnapshotStore::mergeApiRows(
    $rowsByTimestamp,
    'SOXL',
    [snapshotToolBar('2021-01-01T00:00:00Z')],
    '2021-01-01',
    '2022-01-01',
);
snapshotToolSame(1, $duplicate['identical_duplicates'], 'Identical page overlap must be counted and deduplicated.');
snapshotToolFailure(
    static function () use (&$rowsByTimestamp): void {
        AlpacaIntradaySnapshotStore::mergeApiRows(
            $rowsByTimestamp,
            'SOXL',
            [snapshotToolBar('2021-01-01T00:00:00Z', 100.0, 102.0, 99.0, 101.5)],
            '2021-01-01',
            '2022-01-01',
        );
    },
    'Conflicting Alpaca bar',
    'Disagreeing duplicate timestamps must fail closed.',
);
snapshotToolFailure(
    static fn (): array => AlpacaIntradaySnapshotStore::finalizeRows([], 'SOXL', '2021-01-01', '2022-01-01'),
    'empty chunk',
    'An empty downloaded chunk must fail closed.',
);

$densityAccumulator = AlpacaIntradaySnapshotStore::newRegularSessionAccumulator();
AlpacaIntradaySnapshotStore::accumulateRegularSessionRows($densityAccumulator, [
    ['time' => '2024-11-29T17:55:00+00:00'], // 12:55 ET: regular on the 13:00 half day.
    ['time' => '2024-11-29T18:00:00+00:00'], // 13:00 ET: outside the half-day regular session.
    ['time' => '2024-12-02T14:30:00+00:00'],
    ['time' => '2024-12-02T14:35:00+00:00'],
    ['time' => '2024-12-02T14:50:00+00:00'],
]);
$density = AlpacaIntradaySnapshotStore::regularSessionDiagnostics($densityAccumulator, '5Min');
snapshotToolSame(5, $density['total_bars'], 'Density total bar count mismatch.');
snapshotToolSame(4, $density['regular_session_bars'], 'Early-close-aware regular bar count mismatch.');
snapshotToolSame(1, $density['non_regular_session_bars_filtered'], 'The 13:00 half-day bar must be excluded.');
snapshotToolSame(2, $density['observed_regular_sessions'], 'Observed regular-session count mismatch.');
snapshotToolSame(1, $density['special_close_sessions_observed'], 'Observed half-day count mismatch.');
snapshotToolSame(['2024-11-29'], $density['special_close_session_dates'], 'Observed half-day date mismatch.');
snapshotToolSame(2.0, $density['median_regular_bars_per_observed_session'], 'Bars/session median mismatch.');
snapshotToolSame(14.0, $density['p90_within_session_gap_minutes'], 'Within-session p90 gap mismatch.');
snapshotToolSame(true, $density['sparse_gap_warning'], 'Sparse p90 gap must be visible in the manifest diagnostics.');

$tempDir = sys_get_temp_dir() . '/ftt-alpaca-snapshot-tool-' . bin2hex(random_bytes(8));
if (!mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
    throw new RuntimeException('Unable to create test directory.');
}
try {
    $rows = AlpacaIntradaySnapshotStore::finalizeRows($rowsByTimestamp, 'SOXL', '2021-01-01', '2022-01-01');
    $cacheFile = AlpacaIntradaySnapshotStore::cacheFile(
        $tempDir,
        'causal-fixture',
        'SOXL',
        '5Min',
        '2021-01-01',
        '2022-01-01',
    );
    snapshotToolSame($tempDir . '/' . $expectedKey . '.json', $cacheFile, 'Canonical cache path mismatch.');
    $metadata = AlpacaIntradaySnapshotStore::writeCacheFileAtomically(
        $cacheFile,
        'SOXL',
        $rows,
        '2021-01-01',
        '2022-01-01',
    );
    snapshotToolSame(2, $metadata['count'], 'Written cache count mismatch.');
    snapshotToolSame(filesize($cacheFile), $metadata['size_bytes'], 'Written cache size mismatch.');
    snapshotToolSame(hash_file('sha256', $cacheFile), $metadata['sha256'], 'Written cache checksum mismatch.');
    snapshotToolSame(
        $metadata,
        AlpacaIntradaySnapshotStore::verifyCacheFile($cacheFile, 'SOXL', '2021-01-01', '2022-01-01'),
        'Cache readback verification is not stable.',
    );

    $provenanceFile = AlpacaIntradaySnapshotStore::provenanceFile(
        $tempDir,
        'causal-fixture',
        'SOXL',
        '5Min',
        '2021-01-01',
        '2022-01-01',
    );
    snapshotToolSame(
        $tempDir . '/' . $expectedKey . '.provenance',
        $provenanceFile,
        'Canonical provenance sidecar path mismatch.',
    );
    $iexRequest = AlpacaIntradaySnapshotStore::chunkProvenanceRequest(
        'https://data.alpaca.markets/v2/stocks/bars',
        'causal-fixture',
        'SOXL',
        '5Min',
        'iex',
        'split',
        10000,
        '2021-01-01',
        '2022-01-01',
    );
    $iexProvenance = AlpacaIntradaySnapshotStore::chunkProvenancePayload(
        $iexRequest,
        basename($cacheFile),
        $metadata,
    );
    $provenanceMetadata = AlpacaIntradaySnapshotStore::writeChunkProvenanceAtomically(
        $provenanceFile,
        $iexProvenance,
    );
    snapshotToolSame(filesize($provenanceFile), $provenanceMetadata['size_bytes'], 'Provenance sidecar size mismatch.');
    snapshotToolSame(hash_file('sha256', $provenanceFile), $provenanceMetadata['sha256'], 'Provenance sidecar checksum mismatch.');
    snapshotToolSame(
        $provenanceMetadata,
        AlpacaIntradaySnapshotStore::readVerifiedChunkProvenance($provenanceFile, $iexProvenance)['metadata'],
        'Exact provenance sidecar readback is not stable.',
    );
    snapshotToolAssert(
        !in_array($provenanceFile, glob($tempDir . '/*.json') ?: [], true),
        'Provenance sidecar must stay outside the legacy cache provider JSON scan.',
    );

    $sipRequest = AlpacaIntradaySnapshotStore::chunkProvenanceRequest(
        'https://data.alpaca.markets/v2/stocks/bars',
        'causal-fixture',
        'SOXL',
        '5Min',
        'sip',
        'split',
        10000,
        '2021-01-01',
        '2022-01-01',
    );
    $sipProvenance = AlpacaIntradaySnapshotStore::chunkProvenancePayload(
        $sipRequest,
        basename($cacheFile),
        $metadata,
    );
    snapshotToolFailure(
        static fn (): array => AlpacaIntradaySnapshotStore::readVerifiedChunkProvenance(
            $provenanceFile,
            $sipProvenance,
        ),
        'does not exactly match',
        'An IEX provenance sidecar must not allow the same cache payload to be reused as SIP.',
    );

    $duplicateFile = $tempDir . '/duplicate.json';
    AlpacaIntradaySnapshotStore::writeJsonAtomically($duplicateFile, ['SOXL' => [$rows[0], $rows[0]]]);
    snapshotToolFailure(
        static fn (): array => AlpacaIntradaySnapshotStore::verifyCacheFile(
            $duplicateFile,
            'SOXL',
            '2021-01-01',
            '2022-01-01',
        ),
        'duplicate',
        'Cache readback must reject duplicate timestamps.',
    );

    $manifestFile = AlpacaIntradaySnapshotStore::manifestFile(
        $tempDir,
        'causal-fixture',
        ['SOXL'],
        '5Min',
        '2021-01-01',
        '2022-01-01',
    );
    $manifest = [
        'schema_version' => AlpacaIntradaySnapshotStore::SCHEMA_VERSION,
        'complete' => true,
        'generated_at' => '2026-07-16T00:00:00+00:00',
        'request' => [
            'symbol' => 'SOXL',
            'start' => '2021-01-01T00:00:00Z',
            'end_exclusive' => '2022-01-01T00:00:00Z',
            'feed' => 'iex',
            'adjustment' => 'split',
            'timeframe' => '5Min',
        ],
        'totals' => ['chunks' => 1, 'bars' => $metadata['count'], 'size_bytes' => $metadata['size_bytes']],
        'chunks' => [[
            'file' => basename($cacheFile),
            'count' => $metadata['count'],
            'size_bytes' => $metadata['size_bytes'],
            'sha256' => $metadata['sha256'],
            'request' => ['symbol' => 'SOXL', 'bounds' => '[start,end)'],
            'provenance_file' => basename($provenanceFile),
            'provenance_size_bytes' => $provenanceMetadata['size_bytes'],
            'provenance_sha256' => $provenanceMetadata['sha256'],
        ]],
    ];
    $manifestMetadata = AlpacaIntradaySnapshotStore::writeManifestAtomically($manifestFile, $manifest);
    snapshotToolAssert(is_file($manifestFile), 'Atomic manifest was not published.');
    snapshotToolSame(filesize($manifestFile), $manifestMetadata['size_bytes'], 'Manifest size mismatch.');
    snapshotToolSame(hash_file('sha256', $manifestFile), $manifestMetadata['sha256'], 'Manifest checksum mismatch.');
    snapshotToolSame($manifest, json_decode((string) file_get_contents($manifestFile), true, 512, JSON_THROW_ON_ERROR), 'Manifest readback mismatch.');
    snapshotToolSame([], glob($tempDir . '/.*.tmp-*') ?: [], 'Atomic writes left temporary files behind.');
} finally {
    snapshotToolRemoveDirectory($tempDir);
}

echo "Alpaca intraday snapshot tool OK\n";
