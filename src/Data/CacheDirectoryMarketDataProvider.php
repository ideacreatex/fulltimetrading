<?php

declare(strict_types=1);

namespace FulltimeTrading\Data;

use FulltimeTrading\Domain\Bar;

final readonly class CacheDirectoryMarketDataProvider implements MarketDataProvider
{
    public function __construct(
        private string $cachePath,
        private ?string $namespace = null,
    ) {
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
        $wanted = array_fill_keys($symbols, true);

        if ($this->namespace !== null && $this->namespace !== '') {
            $file = rtrim($this->cachePath, '/') . '/' . sha1(
                $this->namespace . '|' . implode(',', $symbols) . '|' . $timeframe . '|' . $start . '|' . $end,
            ) . '.json';
            if (is_file($file)) {
                $payload = json_decode((string) file_get_contents($file), true);
                if (is_array($payload)) {
                    return $this->decodePayload($payload, $wanted, $timeframe, $start, $end, true);
                }
            }
        }

        $rowsBySymbolTime = $this->loadRowsFromDirectory($wanted, $timeframe, $start, $end);
        $result = array_fill_keys(array_keys($wanted), []);
        foreach ($rowsBySymbolTime as $symbol => $rowsByTime) {
            ksort($rowsByTime);
            $result[$symbol] = array_values($rowsByTime);
        }

        return $result;
    }

    /**
     * @param array<string, bool> $wanted
     * @return array<string, array<string, Bar>>
     */
    private function loadRowsFromDirectory(array $wanted, string $timeframe, string $start, string $end): array
    {
        $rowsBySymbolTime = [];

        foreach (glob(rtrim($this->cachePath, '/') . '/*.json') ?: [] as $file) {
            $payload = json_decode((string) file_get_contents($file), true);
            if (!is_array($payload)) {
                continue;
            }

            foreach ($this->decodePayload($payload, $wanted, $timeframe, $start, $end, false) as $symbol => $bars) {
                foreach ($bars as $bar) {
                    $rowsBySymbolTime[$symbol][$bar->time->format(\DateTimeInterface::ATOM)] = $bar;
                }
            }
        }

        return $rowsBySymbolTime;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, bool> $wanted
     * @return array<string, list<Bar>>
     */
    private function decodePayload(array $payload, array $wanted, string $timeframe, string $start, string $end, bool $trustPayloadTimeframe): array
    {
        $rowsBySymbolTime = [];
        foreach ($payload as $symbol => $rows) {
            $symbol = strtoupper((string) $symbol);
            if (!isset($wanted[$symbol]) || !is_array($rows)) {
                continue;
            }

            foreach ($rows as $row) {
                if (!is_array($row) || (!$trustPayloadTimeframe && !$this->rowMatchesTimeframe($row, $timeframe))) {
                    continue;
                }

                $timeRaw = (string) ($row['time'] ?? '');
                $date = substr($timeRaw, 0, 10);
                if ($date < $start || $date > $end) {
                    continue;
                }

                $time = new \DateTimeImmutable($timeRaw);
                $rowsBySymbolTime[$symbol][$time->format(\DateTimeInterface::ATOM)] = new Bar(
                    $symbol,
                    $time,
                    (float) $row['open'],
                    (float) $row['high'],
                    (float) $row['low'],
                    (float) $row['close'],
                    (float) $row['volume'],
                );
            }
        }

        $result = array_fill_keys(array_keys($wanted), []);
        foreach ($rowsBySymbolTime as $symbol => $rowsByTime) {
            ksort($rowsByTime);
            $result[$symbol] = array_values($rowsByTime);
        }

        return $result;
    }

    /** @param array<string, mixed> $row */
    private function rowMatchesTimeframe(array $row, string $timeframe): bool
    {
        $time = (string) ($row['time'] ?? '');
        if ($time === '') {
            return false;
        }

        $normalized = strtolower($timeframe);
        $isDaily = !str_contains($time, 'T')
            || str_contains($time, 'T00:00:00')
            || str_contains($time, 'T04:00:00')
            || str_contains($time, 'T05:00:00')
            || str_contains($time, ' 00:00:00');

        if (in_array($normalized, ['1day', '1d', 'd', 'day'], true)) {
            return $isDaily;
        }
        if (in_array($normalized, ['1min', '1minute', '1m', 'minute'], true)) {
            return !$isDaily;
        }

        return true;
    }
}
