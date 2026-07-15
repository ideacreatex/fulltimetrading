<?php

declare(strict_types=1);

namespace FulltimeTrading\Trading;

final class PaperReviewAlertGuard
{
    public static function isReviewAction(string $action): bool
    {
        return self::cooldownPayloadKey($action) !== null;
    }

    public static function shouldSuppress(array $state, string $action, \DateTimeImmutable $now, int $cooldownMinutes): bool
    {
        $payloadKey = self::cooldownPayloadKey($action);
        if ($payloadKey === null || $cooldownMinutes <= 0) {
            return false;
        }

        $payload = is_array($state['payload'] ?? null) ? $state['payload'] : [];
        $lastAt = (string) ($payload[$payloadKey] ?? '');
        if ($lastAt === '') {
            return false;
        }

        try {
            $last = new \DateTimeImmutable($lastAt);
        } catch (\Throwable) {
            return false;
        }

        return ($now->getTimestamp() - $last->getTimestamp()) < ($cooldownMinutes * 60);
    }

    /** @param array<string, mixed> $state @return array<string, mixed> */
    public static function markDelivered(array $state, string $action, \DateTimeImmutable $now): array
    {
        $payloadKey = self::cooldownPayloadKey($action);
        if ($payloadKey === null) {
            return $state;
        }

        $payload = is_array($state['payload'] ?? null) ? $state['payload'] : [];
        $payload[$payloadKey] = $now->format(\DateTimeInterface::ATOM);
        $state['payload'] = $payload;
        $state['last_action'] = $action;
        $state['last_event_at'] = $now->format(\DateTimeInterface::ATOM);

        return $state;
    }

    public static function cooldownPayloadKey(string $action): ?string
    {
        return match ($action) {
            'review_model_missing' => 'last_review_model_missing_at',
            'review_risk_source_invalid' => 'last_review_risk_source_invalid_at',
            default => null,
        };
    }
}
