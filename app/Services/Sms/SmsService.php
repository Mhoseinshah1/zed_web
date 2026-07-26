<?php

namespace App\Services\Sms;

use App\Models\SiteSetting;
use App\Services\Sms\Providers\CustomSmsProvider;
use App\Services\Sms\Providers\FarazSmsProvider;
use App\Services\Sms\Providers\KavenegarSmsProvider;
use App\Services\Sms\Providers\SmsIrProvider;
use App\Support\SmsFailure;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * SMS gateway façade.
 *
 * Reads provider settings from the database (api_key stored encrypted), resolves
 * the configured provider adapter, and sends messages/OTPs. All sending is
 * best-effort: when SMS is disabled or misconfigured it logs and returns false
 * instead of throwing, so the OTP flow never crashes the site.
 */
class SmsService
{
    public const PROVIDERS = [
        'kavenegar' => 'کاوه‌نگار',
        'sms_ir' => 'SMS.ir',
        'farazsms' => 'فراز اس‌ام‌اس',
        'custom' => 'سفارشی',
    ];

    public const DEFAULT_OTP_MESSAGE = "کد تایید شما در زدپروکسی: {code}\nاعتبار کد: {minutes} دقیقه";

    /** Whether admin enabled SMS sending. */
    public function isEnabled(): bool
    {
        return (bool) SiteSetting::get('sms_enabled', false);
    }

    /**
     * Whether SMS is enabled AND has the minimum required configuration to send.
     */
    public function isConfigured(): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $provider = (string) SiteSetting::get('sms_provider', '');
        if ($provider === '' || ! array_key_exists($provider, self::PROVIDERS)) {
            return false;
        }

        if ($this->apiKey() === '') {
            return false;
        }

        if ($provider === 'custom' && (string) SiteSetting::get('sms_custom_url', '') === '') {
            return false;
        }

        return true;
    }

    /**
     * Send an OTP code. Returns true on success. Never throws.
     */
    public function sendOtp(string $normalizedPhone, string $code): bool
    {
        return $this->dispatch(fn (SmsProviderInterface $p) => $p->sendOtp($normalizedPhone, $code), $normalizedPhone);
    }

    /**
     * Send a free-form message. Returns true on success. Never throws.
     */
    public function sendMessage(string $normalizedPhone, string $message): bool
    {
        return $this->dispatch(fn (SmsProviderInterface $p) => $p->sendMessage($normalizedPhone, $message), $normalizedPhone);
    }

    /**
     * Backward-compatible alias used by older callers.
     */
    public function send(string $normalizedPhone, string $message): bool
    {
        return $this->sendMessage($normalizedPhone, $message);
    }

    /**
     * Like sendMessage() but rethrows so the admin "test SMS" action can show
     * the underlying error.
     *
     * @throws \RuntimeException
     */
    public function sendTest(string $normalizedPhone, string $message): bool
    {
        if (! $this->isConfigured()) {
            throw new \RuntimeException('سرویس پیامک فعال یا تنظیم نشده است.');
        }

        return $this->provider()->sendMessage($normalizedPhone, $message);
    }

    /**
     * Resolve the configured provider adapter.
     */
    public function provider(): SmsProviderInterface
    {
        $config = $this->config();

        return match ($config['provider']) {
            'kavenegar' => new KavenegarSmsProvider($config),
            'sms_ir' => new SmsIrProvider($config),
            'farazsms' => new FarazSmsProvider($config),
            default => new CustomSmsProvider($config),
        };
    }

    /**
     * Resolved configuration with the api_key decrypted.
     *
     * @return array<string,mixed>
     */
    public function config(): array
    {
        return [
            'provider' => (string) SiteSetting::get('sms_provider', 'custom'),
            'api_key' => $this->apiKey(),
            'sender' => (string) SiteSetting::get('sms_sender', ''),
            'pattern_code' => (string) SiteSetting::get('sms_pattern_code', ''),
            'otp_message' => (string) SiteSetting::get('sms_otp_message', self::DEFAULT_OTP_MESSAGE),
            'otp_ttl_minutes' => (int) SiteSetting::get('otp_ttl_minutes', 5),
            'custom_url' => (string) SiteSetting::get('sms_custom_url', ''),
            'custom_method' => (string) SiteSetting::get('sms_custom_method', 'POST'),
            'custom_headers' => SiteSetting::get('sms_custom_headers', null),
            'custom_body_template' => (string) SiteSetting::get('sms_custom_body_template', ''),
        ];
    }

    /**
     * Decrypted API key. Returns '' when unset.
     *
     * Legacy plaintext (stored before encryption existed) is a BOUNDED
     * compatibility path, not a silent normal: a value that structurally IS a
     * Laravel ciphertext but fails to decrypt means corruption or an APP_KEY
     * change — that fails CLOSED (treated as unset, sanitized warning). Only
     * a value that structurally is NOT a ciphertext is accepted as legacy
     * plaintext, and it is immediately re-encrypted in place so the fallback
     * self-retires on first use. (Removal plan: once installations have read
     * the key once post-upgrade, this branch is dead code and can be
     * dropped.)
     */
    public function apiKey(): string
    {
        $raw = SiteSetting::get('sms_api_key', '');
        if (! is_string($raw) || $raw === '') {
            return '';
        }

        try {
            return Crypt::decryptString($raw);
        } catch (\Throwable) {
            if (self::looksLikeCiphertext($raw)) {
                Log::warning('SmsService: stored API key cannot be decrypted (APP_KEY change or corruption) — treating as unset');

                return '';
            }

            Log::warning('SmsService: migrating legacy plaintext SMS API key to encrypted storage');
            SiteSetting::set('sms_api_key', Crypt::encryptString($raw));

            return $raw;
        }
    }

    /** Structural check for a Laravel encrypter payload (iv/value/mac JSON). */
    private static function looksLikeCiphertext(string $raw): bool
    {
        $decoded = base64_decode($raw, true);
        if (! is_string($decoded)) {
            return false;
        }

        $json = json_decode($decoded, true);

        return is_array($json) && isset($json['iv'], $json['value'], $json['mac']);
    }

    /**
     * Encrypt and persist the API key. Empty value clears it.
     */
    public static function storeApiKey(?string $plain): void
    {
        if ($plain === null || $plain === '') {
            SiteSetting::set('sms_api_key', '');

            return;
        }
        SiteSetting::set('sms_api_key', Crypt::encryptString($plain));
    }

    /**
     * Run a send closure with the resolved provider, never throwing.
     */
    private function dispatch(callable $send, string $normalizedPhone): bool
    {
        if (! $this->isConfigured()) {
            Log::info('SmsService: SMS disabled/unconfigured — message not sent', [
                'phone' => $this->maskPhone($normalizedPhone),
            ]);

            return false;
        }

        try {
            return (bool) $send($this->provider());
        } catch (\Throwable $e) {
            // Adapter intent ("we keep credentials out of messages") is not a
            // guarantee — HTTP client exceptions can echo URLs/headers, so
            // failures go through the dedicated sanitizer before logging.
            Log::error('SmsService: send failed', [
                'provider' => (string) SiteSetting::get('sms_provider', ''),
                'phone' => $this->maskPhone($normalizedPhone),
                'error' => SmsFailure::summarize('sms send failed', $e),
            ]);

            return false;
        }
    }

    private function maskPhone(string $phone): string
    {
        return strlen($phone) > 4 ? substr($phone, 0, -4).'****' : '****';
    }
}
