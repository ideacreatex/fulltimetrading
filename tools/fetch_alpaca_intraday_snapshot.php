#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Data\AlpacaIntradaySnapshotStore;
use FulltimeTrading\Data\HttpClient;
use FulltimeTrading\Support\Config;

require __DIR__ . '/../bootstrap.php';

try {
    exit(fetchAlpacaSnapshotMain($argv));
} catch (Throwable $e) {
    fwrite(STDERR, 'Alpaca intraday snapshot failed: ' . $e->getMessage() . "\n");
    exit(1);
}

/** @param list<string> $argv */
function fetchAlpacaSnapshotMain(array $argv): int
{
    $config = Config::fromFile(__DIR__ . '/../config/config.php');
    $options = parseSnapshotOptions($argv, $config);
    if ($options['help']) {
        fwrite(STDOUT, snapshotUsage());

        return 0;
    }

    /** @var list<string> $symbols */
    $symbols = $options['symbols'];
    /** @var list<array{start:string,end:string}> $ranges */
    $ranges = AlpacaIntradaySnapshotStore::yearlyRanges($options['start'], $options['end']);
    $manifestPath = AlpacaIntradaySnapshotStore::manifestFile(
        $options['cache_dir'],
        $options['namespace'],
        $symbols,
        $options['timeframe'],
        $options['start'],
        $options['end'],
    );

    $chunks = [];
    $fetched = 0;
    $reused = 0;
    $planned = 0;
    $http = new HttpClient();
    $densityAccumulators = [];
    foreach ($symbols as $symbol) {
        $densityAccumulators[$symbol] = AlpacaIntradaySnapshotStore::newRegularSessionAccumulator();
    }
    foreach ($symbols as $symbol) {
        foreach ($ranges as $range) {
            $path = AlpacaIntradaySnapshotStore::cacheFile(
                $options['cache_dir'],
                $options['namespace'],
                $symbol,
                $options['timeframe'],
                $range['start'],
                $range['end'],
            );
            $provenancePath = AlpacaIntradaySnapshotStore::provenanceFile(
                $options['cache_dir'],
                $options['namespace'],
                $symbol,
                $options['timeframe'],
                $range['start'],
                $range['end'],
            );
            $request = chunkRequestMetadata($options, $symbol, $range['start'], $range['end']);
            $baseChunk = [
                'symbol' => $symbol,
                'start_date' => $range['start'],
                'end_date_exclusive' => $range['end'],
                'canonical_cache_key' => AlpacaIntradaySnapshotStore::canonicalCacheKey(
                    $options['namespace'],
                    $symbol,
                    $options['timeframe'],
                    $range['start'],
                    $range['end'],
                ),
                'file' => basename($path),
                'request' => $request,
            ];

            $reuseFailure = null;
            if (is_file($path) && !$options['force']) {
                try {
                    $verifiedFile = AlpacaIntradaySnapshotStore::readVerifiedCacheFile(
                        $path,
                        $symbol,
                        $range['start'],
                        $range['end'],
                    );
                    $verified = $verifiedFile['metadata'];
                    $expectedProvenance = AlpacaIntradaySnapshotStore::chunkProvenancePayload(
                        $request,
                        basename($path),
                        $verified,
                    );
                    $verifiedProvenance = AlpacaIntradaySnapshotStore::readVerifiedChunkProvenance(
                        $provenancePath,
                        $expectedProvenance,
                    );
                } catch (Throwable $e) {
                    $reuseFailure = $e;
                }
                if ($reuseFailure === null) {
                    $chunkDensityAccumulator = AlpacaIntradaySnapshotStore::newRegularSessionAccumulator();
                    AlpacaIntradaySnapshotStore::accumulateRegularSessionRows($chunkDensityAccumulator, $verifiedFile['rows']);
                    AlpacaIntradaySnapshotStore::accumulateRegularSessionRows($densityAccumulators[$symbol], $verifiedFile['rows']);
                    $chunks[] = $baseChunk + $verified + [
                        'provenance_file' => basename($provenancePath),
                        'provenance_size_bytes' => $verifiedProvenance['metadata']['size_bytes'],
                        'provenance_sha256' => $verifiedProvenance['metadata']['sha256'],
                        'source' => 'verified_existing',
                        'pages' => null,
                        'filtered_out_of_bounds' => null,
                        'identical_duplicates_removed' => null,
                        'regular_session_density' => AlpacaIntradaySnapshotStore::regularSessionDiagnostics(
                            $chunkDensityAccumulator,
                            $options['timeframe'],
                        ),
                    ];
                    $reused++;
                    fwrite(STDERR, sprintf("verified %s %s..%s (%d bars, exact provenance)\n", $symbol, $range['start'], $range['end'], $verified['count']));
                    continue;
                }

                fwrite(STDERR, sprintf(
                    "refetch required %s %s..%s: cached data/provenance is not reusable (%s)\n",
                    $symbol,
                    $range['start'],
                    $range['end'],
                    $reuseFailure->getMessage(),
                ));
            }

            if ($options['dry_run']) {
                $chunks[] = $baseChunk + [
                    'source' => $options['force'] && is_file($path)
                        ? 'would_force_refetch'
                        : ($reuseFailure !== null ? 'would_refetch_unverified_provenance' : 'would_fetch'),
                    'count' => null,
                    'size_bytes' => null,
                    'sha256' => null,
                    'pages' => null,
                    'filtered_out_of_bounds' => null,
                    'identical_duplicates_removed' => null,
                ];
                $planned++;
                continue;
            }

            $fetch = fetchAlpacaChunk(
                $http,
                $options,
                $symbol,
                $range['start'],
                $range['end'],
            );
            $verified = AlpacaIntradaySnapshotStore::writeCacheFileAtomically(
                $path,
                $symbol,
                $fetch['rows'],
                $range['start'],
                $range['end'],
            );
            $provenancePayload = AlpacaIntradaySnapshotStore::chunkProvenancePayload(
                $request,
                basename($path),
                $verified,
            );
            $verifiedProvenance = AlpacaIntradaySnapshotStore::writeChunkProvenanceAtomically(
                $provenancePath,
                $provenancePayload,
            );
            $chunkDensityAccumulator = AlpacaIntradaySnapshotStore::newRegularSessionAccumulator();
            AlpacaIntradaySnapshotStore::accumulateRegularSessionRows($chunkDensityAccumulator, $fetch['rows']);
            AlpacaIntradaySnapshotStore::accumulateRegularSessionRows($densityAccumulators[$symbol], $fetch['rows']);
            $chunks[] = $baseChunk + $verified + [
                'provenance_file' => basename($provenancePath),
                'provenance_size_bytes' => $verifiedProvenance['size_bytes'],
                'provenance_sha256' => $verifiedProvenance['sha256'],
                'source' => 'fetched',
                'pages' => $fetch['pages'],
                'filtered_out_of_bounds' => $fetch['filtered_out_of_bounds'],
                'identical_duplicates_removed' => $fetch['identical_duplicates'],
                'regular_session_density' => AlpacaIntradaySnapshotStore::regularSessionDiagnostics(
                    $chunkDensityAccumulator,
                    $options['timeframe'],
                ),
            ];
            $fetched++;
            fwrite(STDERR, sprintf("fetched %s %s..%s (%d bars, %d pages)\n", $symbol, $range['start'], $range['end'], $verified['count'], $fetch['pages']));
        }
    }

    $summary = [
        'dry_run' => $options['dry_run'],
        'namespace' => $options['namespace'],
        'symbols' => $symbols,
        'timeframe' => $options['timeframe'],
        'start_date' => $options['start'],
        'end_date_exclusive' => $options['end'],
        'api_start' => AlpacaIntradaySnapshotStore::apiBoundary($options['start']),
        'api_end_exclusive' => AlpacaIntradaySnapshotStore::apiBoundary($options['end']),
        'chunks' => count($chunks),
        'fetched_chunks' => $fetched,
        'verified_existing_chunks' => $reused,
        'planned_chunks' => $planned,
        'manifest' => $manifestPath,
    ];

    $densityBySymbol = [];
    foreach ($symbols as $symbol) {
        $densityBySymbol[$symbol] = AlpacaIntradaySnapshotStore::regularSessionDiagnostics(
            $densityAccumulators[$symbol],
            $options['timeframe'],
        );
    }
    // This only says every requested chunk contributed to the observed-density
    // calculation. It is deliberately not a claim that every expected exchange
    // session or every scheduled bar is present.
    $summary['observed_density_input_complete'] = $planned === 0;
    $summary['regular_session_coverage'] = $densityBySymbol;

    if (!$options['dry_run']) {
        $totalCount = array_sum(array_map(static fn (array $chunk): int => (int) $chunk['count'], $chunks));
        $totalSize = array_sum(array_map(static fn (array $chunk): int => (int) $chunk['size_bytes'], $chunks));
        $manifest = [
            'schema_version' => AlpacaIntradaySnapshotStore::SCHEMA_VERSION,
            'complete' => true,
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'request' => snapshotRequestMetadata($options),
            'totals' => [
                'symbols' => count($symbols),
                'chunks' => count($chunks),
                'bars' => $totalCount,
                'size_bytes' => $totalSize,
                'fetched_chunks' => $fetched,
                'verified_existing_chunks' => $reused,
            ],
            'regular_session_coverage' => [
                'basis' => 'observed_sessions_only',
                'expected_session_calendar_supplied' => false,
                'note' => 'Counts and density use only observed bars; no claim of complete exchange-session coverage is made.',
                'symbols' => $densityBySymbol,
            ],
            'chunks' => $chunks,
        ];
        $manifestVerification = AlpacaIntradaySnapshotStore::writeManifestAtomically($manifestPath, $manifest);
        $summary['bars'] = $totalCount;
        $summary['cache_size_bytes'] = $totalSize;
        $summary['manifest_size_bytes'] = $manifestVerification['size_bytes'];
        $summary['manifest_sha256'] = $manifestVerification['sha256'];
    } else {
        $summary['plan'] = $chunks;
    }

    fwrite(STDOUT, json_encode(
        $summary,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    ) . "\n");

    return 0;
}

