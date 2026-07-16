<?php

declare(strict_types=1);

use FulltimeTrading\Trading\TacticalRotationShadowContext;

require __DIR__ . '/../bootstrap.php';

function shadowAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$now = new DateTimeImmutable('2026-07-16 08:00:00', new DateTimeZone('America/New_York'));
$baseTarget = [
    'rebalance_due_next_session' => true,
    'action' => 'rebalance',
    'signal_date' => '2026-07-15',
    'symbol' => 'PANW',
    'gross' => 1.10,
];
$calendar = static fn (string $start, string $end): array => [
    ['date' => '2026-07-16', 'open' => '09:30'],
];
$activeAsset = static fn (string $symbol): array => [
    'status' => 'active',
    'tradable' => true,
    'marginable' => true,
    'fractionable' => true,
];

$unavailable = new TacticalRotationShadowContext();
foreach (['hold', 'hold_cash'] as $action) {
    $context = $unavailable->resolve(array_replace($baseTarget, ['action' => $action]), $now, true);
    shadowAssert($context['status'] === 'hold_no_trade', $action . ' must not call broker reads.');
}
shadowAssert(
    $unavailable->resolve($baseTarget, $now, false)['status'] === 'blocked_validation_failed',
    'A failed historical candidate must never look ready for paper execution.',
);
shadowAssert(
    $unavailable->resolve(array_replace($baseTarget, ['signal_date' => '../orders']), $now, true)['status']
        === 'blocked_invalid_signal_date',
    'Malformed signal dates must fail closed before a broker read.',
);
shadowAssert(
    $unavailable->resolve($baseTarget, $now, true)['status'] === 'blocked_calendar_unavailable',
    'Missing read-only paper credentials/client must fail closed.',
);

$ready = (new TacticalRotationShadowContext($calendar, $activeAsset))->resolve($baseTarget, $now, true);
shadowAssert(
    $ready['status'] === 'ready_shadow'
        && $ready['order_eligible'] === false
        && $ready['valid_for_session'] === '2026-07-16',
    'A valid target may be ready only as a non-order-eligible shadow.',
);
$expired = (new TacticalRotationShadowContext($calendar, $activeAsset))->resolve(
    $baseTarget,
    new DateTimeImmutable('2026-07-16 09:30:00', new DateTimeZone('America/New_York')),
    true,
);
shadowAssert($expired['status'] === 'expired_no_chase', 'A signal must expire exactly at its intended open.');

$notTradable = static fn (string $symbol): array => [
    'status' => 'inactive',
    'tradable' => false,
    'marginable' => false,
];
shadowAssert(
    (new TacticalRotationShadowContext($calendar, $notTradable))->resolve($baseTarget, $now, true)['status']
        === 'blocked_asset_not_tradable',
    'Inactive/non-tradable assets must be blocked.',
);
$notMarginable = static fn (string $symbol): array => [
    'status' => 'active',
    'tradable' => true,
    'marginable' => false,
];
shadowAssert(
    (new TacticalRotationShadowContext($calendar, $notMarginable))->resolve($baseTarget, $now, true)['status']
        === 'blocked_asset_not_marginable',
    'Gross above one must require a marginable asset.',
);
$throws = static function (string $start, string $end): array {
    throw new RuntimeException('synthetic broker failure');
};
shadowAssert(
    (new TacticalRotationShadowContext($throws, $activeAsset))->resolve($baseTarget, $now, true)['status']
        === 'blocked_broker_check_failed',
    'Broker/calendar exceptions must fail closed.',
);

echo "Tactical rotation shadow context OK\n";
