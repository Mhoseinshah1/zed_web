<?php

namespace App\Services\Sms\Providers;

use App\Services\Sms\AbstractSmsProvider;
use Illuminate\Support\Facades\Http;

/**
 * FarazSMS / ippanel adapter.
 *
 * Uses the REST send endpoint with a bearer API key. When a pattern code is
 * configured the pattern endpoint is used for OTP delivery.
 *
 * TODO: confirm exact endpoint/field names against the live FarazSMS account.
 */
class FarazSmsProvider extends AbstractSmsProvider
{
    private const BASE = 'https://api2.ippanel.com/api/v1';

    public function sendMessage(string $normalizedPhone, string $message): bool
    {
        $response = Http::withHeaders([
            'Authorization' => 'AccessKey ' . $this->apiKey(),
            'Accept'        => 'application/json',
        ])->timeout(20)->post(self::BASE . '/sms/send/webservice/single', array_filter([
            'sender'    => $this->sender() ?: null,
            'message'   => $message,
            'recipient' => [$this->toLocal($normalizedPhone)],
        ]));

        if (! $response->successful()) {
            throw new \RuntimeException('FarazSMS HTTP ' . $response->status());
        }

        return true;
    }

    public function sendOtp(string $normalizedPhone, string $code): bool
    {
        if ($this->pattern() !== '') {
            $response = Http::withHeaders([
                'Authorization' => 'AccessKey ' . $this->apiKey(),
                'Accept'        => 'application/json',
            ])->timeout(20)->post(self::BASE . '/sms/pattern/normal/send', [
                'code'      => $this->pattern(),
                'sender'    => $this->sender(),
                'recipient' => $this->toLocal($normalizedPhone),
                'variable'  => ['code' => $code],
            ]);

            if (! $response->successful()) {
                throw new \RuntimeException('FarazSMS pattern HTTP ' . $response->status());
            }

            return true;
        }

        return parent::sendOtp($normalizedPhone, $code);
    }
}
