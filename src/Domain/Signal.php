<?php

declare(strict_types=1);

namespace FulltimeTrading\Domain;

final readonly class Signal
{
    /**
     * @param list<string> $reasons
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $symbol,
        public \DateTimeImmutable $createdAt,
        public string $strategy,
        public float $entry,
        public float $stop,
        public float $target,
        public float $riskPerShare,
        public float $score,
        public array $reasons,
        public string $direction = 'long',
        public array $metadata = [],
    ) {
        if (!in_array($direction, ['long', 'short'], true)) {
            throw new \InvalidArgumentException('Signal direction must be long or short.');
        }
    }
}
