<?php

declare(strict_types=1);

use FulltimeTrading\Trading\TacticalIntentStatusMessage;

require __DIR__ . '/../bootstrap.php';

function intentMessageExpect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$filled = TacticalIntentStatusMessage::build([
    'side' => 'buy',
    'status' => 'filled',
    'symbol' => 'PANW',
    'sleeve_id' => 'dynamic_loo10',
    'scheduled_session' => '2026-08-12',
    'requested_qty' => 10,
    'cumulative_filled_qty' => 10,
    'cumulative_fill_notional' => 1825.5,
]);
intentMessageExpect(str_contains($filled['text'], '🚨 🟢 АКЦИИ ОТКРЫТЫ'), 'A completed buy must use the urgent green heading.');
intentMessageExpect(str_contains($filled['text'], 'ФАКТИЧЕСКОЕ ИСПОЛНЕНИЕ'), 'A completed buy must be explicitly distinguished from a candidate.');
intentMessageExpect(str_contains($filled['text'], '$182.55'), 'A completed buy must show its average fill price.');
intentMessageExpect(is_array($filled['rich_message']), 'A completed buy must include a rich Telegram representation.');
intentMessageExpect(
    ($filled['rich_message']['blocks'][0]['type'] ?? null) === 'heading'
        && ($filled['rich_message']['blocks'][0]['size'] ?? null) === 1,
    'A completed buy must use the largest rich-message heading.',
);

$partial = TacticalIntentStatusMessage::build([
    'side' => 'buy',
    'status' => 'partially_filled',
    'symbol' => 'AMD',
    'sleeve_id' => 'qqq200_full',
    'scheduled_session' => '2026-08-12',
    'requested_qty' => 8,
    'cumulative_filled_qty' => 3,
    'cumulative_fill_notional' => 510,
]);
intentMessageExpect(str_contains($partial['text'], '🟡 АКЦИИ ЧАСТИЧНО ОТКРЫТЫ'), 'A partial buy must use the yellow warning heading.');
intentMessageExpect(str_contains($partial['text'], '3 / 8'), 'A partial buy must show filled and requested quantities.');
intentMessageExpect(is_array($partial['rich_message']), 'A partial buy must also remain visually prominent.');

$pending = TacticalIntentStatusMessage::build([
    'side' => 'buy',
    'status' => 'accepted',
    'symbol' => 'PANW',
    'sleeve_id' => 'dynamic_loo10',
    'scheduled_session' => '2026-08-12',
    'requested_qty' => 10,
    'cumulative_filled_qty' => 0,
]);
intentMessageExpect(str_contains($pending['text'], 'ЕЩЁ НЕ ИСПОЛНЕНА'), 'A zero-fill buy must not look like an opened position.');
intentMessageExpect($pending['rich_message'] === null, 'A zero-fill buy must not use the urgent fill presentation.');

echo "tactical intent status message tests passed\n";
