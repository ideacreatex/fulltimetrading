<?php

declare(strict_types=1);

namespace FulltimeTrading\Data;

use FulltimeTrading\Domain\Bar;

final class CachedMarketDataProvider implements MarketDataProvider
{
    public function __construct(
        private readonly MarketDataProvider $inner,
        private readonly string $cachePath,
        private readonly string $name,
    ) {
        if (!is_dir($cachePath) && !mkdir($cachePath, 0775, true) && !is_dir($cachePath)) {
            throw new \RuntimeException('Unable to create cache path: ' . $cachePath);
        }
    }

    /**
     * @param list<string> $symbols
     * @return array<string, list<Bar>>
     */
    public function getBars(array $symbols, string $timeframe, string $start, string $end): array
    {
        $key = sha1($this->name . '|' . implode(',', $symbols) . '|' . $timeframe . '|' . $start . '|' . $end);
        $file = rtrim($this->cachePath, '/') . '/' . $key . '.json';
        if (is_file($file)) {
            return $this->decode((string) file_get_contents($file));
        }

        $bars = $this->inner->getBars($symbols, $timeframe, $start, $end);
        file_put_contents($file, json_encode($this->encode($bars), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $bars;
    }

    /**
     * @param array<string, list<Bar>> $bars
     * @return array<string, list<array<string, mixed>>>
     */
    private function encode(array $bars): array
    {
        $encoded = [];
        foreach ($bars as $symbol => $series) {
            $encoded[$symbol] = array_map(static fn (Bar $bar): array => [
                'symbol' => $bar->symbol,
                'time' => $bar->time->format(\DateTimeInterface::ATOM),
                'open' => $bar->open,
                'high' => $bar->high,
                'low' => $bar->low,
                'close' => $bar->close,
                'volume' => $bar->volume,
            ], $series);
        }

        return $encoded;
    }

    /** @return array<string, list<Bar>> */
    private function decode(string $json): array
    {
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $decoded = [];
        foreach ($payload as $symbol => $series) {
            $decoded[$symbol] = array_map(static fn (array $row): Bar => new Bar(
                (string) $row['symbol'],
                new \DateTimeImmutable((string) $row['time']),
                (float) $row['open'],
                (float) $row['high'],
                (float) $row['low'],
                (float) $row['close'],
                (float) $row['volume'],
            ), $series);
        }

        return $decoded;
    }
}
