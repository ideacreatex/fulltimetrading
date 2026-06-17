<?php

declare(strict_types=1);

namespace FulltimeTrading\Data;

use FulltimeTrading\Domain\Bar;

final class YahooChartProvider implements MarketDataProvider
{
    public function __construct(private readonly HttpClient $http)
    {
    }

    /**
     * @param list<string> $symbols
     * @return array<string, list<Bar>>
     */
    public function getBars(array $symbols, string $timeframe, string $start, string $end): array
    {
        if (!in_array($timeframe, ['1Day', '1D', 'D'], true)) {
            throw new \InvalidArgumentException('Yahoo chart provider supports daily bars only.');
        }

        $period1 = (new \DateTimeImmutable($start . ' 00:00:00 UTC'))->getTimestamp();
        $period2 = (new \DateTimeImmutable($end . ' 23:59:59 UTC'))->getTimestamp();
        $result = [];

        foreach ($symbols as $symbol) {
            $symbol = strtoupper(trim($symbol));
            $url = 'https://query1.finance.yahoo.com/v8/finance/chart/' . rawurlencode($symbol)
                . '?' . http_build_query([
                    'period1' => $period1,
                    'period2' => $period2,
                    'interval' => '1d',
                    'events' => 'history',
                    'includeAdjustedClose' => 'true',
                ]);

            $response = $this->http->get($url);
            if ($response['status'] === 404) {
                $result[$symbol] = [];
                continue;
            }
            if ($response['status'] < 200 || $response['status'] >= 300) {
                throw new \RuntimeException('Yahoo request failed with HTTP ' . $response['status'] . ': ' . substr($response['body'], 0, 200));
            }
            if (str_starts_with(trim($response['body']), 'Edge:')) {
                throw new \RuntimeException('Yahoo rate limit/challenge: ' . trim($response['body']));
            }

            $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
            $chart = $payload['chart']['result'][0] ?? null;
            if (!is_array($chart)) {
                $result[$symbol] = [];
                continue;
            }

            $timestamps = $chart['timestamp'] ?? [];
            $quote = $chart['indicators']['quote'][0] ?? [];
            $bars = [];
            foreach ($timestamps as $i => $timestamp) {
                if (($quote['open'][$i] ?? null) === null || ($quote['close'][$i] ?? null) === null) {
                    continue;
                }
                $bars[] = new Bar(
                    $symbol,
                    (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('UTC')),
                    (float) $quote['open'][$i],
                    (float) $quote['high'][$i],
                    (float) $quote['low'][$i],
                    (float) $quote['close'][$i],
                    (float) ($quote['volume'][$i] ?? 0),
                );
            }
            $result[$symbol] = $bars;
        }

        return $result;
    }
}
