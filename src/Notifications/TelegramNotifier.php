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

        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new \RuntimeException('Telegram sendMessage failed with HTTP ' . $response['status'] . ': ' . substr($response['body'], 0, 500));
        }

        $payload = json_decode($response['body'], true);

        return is_array($payload) ? $payload : ['ok' => false, 'raw' => $response['body']];
    }

    private function truncate(string $text): string
    {
        if (strlen($text) <= 3900) {
            return $text;
        }

        return substr($text, 0, 3850) . "\n\n[message truncated]";
    }
}