/**
 * @param array<string, mixed> $options
 * @return array{rows:list<array{symbol:string,time:string,open:float,high:float,low:float,close:float,volume:float}>,pages:int,filtered_out_of_bounds:int,identical_duplicates:int}
 */
function fetchAlpacaChunk(
    HttpClient $http,
    array $options,
    string $symbol,
    string $start,
    string $end,
): array {
    $keyId = getenv('APCA_DATA_API_KEY_ID') ?: getenv('APCA_API_KEY_ID') ?: '';
    $secret = getenv('APCA_DATA_API_SECRET_KEY') ?: getenv('APCA_API_SECRET_KEY') ?: '';
    if ($keyId === '' || $secret === '') {
        throw new RuntimeException('Missing Alpaca data credentials. Set APCA_DATA_API_KEY_ID and APCA_DATA_API_SECRET_KEY.');
    }

    $rowsByTimestamp = [];
    $pageToken = null;
    $usedTokens = [];
    $pages = 0;
    $filtered = 0;
    $duplicates = 0;
    do {
        if ($pageToken !== null) {
            if (isset($usedTokens[$pageToken])) {
                throw new RuntimeException('Alpaca repeated a pagination token for ' . $symbol . '; refusing a partial chunk.');
            }
            $usedTokens[$pageToken] = true;
        }
        $query = [
            'symbols' => $symbol,
            'timeframe' => $options['timeframe'],
            'start' => AlpacaIntradaySnapshotStore::apiBoundary($start),
            'end' => AlpacaIntradaySnapshotStore::apiBoundary($end),
            'limit' => (string) $options['limit'],
            'adjustment' => $options['adjustment'],
            'feed' => $options['feed'],
            'sort' => 'asc',
        ];
        if ($pageToken !== null) {
            $query['page_token'] = $pageToken;
        }
        $url = rtrim($options['base_url'], '/') . '/v2/stocks/bars?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $response = $http->get($url, [
            'APCA-API-KEY-ID' => $keyId,
            'APCA-API-SECRET-KEY' => $secret,
        ], null, false);
        $pages++;
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException(sprintf(
                'Alpaca returned HTTP %d for %s %s..%s; refusing a partial chunk.',
                $response['status'],
                $symbol,
                $start,
                $end,
            ));
        }
        try {
            $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException('Alpaca returned invalid JSON for ' . $symbol . '; refusing a partial chunk.', 0, $e);
        }
        if (!is_array($payload) || !isset($payload['bars']) || !is_array($payload['bars'])) {
            throw new RuntimeException('Alpaca response has no valid bars object for ' . $symbol . '.');
        }

        $apiRows = null;
        foreach ($payload['bars'] as $responseSymbol => $series) {
            $responseSymbol = AlpacaIntradaySnapshotStore::canonicalSymbol((string) $responseSymbol);
            if ($responseSymbol !== $symbol) {
                throw new RuntimeException('Alpaca returned an unexpected symbol while fetching ' . $symbol . '.');
            }
            if ($apiRows !== null) {
                throw new RuntimeException('Alpaca returned duplicate symbol containers for ' . $symbol . '.');
            }
            if (!is_array($series) || !array_is_list($series)) {
                throw new RuntimeException('Alpaca bars series is not a list for ' . $symbol . '.');
            }
            $apiRows = $series;
        }
        $apiRows ??= [];
        $merge = AlpacaIntradaySnapshotStore::mergeApiRows($rowsByTimestamp, $symbol, $apiRows, $start, $end);
        $filtered += $merge['filtered_out_of_bounds'];
        $duplicates += $merge['identical_duplicates'];

        $next = $payload['next_page_token'] ?? null;
        if ($next !== null && !is_string($next)) {
            throw new RuntimeException('Alpaca returned an invalid pagination token for ' . $symbol . '.');
        }
        $pageToken = $next === '' ? null : $next;
    } while ($pageToken !== null);

    return [
        'rows' => AlpacaIntradaySnapshotStore::finalizeRows($rowsByTimestamp, $symbol, $start, $end),
        'pages' => $pages,
        'filtered_out_of_bounds' => $filtered,
        'identical_duplicates' => $duplicates,
    ];
}

