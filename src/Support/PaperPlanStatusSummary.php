<?php

declare(strict_types=1);

namespace FulltimeTrading\Support;

final class PaperPlanStatusSummary
{
    /**
     * @param ?array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public static function fromPayload(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        $plan = is_array($payload['plan'] ?? null) ? $payload['plan'] : $payload;
        $orders = is_array($plan['orders'] ?? null) ? array_values($plan['orders']) : [];
        $skipped = is_array($plan['skipped'] ?? null) ? array_values($plan['skipped']) : [];

        return [
            'generated_at' => $payload['generated_at'] ?? ($plan['generated_at'] ?? null),
            'dry_run' => $payload['dry_run'] ?? ($plan['dry_run'] ?? null),
            'submit_requested' => $payload['submit_requested'] ?? null,
            'submit_allowed' => $payload['submit_allowed'] ?? ($plan['submit_allowed'] ?? null),
            'orders_count' => count($orders),
            'skipped_count' => count($skipped),
            'orders' => $orders,
        ];
    }
}
