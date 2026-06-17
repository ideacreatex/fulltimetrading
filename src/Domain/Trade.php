<?php

declare(strict_types=1);

namespace FulltimeTrading\Domain;

final readonly class Trade
{
    /** @param list<string> $events */
    public function __construct(
        public string $symbol,
        public string $strategy,
        public \DateTimeImmutable $entryTime,
        public \DateTimeImmutable $exitTime,
        public float $entry,
        public float $exit,
        public float $shares,
        public float $pnl,
        public float $rMultiple,
        public string $exitReason,
        public array $events,
    ) {
    }
}
