<?php

declare(strict_types=1);

namespace FulltimeTrading\Trading;

final class TacticalNotificationPolicy
{
    public static function shouldSuppress(string $key, string $message): bool
    {
        if (!str_starts_with($key, 'runtime-error:')) {
            return false;
        }

        if (!str_contains($message, '⚠️ Hybrid-v4 paper: входы заблокированы')) {
            return false;
        }

        $errorLines = [];
        foreach (preg_split('/\R/u', $message) ?: [] as $line) {
            $line = trim($line);
            if ($line === ''
                || str_starts_with($line, '⚠️ Hybrid-v4 paper:')
                || $line === 'Сверка продолжится автоматически.') {
                continue;
            }
            $errorLines[] = $line;
        }

        if ($errorLines === []) {
            return false;
        }

        foreach ($errorLines as $line) {
            if (!str_starts_with($line, 'signal_plan_blocked:')) {
                return false;
            }
        }

        return true;
    }
}
