<?php

namespace App\Services\Sms;

/**
 * Base class for SMS providers.
 *
 * Holds the resolved configuration (api_key, sender, pattern, otp message
 * template, custom adapter fields) and provides shared helpers for building the
 * OTP message and a local-format phone number (0xxxxxxxxxx) that most Iranian
 * panels expect.
 */
abstract class AbstractSmsProvider implements SmsProviderInterface
{
    /**
     * @param array<string,mixed> $config
     */
    public function __construct(protected array $config) {}

    public function sendOtp(string $normalizedPhone, string $code): bool
    {
        return $this->sendMessage($normalizedPhone, $this->buildOtpMessage($code));
    }

    protected function apiKey(): string
    {
        return (string) ($this->config['api_key'] ?? '');
    }

    protected function sender(): string
    {
        return (string) ($this->config['sender'] ?? '');
    }

    protected function pattern(): string
    {
        return (string) ($this->config['pattern_code'] ?? '');
    }

    protected function ttlMinutes(): int
    {
        return (int) ($this->config['otp_ttl_minutes'] ?? 5);
    }

    /**
     * Build the OTP message from the admin template, substituting {code} and
     * {minutes}.
     */
    protected function buildOtpMessage(string $code): string
    {
        $template = (string) ($this->config['otp_message'] ?? 'کد تایید شما در زدپروکسی: {code}');
        return strtr($template, [
            '{code}'    => $code,
            '{minutes}' => (string) $this->ttlMinutes(),
        ]);
    }

    /**
     * Convert +989xxxxxxxxx to the local 09xxxxxxxxx form many panels expect.
     */
    protected function toLocal(string $normalizedPhone): string
    {
        if (str_starts_with($normalizedPhone, '+98')) {
            return '0' . substr($normalizedPhone, 3);
        }
        return $normalizedPhone;
    }
}
