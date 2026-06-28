<?php

namespace App\Services\Payments\NowPayments;

use App\Models\PaymentMethod;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * NOWPayments REST API client.
 *
 * Docs: https://documenter.getpostman.com/view/7907941/2s93JusNJt
 *
 * Never log: api_key, ipn_secret, or Authorization headers.
 */
class NowPaymentsClient
{
    private string $baseUrl;
    private string $apiKey;
    private string $ipnSecret;

    public function __construct(PaymentMethod $method)
    {
        $config  = $method->config ?? [];
        $sandbox = (bool) ($config['sandbox'] ?? false);

        if (! empty($config['base_url'])) {
            $this->baseUrl = rtrim($config['base_url'], '/');
        } elseif ($sandbox) {
            $this->baseUrl = 'https://api-sandbox.nowpayments.io/v1';
        } else {
            $this->baseUrl = 'https://api.nowpayments.io/v1';
        }

        // Use encrypted cast — accessing the attribute auto-decrypts
        $this->apiKey    = $method->api_key    ?? '';
        $this->ipnSecret = $method->ipn_secret ?? '';
    }

    // ── HTTP helper ───────────────────────────────────────────────────────────

    private function http(): PendingRequest
    {
        return Http::withHeaders([
            'x-api-key'    => $this->apiKey,
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
        ]);
    }

    // ── API Methods ───────────────────────────────────────────────────────────

    public function status(): array
    {
        $response = $this->http()->get("{$this->baseUrl}/status");
        $this->assertOk($response, 'status');
        return $response->json();
    }

    public function getCurrencies(): array
    {
        $response = $this->http()->get("{$this->baseUrl}/currencies");
        $this->assertOk($response, 'currencies');
        return $response->json();
    }

    public function getMinimumAmount(string $currencyFrom, string $currencyTo): array
    {
        $response = $this->http()->get("{$this->baseUrl}/min-amount", [
            'currency_from' => $currencyFrom,
            'currency_to'   => $currencyTo,
        ]);
        $this->assertOk($response, 'min-amount');
        return $response->json();
    }

    public function getEstimatedPrice(float $amount, string $currencyFrom, string $currencyTo): array
    {
        $response = $this->http()->get("{$this->baseUrl}/estimate", [
            'amount'        => $amount,
            'currency_from' => $currencyFrom,
            'currency_to'   => $currencyTo,
        ]);
        $this->assertOk($response, 'estimate');
        return $response->json();
    }

    public function createPayment(array $payload): array
    {
        $response = $this->http()->post("{$this->baseUrl}/payment", $payload);
        $this->assertOk($response, 'create-payment');
        return $response->json();
    }

    public function createInvoice(array $payload): array
    {
        $response = $this->http()->post("{$this->baseUrl}/invoice", $payload);
        $this->assertOk($response, 'create-invoice');
        return $response->json();
    }

    public function getPaymentStatus(string|int $paymentId): array
    {
        $response = $this->http()->get("{$this->baseUrl}/payment/{$paymentId}");
        $this->assertOk($response, 'get-payment');
        return $response->json();
    }

    // ── IPN Signature Verification ────────────────────────────────────────────

    /**
     * Verify NOWPayments IPN signature per official docs:
     *  1. Sort all parameters alphabetically by key
     *  2. Encode as JSON with sorted keys
     *  3. Sign with HMAC-SHA512 using ipn_secret
     *  4. Compare using constant-time hash_equals
     */
    public function verifyIpnSignature(array $payload, string $signature): bool
    {
        if (empty($this->ipnSecret)) {
            return false;
        }

        ksort($payload);

        $jsonStr  = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $computed = hash_hmac('sha512', $jsonStr, $this->ipnSecret);

        return hash_equals($computed, strtolower($signature));
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function assertOk(\Illuminate\Http\Client\Response $response, string $endpoint): void
    {
        if ($response->failed()) {
            $body    = $response->json() ?? [];
            $message = $body['message'] ?? ($body['error'] ?? "HTTP {$response->status()}");
            throw new \RuntimeException("NOWPayments [{$endpoint}]: {$message}");
        }
    }
}
