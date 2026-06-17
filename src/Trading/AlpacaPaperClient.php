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
        $host = parse_url($this->baseUrl, PHP_URL_HOST);
        if ($host !== 'paper-api.alpaca.markets') {
            throw new \InvalidArgumentException('Refusing non-paper Alpaca trading host: ' . (string) $host);
        }
    }

    /** @return array<string, mixed> */
    public function account(): array
    {
        $response = $this->http->get(rtrim($this->baseUrl, '/') . '/account', $this->headers());
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
        $response = $this->http->get(rtrim($this->baseUrl, '/') . '/positions', $this->headers());
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
        $response = $this->http->get(rtrim($this->baseUrl, '/') . '/orders?status=open&nested=false', $this->headers());
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException('Alpaca paper orders request failed with HTTP ' . $response['status'] . ': ' . substr($response['body'], 0, 500));
        }

        $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \RuntimeException('Unexpected Alpaca orders response.');
        }

        return array_values(array_filter($payload, 'is_array'));
    }

    /** @return array<string, mixed> */
    public function clock(): array
    {
        $response = $this->http->get(rtrim($this->baseUrl, '/') . '/clock', $this->headers());
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException('Alpaca paper clock request failed with HTTP ' . $response['status'] . ': ' . substr($response['body'], 0, 500));
        }

        $payload = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \RuntimeException('Unexpected Alpaca clock response.');
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    public function submitOrder(array $order): array
    {
        $response = $this->http->postJson(rtrim($this->baseUrl, '/') . '/orders', $order, $this->headers());
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
        $response = $this->http->delete(rtrim($this->baseUrl, '/') . '/orders/' . rawurlencode($orderId), $this->headers());
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException('Alpaca paper cancel order failed with HTTP ' . $response['status'] . ': ' . substr($response['body'], 0, 500));
        }

        return ['status' => $response['status'], 'body' => $response['body']];
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
