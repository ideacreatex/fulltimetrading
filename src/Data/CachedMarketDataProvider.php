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
        $symbols = array_values(array_unique(array_map(
            static fn (string $symbol): string => strtoupper(trim($symbol)),
            $symbols,
        )));
        sort($symbols, SORT_STRING);
        $key = sha1($this->name . '|' . implode(',', $symbols) . '|' . $timeframe . '|' . $start . '|' . $end);
        $file = rtrim($this->cachePath, '/') . '/' . $key . '.json';
        if (is_file($file) && $this->cacheWasCreatedAfterRequestedDailyClose($file, $timeframe, $end)) {
            return $this->decode((string) file_get_contents($file));
        }

        $bars = $this->inner->getBars($symbols, $timeframe, $start, $end);
        $json = json_encode($this->encode($bars), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temp = $file . '.tmp-' . bin2hex(random_bytes(6));
        if (file_put_contents($temp, $json) === false || !rename($temp, $file)) {
            @unlink($temp);
            throw new \RuntimeException('Unable to atomically update market-data cache: ' . $file);
        }

        return $bars;
    }

    private function cacheWasCreatedAfterRequestedDailyClose(string $file, string $timeframe, string $end): bool
    {
        if (!in_array(strtolower($timeframe), ['1day', '1d', 'd', 'day'], true)) {
            return true;
        }
        $timezone = new \DateTimeZone('America/New_York');
        $endDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $end, $timezone);
        if ($endDate === false || $endDate->format('Y-m-d') !== $end) {
            return true;
        }
        $settledAt = $endDate->setTime(16, 15);
        $now = new \DateTimeImmutable('now', $timezone);
        if ($now < $settledAt) {
            return true;
        }
        $modifiedAt = filemtime($file);

        return $modifiedAt !== false && $modifiedAt >= $settledAt->getTimestamp();
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
