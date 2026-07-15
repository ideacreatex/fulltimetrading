<?php

declare(strict_types=1);

namespace FulltimeTrading\Support;

final class StatusSnapshotSafety
{
    public const REDACTED_DETAIL = 'details_redacted_use_local_logs';
    public const ALPACA_SYNC_ERROR = 'alpaca_sync_or_account_validation_failed';

    public static function errorCode(\Throwable $error): string
    {
        // The exception is deliberately not inspected or serialized: upstream
        // HTTP response bodies and locally stored action text are not Git-safe.
        return self::ALPACA_SYNC_ERROR;
    }

    public static function redactedDetail(mixed $detail): ?string
    {
        return is_string($detail) && trim($detail) !== '' ? self::REDACTED_DETAIL : null;
    }

    /** @param array<string, mixed> $payload */
    public static function encodeJson(array $payload): string
    {
        return json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ) . "\n";
    }

    public static function writeAtomic(string $path, string $contents): void
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            throw new \RuntimeException('Status snapshot directory does not exist: ' . $directory);
        }

        $temporary = tempnam($directory, '.paper-status-');
        if ($temporary === false) {
            throw new \RuntimeException('Unable to create a temporary status snapshot.');
        }

        try {
            $written = file_put_contents($temporary, $contents, LOCK_EX);
            if ($written === false || $written !== strlen($contents)) {
                throw new \RuntimeException('Unable to write the complete status snapshot.');
            }
            if (!rename($temporary, $path)) {
                throw new \RuntimeException('Unable to publish the status snapshot atomically.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }
}
