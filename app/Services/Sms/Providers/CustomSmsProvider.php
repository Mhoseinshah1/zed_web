<?php

namespace App\Services\Sms\Providers;

use App\Services\Sms\AbstractSmsProvider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Generic configurable adapter for connecting arbitrary SMS panels without
 * code changes. The admin supplies the URL, HTTP method, optional headers and
 * a body template. The template supports the variables:
 *   {phone} {code} {message} {sender} {api_key}
 *
 * The body template may be JSON or a query string. If it parses as JSON it is
 * sent as a JSON body; otherwise it is sent as form parameters (POST) or query
 * parameters (GET).
 *
 * Response contract: NONE — the panel is whatever the admin pointed this at, so
 * there is no envelope to inspect and only the HTTP status can be judged. The
 * body-level acceptance check the other adapters perform is therefore not
 * available here, and a panel answering HTTP 200 with an error in the body will
 * still be recorded as sent. That limit is inherent to a generic adapter, not
 * an oversight.
 */
class CustomSmsProvider extends AbstractSmsProvider
{
    public function sendMessage(string $normalizedPhone, string $message): bool
    {
        $url = (string) ($this->config['custom_url'] ?? '');
        if ($url === '') {
            throw new \RuntimeException('Custom SMS provider URL is not configured.');
        }

        $method = strtoupper((string) ($this->config['custom_method'] ?? 'POST'));
        $headers = $this->decodeHeaders($this->config['custom_headers'] ?? null);

        $replacements = [
            '{phone}' => $this->toLocal($normalizedPhone),
            '{code}' => (string) ($this->config['_code'] ?? ''),
            '{message}' => $message,
            '{sender}' => $this->sender(),
            '{api_key}' => $this->apiKey(),
        ];

        $bodyTemplate = (string) ($this->config['custom_body_template'] ?? '');
        $rendered = strtr($bodyTemplate, $replacements);

        $request = Http::timeout(20)->withHeaders($headers);

        if ($method === 'GET') {
            $response = $request->get($url, $this->parseToArray($rendered));
        } else {
            $json = json_decode($rendered, true);
            $response = (json_last_error() === JSON_ERROR_NONE && is_array($json))
                ? $request->asJson()->post($url, $json)
                : $request->asForm()->post($url, $this->parseToArray($rendered));
        }

        // No body inspection: see the class docblock — the response shape is
        // unknown by construction.
        $this->assertAccepted($response, 'Custom SMS');

        return true;
    }

    /**
     * HTTP status only, by construction.
     *
     * The admin points this adapter at an arbitrary panel, so there is no
     * response contract to inspect — applying another provider's schema here
     * would be a guess about somebody else's API. A panel answering HTTP 200
     * with an error in the body is therefore still recorded as sent. That limit
     * is inherent to a generic adapter, and it is the reason the built-in
     * adapters fail closed while this one cannot.
     */
    protected function acceptance(Response $response): array
    {
        return [self::ACCEPTED, 'http_only'];
    }

    public function sendOtp(string $normalizedPhone, string $code): bool
    {
        // Expose {code} to the body template for OTP sends.
        $this->config['_code'] = $code;

        return $this->sendMessage($normalizedPhone, $this->buildOtpMessage($code));
    }

    /**
     * @return array<string,string>
     */
    private function decodeHeaders(mixed $headers): array
    {
        if (is_array($headers)) {
            return $headers;
        }
        if (is_string($headers) && $headers !== '') {
            $decoded = json_decode($headers, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * @return array<string,mixed>
     */
    private function parseToArray(string $rendered): array
    {
        $json = json_decode($rendered, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            return $json;
        }
        parse_str($rendered, $parsed);

        return $parsed;
    }
}
