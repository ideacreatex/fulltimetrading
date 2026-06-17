<?php

declare(strict_types=1);

namespace FulltimeTrading\Domain;

final readonly class Bar
{
    public function __construct(
        public string $symbol,
        public \DateTimeImmutable $time,
        public float $open,
        public float $high,
        public float $low,
        public float $close,
        public float $volume,
    ) {
        if ($high < $low) {
            throw new \InvalidArgumentException('Bar high must be >= low.');
        }
    }

    /** @param array<string, mixed> $row */
    public static function fromAlpaca(string $symbol, array $row): self
    {
        return new self(
            strtoupper($symbol),
            new \DateTimeImmutable((string) $row['t']),
            (float) $row['o'],
            (float) $row['h'],
            (float) $row['l'],
            (float) $row['c'],
            (float) $row['v'],
        );
    }

    /** @param array<string, string> $row */
    public static function fromCsvRow(string $symbol, array $row): self
    {
        return new self(
            strtoupper($symbol),
            new \DateTimeImmutable($row['Date']),
            (float) $row['Open'],
            (float) $row['High'],
            (float) $row['Low'],
            (float) $row['Close'],
            (float) ($row['Volume'] ?? 0),
        );
    }
}

