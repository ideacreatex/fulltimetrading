<?php

declare(strict_types=1);

use FulltimeTrading\Notifications\TelegramNotifier;

require __DIR__ . '/../bootstrap.php';

function telegramResponseExpectFailure(array $response): void
{
    try {
        TelegramNotifier::validateSendMessageResponse($response);
    } catch (RuntimeException) {
        return;
    }

    throw new RuntimeException('Telegram response was expected to fail closed.');
}

$valid = TelegramNotifier::validateSendMessageResponse([
    'status' => 200,
    'body' => json_encode(['ok' => true, 'result' => ['message_id' => 123]], JSON_THROW_ON_ERROR),
]);
if (($valid['result']['message_id'] ?? null) !== 123) {
    throw new RuntimeException('A confirmed Telegram message must be returned unchanged.');
}

telegramResponseExpectFailure([
    'status' => 200,
    'body' => json_encode(['ok' => false, 'description' => 'denied'], JSON_THROW_ON_ERROR),
]);
telegramResponseExpectFailure(['status' => 200, 'body' => '{invalid-json']);
telegramResponseExpectFailure([
    'status' => 200,
    'body' => json_encode(['ok' => true, 'result' => []], JSON_THROW_ON_ERROR),
]);
telegramResponseExpectFailure([
    'status' => 403,
    'body' => json_encode(['ok' => false], JSON_THROW_ON_ERROR),
]);

echo "telegram notifier response tests passed\n";
