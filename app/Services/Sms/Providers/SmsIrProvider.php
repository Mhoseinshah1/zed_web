<?php

namespace App\Services\Sms\Providers;

use App\Services\Sms\AbstractSmsProvider;
use Illuminate\Support\Facades\Http;

/**
 * SMS.ir adapter.
 *
 * Uses the v1 REST API with the X-API-KEY header. When a pattern (template) id
 * is configured the "verify/send" endpoint is used for OTP delivery.
 *
 * TODO: confirm exact endpoint/field names against the live SMS.ir account.
 */
class SmsIrProvider extends AbstractSmsProvider
{
    private const BASE = 'https://api.sms.ir/v1';

    public function sendMessage(string $normalizedPhone, string $message): bool
    {
        $response = Http::withHeaders([
            'X-API-KEY' => $this->apiKey(),
            'Accept'    => 'application/json',
        ])->timeout(20)->post(self::BASE . '/send/bulk', array_filter([
            'lineNumber'  => $this->sender() ?: null,
            'messageText' => $message,
            'mobiles'     => [$this->toLocal($normalizedPhone)],
        ]));

        if (! $response->successful()) {
            throw new \RuntimeException('SMS.ir HTTP ' . $response->status());
        }

        return true;
    }

    public function sendOtp(string $normalizedPhone, string $code): bool
    {
        if ($this->pattern() !== '') {
            $response = Http::withHeaders([
                'X-API-KEY' => $this->apiKey(),
                'Accept'    => 'application/json',
            ])->timeout(20)->post(self::BASE . '/send/verify', [
                'mobile'     => $this->toLocal($normalizedPhone),
                'templateId' => $this->pattern(),
                'parameters' => [
                    ['name' => 'CODE', 'value' => $code],
                ],
            ]);

            if (! $response->successful()) {
                throw new \RuntimeException('SMS.ir verify HTTP ' . $response->status());
            }

            return true;
        }

        return parent::sendOtp($normalizedPhone, $code);
    }
}
