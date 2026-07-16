<?php

declare(strict_types=1);

use FulltimeTrading\Data\HttpClient;
use FulltimeTrading\Trading\AlpacaPaperClient;

require __DIR__ . '/../bootstrap.php';

$client = new AlpacaPaperClient(new HttpClient(), 'https://paper-api.alpaca.markets/v2');
new AlpacaPaperClient(new HttpClient(), 'https://paper-api.alpaca.markets/v2/');

foreach ([
    static fn (): array => $client->calendar('2026/07/16', '2026-07-20'),
    static fn (): array => $client->asset('../orders'),
] as $unsafeRead) {
    try {
        $unsafeRead();
    } catch (InvalidArgumentException) {
        continue;
    }

    throw new RuntimeException('Unsafe read-only Alpaca parameter was accepted.');
}

$unsafe = [
    'http://paper-api.alpaca.markets/v2',
    'https://api.alpaca.markets/v2',
    'https://paper-api.alpaca.markets',
    'https://paper-api.alpaca.markets/v2/orders',
    'https://paper-api.alpaca.markets:443/v2',
    'https://user@paper-api.alpaca.markets/v2',
    'https://paper-api.alpaca.markets/v2?redirect=1',
];

foreach ($unsafe as $baseUrl) {
    try {
        new AlpacaPaperClient(new HttpClient(), $baseUrl);
    } catch (InvalidArgumentException) {
        continue;
    }

    throw new RuntimeException('Unsafe paper base URL was accepted: ' . $baseUrl);
}

echo "Alpaca paper client URL guard OK\n";
