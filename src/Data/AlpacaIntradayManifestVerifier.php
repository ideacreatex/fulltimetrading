<?php

declare(strict_types=1);

namespace FulltimeTrading\Data;

/**
 * Fail-closed verification for a canonical Alpaca intraday snapshot manifest.
 *
 * The manifest is not treated as proof by itself: every declared chunk is
 * opened through AlpacaIntradaySnapshotStore, structurally validated, and then
 * matched against its declared byte size, SHA-256 digest, and bar count. Every
 * chunk also needs its canonical immutable provenance sidecar; the manifest is
 * not allowed to reinterpret an IEX/raw cache file as SIP/split data.
 */
final class AlpacaIntradayManifestVerifier
{
    /**
     * @param list<string> $symbols
     * @return array{
     *   verified:true,
     *   manifest_file:string,
     *   manifest_path:string,
     *   manifest_size_bytes:int,
     *   manifest_sha256:string,
     *   namespace:string,
     *   symbols:list<string>,
     *   timeframe:string,
     *   feed:string,
     *   adjustment:string,
     *   snapshot_start:string,
     *   snapshot_end_exclusive:string,
     *   chunks:int,
     *   bars:int,
     *   cache_size_bytes:int,
     *   chunk_files:list<array{symbol:string,start_date:string,end_date_exclusive:string,file:string,count:int,size_bytes:int,sha256:string,provenance_file:string,provenance_size_bytes:int,provenance_sha256:string}>
     * }
     */
    public static function verify(
        string $cacheDir,
        string $namespace,
        array $symbols,
        string $timeframe,
        string $feed,
        string $adjustment,
        string $snapshotStart,
        string $snapshotEndExclusive,
    ): array {
        $cacheDir = rtrim(trim($cacheDir), '/');
        if ($cacheDir === '') {
            throw new \InvalidArgumentException('Alpaca intraday manifest cache directory must not be empty.');
        }
        $namespace = trim($namespace);
        if ($namespace === '') {
            throw new \InvalidArgumentException('Alpaca intraday manifest namespace must not be empty.');
        }
        $timeframe = trim($timeframe);
        if ($timeframe === '') {
            throw new \InvalidArgumentException('Alpaca intraday manifest timeframe must not be empty.');
        }
        $feed = strtolower(trim($feed));
        $adjustment = strtolower(trim($adjustment));
        if ($feed === '' || $adjustment === '') {
            throw new \InvalidArgumentException('Alpaca intraday manifest feed and adjustment must not be empty.');
        }

        $symbols = array_values(array_unique(array_map(
            [AlpacaIntradaySnapshotStore::class, 'canonicalSymbol'],
            $symbols,
        )));
        sort($symbols, SORT_STRING);
        if ($symbols === []) {
            throw new \InvalidArgumentException('Alpaca intraday manifest verification requires at least one symbol.');
        }
        $snapshotStart = AlpacaIntradaySnapshotStore::canonicalDate($snapshotStart, 'snapshot start');
        $snapshotEndExclusive = AlpacaIntradaySnapshotStore::canonicalDate(
            $snapshotEndExclusive,
            'snapshot end',
        );
        $ranges = AlpacaIntradaySnapshotStore::yearlyRanges($snapshotStart, $snapshotEndExclusive);

        $manifestPath = AlpacaIntradaySnapshotStore::manifestFile(
            $cacheDir,
            $namespace,
            $symbols,
            $timeframe,
            $snapshotStart,
            $snapshotEndExclusive,
        );
        $manifestBytes = self::readFile($manifestPath, 'canonical manifest');
        try {
            $manifest = json_decode($manifestBytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Alpaca intraday manifest contains invalid JSON: ' . basename($manifestPath), 0, $e);
        }
        if (!is_array($manifest) || array_is_list($manifest)) {
            throw new \RuntimeException('Alpaca intraday manifest root must be a JSON object.');
        }
        if (($manifest['schema_version'] ?? null) !== AlpacaIntradaySnapshotStore::SCHEMA_VERSION) {
            throw new \RuntimeException('Alpaca intraday manifest schema_version is missing or unsupported.');
        }
        if (($manifest['complete'] ?? null) !== true) {
            throw new \RuntimeException('Alpaca intraday manifest is not marked complete.');
        }

        $request = $manifest['request'] ?? null;
        if (!is_array($request) || array_is_list($request)) {
            throw new \RuntimeException('Alpaca intraday manifest request must be a JSON object.');
        }
        $endpoint = $request['endpoint'] ?? null;
        if (!is_string($endpoint) || !in_array($endpoint, [
            'https://data.alpaca.markets/v2/stocks/bars',
            'https://data.sandbox.alpaca.markets/v2/stocks/bars',
        ], true)) {
            throw new \RuntimeException('Alpaca intraday manifest request endpoint is not an official canonical Alpaca bars endpoint.');
        }
        $limit = $request['limit'] ?? null;
        if (!is_int($limit) || $limit < 1 || $limit > 10000) {
            throw new \RuntimeException('Alpaca intraday manifest request limit must be an integer from 1 through 10000.');
        }
        $expectedRequest = [
            'provider' => 'alpaca_market_data',
            'endpoint' => $endpoint,
            'symbols' => $symbols,
            'timeframe' => $timeframe,
            'feed' => $feed,
            'adjustment' => $adjustment,
            'limit' => $limit,
            'sort' => 'asc',
            'namespace' => $namespace,
            'start_date' => $snapshotStart,
            'end_date_exclusive' => $snapshotEndExclusive,
            'api_start' => AlpacaIntradaySnapshotStore::apiBoundary($snapshotStart),
            'api_end_exclusive' => AlpacaIntradaySnapshotStore::apiBoundary($snapshotEndExclusive),
            'chunking' => 'symbol_year',
            'bounds' => '[start,end)',
        ];
        if ($request !== $expectedRequest) {
            throw new \RuntimeException('Alpaca intraday manifest request metadata does not exactly match the requested snapshot.');
        }

        $chunks = $manifest['chunks'] ?? null;
        if (!is_array($chunks) || !array_is_list($chunks)) {
            throw new \RuntimeException('Alpaca intraday manifest chunks must be a JSON list.');
        }
        $expectedChunks = [];
        foreach ($symbols as $symbol) {
            foreach ($ranges as $range) {
                $expectedChunks[] = [
                    'symbol' => $symbol,
                    'start' => $range['start'],
                    'end' => $range['end'],
                ];
            }
        }
        if (count($chunks) !== count($expectedChunks)) {
            throw new \RuntimeException(sprintf(
                'Alpaca intraday manifest chunk set is incomplete: expected %d canonical symbol-year chunks, found %d.',
                count($expectedChunks),
                count($chunks),
            ));
        }

        $barTotal = 0;
        $sizeTotal = 0;
        $chunkFiles = [];
        foreach ($expectedChunks as $index => $expectedChunk) {
            $chunk = $chunks[$index] ?? null;
            if (!is_array($chunk) || array_is_list($chunk)) {
                throw new \RuntimeException('Alpaca intraday manifest chunk ' . $index . ' must be a JSON object.');
            }
            $symbol = $expectedChunk['symbol'];
            $start = $expectedChunk['start'];
            $end = $expectedChunk['end'];
            $cacheKey = AlpacaIntradaySnapshotStore::canonicalCacheKey(
                $namespace,
                $symbol,
                $timeframe,
                $start,
                $end,
            );
            $cachePath = AlpacaIntradaySnapshotStore::cacheFile(
                $cacheDir,
                $namespace,
                $symbol,
                $timeframe,
                $start,
                $end,
            );
            $expectedFile = basename($cachePath);
            $provenancePath = AlpacaIntradaySnapshotStore::provenanceFile(
                $cacheDir,
                $namespace,
                $symbol,
                $timeframe,
                $start,
                $end,
            );
            $expectedProvenanceFile = basename($provenancePath);
            if (
                ($chunk['symbol'] ?? null) !== $symbol
                || ($chunk['start_date'] ?? null) !== $start
                || ($chunk['end_date_exclusive'] ?? null) !== $end
            ) {
                throw new \RuntimeException(sprintf(
                    'Alpaca intraday manifest chunk %d is not the expected canonical %s %s..%s chunk.',
                    $index,
                    $symbol,
                    $start,
                    $end,
                ));
            }
            if (($chunk['canonical_cache_key'] ?? null) !== $cacheKey) {
                throw new \RuntimeException('Alpaca intraday manifest canonical cache key mismatch for ' . $symbol . ' ' . $start . '..' . $end . '.');
            }
            if (($chunk['file'] ?? null) !== $expectedFile) {
                throw new \RuntimeException('Alpaca intraday manifest canonical chunk basename mismatch for ' . $symbol . ' ' . $start . '..' . $end . '.');
            }
            if (($chunk['provenance_file'] ?? null) !== $expectedProvenanceFile) {
                throw new \RuntimeException('Alpaca intraday manifest canonical provenance sidecar reference is missing or mismatched for ' . $symbol . ' ' . $start . '..' . $end . '.');
            }

            $expectedChunkRequest = AlpacaIntradaySnapshotStore::chunkProvenanceRequest(
                $endpoint,
                $namespace,
                $symbol,
                $timeframe,
                $feed,
                $adjustment,
                $limit,
                $start,
                $end,
            );
            if (($chunk['request'] ?? null) !== $expectedChunkRequest) {
                throw new \RuntimeException('Alpaca intraday manifest chunk request mismatch for ' . $symbol . ' ' . $start . '..' . $end . '.');
            }

            try {
                $actual = AlpacaIntradaySnapshotStore::readVerifiedCacheFile($cachePath, $symbol, $start, $end)['metadata'];
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    'Alpaca intraday manifest chunk file failed verification: ' . $expectedFile,
                    0,
                    $e,
                );
            }
            foreach (['count', 'size_bytes', 'sha256'] as $field) {
                if (($chunk[$field] ?? null) !== $actual[$field]) {
                    throw new \RuntimeException(sprintf(
                        'Alpaca intraday manifest chunk %s mismatch for %s: declared %s, actual %s.',
                        $field,
                        $expectedFile,
                        var_export($chunk[$field] ?? null, true),
                        var_export($actual[$field], true),
                    ));
                }
            }
            $expectedProvenance = AlpacaIntradaySnapshotStore::chunkProvenancePayload(
                $expectedChunkRequest,
                $expectedFile,
                $actual,
            );
            try {
                $actualProvenance = AlpacaIntradaySnapshotStore::readVerifiedChunkProvenance(
                    $provenancePath,
                    $expectedProvenance,
                )['metadata'];
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    'Alpaca intraday provenance sidecar failed exact verification: '
                        . $expectedProvenanceFile . ': ' . $e->getMessage(),
                    0,
                    $e,
                );
            }
            foreach (['size_bytes', 'sha256'] as $field) {
                $manifestField = 'provenance_' . $field;
                if (($chunk[$manifestField] ?? null) !== $actualProvenance[$field]) {
                    throw new \RuntimeException(sprintf(
                        'Alpaca intraday manifest provenance sidecar %s mismatch for %s: declared %s, actual %s.',
                        $field,
                        $expectedProvenanceFile,
                        var_export($chunk[$manifestField] ?? null, true),
                        var_export($actualProvenance[$field], true),
                    ));
                }
            }

            $barTotal += $actual['count'];
            $sizeTotal += $actual['size_bytes'];
            $chunkFiles[] = [
                'symbol' => $symbol,
                'start_date' => $start,
                'end_date_exclusive' => $end,
                'file' => $expectedFile,
                'count' => $actual['count'],
                'size_bytes' => $actual['size_bytes'],
                'sha256' => $actual['sha256'],
                'provenance_file' => $expectedProvenanceFile,
                'provenance_size_bytes' => $actualProvenance['size_bytes'],
                'provenance_sha256' => $actualProvenance['sha256'],
            ];
        }

