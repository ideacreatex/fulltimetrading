<?php

declare(strict_types=1);

namespace FulltimeTrading\Research;

use FulltimeTrading\Data\FrozenSipIexDailyBarsProvider;
use FulltimeTrading\Data\MarketDataProvider;
use FulltimeTrading\Data\VerifiedCacheSnapshotMarketDataProvider;

final class OfflineHybridDataset
{
    public static function load(string $root, string $end): array
    {
        $profile = require $root . '/config/tactical_rotation.php';
        $paper = require $root . '/config/tactical_paper.php';
        $data = $paper['data'];
        $symbols = array_values(array_unique(array_merge($profile['universe'], ['SPY', 'QQQ'])));
        sort($symbols, SORT_STRING);
        $offline = new class($root . '/var/cache', $data['fresh_cache_namespace']) implements MarketDataProvider {
            public array $manifest = [];
            public function __construct(private string $cache, private string $namespace) {}
            public function getBars(array $symbols, string $timeframe, string $start, string $end): array
            {
                sort($symbols, SORT_STRING);
                $file = $this->cache . '/' . sha1($this->namespace . '|' . implode(',', $symbols) . '|' . $timeframe . '|' . $start . '|' . $end) . '.json';
                if (!is_file($file)) {
                    throw new \RuntimeException('Required offline cache is missing: ' . basename($file));
                }
                $provider = new VerifiedCacheSnapshotMarketDataProvider($this->cache, $this->namespace, hash_file('sha256', $file), 'Alpaca', 'iex', 'split');
                $bars = $provider->getBars($symbols, $timeframe, $start, $end);
                $this->manifest[] = $provider->provenance();
                return $bars;
            }
        };
        $provider = new FrozenSipIexDailyBarsProvider(
            new VerifiedCacheSnapshotMarketDataProvider($root . '/var/cache', $data['cache_namespace'], $data['historical_snapshot_sha256'], 'Alpaca', 'sip', 'split'),
            $offline, $data['historical_cutoff'], $data['fresh_cache_namespace'], $data['cross_feed_audit'],
        );
        $bars = $provider->getBars($symbols, '1Day', '2020-01-01', $end);
        return ['profile' => $profile, 'bars' => $bars, 'provenance' => $provider->provenance(), 'files' => $offline->manifest];
    }
}
