<?php

declare(strict_types=1);

namespace FulltimeTrading\Data;

use FulltimeTrading\Domain\Bar;

final class StooqBarsProvider implements MarketDataProvider
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $baseUrl,
    ) {
    }

    /**
     * @param list<string> $symbols
     * @return array<string, list<Bar>>
     */
    public function getBars(array $symbols, string $timeframe, string $start, string $end): array
    {
        if (!in_array($timeframe, ['1Day', '1D', 'D'], true)) {
            throw new \InvalidArgumentException('Stooq provider supports daily bars only.');
        }

        $result = [];
        foreach ($symbols as $symbol) {
            $symbol = strtoupper(trim($symbol));
            $stooqSymbol = strtolower($symbol) . '.us';
            $query = [
                's' => $stooqSymbol,
                'd1' => str_replace('-', '', $start),
                'd2' => str_replace('-', '', $end),
                'i' => 'd',
            ];
            $url = $this->baseUrl . '?' . http_build_query($query);
            $response = $this->getCsvResponse($url);
            if ($response['status'] < 200 || $response['status'] >= 300) {
                throw new \RuntimeException('Stooq request failed with HTTP ' . $response['status'] . ': ' . $symbol);
            }

            $rows = $this->parseCsv($response['body']);
            $result[$symbol] = array_map(static fn (array $row): Bar => Bar::fromCsvRow($symbol, $row), $rows);
        }

        return $result;
    }

    /** @return array{status:int, body:string} */
    private function getCsvResponse(string $url): array
    {
        $cookieJar = sys_get_temp_dir() . '/stooq_' . md5($url) . '.cookie';
        $response = $this->http->get($url, [], $cookieJar);
        if (!$this->isVerificationPage($response['body'])) {
            return $response;
        }

        $challenge = $this->parseVerificationChallenge($response['body']);
        if ($challenge === null) {
            return $response;
        }

        [$challengeCode, $difficulty] = $challenge;
        $nonce = $this->solveVerificationNonce($challengeCode, $difficulty);
        $verifyUrl = $this->verificationUrl($url);
        $this->http->postForm($verifyUrl, ['c' => $challengeCode, 'n' => (string) $nonce], [], $cookieJar);

        return $this->http->get($url, [], $cookieJar);
    }

    private function isVerificationPage(string $body): bool
    {
        return str_contains($body, '/__verify')
            || str_contains($body, 'crypto.subtle.digest')
            || str_starts_with(ltrim($body), '<!DOCTYPE html>')
            || str_starts_with(ltrim($body), '<html');
    }

    /** @return array{string, int}|null */
    private function parseVerificationChallenge(string $body): ?array
    {
        if (!preg_match('/const\s+c\s*=\s*"([^"]+)"\s*,\s*d\s*=\s*(\d+)/', $body, $matches)) {
            return null;
        }

        return [(string) $matches[1], (int) $matches[2]];
    }

    private function solveVerificationNonce(string $challengeCode, int $difficulty): int
    {
        $prefix = str_repeat('0', $difficulty);
        for ($nonce = 0; $nonce < 10000000; $nonce++) {
            if (str_starts_with(hash('sha256', $challengeCode . $nonce), $prefix)) {
                return $nonce;
            }
        }

        throw new \RuntimeException('Unable to solve Stooq verification challenge.');
    }

    private function verificationUrl(string $url): string
    {
        $parts = parse_url($url);
        if (!isset($parts['scheme'], $parts['host'])) {
            throw new \RuntimeException('Invalid Stooq URL: ' . $url);
        }

        return $parts['scheme'] . '://' . $parts['host'] . '/__verify';
    }

    /**
     * @return list<array<string, string>>
     */
    private function parseCsv(string $csv): array
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r\n|\n|\r/', $csv) ?: [])));
        if ($lines === [] || str_contains($lines[0], 'No data')) {
            return [];
        }
        if ($this->isVerificationPage($lines[0])) {
            throw new \RuntimeException('Stooq returned an HTML verification page instead of CSV. Use Alpaca, yahoo, or local CSV for automated runs.');
        }

        $header = str_getcsv(array_shift($lines), ',', '"', '\\');
        $rows = [];
        foreach ($lines as $line) {
            $values = str_getcsv($line, ',', '"', '\\');
            if (count($values) !== count($header)) {
                continue;
            }
            /** @var array<string, string> $row */
            $row = array_combine($header, $values);
            $rows[] = $row;
        }

        return $rows;
    }
}
