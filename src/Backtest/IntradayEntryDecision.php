<?php

declare(strict_types=1);

namespace FulltimeTrading\Backtest;

final readonly class IntradayEntryDecision
{
    public function __construct(
        public string $status,
        public string $session,
        public ?\DateTimeImmutable $touchBarStart = null,
        public ?\DateTimeImmutable $reclaimBarStart = null,
        public ?\DateTimeImmutable $decisionAt = null,
        public ?\DateTimeImmutable $fillAt = null,
        public ?float $rawFillPrice = null,
        public ?float $fillPrice = null,
        public ?int $fillDelayMinutes = null,
        public bool $preEntryStopBreach = false,
        public string $reason = '',
    ) {
    }

    public function isFilled(): bool
    {
        return $this->status === 'filled'
            && $this->fillAt !== null
            && $this->fillPrice !== null;
    }
}