/** @param list<string> $argv @return array<string, mixed> */
function parseSnapshotOptions(array $argv, Config $config): array
{
    $raw = [
        'help' => false,
        'dry-run' => false,
        'force' => false,
        'symbol' => [],
        'symbols' => [],
        'feed' => (string) $config->get('data.alpaca.feed', 'iex'),
        'adjustment' => (string) $config->get('data.alpaca.adjustment', 'split'),
        'timeframe' => '5Min',
        'start' => null,
        'end' => null,
        'namespace' => null,
        'cache-dir' => (string) $config->get('cache_path', __DIR__ . '/../var/cache'),
        'base-url' => (string) $config->get('data.alpaca.base_url', 'https://data.alpaca.markets'),
        'limit' => (string) $config->get('data.alpaca.limit', 10000),
    ];
    foreach (array_slice($argv, 1) as $argument) {
        if ($argument === '--help' || $argument === '-h') {
            $raw['help'] = true;
            continue;
        }
        if ($argument === '--dry-run') {
            $raw['dry-run'] = true;
            continue;
        }
        if ($argument === '--force') {
            $raw['force'] = true;
            continue;
        }
        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            throw new InvalidArgumentException('Unknown argument: ' . $argument . '. Use --help.');
        }
        [$name, $value] = explode('=', substr($argument, 2), 2);
        if (!array_key_exists($name, $raw)) {
            throw new InvalidArgumentException('Unknown option --' . $name . '. Use --help.');
        }
        if ($name === 'symbol' || $name === 'symbols') {
            $raw[$name][] = $value;
        } elseif ($name === 'dry-run' || $name === 'force') {
            $raw[$name] = parseSnapshotBoolean($value, $name);
        } else {
            $raw[$name] = $value;
        }
    }

    if ($raw['help']) {
        return ['help' => true];
    }
    $symbols = [];
    foreach (array_merge($raw['symbol'], $raw['symbols']) as $group) {
        foreach (explode(',', (string) $group) as $symbol) {
            if (trim($symbol) !== '') {
                $symbols[] = AlpacaIntradaySnapshotStore::canonicalSymbol($symbol);
            }
        }
    }
    $symbols = array_values(array_unique($symbols));
    sort($symbols, SORT_STRING);
    if ($symbols === []) {
        throw new InvalidArgumentException('At least one --symbol=SOXL or --symbols=SOXL,TQQQ option is required.');
    }

    $namespace = trim((string) ($raw['namespace'] ?? ''));
    if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D', $namespace) !== 1) {
        throw new InvalidArgumentException('--namespace must use only letters, digits, dot, underscore, or hyphen.');
    }
    $start = AlpacaIntradaySnapshotStore::canonicalDate((string) ($raw['start'] ?? ''), 'start');
    $end = AlpacaIntradaySnapshotStore::canonicalDate((string) ($raw['end'] ?? ''), 'end');
    if ($start >= $end) {
        throw new InvalidArgumentException('--end must be later than --start.');
    }
    $timeframe = trim((string) $raw['timeframe']);
    if (preg_match('/^[1-9][0-9]*(Min|Hour)$/D', $timeframe) !== 1) {
        throw new InvalidArgumentException('--timeframe must be an Alpaca intraday value such as 1Min, 5Min, or 1Hour.');
    }
    $feed = strtolower(trim((string) $raw['feed']));
    if (!in_array($feed, ['iex', 'sip', 'otc'], true)) {
        throw new InvalidArgumentException('--feed must be iex, sip, or otc.');
    }
    $adjustment = strtolower(trim((string) $raw['adjustment']));
    if (!in_array($adjustment, ['raw', 'split', 'dividend', 'all'], true)) {
        throw new InvalidArgumentException('--adjustment must be raw, split, dividend, or all.');
    }
    $limitRaw = (string) $raw['limit'];
    if (preg_match('/^[1-9][0-9]*$/D', $limitRaw) !== 1 || (int) $limitRaw > 10000) {
        throw new InvalidArgumentException('--limit must be an integer from 1 through 10000.');
    }
    $cacheDir = trim((string) $raw['cache-dir']);
    if ($cacheDir === '') {
        throw new InvalidArgumentException('--cache-dir must not be empty.');
    }
    $baseUrl = rtrim(trim((string) $raw['base-url']), '/');
    $host = parse_url($baseUrl, PHP_URL_HOST);
    $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
    if ($scheme !== 'https' || !in_array($host, ['data.alpaca.markets', 'data.sandbox.alpaca.markets'], true)) {
        throw new InvalidArgumentException('--base-url must be an official HTTPS Alpaca data host.');
    }

    return [
        'help' => false,
        'dry_run' => (bool) $raw['dry-run'],
        'force' => (bool) $raw['force'],
        'symbols' => $symbols,
        'feed' => $feed,
        'adjustment' => $adjustment,
        'timeframe' => $timeframe,
        'start' => $start,
        'end' => $end,
        'namespace' => $namespace,
        'cache_dir' => $cacheDir,
        'base_url' => $baseUrl,
        'limit' => (int) $limitRaw,
    ];
}

