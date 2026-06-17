<?php

declare(strict_types=1);

namespace FulltimeTrading\Support;

final class Config
{
    /** @var array<string, mixed> */
    private array $values;

    /** @param array<string, mixed> $values */
    public function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function fromFile(string $path): self
    {
        $values = require $path;
        if (!is_array($values)) {
            throw new \RuntimeException('Config file must return an array: ' . $path);
        }

        return new self($values);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $current = $this->values;
        foreach (explode('.', $key) as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return $default;
            }
            $current = $current[$part];
        }

        return $current;
    }
}

