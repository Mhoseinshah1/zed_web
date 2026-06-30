<?php

namespace App\Services\Telegram;

use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over the Telegram Bot API. The token is read from
 * {@see TelegramSettings} and is never logged or echoed back. Methods return
 * Telegram's decoded JSON (or throw a generic, token-free exception).
 */
class TelegramClient
{
    private const BASE = 'https://api.telegram.org/bot';

    public function __construct(private readonly TelegramSettings $settings) {}

    /**
     * Send a message to the management group, optionally into a forum topic.
     *
     * @return array{message_id:int} the sent message metadata
     *
     * @throws \RuntimeException on any API/transport failure (token-free message)
     */
    public function sendMessage(
        string $text,
        ?int $messageThreadId = null,
        ?string $chatId = null,
        ?string $parseMode = null,
        bool $silent = false,
    ): array {
        $payload = [
            'chat_id'    => $chatId ?? $this->settings->chatId(),
            'text'       => $text,
            'parse_mode' => $parseMode ?? $this->settings->parseMode(),
            'disable_web_page_preview' => true,
            'disable_notification'     => $silent,
        ];
        if ($messageThreadId !== null) {
            $payload['message_thread_id'] = $messageThreadId;
        }

        $result = $this->call('sendMessage', $payload);

        return ['message_id' => (int) ($result['message_id'] ?? 0)];
    }

    /** Bot identity (getMe) — used by the "test connection" action. */
    public function getMe(): array
    {
        return $this->call('getMe');
    }

    /** Chat metadata (getChat) — used by the "get chat info" action. */
    public function getChat(?string $chatId = null): array
    {
        return $this->call('getChat', ['chat_id' => $chatId ?? $this->settings->chatId()]);
    }

    /**
     * Perform an API call and return the `result` payload.
     *
     * @throws \RuntimeException with a generic, token-free message
     */
    private function call(string $method, array $params = []): array
    {
        $token = $this->settings->token();
        if ($token === '') {
            throw new \RuntimeException('Telegram bot token is not configured.');
        }

        try {
            $response = Http::asJson()
                ->timeout(15)
                ->retry(1, 200)
                ->post(self::BASE . $token . '/' . $method, $params);
        } catch (\Throwable $e) {
            // Never include the token (which lives in the URL) in the message.
            throw new \RuntimeException('Telegram transport error: ' . class_basename($e));
        }

        $body = $response->json();

        if (! is_array($body) || empty($body['ok'])) {
            $desc = is_array($body) ? (string) ($body['description'] ?? 'unknown error') : 'invalid response';
            throw new \RuntimeException('Telegram API error: ' . $desc);
        }

        return is_array($body['result'] ?? null) ? $body['result'] : [];
    }
}
