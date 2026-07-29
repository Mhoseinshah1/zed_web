<?php

namespace App\Services\Sms;

use Illuminate\Http\Client\Response;

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
     * @param  array<string,mixed>  $config
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
            '{code}' => $code,
            '{minutes}' => (string) $this->ttlMinutes(),
        ]);
    }

    /** The panel positively confirmed acceptance. */
    protected const ACCEPTED = 'accepted';

    /** The panel positively reported a failure. */
    protected const REJECTED = 'rejected';

    /** No usable acceptance proof — malformed, empty, or an unknown envelope. */
    protected const UNVERIFIED = 'unverified';

    /**
     * Decide whether the panel actually ACCEPTED the message.
     *
     * A 2xx status is not acceptance. Iranian SMS panels routinely answer HTTP
     * 200 while reporting the real outcome in the body — an invalid receptor,
     * an exhausted credit balance, a rejected sender line, an unapproved
     * pattern. Reporting those as delivered is worse than an outright failure:
     * the caller records "code sent", the user waits for an OTP that was never
     * dispatched, and nothing in the logs suggests a problem.
     *
     * Built-in adapters FAIL CLOSED. Anything that is not a positive,
     * exactly-matched acceptance envelope — malformed JSON, HTML under HTTP
     * 200, an empty body, a missing status, a wrong type — is a failure, not a
     * send. An adapter that cannot prove delivery was accepted must not claim
     * it was.
     *
     * @throws \RuntimeException when the panel did not positively accept
     */
    protected function assertAccepted(Response $response, string $provider): void
    {
        if (! $response->successful()) {
            throw new \RuntimeException($provider.' HTTP '.$response->status());
        }

        [$state, $token] = $this->acceptance($response);

        if ($state === self::ACCEPTED) {
            return;
        }

        if ($state === self::REJECTED) {
            throw new \RuntimeException($provider.' rejected the message (status '.$token.')');
        }

        throw new \RuntimeException($provider.' returned an unverified response ('.$token.')');
    }

    /**
     * Classify the response body for THIS provider's documented envelope.
     *
     * @return array{0:string,1:string} [state, safe diagnostic token]
     */
    abstract protected function acceptance(Response $response): array;

    /**
     * Exact match against a canonical integer status.
     *
     * Deliberately NOT `(int) $value === $expected`. Measured, that cast maps
     * `200.9`, `'200abc'`, `' 200'`, `'+200'`, `'200.4'` and `'2e2'` all to
     * 200, and `1.5`, `'1.9'`, `'01'`, `'+1'`, `'1e0'` all to 1 — every one of
     * which would have been read as provider acceptance.
     */
    protected function isExactStatus(mixed $value, int $expected): bool
    {
        if (is_int($value)) {
            return $value === $expected;
        }

        // A canonical decimal string only — JSON numbers can arrive as strings.
        return is_string($value) && $value === (string) $expected;
    }

    /** Reduce a provider status to a bounded, log-safe token. */
    protected function safeStatusToken(mixed $status): string
    {
        if ($status === null) {
            return 'missing';
        }
        if (is_bool($status)) {
            return $status ? 'true' : 'false';
        }
        if (is_array($status)) {
            return 'array';
        }
        if (is_object($status)) {
            return 'object';
        }

        $token = is_scalar($status) ? (string) $status : 'unknown';

        return substr((string) preg_replace('/[^A-Za-z0-9_-]/', '', $token), 0, 32) ?: 'unknown';
    }

    /**
     * Convert +989xxxxxxxxx to the local 09xxxxxxxxx form many panels expect.
     */
    protected function toLocal(string $normalizedPhone): string
    {
        if (str_starts_with($normalizedPhone, '+98')) {
            return '0'.substr($normalizedPhone, 3);
        }

        return $normalizedPhone;
    }
}
