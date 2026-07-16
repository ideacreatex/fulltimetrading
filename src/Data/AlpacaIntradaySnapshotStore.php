<?php

declare(strict_types=1);

namespace FulltimeTrading\Data;

use FulltimeTrading\Backtest\UsEquitySessionCalendar;

/**
 * Canonical, independently verifiable cache format for Alpaca intraday bars.
 *
 * Cache keys intentionally use date-only chunk boundaries so they remain
 * compatible with CacheDirectoryMarketDataProvider. Network requests should
 * use apiBoundary() so Alpaca always receives explicit UTC RFC3339 values.
 */
final class AlpacaIntradaySnapshotStore
{
    public const SCHEMA_VERSION = 2;

    public const PROVENANCE_SCHEMA_VERSION = 1;

    public static function canonicalDate(string $value, string $label = 'date'): string
    {
        $value = trim($value);
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) === 1) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC'));
            $errors = \DateTimeImmutable::getLastErrors();
            if (
                $date === false
                || ($errors !== false && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0))
                || $date->format('Y-m-d') !== $value
            ) {
                throw new \InvalidArgumentException('Invalid ' . $label . ': ' . $value);
            }

            return $value;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+\-]\d{2}:\d{2})$/D', $value) !== 1) {
            throw new \InvalidArgumentException($label . ' must be YYYY-MM-DD or an RFC3339 UTC-midnight boundary.');
        }

        try {
            $date = new \DateTimeImmutable($value);
        } catch (\Throwable $e) {
            throw new \InvalidArgumentException('Invalid ' . $label . ': ' . $value, 0, $e);
        }
        $utc = $date->setTimezone(new \DateTimeZone('UTC'));
        if ($utc->format('H:i:s.u') !== '00:00:00.000000') {
            throw new \InvalidArgumentException($label . ' must be a date or an RFC3339 UTC-midnight boundary.');
        }

        return $utc->format('Y-m-d');
    }

    public static function apiBoundary(string $date): string
    {
        return self::canonicalDate($date) . 'T00:00:00Z';
    }

    public static function canonicalCacheKey(
        string $namespace,
        string $symbol,
        string $timeframe,
        string $start,
        string $end,
    ): string {
        $namespace = trim($namespace);
        if ($namespace === '') {
            throw new \InvalidArgumentException('Snapshot namespace must not be empty.');
        }
        $symbol = self::canonicalSymbol($symbol);
        $timeframe = trim($timeframe);
        if ($timeframe === '') {
            throw new \InvalidArgumentException('Timeframe must not be empty.');
        }

        return sha1(implode('|', [
            $namespace,
            $symbol,
            $timeframe,
            self::canonicalDate($start, 'start'),
            self::canonicalDate($end, 'end'),
        ]));
    }

    public static function cacheFile(
        string $cacheDir,
        string $namespace,
        string $symbol,
        string $timeframe,
        string $start,
        string $end,
    ): string {
        return rtrim($cacheDir, '/') . '/' . self::canonicalCacheKey(
            $namespace,
            $symbol,
            $timeframe,
            $start,
            $end,
        ) . '.json';
    }

    public static function provenanceFile(
        string $cacheDir,
        string $namespace,
        string $symbol,
        string $timeframe,
        string $start,
        string $end,
    ): string {
        // Deliberately do not use a .json suffix: the broad legacy cache
        // provider scans *.json data payloads and must never treat provenance
        // metadata as market bars.
        return rtrim($cacheDir, '/') . '/' . self::canonicalCacheKey(
            $namespace,
            $symbol,
            $timeframe,
            $start,
            $end,
        ) . '.provenance';
    }

    /** @return array<string, mixed> */
    public static function chunkProvenanceRequest(
        string $endpoint,
        string $namespace,
        string $symbol,
        string $timeframe,
        string $feed,
        string $adjustment,
        int $limit,
        string $start,
        string $end,
    ): array {
        $endpoint = rtrim(trim($endpoint), '/');
        if (!in_array($endpoint, [
            'https://data.alpaca.markets/v2/stocks/bars',
            'https://data.sandbox.alpaca.markets/v2/stocks/bars',
        ], true)) {
            throw new \InvalidArgumentException('Chunk provenance endpoint must be an official canonical Alpaca bars endpoint.');
        }
        $namespace = trim($namespace);
        if ($namespace === '') {
            throw new \InvalidArgumentException('Chunk provenance namespace must not be empty.');
        }
        $symbol = self::canonicalSymbol($symbol);
        $timeframe = trim($timeframe);
        if (preg_match('/^[1-9][0-9]*(Min|Hour)$/D', $timeframe) !== 1) {
            throw new \InvalidArgumentException('Chunk provenance timeframe is invalid.');
        }
        $feed = strtolower(trim($feed));
        if (!in_array($feed, ['iex', 'sip', 'otc'], true)) {
            throw new \InvalidArgumentException('Chunk provenance feed is invalid.');
        }
        $adjustment = strtolower(trim($adjustment));
        if (!in_array($adjustment, ['raw', 'split', 'dividend', 'all'], true)) {
            throw new \InvalidArgumentException('Chunk provenance adjustment is invalid.');
        }
        if ($limit < 1 || $limit > 10000) {
            throw new \InvalidArgumentException('Chunk provenance limit must be from 1 through 10000.');
        }
        $start = self::canonicalDate($start, 'chunk provenance start');
        $end = self::canonicalDate($end, 'chunk provenance end');
        if ($start >= $end) {
            throw new \InvalidArgumentException('Chunk provenance end must be later than start.');
        }

        return [
            'provider' => 'alpaca_market_data',
            'endpoint' => $endpoint,
            'namespace' => $namespace,
            'symbol' => $symbol,
            'timeframe' => $timeframe,
            'feed' => $feed,
            'adjustment' => $adjustment,
            'limit' => $limit,
            'sort' => 'asc',
            'start_date' => $start,
            'end_date_exclusive' => $end,
            'api_start' => self::apiBoundary($start),
            'api_end_exclusive' => self::apiBoundary($end),
            'bounds' => '[start,end)',
        ];
    }

    /**
     * @param array<string, mixed> $request
     * @param array{count:int,size_bytes:int,sha256:string} $dataMetadata
     * @return array<string, mixed>
     */
    public static function chunkProvenancePayload(
        array $request,
        string $dataFile,
        array $dataMetadata,
    ): array {
        $canonicalRequest = self::chunkProvenanceRequest(
            (string) ($request['endpoint'] ?? ''),
            (string) ($request['namespace'] ?? ''),
            (string) ($request['symbol'] ?? ''),
            (string) ($request['timeframe'] ?? ''),
            (string) ($request['feed'] ?? ''),
            (string) ($request['adjustment'] ?? ''),
            (int) ($request['limit'] ?? 0),
            (string) ($request['start_date'] ?? ''),
            (string) ($request['end_date_exclusive'] ?? ''),
        );
        if ($request !== $canonicalRequest) {
            throw new \InvalidArgumentException('Chunk provenance request metadata is not exact and canonical.');
        }
        if ($dataFile === '' || basename($dataFile) !== $dataFile || !str_ends_with($dataFile, '.json')) {
            throw new \InvalidArgumentException('Chunk provenance data filename must be a canonical JSON basename.');
        }
        $count = $dataMetadata['count'] ?? null;
        $size = $dataMetadata['size_bytes'] ?? null;
        $sha256 = $dataMetadata['sha256'] ?? null;
        if (
            !is_int($count)
            || $count <= 0
            || !is_int($size)
            || $size <= 0
            || !is_string($sha256)
            || preg_match('/^[a-f0-9]{64}$/D', $sha256) !== 1
        ) {
            throw new \InvalidArgumentException('Chunk provenance data metadata is incomplete or invalid.');
        }

        return [
            'schema_version' => self::PROVENANCE_SCHEMA_VERSION,
            'kind' => 'alpaca_intraday_chunk_provenance',
            'request' => $canonicalRequest,
            'data' => [
                'file' => $dataFile,
                'sha256' => $sha256,
                'size_bytes' => $size,
                'count' => $count,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{size_bytes:int,sha256:string}
     */
    public static function writeChunkProvenanceAtomically(string $path, array $payload): array
    {
        self::assertCanonicalChunkProvenancePayload($payload);
        self::writeJsonAtomically($path, $payload);

        return self::readVerifiedChunkProvenance($path, $payload)['metadata'];
    }

    /**
     * @param array<string, mixed> $expectedPayload
     * @return array{payload:array<string,mixed>,metadata:array{size_bytes:int,sha256:string}}
     */
    public static function readVerifiedChunkProvenance(string $path, array $expectedPayload): array
    {
        self::assertCanonicalChunkProvenancePayload($expectedPayload);
        $bytes = self::readFile($path);
        try {
            $payload = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Invalid Alpaca chunk provenance JSON: ' . $path, 0, $e);
        }
        if (!is_array($payload) || array_is_list($payload)) {
            throw new \RuntimeException('Alpaca chunk provenance root must be a JSON object: ' . $path);
        }
        if ($payload !== $expectedPayload || $bytes !== self::encodeCanonicalJson($expectedPayload)) {
            throw new \RuntimeException('Alpaca chunk provenance does not exactly match the requested feed and data file: ' . basename($path));
        }

        return [
            'payload' => $payload,
            'metadata' => [
                'size_bytes' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
            ],
        ];
    }

    /** @param list<string> $symbols */
    public static function manifestFile(
        string $cacheDir,
        string $namespace,
        array $symbols,
        string $timeframe,
        string $start,
        string $end,
    ): string {
        $symbols = array_values(array_unique(array_map([self::class, 'canonicalSymbol'], $symbols)));
        sort($symbols, SORT_STRING);
        if ($symbols === []) {
            throw new \InvalidArgumentException('At least one symbol is required for a snapshot manifest.');
        }
        $namespace = trim($namespace);
        if ($namespace === '') {
            throw new \InvalidArgumentException('Snapshot namespace must not be empty.');
        }
        $key = sha1(implode('|', [
            'alpaca-intraday-snapshot-manifest-v2',
            $namespace,
            implode(',', $symbols),
            trim($timeframe),
            self::canonicalDate($start, 'start'),
            self::canonicalDate($end, 'end'),
        ]));

        return rtrim($cacheDir, '/') . '/' . $key . '.manifest.json';
    }

    /** @return list<array{start:string,end:string}> */
    public static function yearlyRanges(string $start, string $end): array
    {
        $start = self::canonicalDate($start, 'start');
        $end = self::canonicalDate($end, 'end');
        if ($start >= $end) {
            throw new \InvalidArgumentException('Snapshot end must be later than start.');
        }

        $cursor = new \DateTimeImmutable($start, new \DateTimeZone('UTC'));
        $finish = new \DateTimeImmutable($end, new \DateTimeZone('UTC'));
        $ranges = [];
        while ($cursor < $finish) {
            $nextYear = new \DateTimeImmutable(($cursor->format('Y') + 1) . '-01-01', new \DateTimeZone('UTC'));
            $chunkEnd = $nextYear < $finish ? $nextYear : $finish;
            $ranges[] = [
                'start' => $cursor->format('Y-m-d'),
                'end' => $chunkEnd->format('Y-m-d'),
            ];
            $cursor = $chunkEnd;
        }

        return $ranges;
    }

    /**
     * Merge one Alpaca response page into a timestamp-keyed canonical series.
     * Identical pagination overlap is removed; disagreeing overlap fails closed.
     *
     * @param array<string, array{symbol:string,time:string,open:float,high:float,low:float,close:float,volume:float}> $rowsByTimestamp
     * @param list<array<string, mixed>> $apiRows
     * @return array{accepted:int,filtered_out_of_bounds:int,identical_duplicates:int}
     */
    public static function mergeApiRows(
        array &$rowsByTimestamp,
        string $symbol,
        array $apiRows,
        string $start,
        string $end,
    ): array {
        $symbol = self::canonicalSymbol($symbol);
        $startDate = self::canonicalDate($start, 'start');
        $endDate = self::canonicalDate($end, 'end');
        $startTime = new \DateTimeImmutable(self::apiBoundary($startDate));
        $endTime = new \DateTimeImmutable(self::apiBoundary($endDate));
        if ($startTime >= $endTime) {
            throw new \InvalidArgumentException('Chunk end must be later than start.');
        }

        $stats = ['accepted' => 0, 'filtered_out_of_bounds' => 0, 'identical_duplicates' => 0];
        foreach ($apiRows as $index => $raw) {
            if (!is_array($raw)) {
                throw new \RuntimeException('Alpaca bar row ' . $index . ' is not an object.');
            }
            $row = self::canonicalApiRow($symbol, $raw);
            $time = new \DateTimeImmutable($row['time']);
            if ($time < $startTime || $time >= $endTime) {
                $stats['filtered_out_of_bounds']++;
                continue;
            }

            $key = $row['time'];
            if (isset($rowsByTimestamp[$key])) {
                if ($rowsByTimestamp[$key] !== $row) {
                    throw new \RuntimeException('Conflicting Alpaca bar for ' . $symbol . ' at ' . $key . '.');
                }
                $stats['identical_duplicates']++;
                continue;
            }
            $rowsByTimestamp[$key] = $row;
            $stats['accepted']++;
        }

        return $stats;
    }

    /**
     * @param array<string, array{symbol:string,time:string,open:float,high:float,low:float,close:float,volume:float}> $rowsByTimestamp
     * @return list<array{symbol:string,time:string,open:float,high:float,low:float,close:float,volume:float}>
     */
    public static function finalizeRows(array $rowsByTimestamp, string $symbol, string $start, string $end): array
    {
        if ($rowsByTimestamp === []) {
            throw new \RuntimeException(sprintf(
                'Alpaca returned an empty chunk for %s, %s..%s.',
                self::canonicalSymbol($symbol),
                self::canonicalDate($start),
                self::canonicalDate($end),
            ));
        }
        ksort($rowsByTimestamp, SORT_STRING);
        $rows = array_values($rowsByTimestamp);
        self::verifyRows($rows, self::canonicalSymbol($symbol), $start, $end);

        return $rows;
    }

    /**
     * @param list<array{symbol:string,time:string,open:float,high:float,low:float,close:float,volume:float}> $rows
     * @return array{count:int,size_bytes:int,sha256:string}
     */
    public static function writeCacheFileAtomically(
        string $path,
        string $symbol,
        array $rows,
        string $start,
        string $end,
    ): array {
        $symbol = self::canonicalSymbol($symbol);
        self::verifyRows($rows, $symbol, $start, $end);
        self::writeJsonAtomically($path, [$symbol => $rows]);

        return self::verifyCacheFile($path, $symbol, $start, $end);
    }

    /** @return array{count:int,size_bytes:int,sha256:string} */
    public static function verifyCacheFile(
        string $path,
        string $symbol,
        string $start,
        string $end,
    ): array {
        return self::readVerifiedCacheFile($path, $symbol, $start, $end)['metadata'];
    }

    /**
     * @return array{
     *   metadata:array{count:int,size_bytes:int,sha256:string},
     *   rows:list<array<string, mixed>>
     * }
     */
    public static function readVerifiedCacheFile(
        string $path,
        string $symbol,
        string $start,
        string $end,
    ): array {
        $bytes = self::readFile($path);
        try {
            $payload = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Invalid cached snapshot JSON: ' . $path, 0, $e);
        }
        if (!is_array($payload)) {
            throw new \RuntimeException('Cached snapshot root must be an object: ' . $path);
        }

        $symbol = self::canonicalSymbol($symbol);
        if (array_keys($payload) !== [$symbol] || !is_array($payload[$symbol]) || !array_is_list($payload[$symbol])) {
            throw new \RuntimeException('Cached snapshot must contain exactly one canonical symbol series: ' . $symbol);
        }
        /** @var list<array<string, mixed>> $rows */
        $rows = $payload[$symbol];
        self::verifyRows($rows, $symbol, $start, $end);

        return [
            'metadata' => [
                'count' => count($rows),
                'size_bytes' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @return array{
     *   total_bars:int,
     *   regular_session_bars:int,
     *   non_regular_session_bars:int,
     *   bars_per_session:array<string,int>,
     *   last_timestamp_by_session:array<string,int>,
     *   within_session_gaps_minutes:list<float>,
     *   special_close_sessions:array<string,bool>
     * }
     */
    public static function newRegularSessionAccumulator(): array
    {
        return [
            'total_bars' => 0,
            'regular_session_bars' => 0,
            'non_regular_session_bars' => 0,
            'bars_per_session' => [],
            'last_timestamp_by_session' => [],
            'within_session_gaps_minutes' => [],
            'special_close_sessions' => [],
        ];
    }

    /**
     * Accumulate only regular-session bar starts. The shared calendar handles
     * historical half days, so after-hours prints cannot inflate density.
     *
     * @param array<string, mixed> $accumulator
     * @param list<array<string, mixed>> $rows
     */
    public static function accumulateRegularSessionRows(array &$accumulator, array $rows): void
    {
        $timezone = new \DateTimeZone('America/New_York');
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['time'] ?? null)) {
                throw new \RuntimeException('Cannot compute density from an invalid snapshot row.');
            }
            $time = (new \DateTimeImmutable($row['time']))->setTimezone($timezone);
            $session = $time->format('Y-m-d');
            $clock = $time->format('H:i');
            $accumulator['total_bars'] = (int) ($accumulator['total_bars'] ?? 0) + 1;
            if (!UsEquitySessionCalendar::isRegularBarStart($session, $clock)) {
                $accumulator['non_regular_session_bars'] = (int) ($accumulator['non_regular_session_bars'] ?? 0) + 1;
                continue;
            }

            $accumulator['regular_session_bars'] = (int) ($accumulator['regular_session_bars'] ?? 0) + 1;
            $accumulator['bars_per_session'][$session] = (int) ($accumulator['bars_per_session'][$session] ?? 0) + 1;
            $timestamp = $time->getTimestamp();
            if (isset($accumulator['last_timestamp_by_session'][$session])) {
                $gap = ($timestamp - (int) $accumulator['last_timestamp_by_session'][$session]) / 60.0;
                if ($gap <= 0.0) {
                    throw new \RuntimeException('Regular-session density input is duplicated or out of order at ' . $row['time'] . '.');
                }
                $accumulator['within_session_gaps_minutes'][] = $gap;
            }
            $accumulator['last_timestamp_by_session'][$session] = $timestamp;
            if (UsEquitySessionCalendar::isSpecialClose($session)) {
                $accumulator['special_close_sessions'][$session] = true;
            }
        }
    }

    /** @param array<string, mixed> $accumulator @return array<string, mixed> */
    public static function regularSessionDiagnostics(array $accumulator, string $timeframe): array
    {
        $barsPerSession = array_map('intval', array_values($accumulator['bars_per_session'] ?? []));
        $gaps = array_map('floatval', array_values($accumulator['within_session_gaps_minutes'] ?? []));
        $specialCloses = array_keys($accumulator['special_close_sessions'] ?? []);
        sort($specialCloses, SORT_STRING);
        $p90Gap = self::percentile($gaps, 0.90);
        $timeframeMinutes = self::timeframeMinutes($timeframe);

        return [
            'basis' => 'observed_sessions_only',
            'timezone' => 'America/New_York',
            'session_filter' => UsEquitySessionCalendar::class,
            'total_bars' => (int) ($accumulator['total_bars'] ?? 0),
            'regular_session_bars' => (int) ($accumulator['regular_session_bars'] ?? 0),
            'non_regular_session_bars_filtered' => (int) ($accumulator['non_regular_session_bars'] ?? 0),
            'observed_regular_sessions' => count($barsPerSession),
            'p10_regular_bars_per_observed_session' => self::percentile($barsPerSession, 0.10),
            'median_regular_bars_per_observed_session' => self::percentile($barsPerSession, 0.50),
            'median_within_session_gap_minutes' => self::percentile($gaps, 0.50),
            'p90_within_session_gap_minutes' => $p90Gap,
            'max_within_session_gap_minutes' => $gaps === [] ? null : max($gaps),
            'special_close_sessions_observed' => count($specialCloses),
            'special_close_session_dates' => $specialCloses,
            'sparse_gap_warning' => $p90Gap !== null && $p90Gap > $timeframeMinutes * 2.0,
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array{size_bytes:int,sha256:string}
     */
    public static function writeManifestAtomically(string $path, array $manifest): array
    {
        if (($manifest['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new \RuntimeException('Snapshot manifest schema_version is missing or unsupported.');
        }
        if (($manifest['complete'] ?? null) !== true) {
            throw new \RuntimeException('Refusing to publish an incomplete snapshot manifest.');
        }
        if (!isset($manifest['request']) || !is_array($manifest['request'])) {
            throw new \RuntimeException('Snapshot manifest request metadata is required.');
        }
        if (!isset($manifest['chunks']) || !is_array($manifest['chunks']) || $manifest['chunks'] === []) {
            throw new \RuntimeException('Snapshot manifest must contain at least one chunk.');
        }
        foreach ($manifest['chunks'] as $chunk) {
            if (
                !is_array($chunk)
                || !isset(
                    $chunk['count'],
                    $chunk['size_bytes'],
                    $chunk['sha256'],
                    $chunk['request'],
                    $chunk['provenance_file'],
                    $chunk['provenance_size_bytes'],
                    $chunk['provenance_sha256'],
                )
                || !is_int($chunk['count'])
                || $chunk['count'] <= 0
                || !is_int($chunk['size_bytes'])
                || $chunk['size_bytes'] <= 0
                || !is_string($chunk['sha256'])
                || preg_match('/^[a-f0-9]{64}$/D', $chunk['sha256']) !== 1
                || !is_array($chunk['request'])
                || !is_string($chunk['provenance_file'])
                || $chunk['provenance_file'] === ''
                || basename($chunk['provenance_file']) !== $chunk['provenance_file']
                || !str_ends_with($chunk['provenance_file'], '.provenance')
                || !is_int($chunk['provenance_size_bytes'])
                || $chunk['provenance_size_bytes'] <= 0
                || !is_string($chunk['provenance_sha256'])
                || preg_match('/^[a-f0-9]{64}$/D', $chunk['provenance_sha256']) !== 1
            ) {
                throw new \RuntimeException('Snapshot manifest contains an unverifiable chunk.');
            }
        }

        self::writeJsonAtomically($path, $manifest);
        $bytes = self::readFile($path);
        try {
            $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Published snapshot manifest failed JSON readback.', 0, $e);
        }
        if ($decoded !== $manifest) {
            throw new \RuntimeException('Published snapshot manifest did not survive exact readback.');
        }

        return ['size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
    }

    /** @param array<string, mixed> $payload */
    public static function writeJsonAtomically(string $path, array $payload): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create snapshot directory: ' . $directory);
        }

        $json = self::encodeCanonicalJson($payload);
        $temp = $directory . '/.' . basename($path) . '.tmp-' . bin2hex(random_bytes(8));
        try {
            $written = file_put_contents($temp, $json, LOCK_EX);
            if ($written !== strlen($json)) {
                throw new \RuntimeException('Unable to write complete temporary snapshot file: ' . $temp);
            }
            if (!rename($temp, $path)) {
                throw new \RuntimeException('Unable to publish snapshot file atomically: ' . $path);
            }
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }

        if (self::readFile($path) !== $json) {
            throw new \RuntimeException('Published snapshot file failed exact readback: ' . $path);
        }
    }

    public static function canonicalSymbol(string $symbol): string
    {
        $symbol = strtoupper(trim($symbol));
        if (preg_match('/^[A-Z0-9][A-Z0-9.\-]{0,19}$/D', $symbol) !== 1) {
            throw new \InvalidArgumentException('Invalid Alpaca symbol: ' . $symbol);
        }

        return $symbol;
    }

    /** @param array<string, mixed> $payload */
    private static function assertCanonicalChunkProvenancePayload(array $payload): void
    {
        if (
            ($payload['schema_version'] ?? null) !== self::PROVENANCE_SCHEMA_VERSION
            || ($payload['kind'] ?? null) !== 'alpaca_intraday_chunk_provenance'
            || !isset($payload['request'], $payload['data'])
            || !is_array($payload['request'])
            || array_is_list($payload['request'])
            || !is_array($payload['data'])
            || array_is_list($payload['data'])
        ) {
            throw new \RuntimeException('Alpaca chunk provenance payload is missing canonical metadata.');
        }

        $canonical = self::chunkProvenancePayload(
            $payload['request'],
            is_string($payload['data']['file'] ?? null) ? $payload['data']['file'] : '',
            [
                'count' => $payload['data']['count'] ?? null,
                'size_bytes' => $payload['data']['size_bytes'] ?? null,
                'sha256' => $payload['data']['sha256'] ?? null,
            ],
        );
        if ($payload !== $canonical) {
            throw new \RuntimeException('Alpaca chunk provenance payload is not exact and canonical.');
        }
    }

    /** @param array<string, mixed> $payload */
    private static function encodeCanonicalJson(array $payload): string
    {
        return json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    /** @param array<string, mixed> $raw @return array{symbol:string,time:string,open:float,high:float,low:float,close:float,volume:float} */
    private static function canonicalApiRow(string $symbol, array $raw): array
    {
        if (!isset($raw['t']) || !is_string($raw['t']) || trim($raw['t']) === '') {
            throw new \RuntimeException('Alpaca bar timestamp is missing or invalid.');
        }
        try {
            $time = new \DateTimeImmutable($raw['t']);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Alpaca bar timestamp is invalid.', 0, $e);
        }
        $time = $time->setTimezone(new \DateTimeZone('UTC'));

        $values = [];
        foreach (['o' => 'open', 'h' => 'high', 'l' => 'low', 'c' => 'close', 'v' => 'volume'] as $source => $target) {
            if (!array_key_exists($source, $raw) || !is_numeric($raw[$source])) {
                throw new \RuntimeException('Alpaca bar ' . $source . ' is missing or non-numeric.');
            }
            $values[$target] = (float) $raw[$source];
            if (!is_finite($values[$target])) {
                throw new \RuntimeException('Alpaca bar ' . $source . ' is not finite.');
            }
        }
        self::validateOhlcv($values['open'], $values['high'], $values['low'], $values['close'], $values['volume']);

        return [
            'symbol' => $symbol,
            'time' => $time->format('Y-m-d\TH:i:sP'),
            'open' => $values['open'],
            'high' => $values['high'],
            'low' => $values['low'],
            'close' => $values['close'],
            'volume' => $values['volume'],
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private static function verifyRows(array $rows, string $symbol, string $start, string $end): void
    {
        if ($rows === [] || !array_is_list($rows)) {
            throw new \RuntimeException('Snapshot chunk must contain a non-empty list of bars.');
        }
        $startTime = new \DateTimeImmutable(self::apiBoundary($start));
        $endTime = new \DateTimeImmutable(self::apiBoundary($end));
        $previous = null;
        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw new \RuntimeException('Snapshot row ' . $index . ' is not an object.');
            }
            if ((string) ($row['symbol'] ?? '') !== $symbol) {
                throw new \RuntimeException('Snapshot row symbol mismatch at index ' . $index . '.');
            }
            $timeRaw = $row['time'] ?? null;
            if (!is_string($timeRaw) || $timeRaw === '') {
                throw new \RuntimeException('Snapshot row timestamp is missing at index ' . $index . '.');
            }
            try {
                $time = (new \DateTimeImmutable($timeRaw))->setTimezone(new \DateTimeZone('UTC'));
            } catch (\Throwable $e) {
                throw new \RuntimeException('Snapshot row timestamp is invalid at index ' . $index . '.', 0, $e);
            }
            $canonicalTime = $time->format('Y-m-d\TH:i:sP');
            if ($canonicalTime !== $timeRaw) {
                throw new \RuntimeException('Snapshot row timestamp is not canonical UTC at index ' . $index . '.');
            }
            if ($time < $startTime || $time >= $endTime) {
                throw new \RuntimeException('Snapshot row is outside the strict [start,end) chunk at ' . $timeRaw . '.');
            }
            if ($previous !== null && $canonicalTime <= $previous) {
                throw new \RuntimeException('Snapshot rows contain a duplicate or are not strictly sorted at ' . $timeRaw . '.');
            }

            $numbers = [];
            foreach (['open', 'high', 'low', 'close', 'volume'] as $field) {
                if (!array_key_exists($field, $row) || !is_numeric($row[$field])) {
                    throw new \RuntimeException('Snapshot row field ' . $field . ' is missing or non-numeric at ' . $timeRaw . '.');
                }
                $numbers[$field] = (float) $row[$field];
                if (!is_finite($numbers[$field])) {
                    throw new \RuntimeException('Snapshot row field ' . $field . ' is not finite at ' . $timeRaw . '.');
                }
            }
            self::validateOhlcv($numbers['open'], $numbers['high'], $numbers['low'], $numbers['close'], $numbers['volume']);
            $previous = $canonicalTime;
        }
    }

    private static function validateOhlcv(float $open, float $high, float $low, float $close, float $volume): void
    {
        if ($open <= 0.0 || $high <= 0.0 || $low <= 0.0 || $close <= 0.0) {
            throw new \RuntimeException('Snapshot prices must be positive.');
        }
        if ($high < $low || $open > $high || $open < $low || $close > $high || $close < $low) {
            throw new \RuntimeException('Snapshot OHLC values are internally inconsistent.');
        }
        if ($volume < 0.0) {
            throw new \RuntimeException('Snapshot volume must not be negative.');
        }
    }

    private static function readFile(string $path): string
    {
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException('Unable to read snapshot file: ' . $path);
        }

        return $bytes;
    }

    /** @param list<int|float> $values */
    private static function percentile(array $values, float $quantile): ?float
    {
        if ($values === []) {
            return null;
        }
        $values = array_map('floatval', $values);
        sort($values, SORT_NUMERIC);
        $quantile = min(1.0, max(0.0, $quantile));
        $position = (count($values) - 1) * $quantile;
        $lower = (int) floor($position);
        $upper = (int) ceil($position);
        if ($lower === $upper) {
            return $values[$lower];
        }
        $weight = $position - $lower;

        return $values[$lower] * (1.0 - $weight) + $values[$upper] * $weight;
    }

    private static function timeframeMinutes(string $timeframe): int
    {
        if (preg_match('/^([1-9][0-9]*)(Min|Hour)$/D', $timeframe, $matches) !== 1) {
            throw new \InvalidArgumentException('Unsupported intraday timeframe for density diagnostics: ' . $timeframe);
        }

        return (int) $matches[1] * ($matches[2] === 'Hour' ? 60 : 1);
    }
}
