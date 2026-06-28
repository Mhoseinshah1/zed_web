<?php

namespace App\Services\Payments\CentralPay;

use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Support\Facades\Http;

class CentralPayClient
{
    private string $apiKey;
    private string $baseUrl;
    private string $type;
    private string $callbackPath;

    public function __construct(PaymentMethod $method)
    {
        $apiKey = $method->api_key;

        if (empty($apiKey)) {
            throw new \RuntimeException('کلید API درگاه CentralPay ثبت نشده است.');
        }

        $this->apiKey       = $apiKey;
        $this->baseUrl      = rtrim($method->getConfig('base_url', 'https://centralapi.org/webservice/basic'), '/');
        $this->type         = $method->getConfig('type', 'deposit');
        $this->callbackPath = $method->getConfig('callback_path', '/payments/centralpay/callback');
    }

    /**
     * Build the returnUrl sent to CentralPay with orderId appended.
     * Uses the callback_path stored in admin config, not a hardcoded route.
     */
    public function buildReturnUrl(int $txId): string
    {
        return url($this->callbackPath) . '?orderId=' . $txId;
    }

    /**
     * Create a payment link via CentralPay getLink endpoint.
     *
     * orderId sent to CentralPay = $transaction->id (unique per attempt, avoids duplicate_orderId).
     * returnUrl includes orderId so the callback can find the transaction.
     */
    public function createPaymentLink(Order $order, PaymentTransaction $transaction): array
    {
        $amount    = $this->toCentralPayTomanAmount($order);
        $returnUrl = $this->buildReturnUrl($transaction->id);

        $payload = [
            'api_key'   => $this->apiKey,
            'type'      => $this->type,
            'amount'    => $amount,
            'userId'    => $order->user_id,
            'orderId'   => $transaction->id,
            'returnUrl' => $returnUrl,
        ];

        $response = Http::timeout(20)
            ->acceptJson()
            ->post($this->baseUrl . '/getLink.php', $payload);

        if (! $response->successful()) {
            throw new \RuntimeException('CentralPay HTTP error: ' . $response->status());
        }

        return $response->json() ?? [];
    }

    /**
     * Create a payment link for a wallet top-up (no order involved).
     */
    public function createPaymentLinkForTopup(User $user, int $amountToman, PaymentTransaction $transaction): array
    {
        $returnUrl = $this->buildReturnUrl($transaction->id);

        $payload = [
            'api_key'   => $this->apiKey,
            'type'      => $this->type,
            'amount'    => $amountToman,
            'userId'    => $user->id,
            'orderId'   => $transaction->id,
            'returnUrl' => $returnUrl,
        ];

        $response = Http::timeout(20)
            ->acceptJson()
            ->post($this->baseUrl . '/getLink.php', $payload);

        if (! $response->successful()) {
            throw new \RuntimeException('CentralPay HTTP error: ' . $response->status());
        }

        return $response->json() ?? [];
    }

    /**
     * Verify a CentralPay payment server-to-server.
     *
     * @param  int|string $orderId  The transaction->id sent to CentralPay as orderId
     */
    public function verifyPayment(int|string $orderId): array
    {
        $response = Http::timeout(20)
            ->acceptJson()
            ->post($this->baseUrl . '/verify.php', [
                'api_key' => $this->apiKey,
                'orderId' => (int) $orderId,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException('CentralPay verify HTTP error: ' . $response->status());
        }

        return $response->json() ?? [];
    }

    /**
     * Amount in Toman to send to CentralPay.
     * ZedProxy stores prices in Toman (IRT) so no conversion is needed.
     */
    public function toCentralPayTomanAmount(Order $order): int
    {
        return (int) $order->final_price_toman;
    }

    /**
     * Extract the error message from a CentralPay failure response.
     */
    public function normalizeError(array $response): string
    {
        return $response['data']['message'] ?? 'unknown_error';
    }

    /**
     * Remove api_key from payload before storing/logging.
     */
    public function sanitizePayload(array $payload): array
    {
        $safe = $payload;
        unset($safe['api_key']);
        return $safe;
    }

    public function getType(): string
    {
        return $this->type;
    }
}
