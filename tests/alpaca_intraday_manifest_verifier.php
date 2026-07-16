<?php

declare(strict_types=1);

use FulltimeTrading\Data\AlpacaIntradayManifestVerifier;
use FulltimeTrading\Data\AlpacaIntradaySnapshotStore;

require __DIR__ . '/../bootstrap.php';

function manifestVerifierAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function manifestVerifierSame(mixed $expected, mixed $actual, string $message): void
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

function manifestVerifierFailure(callable $callback, string $contains, string $message): void
{
    try {
        $callback();
    } catch (Throwable $e) {
        manifestVerifierAssert(
            str_contains($e->getMessage(), $contains),
            $message . ': wrong failure: ' . $e->getMessage(),
        );

        return;
    }

    throw new RuntimeException($message . ': no failure was raised.');
}

function manifestVerifierRemoveDirectory(string $path): void
{
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        $target = $path . '/' . $entry;
        if (is_dir($target)) {
            manifestVerifierRemoveDirectory($target);
        } else {
            unlink($target);
        }
    }
    rmdir($path);
}

/** @return array<string, mixed> */
function manifestVerifierRequest(
    array $symbols,
    string $namespace,
    string $start,
    string $end,
    string $timeframe,
    string $feed,
    string $adjustment,
): array {
    return [
        'provider' => 'alpaca_market_data',
        'endpoint' => 'https://data.alpaca.markets/v2/stocks/bars',
        'symbols' => $symbols,
        'timeframe' => $timeframe,
        'feed' => $feed,
        'adjustment' => $adjustment,
        'limit' => 10000,
        'sort' => 'asc',
        'namespace' => $namespace,
        'start_date' => $start,
        'end_date_exclusive' => $end,
        'api_start' => AlpacaIntradaySnapshotStore::apiBoundary($start),
        'api_end_exclusive' => AlpacaIntradaySnapshotStore::apiBoundary($end),
        'chunking' => 'symbol_year',
        'bounds' => '[start,end)',
    ];
}

/** @return array<string, mixed> */
function manifestVerifierChunkRequest(
    string $namespace,
    string $symbol,
    string $start,
    string $end,
    string $timeframe,
    string $feed,
    string $adjustment,
): array {
    return AlpacaIntradaySnapshotStore::chunkProvenanceRequest(
        'https://data.alpaca.markets/v2/stocks/bars',
        $namespace,
        $symbol,
        $timeframe,
        $feed,
        $adjustment,
        10000,
        $start,
        $end,
    );
}

$tempDir = sys_get_temp_dir() . '/ftt-alpaca-manifest-verifier-' . bin2hex(random_bytes(8));
if (!mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
    throw new RuntimeException('Unable to create manifest verifier fixture directory.');
}

$namespace = 'manifest-verifier-fixture';
$symbols = ['SOXL', 'TQQQ'];
$timeframe = '5Min';
$feed = 'sip';
$adjustment = 'split';
$start = '2021-06-15';
$end = '2022-02-01';
$manifestPath = AlpacaIntradaySnapshotStore::manifestFile(
    $tempDir,
    $namespace,
    $symbols,
    $timeframe,
    $start,
    $end,
);

