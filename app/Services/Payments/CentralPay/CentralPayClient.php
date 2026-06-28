<?php

namespace App\Services\Payments\CentralPay;

use App\Models\Order;
use App\Models\PaymentTransaction;
use Illuminate\Support\Facades\Http;

class CentralPayClient
{
    private string $apiKey;
    private string $baseUrl;
    private string $type;

    public function __construct()
    {
        $this->apiKey  = config('services.centralpay.api_key', '');
        $this->baseUrl = rtrim(config('services.centralpay.base_url', 'https://centralapi.org/webservice/basic'), '/');
        $this->type    = config('services.centralpay.type', 'deposit');
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
        $returnUrl = route('payments.centralpay.callback', ['orderId' => $transaction->id]);

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
}
