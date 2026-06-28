<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Services\Orders\MarkOrderAsPaidService;
use App\Services\Payments\NowPayments\NowPaymentsClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NowPaymentsController extends Controller
{
    // NOWPayments statuses that mean "payment is done, provision now"
    private const FINISHED_STATUSES = ['finished'];

    // Statuses where user should wait
    private const PENDING_STATUSES = [
        'waiting', 'confirming', 'confirmed', 'sending', 'partially_paid',
    ];

    // Statuses that are terminal failures
    private const FAILED_STATUSES = ['failed', 'refunded', 'expired'];

    public function __construct(private readonly MarkOrderAsPaidService $markPaidService) {}

    /**
     * Create a NOWPayments invoice and redirect user to payment page or show details.
     */
    public function create(Request $request, Order $order)
    {
        abort_if($order->user_id !== auth()->id(), 403);
        abort_if($order->payment_status === Order::PAYMENT_PAID, 302, route('dashboard.orders.show', $order));

        $request->validate([
            'payment_method_id' => ['required', 'exists:payment_methods,id'],
            'pay_currency'      => ['nullable', 'string', 'max:20'],
        ]);

        $method = PaymentMethod::findOrFail($request->payment_method_id);
        abort_if(! $method->isNowPayments() || ! $method->is_active, 422);

        // Check for existing active NOWPayments transaction
        $existing = PaymentTransaction::where('order_id', $order->id)
            ->whereIn('status', [
                PaymentTransaction::STATUS_WAITING,
                PaymentTransaction::STATUS_CONFIRMING,
                PaymentTransaction::STATUS_PARTIAL,
            ])
            ->whereNotNull('provider_reference')
            ->first();

        if ($existing) {
            return redirect()->route('dashboard.orders.nowpayments', $order);
        }

        $client      = new NowPaymentsClient($method);
        $payCurrency = $request->pay_currency
            ?? $method->getConfig('default_pay_currency', 'btc');

        try {
            $amountUsd = $this->convertToUsd($order->final_price_toman, $method);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['payment' => $e->getMessage()]);
        }

        $payload = [
            'price_amount'      => round($amountUsd, 2),
            'price_currency'    => 'usd',
            'pay_currency'      => strtolower($payCurrency),
            'order_id'          => (string) $order->id,
            'order_description' => "ZedProxy Order #{$order->order_number}",
            'ipn_callback_url'  => $method->getConfig('ipn_callback_url')
                                    ?? route('webhooks.nowpayments'),
        ];

        if ($successUrl = $method->getConfig('success_url')) {
            $payload['success_url'] = $successUrl;
        }
        if ($cancelUrl = $method->getConfig('cancel_url')) {
            $payload['cancel_url'] = $cancelUrl;
        }

        try {
            $response = $client->createInvoice($payload);
        } catch (\RuntimeException $e) {
            Log::error('NOWPayments create invoice failed', [
                'order_id'  => $order->id,
                'error'     => $e->getMessage(),
            ]);
            return back()->withErrors(['payment' => 'خطا در اتصال به درگاه پرداخت: ' . $e->getMessage()]);
        }

        // Sanitize response — never store api_key or secrets
        $safeResponse = collect($response)->except(['api_key', 'ipn_secret'])->all();

        $tx = PaymentTransaction::create([
            'order_id'              => $order->id,
            'user_id'               => auth()->id(),
            'payment_method_id'     => $method->id,
            'provider'              => 'nowpayments',
            'method'                => 'nowpayments',
            'status'                => PaymentTransaction::STATUS_WAITING,
            'amount_toman'          => $order->final_price_toman,
            'currency'              => 'IRT',
            'provider_reference'    => $response['id'] ?? $response['payment_id'] ?? null,
            'gateway_url'           => $response['invoice_url'] ?? null,
            'gateway_status'        => $response['payment_status'] ?? 'waiting',
            'gateway_price_amount'  => $response['price_amount'] ?? $amountUsd,
            'gateway_price_currency' => $response['price_currency'] ?? 'usd',
            'pay_amount'            => $response['pay_amount'] ?? null,
            'pay_currency'          => $response['pay_currency'] ?? $payCurrency,
            'pay_address'           => $response['pay_address'] ?? null,
            'expires_at'            => isset($response['expiration_estimate_date'])
                                        ? \Carbon\Carbon::parse($response['expiration_estimate_date'])
                                        : null,
            'request_payload'       => $payload,
            'response_payload'      => $safeResponse,
        ]);

        $order->update([
            'payment_status' => Order::PAYMENT_PENDING,
            'status'         => Order::STATUS_AWAITING_PAYMENT,
        ]);

        // If invoice_url is available, redirect to hosted payment page
        if (! empty($response['invoice_url'])) {
            return redirect()->away($response['invoice_url']);
        }

        return redirect()->route('dashboard.orders.nowpayments', $order);
    }

    /**
     * Show the NOWPayments payment details page.
     */
    public function show(Order $order)
    {
        abort_if($order->user_id !== auth()->id(), 403);

        if ($order->payment_status === Order::PAYMENT_PAID) {
            return redirect()->route('dashboard.orders.show', $order)
                ->with('success', 'سفارش شما قبلاً پرداخت شده است.');
        }

        $tx = PaymentTransaction::where('order_id', $order->id)
            ->where('provider', 'nowpayments')
            ->latest()
            ->first();

        if (! $tx) {
            return redirect()->route('dashboard.orders.pay', $order);
        }

        return view('dashboard.orders.nowpayments', compact('order', 'tx'));
    }

    /**
     * Manually check payment status from NOWPayments API.
     */
    public function checkStatus(Request $request, Order $order)
    {
        abort_if($order->user_id !== auth()->id(), 403);
        abort_if($order->payment_status === Order::PAYMENT_PAID, 403);

        $tx = PaymentTransaction::where('order_id', $order->id)
            ->where('provider', 'nowpayments')
            ->whereNotNull('provider_reference')
            ->latest()
            ->firstOrFail();

        $method = PaymentMethod::where('type', PaymentMethod::TYPE_NOWPAYMENTS)
            ->where('is_active', true)
            ->firstOrFail();

        $client = new NowPaymentsClient($method);

        try {
            $status = $client->getPaymentStatus($tx->provider_reference);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['status' => 'خطا در بررسی وضعیت: ' . $e->getMessage()]);
        }

        $gatewayStatus = strtolower($status['payment_status'] ?? '');
        $tx->update([
            'gateway_status'   => $gatewayStatus,
            'response_payload' => collect($status)->except(['api_key', 'ipn_secret'])->all(),
        ]);

        if (in_array($gatewayStatus, self::FINISHED_STATUSES)) {
            $this->markPaidService->markPaid($order, $tx);
            return redirect()->route('dashboard.orders.show', $order)
                ->with('success', 'پرداخت با موفقیت تایید شد.');
        }

        if (in_array($gatewayStatus, self::FAILED_STATUSES)) {
            $tx->update(['status' => PaymentTransaction::STATUS_FAILED]);
            return redirect()->route('dashboard.orders.nowpayments', $order)
                ->with('error', 'پرداخت ناموفق بود یا منقضی شده است.');
        }

        $label = $this->gatewayStatusLabel($gatewayStatus);
        return redirect()->route('dashboard.orders.nowpayments', $order)
            ->with('info', "وضعیت پرداخت: {$label}");
    }

    /**
     * IPN webhook — called by NOWPayments server.
     * No auth, no CSRF — verified by HMAC-SHA512 signature.
     */
    public function ipn(Request $request)
    {
        $signature = $request->header('x-nowpayments-sig', '');
        $payload   = $request->all();

        if (empty($signature)) {
            Log::warning('NOWPayments IPN: missing signature header');
            return response()->json(['error' => 'missing signature'], 400);
        }

        // Find the payment method to get the IPN secret
        $method = PaymentMethod::where('type', PaymentMethod::TYPE_NOWPAYMENTS)
            ->where('is_active', true)
            ->first();

        if (! $method) {
            Log::error('NOWPayments IPN: no active NOWPayments payment method found');
            return response()->json(['error' => 'gateway not configured'], 500);
        }

        $client = new NowPaymentsClient($method);

        if (! $client->verifyIpnSignature($payload, $signature)) {
            Log::warning('NOWPayments IPN: invalid signature', [
                'payment_id' => $payload['payment_id'] ?? null,
            ]);
            return response()->json(['error' => 'invalid signature'], 401);
        }

        $paymentId     = (string) ($payload['payment_id'] ?? '');
        $orderId       = $payload['order_id'] ?? null;
        $gatewayStatus = strtolower($payload['payment_status'] ?? '');

        $tx = PaymentTransaction::where('provider_reference', $paymentId)
            ->where('provider', 'nowpayments')
            ->first();

        if (! $tx) {
            Log::warning('NOWPayments IPN: transaction not found', ['payment_id' => $paymentId]);
            return response()->json(['error' => 'transaction not found'], 404);
        }

        // Update transaction with IPN data
        $tx->update([
            'gateway_status'        => $gatewayStatus,
            'callback_payload'      => $payload,
            'callback_received_at'  => now(),
            'pay_amount'            => $payload['pay_amount'] ?? $tx->pay_amount,
            'pay_currency'          => $payload['pay_currency'] ?? $tx->pay_currency,
            'pay_address'           => $payload['pay_address'] ?? $tx->pay_address,
        ]);

        $order = $tx->order->fresh();

        if (in_array($gatewayStatus, self::FINISHED_STATUSES)) {
            if ($order->payment_status !== \App\Models\Order::PAYMENT_PAID) {
                $this->markPaidService->markPaid($order, $tx);
            }
            Log::info('NOWPayments IPN: order marked paid', [
                'order_id'   => $order->id,
                'payment_id' => $paymentId,
            ]);
        } elseif (in_array($gatewayStatus, self::PENDING_STATUSES)) {
            $statusMap = [
                'waiting'        => PaymentTransaction::STATUS_WAITING,
                'confirming'     => PaymentTransaction::STATUS_CONFIRMING,
                'confirmed'      => PaymentTransaction::STATUS_CONFIRMING,
                'sending'        => PaymentTransaction::STATUS_CONFIRMING,
                'partially_paid' => PaymentTransaction::STATUS_PARTIAL,
            ];
            $tx->update(['status' => $statusMap[$gatewayStatus] ?? PaymentTransaction::STATUS_WAITING]);
        } elseif (in_array($gatewayStatus, self::FAILED_STATUSES)) {
            $failMap = [
                'failed'   => PaymentTransaction::STATUS_FAILED,
                'refunded' => PaymentTransaction::STATUS_REFUNDED,
                'expired'  => PaymentTransaction::STATUS_EXPIRED,
            ];
            $tx->update(['status' => $failMap[$gatewayStatus] ?? PaymentTransaction::STATUS_FAILED]);
        }

        return response()->json(['status' => 'ok']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function convertToUsd(int $amountToman, PaymentMethod $method): float
    {
        $siteCurrency = $method->getConfig('site_currency', 'IRT');
        $exchangeRate = (float) $method->getConfig('exchange_rate_usd', 0);

        if ($exchangeRate <= 0) {
            throw new \RuntimeException('نرخ تبدیل دلار تنظیم نشده است. لطفاً با پشتیبانی تماس بگیرید.');
        }

        // IRT (Toman) → USD
        if (in_array(strtoupper($siteCurrency), ['IRT', 'IRR', 'TOMAN'])) {
            $divisor = strtoupper($siteCurrency) === 'IRR' ? 10 : 1;
            $toman   = $amountToman / $divisor;
            return $toman / $exchangeRate;
        }

        // Already USD or other
        return $amountToman / $exchangeRate;
    }

    private function gatewayStatusLabel(string $status): string
    {
        return match($status) {
            'waiting'        => 'در انتظار واریز',
            'confirming'     => 'در حال تایید تراکنش',
            'confirmed'      => 'تایید شده',
            'sending'        => 'در حال ارسال',
            'partially_paid' => 'پرداخت ناقص',
            'finished'       => 'پرداخت شده',
            'failed'         => 'ناموفق',
            'refunded'       => 'بازگشت داده شده',
            'expired'        => 'منقضی شده',
            default          => $status,
        };
    }
}
