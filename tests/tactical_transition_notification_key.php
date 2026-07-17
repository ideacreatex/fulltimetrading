<?php

declare(strict_types=1);

use FulltimeTrading\Trading\TacticalTransitionNotificationKey;

require dirname(__DIR__) . '/bootstrap.php';

function transitionKeyExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$manifest = [
    'positions' => [
        [
            'symbol' => 'TECL',
            'side' => 'long',
            'qty' => 66.0,
            'avg_entry_price' => 224.505455,
            'current_price' => 176.66,
            'market_value' => 11659.56,
            'unrealized_pl' => -3157.80,
        ],
        [
            'symbol' => 'TQQQ',
            'side' => 'long',
            'qty' => 165.0,
            'avg_entry_price' => 75.34,
            'current_price' => 69.12,
            'market_value' => 11404.80,
            'unrealized_pl' => -1026.30,
        ],
    ],
    'open_orders' => [],
];

$key = TacticalTransitionNotificationKey::fromManifest($manifest);
transitionKeyExpect(str_starts_with($key, 'transition:'), 'Transition key prefix must be explicit.');

$repriced = $manifest;
$repriced['positions'] = array_reverse($repriced['positions']);
$repriced['positions'][0]['current_price'] = 70.01;
$repriced['positions'][0]['market_value'] = 11551.65;
$repriced['positions'][0]['unrealized_pl'] = -879.45;
transitionKeyExpect(
    hash_equals($key, TacticalTransitionNotificationKey::fromManifest($repriced)),
    'Mark-to-market changes and broker row order must not spam transition Telegram messages.',
);

$quantityChanged = $manifest;
$quantityChanged['positions'][0]['qty'] = 65.0;
transitionKeyExpect(
    !hash_equals($key, TacticalTransitionNotificationKey::fromManifest($quantityChanged)),
    'A real legacy position quantity change must produce a new transition notification.',
);

$withOrder = $manifest;
$withOrder['open_orders'][] = [
    'client_order_id' => 'legacy-exit-1',
    'symbol' => 'TECL',
    'side' => 'sell',
    'qty' => 66.0,
    'filled_qty' => 0.0,
    'status' => 'new',
    'time_in_force' => 'day',
];
$orderKey = TacticalTransitionNotificationKey::fromManifest($withOrder);
transitionKeyExpect(!hash_equals($key, $orderKey), 'A new legacy order must produce a new transition notification.');
$withOrder['open_orders'][0]['filled_qty'] = 10.0;
transitionKeyExpect(
    !hash_equals($orderKey, TacticalTransitionNotificationKey::fromManifest($withOrder)),
    'A material open-order fill change must produce a new transition notification.',
);

echo "Tactical transition notification key OK\n";