try {
    $chunks = [];
    $totalBars = 0;
    $totalSize = 0;
    foreach ($symbols as $symbol) {
        foreach (AlpacaIntradaySnapshotStore::yearlyRanges($start, $end) as $range) {
            $barDate = $range['start'] === '2021-06-15' ? '2021-06-15' : '2022-01-03';
            $row = [
                'symbol' => $symbol,
                'time' => $barDate . 'T14:30:00+00:00',
                'open' => 100.0,
                'high' => 102.0,
                'low' => 99.0,
                'close' => 101.0,
                'volume' => 1000.0,
            ];
            $cachePath = AlpacaIntradaySnapshotStore::cacheFile(
                $tempDir,
                $namespace,
                $symbol,
                $timeframe,
                $range['start'],
                $range['end'],
            );
            $metadata = AlpacaIntradaySnapshotStore::writeCacheFileAtomically(
                $cachePath,
                $symbol,
                [$row],
                $range['start'],
                $range['end'],
            );
            $chunkRequest = manifestVerifierChunkRequest(
                $namespace,
                $symbol,
                $range['start'],
                $range['end'],
                $timeframe,
                $feed,
                $adjustment,
            );
            $provenancePath = AlpacaIntradaySnapshotStore::provenanceFile(
                $tempDir,
                $namespace,
                $symbol,
                $timeframe,
                $range['start'],
                $range['end'],
            );
            $provenanceMetadata = AlpacaIntradaySnapshotStore::writeChunkProvenanceAtomically(
                $provenancePath,
                AlpacaIntradaySnapshotStore::chunkProvenancePayload(
                    $chunkRequest,
                    basename($cachePath),
                    $metadata,
                ),
            );
            $chunks[] = [
                'symbol' => $symbol,
                'start_date' => $range['start'],
                'end_date_exclusive' => $range['end'],
                'canonical_cache_key' => AlpacaIntradaySnapshotStore::canonicalCacheKey(
                    $namespace,
                    $symbol,
                    $timeframe,
                    $range['start'],
                    $range['end'],
                ),
                'file' => basename($cachePath),
                'request' => $chunkRequest,
                'provenance_file' => basename($provenancePath),
                'provenance_size_bytes' => $provenanceMetadata['size_bytes'],
                'provenance_sha256' => $provenanceMetadata['sha256'],
            ] + $metadata + [
                'source' => 'verified_existing',
                'pages' => null,
                'filtered_out_of_bounds' => null,
                'identical_duplicates_removed' => null,
            ];
            $totalBars += $metadata['count'];
            $totalSize += $metadata['size_bytes'];
        }
    }

    $manifest = [
        'schema_version' => AlpacaIntradaySnapshotStore::SCHEMA_VERSION,
        'complete' => true,
        'generated_at' => '2026-07-16T00:00:00+00:00',
        'request' => manifestVerifierRequest(
            $symbols,
            $namespace,
            $start,
            $end,
            $timeframe,
            $feed,
            $adjustment,
        ),
        'totals' => [
            'symbols' => count($symbols),
            'chunks' => count($chunks),
            'bars' => $totalBars,
            'size_bytes' => $totalSize,
            'fetched_chunks' => 0,
            'verified_existing_chunks' => count($chunks),
        ],
        'regular_session_coverage' => [
            'basis' => 'observed_sessions_only',
            'expected_session_calendar_supplied' => false,
            'symbols' => [],
        ],
        'chunks' => $chunks,
    ];
    AlpacaIntradaySnapshotStore::writeManifestAtomically($manifestPath, $manifest);

    $verified = AlpacaIntradayManifestVerifier::verify(
        cacheDir: $tempDir,
        namespace: $namespace,
        symbols: ['tqqq', 'soxl'],
        timeframe: $timeframe,
        feed: $feed,
        adjustment: $adjustment,
        snapshotStart: $start,
        snapshotEndExclusive: $end,
    );
    manifestVerifierSame(true, $verified['verified'], 'Canonical manifest was not marked verified.');
    manifestVerifierSame(basename($manifestPath), $verified['manifest_file'], 'Manifest basename provenance mismatch.');
    manifestVerifierSame(filesize($manifestPath), $verified['manifest_size_bytes'], 'Manifest size provenance mismatch.');
    manifestVerifierSame(hash_file('sha256', $manifestPath), $verified['manifest_sha256'], 'Manifest hash provenance mismatch.');
    manifestVerifierSame(4, $verified['chunks'], 'Verified chunk count mismatch.');
    manifestVerifierSame(4, $verified['bars'], 'Verified bar count mismatch.');
    manifestVerifierSame($totalSize, $verified['cache_size_bytes'], 'Verified cache size mismatch.');
    manifestVerifierSame($symbols, $verified['symbols'], 'Verified symbols must be canonical and sorted.');
    manifestVerifierSame(4, count($verified['chunk_files']), 'Chunk provenance list mismatch.');
    manifestVerifierSame(
        $chunks[0]['provenance_sha256'],
        $verified['chunk_files'][0]['provenance_sha256'],
        'Verified chunk must expose the exact sidecar hash.',
    );

    $firstCachePath = $tempDir . '/' . $chunks[0]['file'];
    $firstProvenancePath = $tempDir . '/' . $chunks[0]['provenance_file'];
    $firstProvenancePayload = AlpacaIntradaySnapshotStore::chunkProvenancePayload(
        $chunks[0]['request'],
        $chunks[0]['file'],
        [
            'count' => $chunks[0]['count'],
            'size_bytes' => $chunks[0]['size_bytes'],
            'sha256' => $chunks[0]['sha256'],
        ],
    );

    $legacyWithoutSidecars = $manifest;
    foreach ($legacyWithoutSidecars['chunks'] as &$legacyChunk) {
        unset(
            $legacyChunk['provenance_file'],
            $legacyChunk['provenance_size_bytes'],
            $legacyChunk['provenance_sha256'],
        );
    }
    unset($legacyChunk);
    manifestVerifierFailure(
        static fn (): array => AlpacaIntradaySnapshotStore::writeManifestAtomically(
            $manifestPath,
            $legacyWithoutSidecars,
        ),
        'unverifiable chunk',
        'A manifest without immutable sidecar references must not be publishable.',
    );
    AlpacaIntradaySnapshotStore::writeJsonAtomically($manifestPath, $legacyWithoutSidecars);
    manifestVerifierFailure(
        static fn (): array => AlpacaIntradayManifestVerifier::verify(
            $tempDir,
            $namespace,
            $symbols,
            $timeframe,
            $feed,
            $adjustment,
            $start,
            $end,
        ),
        'sidecar reference is missing',
        'A legacy manifest without sidecar references must fail closed in the verifier.',
    );

    $iexRequest = manifestVerifierChunkRequest(
        $namespace,
        $chunks[0]['symbol'],
        $chunks[0]['start_date'],
        $chunks[0]['end_date_exclusive'],
        $timeframe,
        'iex',
        $adjustment,
    );
    $iexProvenanceMetadata = AlpacaIntradaySnapshotStore::writeChunkProvenanceAtomically(
        $firstProvenancePath,
        AlpacaIntradaySnapshotStore::chunkProvenancePayload(
            $iexRequest,
            $chunks[0]['file'],
            [
                'count' => $chunks[0]['count'],
                'size_bytes' => $chunks[0]['size_bytes'],
                'sha256' => $chunks[0]['sha256'],
            ],
        ),
    );
    $bad = $manifest;
    $bad['chunks'][0]['provenance_size_bytes'] = $iexProvenanceMetadata['size_bytes'];
    $bad['chunks'][0]['provenance_sha256'] = $iexProvenanceMetadata['sha256'];
    AlpacaIntradaySnapshotStore::writeManifestAtomically($manifestPath, $bad);
    manifestVerifierFailure(
        static fn (): array => AlpacaIntradayManifestVerifier::verify(
            $tempDir,
            $namespace,
            $symbols,
            $timeframe,
            $feed,
            $adjustment,
            $start,
            $end,
        ),
        'does not exactly match',
        'An IEX sidecar must not be accepted by a manifest that declares SIP.',
    );

    $restoredProvenanceMetadata = AlpacaIntradaySnapshotStore::writeChunkProvenanceAtomically(
        $firstProvenancePath,
        $firstProvenancePayload,
    );
    manifestVerifierSame(
        $chunks[0]['provenance_sha256'],
        $restoredProvenanceMetadata['sha256'],
        'Restored SIP sidecar hash differs from the canonical fixture.',
    );
    AlpacaIntradaySnapshotStore::writeManifestAtomically($manifestPath, $manifest);
    unlink($firstProvenancePath);
    manifestVerifierFailure(
        static fn (): array => AlpacaIntradayManifestVerifier::verify(
            $tempDir,
            $namespace,
            $symbols,
            $timeframe,
            $feed,
            $adjustment,
            $start,
            $end,
        ),
        'Unable to read snapshot file',
        'A missing canonical provenance sidecar must fail closed.',
    );

    AlpacaIntradaySnapshotStore::writeChunkProvenanceAtomically($firstProvenancePath, $firstProvenancePayload);
    file_put_contents($firstProvenancePath, (string) file_get_contents($firstProvenancePath) . " ");
    manifestVerifierFailure(
        static fn (): array => AlpacaIntradayManifestVerifier::verify(
            $tempDir,
            $namespace,
            $symbols,
            $timeframe,
            $feed,
            $adjustment,
            $start,
            $end,
        ),
        'does not exactly match',
        'A byte-level tamper of the provenance sidecar must fail closed.',
    );
    AlpacaIntradaySnapshotStore::writeChunkProvenanceAtomically($firstProvenancePath, $firstProvenancePayload);
    AlpacaIntradaySnapshotStore::writeManifestAtomically($manifestPath, $manifest);

    $bad = $manifest;
    $bad['request']['symbols'] = ['TQQQ', 'SOXL'];
    AlpacaIntradaySnapshotStore::writeManifestAtomically($manifestPath, $bad);
    manifestVerifierFailure(
        static fn (): array => AlpacaIntradayManifestVerifier::verify(
            $tempDir,
            $namespace,
            $symbols,
            $timeframe,
            $feed,
            $adjustment,
            $start,
            $end,
        ),
        'request metadata does not exactly match',
        'Unsorted or different manifest symbols must fail closed.',
    );

    $bad = $manifest;
    array_pop($bad['chunks']);
    $bad['totals']['chunks']--;
    $bad['totals']['bars']--;
    $bad['totals']['size_bytes'] -= $chunks[3]['size_bytes'];
    $bad['totals']['verified_existing_chunks']--;
    AlpacaIntradaySnapshotStore::writeManifestAtomically($manifestPath, $bad);
    manifestVerifierFailure(
        static fn (): array => AlpacaIntradayManifestVerifier::verify(
            $tempDir,
            $namespace,
            $symbols,
            $timeframe,
            $feed,
            $adjustment,
            $start,
            $end,
        ),
        'chunk set is incomplete',
        'A missing canonical symbol-year chunk must fail closed.',
    );

    $bad = $manifest;
    $bad['chunks'][0]['file'] = 'noncanonical.json';
    AlpacaIntradaySnapshotStore::writeManifestAtomically($manifestPath, $bad);
    manifestVerifierFailure(
        static fn (): array => AlpacaIntradayManifestVerifier::verify(
            $tempDir,
            $namespace,
            $symbols,
            $timeframe,
            $feed,
            $adjustment,
            $start,
            $end,
        ),
        'canonical chunk basename mismatch',
        'A noncanonical chunk basename must fail closed.',
    );

    $bad = $manifest;
    $bad['chunks'][0]['request']['feed'] = 'iex';
    AlpacaIntradaySnapshotStore::writeManifestAtomically($manifestPath, $bad);
    manifestVerifierFailure(
        static fn (): array => AlpacaIntradayManifestVerifier::verify(
            $tempDir,
            $namespace,
            $symbols,
            $timeframe,
            $feed,
            $adjustment,
            $start,
            $end,
        ),
        'chunk request mismatch',
        'A chunk with different feed provenance must fail closed.',
    );

    $bad = $manifest;
    $bad['totals']['bars']++;
    AlpacaIntradaySnapshotStore::writeManifestAtomically($manifestPath, $bad);
    manifestVerifierFailure(
        static fn (): array => AlpacaIntradayManifestVerifier::verify(
            $tempDir,
            $namespace,
            $symbols,
            $timeframe,
            $feed,
            $adjustment,
            $start,
            $end,
        ),
        'totals do not exactly match',
        'Manifest totals that differ from verified chunks must fail closed.',
    );

    AlpacaIntradaySnapshotStore::writeManifestAtomically($manifestPath, $manifest);
    $originalCacheBytes = (string) file_get_contents($firstCachePath);
    file_put_contents($firstCachePath, $originalCacheBytes . "\n");
    manifestVerifierFailure(
        static fn (): array => AlpacaIntradayManifestVerifier::verify(
            $tempDir,
            $namespace,
            $symbols,
            $timeframe,
            $feed,
            $adjustment,
            $start,
            $end,
        ),
        'chunk size_bytes mismatch',
        'A byte-level change to a declared chunk must fail closed.',
    );
} finally {
    manifestVerifierRemoveDirectory($tempDir);
}

echo "Alpaca intraday manifest verifier OK\n";