function parseSnapshotBoolean(string $value, string $name): bool
{
    $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($parsed === null) {
        throw new InvalidArgumentException('--' . $name . ' expects true or false.');
    }

    return $parsed;
}

/** @param array<string, mixed> $options @return array<string, mixed> */
function snapshotRequestMetadata(array $options): array
{
    return [
        'provider' => 'alpaca_market_data',
        'endpoint' => rtrim($options['base_url'], '/') . '/v2/stocks/bars',
        'symbols' => $options['symbols'],
        'timeframe' => $options['timeframe'],
        'feed' => $options['feed'],
        'adjustment' => $options['adjustment'],
        'limit' => $options['limit'],
        'sort' => 'asc',
        'namespace' => $options['namespace'],
        'start_date' => $options['start'],
        'end_date_exclusive' => $options['end'],
        'api_start' => AlpacaIntradaySnapshotStore::apiBoundary($options['start']),
        'api_end_exclusive' => AlpacaIntradaySnapshotStore::apiBoundary($options['end']),
        'chunking' => 'symbol_year',
        'bounds' => '[start,end)',
    ];
}

/** @param array<string, mixed> $options @return array<string, mixed> */
function chunkRequestMetadata(array $options, string $symbol, string $start, string $end): array
{
    return AlpacaIntradaySnapshotStore::chunkProvenanceRequest(
        rtrim($options['base_url'], '/') . '/v2/stocks/bars',
        $options['namespace'],
        $symbol,
        $options['timeframe'],
        $options['feed'],
        $options['adjustment'],
        $options['limit'],
        $start,
        $end,
    );
}

