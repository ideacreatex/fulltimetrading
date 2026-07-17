<?php

declare(strict_types=1);

namespace FulltimeTrading\Trading;

/**
 * Stable Telegram deduplication identity for the legacy-to-hybrid handoff.
 *
 * Mark-to-market fields move on every broker poll and must not create a new
 * transition message. A new key is produced only when position ownership or
 * an open order materially changes.
 */
final class TacticalTransitionNotificationKey
{
    /** @param array<string,mixed> $manifest */
    public static function fromManifest(array $manifest): string
    {
        $positions = [];
        foreach ((array) ($manifest['positions'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $positions[] = [
                'symbol' => strtoupper(trim((string) ($row['symbol'] ?? ''))),
                'side' => strtolower(trim((string) ($row['side'] ?? ''))),
                'qty' => self::decimal($row['qty'] ?? 0.0),
                'avg_entry_price' => self::decimal($row['avg_entry_price'] ?? 0.0),
            ];
        }
        usort($positions, static fn (array $left, array $right): int => strcmp(
            implode('|', $left),
            implode('|', $right),
        ));

        $orders = [];
        foreach ((array) ($manifest['open_orders'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $orders[] = [
                'client_order_id' => trim((string) ($row['client_order_id'] ?? '')),
                'symbol' => strtoupper(trim((string) ($row['symbol'] ?? ''))),
                'side' => strtolower(trim((string) ($row['side'] ?? ''))),
                'qty' => self::decimal($row['qty'] ?? 0.0),
                'filled_qty' => self::decimal($row['filled_qty'] ?? 0.0),
                'status' => strtolower(trim((string) ($row['status'] ?? ''))),
                'time_in_force' => strtolower(trim((string) ($row['time_in_force'] ?? ''))),
            ];
        }
        usort($orders, static fn (array $left, array $right): int => strcmp(
            implode('|', $left),
            implode('|', $right),
        ));

        return 'transition:' . hash(
            'sha256',
            json_encode(
                ['positions' => $positions, 'open_orders' => $orders],
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ),
        );
    }

    private static function decimal(mixed $value): string
    {
        $number = (float) $value;
        if (!is_finite($number)) {
            throw new \InvalidArgumentException('Transition manifest contains a non-finite number.');
        }
        $formatted = rtrim(rtrim(number_format($number, 8, '.', ''), '0'), '.');

        return $formatted === '' || $formatted === '-0' ? '0' : $formatted;
    }
}
