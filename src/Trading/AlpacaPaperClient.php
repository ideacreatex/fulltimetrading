<?php

declare(strict_types=1);

namespace FulltimeTrading\Trading;

use FulltimeTrading\Data\HttpClient;

final readonly class AlpacaPaperClient
{
    public function __construct(
        private HttpClient $http,
        private string $baseUrl,
    ) {
        $parts = parse_url($this->baseUrl);
        $valid = is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && ($parts['host'] ?? null) === 'paper-api.alpaca.markets'
            && !isset($parts['port'])
            && !isset($parts['user'])
            && !isset($parts['pass'])
            && !isset($parts['query'])
            && !isset($parts['fragment'])
            && rtrim((string) ($parts['path'] ?? ''), '/') === '/v2';
        if (!$valid) {
            throw new \InvalidArgumentException(
                'Refusing unsafe/non-paper Alpaca trading base URL; expected exactly https://paper-api.alpaca.markets/v2',
            );
        }
    }

    /** @return array<string, mixed> */
    public function account(): array
    {
        $response = $this->http->get(rtrim($this->baseUrl, '/') . '/account', $this->headers(), null, false);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException('Alpaca paper account request failed with HTTP ' . $response['status'] . ': ' . substr($response['body'], 0, 500));
        }

        $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \RuntimeException('Unexpected Alpaca account response.');
        }

        return $payload;
    }

    /** @return list<array<string, mixed>> */
    public function positions(): array
    {
        $response = $this->http->get(rtrim($this->baseUrl, '/') . '/positions', $this->headers(), null, false);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException('Alpaca paper positions request failed with HTTP ' . $response['status'] . ': ' . substr($response['body'], 0, 500));
        }

        $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \RuntimeException('Unexpected Alpaca positions response.');
        }

        return array_values(array_filter($payload, 'is_array'));
    }

    /** @return list<array<string, mixed>> */
    public function openOrders(): array
    {
        $response = $this->http->get(rtrim($this->baseUrl, '/') . '/orders?status=open&nested=false', $this->headers(), null, false);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException('Alpaca paper orders request failed with HTTP ' . $response['status'] . ': ' . substr($response['body'], 0, 500));
        }

        $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \RuntimeException('Unexpected Alpaca orders response.');
        }

        return array_values(array_filter($payload, 'is_array'));
    }

    /** @return array<string, mixed>|null */
    public function order(string $orderId): ?array
    {
        $response = $this->http->get(rtrim($this->baseUrl, '/') . '/orders/' . rawurlencode($orderId), $this->headers(), null, false);

        return $this->orderFromResponse($response, 'order id');
    }

    /** @return array<string, mixed>|null */
    public function orderByClientOrderId(string $clientOrderId): ?array
    {
        $url = rtrim($this->baseUrl, '/') . '/orders:by_client_order_id?client_order_id=' . rawurlencode($clientOrderId);
        $response = $this->http->get($url, $this->headers(), null, false);

        return $this->orderFromResponse($response, 'client order id');
    }

    /** @return array<string, mixed> */
    public function clock(): array
    {
        $response = $this->http->get(rtrim($this->baseUrl, '/') . '/clock', $this->headers(), null, false);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException('Alpaca paper clock request failed with HTTP ' . $response['status'] . ': ' . substr($response['body'], 0, 500));
        }

        $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \RuntimeException('Unexpected Alpaca clock response.');
        }

        return $payload;
    }

    /** @return list<array<string, mixed>> */
    public function calendar(string $start, string $end): array
    {
        foreach ([$start, $end] as $date) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw new \InvalidArgumentException('Alpaca calendar dates must use YYYY-MM-DD.');
            }
        }
        $url = rtrim($this->baseUrl, '/') . '/calendar?start=' . rawurlencode($start) . '&end=' . rawurlencode($end);
        $response = $this->http->get($url, $this->headers(), null, false);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException('Alpaca paper calendar request failed with HTTP ' . $response['status'] . ': ' . substr($response['body'], 0, 500));
        }

        $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \RuntimeException('Unexpected Alpaca calendar response.');
        }

        return array_values(array_filter($payload, 'is_array'));
    }

    /** @return array<string, mixed> */
    public function asset(string $symbol): array
    {
        $symbol = strtoupper(trim($symbol));
        if (!preg_match('/^[A-Z][A-Z0-9.\-]{0,14}$/', $symbol)) {
            throw new \InvalidArgumentException('Invalid Alpaca asset symbol.');
        }
        $response = $this->http->get(
            rtrim($this->baseUrl, '/') . '/assets/' . rawurlencode($symbol),
            $this->headers(),
            null,
            false,
        );
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException('Alpaca paper asset request failed with HTTP ' . $response['status'] . ': ' . substr($response['body'], 0, 500));
        }

        $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \RuntimeException('Unexpected Alpaca asset response.');
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function submitOrder(array $order): array
    {
        $response = $this->http->postJson(rtrim($this->baseUrl, '/') . '/orders', $order, $this->headers(), null, false);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException('Alpaca paper order submit failed with HTTP ' . $response['status'] . ': ' . substr($response['body'], 0, 500));
        }

        $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \RuntimeException('Unexpected Alpaca order response.');
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function cancelOrder(string $orderId): array
    {
        $response = $this->http->delete(rtrim($this->baseUrl, '/') . '/orders/' . rawurlencode($orderId), $this->headers(), null, false);
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException('Alpaca paper cancel order failed with HTTP ' . $response['status'] . ': ' . substr($response['body'], 0, 500));
        }

        return ['status' => $response['status'], 'body' => $response['body']];
    }

    /**
     * @param array{status:int, body:string} $response
     * @return array<string, mixed>|null
     */
    private function orderFromResponse(array $response, string $lookup): ?array
    {
        if ($response['status'] === 404) {
            return null;
        }
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException('Alpaca paper ' . $lookup . ' lookup failed with HTTP ' . $response['status'] . ': ' . substr($response['body'], 0, 500));
        }

        $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \RuntimeException('Unexpected Alpaca order lookup response.');
        }

        return $payload;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        $keyId = getenv('APCA_PAPER_API_KEY_ID') ?: '';
        $secret = getenv('APCA_PAPER_API_SECRET_KEY') ?: '';
        if ($keyId === '' || $secret === '') {
            throw new \RuntimeException('Missing APCA_PAPER_API_KEY_ID/APCA_PAPER_API_SECRET_KEY.');
        }

        return [
            'APCA-API-KEY-ID' => $keyId,
            'APCA-API-SECRET-KEY' => $secret,
        ];
    }
}