        $totals = $manifest['totals'] ?? null;
        if (!is_array($totals) || array_is_list($totals)) {
            throw new \RuntimeException('Alpaca intraday manifest totals must be a JSON object.');
        }
        $fetchedChunks = $totals['fetched_chunks'] ?? null;
        $verifiedExistingChunks = $totals['verified_existing_chunks'] ?? null;
        if (
            !is_int($fetchedChunks)
            || !is_int($verifiedExistingChunks)
            || $fetchedChunks < 0
            || $verifiedExistingChunks < 0
            || $fetchedChunks + $verifiedExistingChunks !== count($expectedChunks)
        ) {
            throw new \RuntimeException('Alpaca intraday manifest fetched/reused chunk totals are inconsistent.');
        }
        $expectedTotals = [
            'symbols' => count($symbols),
            'chunks' => count($expectedChunks),
            'bars' => $barTotal,
            'size_bytes' => $sizeTotal,
            'fetched_chunks' => $fetchedChunks,
            'verified_existing_chunks' => $verifiedExistingChunks,
        ];
        if ($totals !== $expectedTotals) {
            throw new \RuntimeException('Alpaca intraday manifest totals do not exactly match the verified chunk files.');
        }

        return [
            'verified' => true,
            'manifest_file' => basename($manifestPath),
            'manifest_path' => $manifestPath,
            'manifest_size_bytes' => strlen($manifestBytes),
            'manifest_sha256' => hash('sha256', $manifestBytes),
            'namespace' => $namespace,
            'symbols' => $symbols,
            'timeframe' => $timeframe,
            'feed' => $feed,
            'adjustment' => $adjustment,
            'snapshot_start' => $snapshotStart,
            'snapshot_end_exclusive' => $snapshotEndExclusive,
            'chunks' => count($expectedChunks),
            'bars' => $barTotal,
            'cache_size_bytes' => $sizeTotal,
            'chunk_files' => $chunkFiles,
        ];
    }

    private static function readFile(string $path, string $label): string
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException('Unable to read Alpaca intraday ' . $label . ': ' . $path);
        }

        return $bytes;
    }
}
