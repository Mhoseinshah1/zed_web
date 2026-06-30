<?php

namespace App\Services\Telegram;

use App\Models\SiteSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Typed accessors for all admin Telegram settings (stored in SiteSetting, so no
 * .env edits are needed). The bot token is stored ENCRYPTED and is never
 * returned to the frontend, tables, logs or errors.
 */
class TelegramSettings
{
    public const PARSE_HTML        = 'HTML';
    public const PARSE_MARKDOWN_V2 = 'MarkdownV2';

    /** All notification categories (mapped to topics). */
    public const CATEGORIES = [
        'sales', 'payments', 'wallet', 'tickets', 'users',
        'services', 'panels', 'errors', 'representatives', 'admin', 'system',
    ];

    public function enabled(): bool
    {
        return (bool) SiteSetting::get('telegram_admin_enabled', false);
    }

    /** The decrypted bot token, or '' if unset/undecryptable. Never logged. */
    public function token(): string
    {
        $raw = (string) SiteSetting::get('telegram_bot_token', '');
        if ($raw === '') {
            return '';
        }
        try {
            return (string) Crypt::decryptString($raw);
        } catch (\Throwable $e) {
            return '';
        }
    }

    public function hasToken(): bool
    {
        return $this->token() !== '';
    }

    /** Store the bot token encrypted. Never keep the plaintext anywhere else. */
    public function storeToken(string $token): void
    {
        $token = trim($token);
        if ($token === '') {
            return;
        }
        SiteSetting::set('telegram_bot_token', Crypt::encryptString($token));
    }

    public function clearToken(): void
    {
        SiteSetting::set('telegram_bot_token', '');
    }

    public function chatId(): string
    {
        return (string) SiteSetting::get('telegram_admin_chat_id', '');
    }

    /** @return array<int,string> allowed Telegram admin user IDs */
    public function allowedAdminIds(): array
    {
        $raw = (string) SiteSetting::get('telegram_admin_user_ids', '');
        return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', $raw) ?: [])));
    }

    public function parseMode(): string
    {
        $mode = (string) SiteSetting::get('telegram_parse_mode', self::PARSE_HTML);
        return in_array($mode, [self::PARSE_HTML, self::PARSE_MARKDOWN_V2], true) ? $mode : self::PARSE_HTML;
    }

    public function silent(): bool
    {
        return (bool) SiteSetting::get('telegram_silent', false);
    }

    public function rateLimitDuplicates(): bool
    {
        return (bool) SiteSetting::get('telegram_rate_limit_duplicates', true);
    }

    public function categoryEnabled(string $category): bool
    {
        // Default ON for every known category unless explicitly disabled.
        return (bool) SiteSetting::get('telegram_cat_' . $category, true);
    }

    public function setCategoryEnabled(string $category, bool $enabled): void
    {
        SiteSetting::set('telegram_cat_' . $category, $enabled ? 'true' : 'false');
    }

    /** True only when the bot is fully configured and ready to send. */
    public function isReady(): bool
    {
        return $this->enabled() && $this->hasToken() && $this->chatId() !== '';
    }

    /** Log a Telegram problem WITHOUT ever exposing the token. */
    public static function safeLog(string $message, array $context = []): void
    {
        unset($context['token'], $context['bot_token']);
        Log::warning('[telegram-admin] ' . $message, $context);
    }
}
