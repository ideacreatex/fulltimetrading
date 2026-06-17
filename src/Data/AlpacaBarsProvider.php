<?php

declare(strict_types=1);

namespace FulltimeTrading\Data;

use FulltimeTrading\Domain\Bar;

final class AlpacaBarsProvider implements MarketDataProvider
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $baseUrl,
        private readonly string $feed,
        private readonly string $adjustment,
        private readonly int $limit,
    ) {
        $host = parse_url($this->baseUrl, PHP_URL_HOST);
        if (!in_array($host, ['data.alpaca.markets', 'data.sandbox.alpaca.markets'], true)) {
            throw new \InvalidArgumentException('AlpacaBarsProvider is data-only. Refusing non-data Alpaca host: ' . (string) $host);
        }
    }

    /**
     * @param list<string> $symbols
     * @return array<string, list<Bar>>
     */
    public function getBars(array $symbols, string $timeframe, string $start, string $end): array
    {
        $keyId = getenv('APCA_DATA_API_KEY_ID') ?: getenv('APCA_API_KEY_ID') ?: '';
        $secret = getenv('APCA_DATA_API_SECRET_KEY') ?: getenv('APCA_API_SECRET_KEY') ?: '';
        if ($keyId === '' || $secret === '') {
            throw new \RuntimeException('Missing APCA_DATA_API_KEY_ID/APCA_DATA_API_SECRET_KEY. Copy .env.example to .env or export env vars.');
        }

        $symbols = array_values(array_unique(array_map(static fn (string $s): string => strtoupper(trim($s)), $symbols)));
        $result = array_fill_keys($symbols, []);
        $pageToken = null;

        do {
            $query = [
                'symbols' => implode(',', $symbols),
                'timeframe' => $timeframe,
                'start' => $start,
                'end' => $end,
                'limit' => (string) $this->limit,
                'adjustment' => $this->adjustment,
                'feed' => $this->feed,
                'sort' => 'asc',
            ];
            if ($pageToken !== null) {
                $query['page_token'] = $pageToken;
            }

            $url = rtrim($this->baseUrl, '/') . '/v2/stocks/bars?' . http_build_query($query);
            $response = $this->http->get($url, [
                'APCA-API-KEY-ID' => $keyId,
                'APCA-API-SECRET-KEY' => $secret,
            ]);

            if ($response['status'] < 200 || $response['status'] >= 300) {
                throw new \RuntimeException('Alpaca request failed with HTTP ' . $response['status'] . ': ' . substr($response['body'], 0, 500));
            }

            $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
            foreach (($payload['bars'] ?? []) as $symbol => $bars) {
                $upper = strtoupper((string) $symbol);
                foreach ($bars as $bar) {
                    $result[$upper][] = Bar::fromAlpaca($upper, $bar);
                }
            }

            $pageToken = $payload['next_page_token'] ?? null;
        } while ($pageToken !== null && $pageToken !== '');

        foreach ($result as &$bars) {
            usort($bars, static fn (Bar $a, Bar $b): int => $a->time <=> $b->time);
        }

        return $result;
    }
}
