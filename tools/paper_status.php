#!/usr/bin/env php
<?php

declare(strict_types=1);

use FulltimeTrading\Storage\SqliteRepository;
use FulltimeTrading\Support\Config;

require __DIR__ . '/../bootstrap.php';

$options = [
    'limit' => '20',
    'json' => 'false',
];

foreach (array_slice($argv, 1) as $arg) {
    if (!str_starts_with($arg, '--') || !str_contains($arg, '=')) {
        continue;
    }
    [$key, $value] = explode('=', substr($arg, 2), 2);
    $options[$key] = $value;
}

$config = Config::fromFile(__DIR__ . '/../config/config.php');
$repo = new SqliteRepository((string) $config->get('database_path'));
$repo->migrate();

$payload = [
    'generated_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
    'states' => $repo->loadPaperPositionStates(),
    'orders' => $repo->recentPaperOrders((int) $options['limit']),
    'recent_actions' => $repo->recentPaperActions((int) $options['limit']),
];

if (boolOption((string) $options['json'])) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit(0);
}

echo "FTT paper status " . substr($payload['generated_at'], 0, 19) . "\n";
echo "States: " . count($payload['states']) . "\n";
foreach ($payload['states'] as $state) {
    if (!is_array($state)) {
        continue;
    }
    printf(
        "- %s %s qty %.6f price %.2f stop %.2f BE %s partial %s last %s\n",
        (string) ($state['symbol'] ?? ''),
        (string) ($state['status'] ?? ''),
        (float) ($state['qty'] ?? 0.0),
        (float) ($state['market_price'] ?? 0.0),
        (float) ($state['stop_price'] ?? 0.0),
        !empty($state['break_even_armed']) ? 'yes' : 'no',
        !empty($state['partial_done']) ? 'yes' : 'no',
        (string) ($state['last_action'] ?? ''),
    );
}

echo "Recent orders:\n";
foreach ($payload['orders'] as $order) {
    if (!is_array($order)) {
        continue;
    }
    printf(
        "- %s %s %s qty %.6f limit %s status %s submitted %s updated %s\n",
        (string) ($order['client_order_id'] ?? ''),
        strtoupper((string) ($order['side'] ?? '')),
        (string) ($order['symbol'] ?? ''),
        (float) ($order['qty'] ?? 0.0),
        $order['limit_price'] !== null ? number_format((float) $order['limit_price'], 2, '.', '') : '-',
        (string) ($order['status'] ?? ''),
        !empty($order['submitted']) ? 'yes' : 'no',
        substr((string) ($order['updated_at'] ?? ''), 0, 19),
    );
}

echo "Recent actions:\n";
foreach ($payload['recent_actions'] as $action) {
    if (!is_array($action)) {
        continue;
    }
    printf(
        "- #%d %s %s %s %s\n",
        (int) ($action['id'] ?? 0),
        substr((string) ($action['created_at'] ?? ''), 0, 19),
        (string) ($action['symbol'] ?? '-'),
        (string) ($action['action'] ?? ''),
        (string) ($action['reason'] ?? ''),
    );
}

function boolOption(string $value): bool
{
    return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'y', 'on'], true);
}
