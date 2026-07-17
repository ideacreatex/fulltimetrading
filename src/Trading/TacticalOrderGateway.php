<?php

declare(strict_types=1);

namespace FulltimeTrading\Trading;

interface TacticalOrderGateway
{
    /** @return array<string,mixed>|null */
    public function orderByClientOrderId(string $clientOrderId): ?array;

    /** @param array<string,mixed> $order @return array<string,mixed> */
    public function submitOrder(array $order): array;
}
