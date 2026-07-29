<?php

namespace App\Services\Sms\Providers;

use App\Services\Sms\AbstractSmsProvider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Kavenegar (kavenegar.com) adapter.
 *
 * Uses the simple "send" REST endpoint. When a pattern (template) code is
 * configured the "verify/lookup" endpoint is used instead, which is the
 * recommended way to deliver OTPs on Kavenegar.
 *
 * Response contract: Kavenegar wraps every reply in
 * `{"return":{"status":<int>,"message":"..."},"entries":[...]}`, where
 * `return.status` is 200 on acceptance and carries the real error code
 * otherwise (411 invalid receptor, 418 insufficient credit, 424 unapproved
 * pattern, …) — all delivered over HTTP 200. Only that envelope is inspected.
 */
class KavenegarSmsProvider extends AbstractSmsProvider
{
    private const BASE = 'https://api.kavenegar.com/v1';

    public function sendMessage(string $normalizedPhone, string $message): bool
    {
        $receptor = $this->toLocal($normalizedPhone);

        $response = Http::asForm()->timeout(20)->post(
            self::BASE.'/'.rawurlencode($this->apiKey()).'/sms/send.json',
            array_filter([
                'receptor' => $receptor,
                'sender' => $this->sender() ?: null,
                'message' => $message,
            ]),
        );

        $this->assertAccepted($response, 'Kavenegar');

        return true;
    }

    /**
     * Kavenegar wraps every reply in
     * `{"return":{"status":<int>,"message":"..."},"entries":[...]}`.
     * `return.status` is exactly 200 on acceptance and carries the real error
     * code otherwise (411 invalid receptor, 418 insufficient credit, 424
     * unapproved pattern) — all delivered over HTTP 200.
     *
     * Anything without that envelope cannot prove acceptance, so it is
     * UNVERIFIED rather than assumed sent.
     */
    protected function acceptance(Response $response): array
    {
        $status = $response->json('return.status');

        if ($status === null) {
            return [self::UNVERIFIED, 'no_return_status'];
        }

        if ($this->isExactStatus($status, 200)) {
            return [self::ACCEPTED, '200'];
        }

        if (is_int($status) || (is_string($status) && preg_match('/^[0-9]{1,6}$/', $status) === 1)) {
            return [self::REJECTED, $this->safeStatusToken($status)];
        }

        // A status of an unexpected TYPE proves nothing either way.
        return [self::UNVERIFIED, $this->safeStatusToken($status)];
    }

    public function sendOtp(string $normalizedPhone, string $code): bool
    {
        // Pattern/lookup mode is preferred for OTP delivery when configured.
        if ($this->pattern() !== '') {
            $receptor = $this->toLocal($normalizedPhone);

            $response = Http::asForm()->timeout(20)->post(
                self::BASE.'/'.rawurlencode($this->apiKey()).'/verify/lookup.json',
                [
                    'receptor' => $receptor,
                    'template' => $this->pattern(),
                    'token' => $code,
                ],
            );

            $this->assertAccepted($response, 'Kavenegar lookup');

            return true;
        }

        return parent::sendOtp($normalizedPhone, $code);
    }
}
