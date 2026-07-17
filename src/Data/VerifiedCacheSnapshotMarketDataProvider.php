<?php

declare(strict_types=1);

namespace FulltimeTrading\Data;

use FulltimeTrading\Domain\Bar;

/**
 * Reads one immutable, exact-key market-data cache artifact.
 *
 * Unlike CacheDirectoryMarketDataProvider this class never scans, falls back,
 * refreshes, or accepts a cache payload whose bytes differ from the configured
 * snapshot hash. It is intended for a frozen research-history boundary.
 */
final class VerifiedCacheSnapshotMarketDataProvider implements MarketDataProvider
{
    /** @var ?array<string, mixed> */
    private ?array $lastProvenance = null;

    public function __construct(
        private readonly string $cachePath,
        private readonly string $namespace,
        private readonly string $expectedSha256,
        private readonly string $providerName,
        private readonly string $feed,
        private readonly string $adjustment,
    ) {
        if (trim($this->namespace) === '') {
            throw new \InvalidArgumentException('Verified cache namespace must not be empty.');
        }
        if (preg_match('/^[a-f0-9]{64}$/D', $this->expectedSha256) !== 1) {
            throw new \InvalidArgumentException('Verified cache SHA-256 must be lowercase hexadecimal.');
        }
        foreach ([
            'provider name' => $this->providerName,
            'feed' => $this->feed,
            'adjustment' => $this->adjustment,
        ] as $label => $value) {
            if (trim($value) === '') {
                throw new \InvalidArgumentException('Verified cache ' . $label . ' must not be empty.');
            }
        }
    }

    /**
     * @param list<string> $symbols
     * @return array<string, list<Bar>>
     */
    public function getBars(array $symbols, string $timeframe, string $start, string $end): array
    {
        $this->lastProvenance = null;
        $symbols = $this->canonicalSymbols($symbols);
        $file = rtrim($this->cachePath, '/') . '/' . sha1(
            $this->namespace . '|' . implode(',', $symbols) . '|' . $timeframe . '|' . $start . '|' . $end,
        ) . '.json';
        if (!is_file($file) || is_link($file)) {
            throw new \RuntimeException('Verified cache snapshot is missing or is not a regular file: ' . basename($file));
        }

        $json = file_get_contents($file);
        if (!is_string($json)) {
            throw new \RuntimeException('Unable to read verified cache snapshot: ' . basename($file));
        }
        $actualSha256 = hash('sha256', $json);
        if (!hash_equals($this->expectedSha256, $actualSha256)) {
            throw new \RuntimeException(sprintf(
                'Verified cache snapshot SHA-256 mismatch for %s: expected %s, got %s.',
                basename($file),
                $this->expectedSha256,
                $actualSha256,
            ));
        }

        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException('Verified cache snapshot JSON is invalid: ' . basename($file), 0, $e);
        }
        if (!is_array($payload) || array_is_list($payload)) {
            throw new \RuntimeException('Verified cache snapshot root must be a symbol-keyed object.');
        }

        $bars = [];
        foreach ($payload as $symbol => $rows) {
            if (!is_string($symbol) || !is_array($rows) || !array_is_list($rows)) {
                throw new \RuntimeException('Verified cache snapshot contains a malformed symbol series.');
            }
            $bars[$symbol] = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    throw new \RuntimeException('Verified cache snapshot contains a malformed bar row for ' . $symbol . '.');
                }
                foreach (['symbol', 'time', 'open', 'high', 'low', 'close', 'volume'] as $field) {
                    if (!array_key_exists($field, $row)) {
                        throw new \RuntimeException(sprintf(
                            'Verified cache snapshot bar for %s is missing %s.',
                            $symbol,
                            $field,
                        ));
                    }
                }
                foreach (['open', 'high', 'low', 'close', 'volume'] as $field) {
                    if (!is_int($row[$field]) && !is_float($row[$field])) {
                        throw new \RuntimeException(sprintf(
                            'Verified cache snapshot numeric field %s is invalid for %s.',
                            $field,
                            $symbol,
                        ));
                    }
                }
                try {
                    $time = new \DateTimeImmutable((string) $row['time']);
                } catch (\Throwable $e) {
                    throw new \RuntimeException('Verified cache snapshot time is invalid for ' . $symbol . '.', 0, $e);
                }
                $bars[$symbol][] = new Bar(
                    (string) $row['symbol'],
                    $time,
                    (float) $row['open'],
                    (float) $row['high'],
                    (float) $row['low'],
                    (float) $row['close'],
                    (float) $row['volume'],
                );
            }
        }

        $size = strlen($json);
        $this->lastProvenance = [
            'provider' => $this->providerName,
            'feed' => strtolower($this->feed),
            'adjustment' => strtolower($this->adjustment),
            'storage' => 'immutable_exact_cache_snapshot',
            'namespace' => $this->namespace,
            'file' => basename($file),
            'size_bytes' => $size,
            'sha256' => $actualSha256,
            'expected_sha256' => $this->expectedSha256,
            'request' => [
                'symbols' => $symbols,
                'timeframe' => $timeframe,
                'start' => $start,
                'end' => $end,
            ],
        ];

        return $bars;
    }

    /** @return array<string, mixed> */
    public function provenance(): array
    {
        if ($this->lastProvenance === null) {
            throw new \LogicException('Verified cache provenance is unavailable before a successful read.');
        }

        return $this->lastProvenance;
    }

    /** @param list<string> $symbols @return list<string> */
    private function canonicalSymbols(array $symbols): array
    {
        $canonical = [];
        foreach ($symbols as $symbol) {
            $symbol = strtoupper(trim($symbol));
            if ($symbol === '' || preg_match('/^[A-Z0-9.\-]+$/D', $symbol) !== 1) {
                throw new \InvalidArgumentException('Verified cache request contains an invalid symbol.');
            }
            $canonical[$symbol] = true;
        }
        if ($canonical === []) {
            throw new \InvalidArgumentException('Verified cache request must contain at least one symbol.');
        }
        $symbols = array_keys($canonical);
        sort($symbols, SORT_STRING);

        return $symbols;
    }
}
