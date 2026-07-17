<?php

declare(strict_types=1);

namespace FulltimeTrading\Notifications;

use FulltimeTrading\Data\HttpClient;

final readonly class TelegramNotifier
{
    public function __construct(
        private HttpClient $http,
        private string $botToken,
        private string $chatId,
    ) {
        if (trim($this->botToken) === '' || trim($this->chatId) === '') {
            throw new \InvalidArgumentException('Telegram bot token and chat id are required.');
        }
    }

    public static function fromEnv(HttpClient $http): ?self
    {
        $token = getenv('TELEGRAM_BOT_TOKEN') ?: '';
        $chatId = getenv('TELEGRAM_CHAT_ID') ?: '';
        if (trim($token) === '' || trim($chatId) === '') {
            return null;
        }

        return new self($http, $token, $chatId);
    }

    /** @return array<string, mixed> */
    public function sendMessage(string $text, bool $disableNotification = false): array
    {
        $response = $this->http->postForm(
            'https://api.telegram.org/bot' . rawurlencode($this->botToken) . '/sendMessage',
            [
                'chat_id' => $this->chatId,
                'text' => $this->truncate($text),
                'disable_web_page_preview' => 'true',
                'disable_notification' => $disableNotification ? 'true' : 'false',
            ],
        );

        return self::validateSendMessageResponse($response);
    }

    /**
     * Telegram delivery is acknowledged only by its structured success
     * response. A transport-level 2xx with malformed JSON or ok=false must
     * stay in the durable retry outbox.
     *
     * @param array{status:int,body:string} $response
     * @return array<string,mixed>
     */
    public static function validateSendMessageResponse(array $response): array
    {
        $status = (int) ($response['status'] ?? 0);
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Telegram sendMessage failed with HTTP ' . $status . '.');
        }

        $payload = json_decode((string) ($response['body'] ?? ''), true);
        $messageId = is_array($payload['result'] ?? null) ? ($payload['result']['message_id'] ?? null) : null;
        if (!is_array($payload)
            || ($payload['ok'] ?? null) !== true
            || (!is_int($messageId) && !(is_string($messageId) && ctype_digit($messageId)))
            || (int) $messageId <= 0) {
            throw new \RuntimeException('Telegram sendMessage response did not confirm delivery.');
        }

        return $payload;
    }

    private function truncate(string $text): string
    {
        if (strlen($text) <= 3900) {
            return $text;
        }

        return substr($text, 0, 3850) . "\n\n[message truncated]";
    }
}
