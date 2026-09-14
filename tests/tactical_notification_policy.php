<?php

declare(strict_types=1);

use FulltimeTrading\Trading\TacticalNotificationPolicy;

require __DIR__ . '/../bootstrap.php';

function notificationPolicyExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$suppressedMessage = "⚠️ Hybrid-v4 paper: входы заблокированы\n"
    . "signal_plan_blocked:9b9de15ec1ae\n"
    . "Сверка продолжится автоматически.";
notificationPolicyExpect(
    TacticalNotificationPolicy::shouldSuppress('runtime-error:2026-08-19-06:abc', $suppressedMessage),
    'Pure signal_plan_blocked runtime notices must be suppressible.',
);

$otherRuntimeMessage = "⚠️ Hybrid-v4 paper: входы заблокированы\n"
    . "signal_plan_blocked:9b9de15ec1ae\n"
    . "post_execution_broker_snapshot_failed:deadbeefcafe\n"
    . "Сверка продолжится автоматически.";
notificationPolicyExpect(
    !TacticalNotificationPolicy::shouldSuppress('runtime-error:2026-08-19-06:def', $otherRuntimeMessage),
    'Mixed runtime failures must still be delivered.',
);

notificationPolicyExpect(
    !TacticalNotificationPolicy::shouldSuppress('portfolio-close:key', $suppressedMessage),
    'Non-runtime Telegram messages must never be suppressed by this rule.',
);

echo "tactical notification policy tests passed\n";