function snapshotUsage(): string
{
    return <<<'USAGE'
Fetch a canonical, resumable Alpaca intraday snapshot in yearly symbol chunks.

Usage:
  php tools/fetch_alpaca_intraday_snapshot.php \
    --symbols=SOXL,TECL,TQQQ,UPRO \
    --start=2021-01-01 --end=2026-07-16 \
    --timeframe=5Min --feed=sip --adjustment=split \
    --namespace=alpaca-causal-touch-reclaim-v1-feed-sip-adjustment-split-timeframe-5min \
    [--cache-dir=var/cache] [--limit=10000] [--dry-run] [--force]

Required:
  --symbol=SYMBOL        One symbol; repeat the option for more symbols.
  --symbols=A,B,C        Comma-separated alternative to repeated --symbol.
  --start=YYYY-MM-DD     Inclusive UTC boundary.
  --end=YYYY-MM-DD       Exclusive UTC boundary.
  --namespace=NAME       Canonical cache namespace used in the date-only hash.

Behavior:
  API requests always use explicit UTC RFC3339 boundaries. Cache keys retain
  date-only boundaries expected by CacheDirectoryMarketDataProvider. Existing
  chunks are skipped only after full JSON, bounds, ordering, and duplicate
  verification. --force downloads and atomically replaces canonical chunks.
  --dry-run needs no credentials and writes nothing.

Credentials are read only from APCA_DATA_API_KEY_ID/APCA_DATA_API_SECRET_KEY
(with APCA_API_KEY_ID/APCA_API_SECRET_KEY as the existing fallback convention).
USAGE;
}
