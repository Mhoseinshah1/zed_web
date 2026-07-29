<?php

namespace App\Services\Sms\Providers;

use App\Services\Sms\AbstractSmsProvider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * SMS.ir adapter.
 *
 * Uses the v1 REST API with the X-API-KEY header. When a pattern (template) id
 * is configured the "verify/send" endpoint is used for OTP delivery.
 *
 * Response contract: the v1 API answers `{"status":<int>,"message":"...",
 * "data":{...}}`, where `status` is 1 on acceptance and 0 (or an error code)
 * otherwise — delivered over HTTP 200. Only that envelope is inspected.
 */
class SmsIrProvider extends AbstractSmsProvider
{
    private const BASE = 'https://api.sms.ir/v1';

    public function sendMessage(string $normalizedPhone, string $message): bool
    {
        $response = Http::withHeaders([
            'X-API-KEY' => $this->apiKey(),
            'Accept' => 'application/json',
        ])->timeout(20)->post(self::BASE.'/send/bulk', array_filter([
            'lineNumber' => $this->sender() ?: null,
            'messageText' => $message,
            'mobiles' => [$this->toLocal($normalizedPhone)],
        ]));

        $this->assertAccepted($response, 'SMS.ir');

        return true;
    }

    /**
     * SMS.ir v1 answers `{"status":<int>,"message":"...","data":{...}}` with
     * `status` exactly 1 on acceptance. Some endpoints render it as the JSON
     * boolean `true`; both are accepted, nothing else is.
     *
     * `1.5`, `"1.9"`, `"01"`, `"+1"` and `"1e0"` were all read as success by
     * the previous `(int)` comparison.
     */
    protected function acceptance(Response $response): array
    {
        $status = $response->json('status');

        if ($status === null) {
            return [self::UNVERIFIED, 'no_status'];
        }

        if ($status === true || $this->isExactStatus($status, 1)) {
            return [self::ACCEPTED, '1'];
        }

        if ($status === false || is_int($status) || (is_string($status) && preg_match('/^[0-9]{1,6}$/', $status) === 1)) {
            return [self::REJECTED, $this->safeStatusToken($status)];
        }

        return [self::UNVERIFIED, $this->safeStatusToken($status)];
    }

    public function sendOtp(string $normalizedPhone, string $code): bool
    {
        if ($this->pattern() !== '') {
            $response = Http::withHeaders([
                'X-API-KEY' => $this->apiKey(),
                'Accept' => 'application/json',
            ])->timeout(20)->post(self::BASE.'/send/verify', [
                'mobile' => $this->toLocal($normalizedPhone),
                'templateId' => $this->pattern(),
                'parameters' => [
                    ['name' => 'CODE', 'value' => $code],
                ],
            ]);

            $this->assertAccepted($response, 'SMS.ir verify');

            return true;
        }

        return parent::sendOtp($normalizedPhone, $code);
    }
}
