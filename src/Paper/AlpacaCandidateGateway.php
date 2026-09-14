<?php

declare(strict_types=1);

namespace FulltimeTrading\Paper;

use FulltimeTrading\Trading\AlpacaPaperClient;

/** The underlying client enforces the exact paper URL and uses the existing credentials. */
final readonly class AlpacaCandidateGateway implements CandidateBrokerGateway
{
    public function __construct(private AlpacaPaperClient $client) { }
    public function orderByClientOrderId(string $id): ?array { return $this->client->orderByClientOrderId($id); }
    public function submitOrder(array $body): array { return $this->client->submitOrder($body); }
    public function cancelOrder(string $id): array { return $this->client->cancelOrder($id); }
}
