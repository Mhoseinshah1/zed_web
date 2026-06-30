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
     * Register the webhook. The secret is sent to Telegram and echoed back on
     * every update via the X-Telegram-Bot-Api-Secret-Token header. We only
     * subscribe to message updates.
     */
    public function setWebhook(string $url, string $secret): array
    {
        return $this->call('setWebhook', [
            'url'             => $url,
            'secret_token'    => $secret,
            'allowed_updates' => ['message'],
            'max_connections' => 20,
        ]);
    }

    public function deleteWebhook(bool $dropPending = false): array
    {
        return $this->call('deleteWebhook', ['drop_pending_updates' => $dropPending]);
    }

    /** Webhook status (getWebhookInfo). Never contains the secret. */
    public function getWebhookInfo(): array
    {
        return $this->call('getWebhookInfo');
    }

    /**
     * Create a forum topic in the management group. Requires the bot to be an
     * admin with the "Manage Topics" right and the group to be a forum.
     *
     * @return array{message_thread_id:int, name:string}
     */
    public function createForumTopic(string $name, ?int $iconColor = null, ?string $chatId = null): array
    {
        $params = ['chat_id' => $chatId ?? $this->settings->chatId(), 'name' => $name];
        if ($iconColor !== null) {
            $params['icon_color'] = $iconColor;
        }
        $result = $this->call('createForumTopic', $params);

        return [
            'message_thread_id' => (int) ($result['message_thread_id'] ?? 0),
            'name'              => (string) ($result['name'] ?? $name),
        ];
    }

    /**
     * Send a file to the management group (sendDocument, multipart). Used to
     * deliver a backup archive. The token lives only in the URL.
     *
     * @return array{message_id:int}
     *
     * @throws \RuntimeException on any API/transport failure (token-free message)
     */
    public function sendDocument(
        string $filePath,
        ?string $caption = null,
        ?int $messageThreadId = null,
        ?string $chatId = null,
    ): array {
        $token = $this->settings->token();
        if ($token === '') {
            throw new \RuntimeException('Telegram bot token is not configured.');
        }
        if (! is_file($filePath)) {
            throw new \RuntimeException('Backup file not found for upload.');
        }

        $payload = ['chat_id' => $chatId ?? $this->settings->chatId()];
        if ($caption !== null) {
            $payload['caption'] = mb_substr($caption, 0, 1024);
            $payload['parse_mode'] = $this->settings->parseMode();
        }
        if ($messageThreadId !== null) {
            $payload['message_thread_id'] = $messageThreadId;
        }

        try {
            $response = Http::asMultipart()
                ->timeout(120)
                ->attach('document', fopen($filePath, 'r'), basename($filePath))
                ->post(self::BASE . $token . '/sendDocument', $payload);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Telegram transport error: ' . class_basename($e));
        }

        $body = $response->json();
        if (! is_array($body) || empty($body['ok'])) {
            $desc = is_array($body) ? (string) ($body['description'] ?? 'unknown error') : 'invalid response';
            throw new \RuntimeException('Telegram API error: ' . $desc);
        }

        return ['message_id' => (int) ($body['result']['message_id'] ?? 0)];
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
