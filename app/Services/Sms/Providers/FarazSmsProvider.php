<?php

namespace App\Services\Sms\Providers;

use App\Services\Sms\AbstractSmsProvider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * FarazSMS / ippanel adapter.
 *
 * Uses the REST send endpoint with a bearer API key. When a pattern code is
 * configured the pattern endpoint is used for OTP delivery.
 *
 * Response contract: the ippanel v1 API answers `{"status":"OK"|"ERROR",
 * "code":<int>,"data":{...}}` — a rejection arrives as `status: "ERROR"` over
 * HTTP 200. Only that envelope is inspected.
 */
class FarazSmsProvider extends AbstractSmsProvider
{
    private const BASE = 'https://api2.ippanel.com/api/v1';

    public function sendMessage(string $normalizedPhone, string $message): bool
    {
        $response = Http::withHeaders([
            'Authorization' => 'AccessKey '.$this->apiKey(),
            'Accept' => 'application/json',
        ])->timeout(20)->post(self::BASE.'/sms/send/webservice/single', array_filter([
            'sender' => $this->sender() ?: null,
            'message' => $message,
            'recipient' => [$this->toLocal($normalizedPhone)],
        ]));

        $this->assertAccepted($response, 'FarazSMS');

        return true;
    }

    /**
     * ippanel v1 answers `{"status":"OK"|"ERROR","code":<int>,"data":{...}}`;
     * a rejection arrives as `status: "ERROR"` over HTTP 200.
     *
     * Only an exact string is judged. A non-string status belongs to a
     * different API generation than the endpoint this adapter targets, so it
     * cannot prove acceptance and is UNVERIFIED rather than assumed sent.
     */
    protected function acceptance(Response $response): array
    {
        $status = $response->json('status');

        if ($status === null) {
            return [self::UNVERIFIED, 'no_status'];
        }

        if (! is_string($status)) {
            return [self::UNVERIFIED, $this->safeStatusToken($status)];
        }

        if (strtoupper($status) === 'OK') {
            return [self::ACCEPTED, 'OK'];
        }

        return [self::REJECTED, $this->safeStatusToken($status)];
    }

    public function sendOtp(string $normalizedPhone, string $code): bool
    {
        if ($this->pattern() !== '') {
            $response = Http::withHeaders([
                'Authorization' => 'AccessKey '.$this->apiKey(),
                'Accept' => 'application/json',
            ])->timeout(20)->post(self::BASE.'/sms/pattern/normal/send', [
                'code' => $this->pattern(),
                'sender' => $this->sender(),
                'recipient' => $this->toLocal($normalizedPhone),
                'variable' => ['code' => $code],
            ]);

            $this->assertAccepted($response, 'FarazSMS pattern');

            return true;
        }

        return parent::sendOtp($normalizedPhone, $code);
    }
}
