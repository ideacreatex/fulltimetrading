#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Support\Config;

require __DIR__ . '/../bootstrap.php';

$config = Config::fromFile(__DIR__ . '/../config/config.php');
$paperBaseUrl = getenv('APCA_PAPER_BASE_URL')
    ?: (string) $config->get('trading.alpaca.paper_base_url', 'https://paper-api.alpaca.markets/v2');

$checks = [
    'data_key' => present('APCA_DATA_API_KEY_ID') || present('APCA_API_KEY_ID'),
    'data_secret' => present('APCA_DATA_API_SECRET_KEY') || present('APCA_API_SECRET_KEY'),
    'paper_key' => present('APCA_PAPER_API_KEY_ID'),
    'paper_secret' => present('APCA_PAPER_API_SECRET_KEY'),
    'paper_base_url' => $paperBaseUrl,
    'paper_base_host_ok' => parse_url($paperBaseUrl, PHP_URL_HOST) === 'paper-api.alpaca.markets',
    'paper_account_id_set' => present('APCA_PAPER_ACCOUNT_ID'),
    'paper_account_label' => getenv('APCA_PAPER_ACCOUNT_LABEL') ?: null,
    'paper_expected_multiplier' => getenv('APCA_PAPER_EXPECTED_MULTIPLIER') ?: null,
    'paper_expected_shorting_enabled' => getenv('APCA_PAPER_EXPECTED_SHORTING_ENABLED') ?: null,
    'orders_enabled' => (bool) $config->get('trading.alpaca.orders_enabled', false),
    'paper_only' => (bool) $config->get('trading.alpaca.paper_only', true),
];

echo json_encode($checks, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";

function present(string $key): bool
{
    $value = getenv($key);

    return is_string($value) && trim($value) !== '';
}
