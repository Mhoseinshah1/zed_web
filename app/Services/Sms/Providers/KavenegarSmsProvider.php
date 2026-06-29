<?php

namespace App\Services\Sms\Providers;

use App\Services\Sms\AbstractSmsProvider;
use Illuminate\Support\Facades\Http;

/**
 * Kavenegar (kavenegar.com) adapter.
 *
 * Uses the simple "send" REST endpoint. When a pattern (template) code is
 * configured the "verify/lookup" endpoint is used instead, which is the
 * recommended way to deliver OTPs on Kavenegar.
 *
 * TODO: confirm exact field names against the live Kavenegar account/plan.
 */
class KavenegarSmsProvider extends AbstractSmsProvider
{
    private const BASE = 'https://api.kavenegar.com/v1';

    public function sendMessage(string $normalizedPhone, string $message): bool
    {
        $receptor = $this->toLocal($normalizedPhone);

        $response = Http::asForm()->timeout(20)->post(
            self::BASE . '/' . rawurlencode($this->apiKey()) . '/sms/send.json',
            array_filter([
                'receptor' => $receptor,
                'sender'   => $this->sender() ?: null,
                'message'  => $message,
            ]),
        );

        if (! $response->successful()) {
            throw new \RuntimeException('Kavenegar HTTP ' . $response->status());
        }

        return true;
    }

    public function sendOtp(string $normalizedPhone, string $code): bool
    {
        // Pattern/lookup mode is preferred for OTP delivery when configured.
        if ($this->pattern() !== '') {
            $receptor = $this->toLocal($normalizedPhone);

            $response = Http::asForm()->timeout(20)->post(
                self::BASE . '/' . rawurlencode($this->apiKey()) . '/verify/lookup.json',
                [
                    'receptor' => $receptor,
                    'template' => $this->pattern(),
                    'token'    => $code,
                ],
            );

            if (! $response->successful()) {
                throw new \RuntimeException('Kavenegar lookup HTTP ' . $response->status());
            }

            return true;
        }

        return parent::sendOtp($normalizedPhone, $code);
    }
}
