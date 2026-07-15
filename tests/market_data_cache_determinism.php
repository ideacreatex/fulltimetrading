<?php

declare(strict_types=1);

use FulltimeTrading\Data\CachedMarketDataProvider;
use FulltimeTrading\Data\CacheDirectoryMarketDataProvider;
use FulltimeTrading\Data\MarketDataProvider;
use FulltimeTrading\Domain\Bar;

require __DIR__ . '/../bootstrap.php';

function cacheExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class CountingMarketDataProvider implements MarketDataProvider
{
    public int $calls = 0;

    public function getBars(array $symbols, string $timeframe, string $start, string $end): array
    {
        $this->calls++;
        $result = [];
        foreach ($symbols as $index => $symbol) {
            $result[$symbol] = [new Bar(
                $symbol,
                new DateTimeImmutable($end . ' 16:00:00', new DateTimeZone('America/New_York')),
                100.0 + $index,
                101.0 + $index,
                99.0 + $index,
                100.5 + $index,
                1000.0,
            )];
        }

        return $result;
    }
}

function cacheRemoveTree(string $path): void
{
    foreach (glob($path . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($path);
}

$tempDir = sys_get_temp_dir() . '/ftt-market-cache-' . bin2hex(random_bytes(6));
if (!mkdir($tempDir, 0775, true) && !is_dir($tempDir)) {
    throw new RuntimeException('Unable to create cache test directory.');
}

try {
    $timezone = new DateTimeZone('America/New_York');
    $end = (new DateTimeImmutable('now', $timezone))->modify('-2 days')->format('Y-m-d');
    $inner = new CountingMarketDataProvider();
    $cached = new CachedMarketDataProvider($inner, $tempDir, 'deterministic');

    $first = $cached->getBars(['BBB', 'AAA'], '1Day', '2026-01-01', $end);
    $second = $cached->getBars(['AAA', 'BBB'], '1Day', '2026-01-01', $end);
    cacheExpect($inner->calls === 1, 'Symbol permutations must share one canonical cache key.');
    cacheExpect(array_keys($first) === ['AAA', 'BBB'], 'The inner provider must receive a canonical symbol order.');
    cacheExpect(array_keys($second) === ['AAA', 'BBB'], 'Cached output must preserve the canonical symbol order.');

    $files = glob($tempDir . '/*.json') ?: [];
    cacheExpect(count($files) === 1, 'Exactly one canonical cache artifact must be written.');
    $preClose = (new DateTimeImmutable($end . ' 10:00:00', $timezone))->getTimestamp();
    touch($files[0], $preClose);
    $cached->getBars(['BBB', 'AAA'], '1Day', '2026-01-01', $end);
    cacheExpect($inner->calls === 2, 'A daily cache created before the requested close must be refreshed.');

    $offline = new CacheDirectoryMarketDataProvider($tempDir, 'deterministic');
    $offlineBars = $offline->getBars(['BBB', 'AAA'], '1Day', '2026-01-01', $end);
    cacheExpect(array_keys($offlineBars) === ['AAA', 'BBB'], 'Exact offline reads must also be permutation invariant.');

    try {
        (new CacheDirectoryMarketDataProvider($tempDir, 'missing-namespace'))
            ->getBars(['AAA', 'BBB'], '1Day', '2026-01-01', $end);
        throw new RuntimeException('Expected an exact-cache miss.');
    } catch (RuntimeException $e) {
        cacheExpect(
            str_contains($e->getMessage(), 'Exact cache snapshot is missing'),
            'A namespaced cache miss must fail closed instead of scanning unrelated snapshots.',
        );
    }
} finally {
    cacheRemoveTree($tempDir);
}

echo "Market data cache determinism OK\n";
