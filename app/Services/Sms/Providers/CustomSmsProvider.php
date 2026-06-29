<?php

namespace App\Services\Sms\Providers;

use App\Services\Sms\AbstractSmsProvider;
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
 */
class CustomSmsProvider extends AbstractSmsProvider
{
    public function sendMessage(string $normalizedPhone, string $message): bool
    {
        $url = (string) ($this->config['custom_url'] ?? '');
        if ($url === '') {
            throw new \RuntimeException('Custom SMS provider URL is not configured.');
        }

        $method  = strtoupper((string) ($this->config['custom_method'] ?? 'POST'));
        $headers = $this->decodeHeaders($this->config['custom_headers'] ?? null);

        $replacements = [
            '{phone}'   => $this->toLocal($normalizedPhone),
            '{code}'    => (string) ($this->config['_code'] ?? ''),
            '{message}' => $message,
            '{sender}'  => $this->sender(),
            '{api_key}' => $this->apiKey(),
        ];

        $bodyTemplate = (string) ($this->config['custom_body_template'] ?? '');
        $rendered     = strtr($bodyTemplate, $replacements);

        $request = Http::timeout(20)->withHeaders($headers);

        if ($method === 'GET') {
            $response = $request->get($url, $this->parseToArray($rendered));
        } else {
            $json = json_decode($rendered, true);
            $response = (json_last_error() === JSON_ERROR_NONE && is_array($json))
                ? $request->asJson()->post($url, $json)
                : $request->asForm()->post($url, $this->parseToArray($rendered));
        }

        if (! $response->successful()) {
            throw new \RuntimeException('Custom SMS HTTP ' . $response->status());
        }

        return true;
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
