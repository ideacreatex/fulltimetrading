<?php

declare(strict_types=1);

namespace FulltimeTrading\Paper;

interface CandidateBrokerGateway
{
    public function orderByClientOrderId(string $clientOrderId): ?array;
    public function submitOrder(array $body): array;
    public function cancelOrder(string $orderId): array;
}
