<?php

declare(strict_types=1);

namespace FulltimeTrading\Strategy;

final readonly class MarketRegime
{
    /** @param list<string> $warnings */
    public function __construct(
        public \DateTimeImmutable $date,
        public bool $allowsLongRisk,
        public float $score,
        public array $warnings,
        public float $spyDrawdownPct = 0.0,
        public ?float $spyRsi14 = null,
    ) {
    }
}
