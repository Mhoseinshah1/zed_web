<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Orders\MarkOrderAsPaidService;
use App\Services\Payments\NowPayments\NowPaymentsClient;
use App\Services\WalletService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NowPaymentsController extends Controller
{
    private const FINISHED_STATUSES = ['finished'];

    private const PENDING_STATUSES = [
        'waiting', 'confirming', 'confirmed', 'sending', 'partially_paid',
    ];

    private const FAILED_STATUSES = ['failed', 'refunded', 'expired'];

    public function __construct(
        private readonly MarkOrderAsPaidService $markPaidService,
        private readonly WalletService $walletService,
    ) {}

    /**
     * Create a NOWPayments hosted invoice and redirect user to the payment page.
     */
    public function create(Request $request, Order $order)
    {
        abort_if($order->user_id !== auth()->id(), 403);
        abort_if($order->payment_status === Order::PAYMENT_PAID, 302, route('dashboard.orders.show', $order));

        $request->validate([
            'payment_method_id' => ['required', 'exists:payment_methods,id'],
        ]);

        $method = PaymentMethod::findOrFail($request->payment_method_id);
        abort_if(! $method->isNowPayments() || ! $method->is_active, 422);

        // Check for existing active NOWPayments transaction (idempotency)
        $existing = PaymentTransaction::where('order_id', $order->id)
            ->whereIn('status', [
                PaymentTransaction::STATUS_WAITING,
                PaymentTransaction::STATUS_CONFIRMING,
                PaymentTransaction::STATUS_PARTIAL,
            ])
            ->whereNotNull('provider_reference')
            ->first();

        if ($existing) {
            if ($existing->gateway_url) {
                return redirect()->away($existing->gateway_url);
            }
            return redirect()->route('dashboard.orders.nowpayments', $order);
        }

        try {
            $amountUsd = $this->convertToUsd($order->final_price_toman, $method);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['payment' => $e->getMessage()]);
        }

        $mode = $method->getConfig('nowpayments_mode', 'invoice');

        $payload = [
            'price_amount'      => round($amountUsd, 2),
            'price_currency'    => strtolower($method->getConfig('price_currency', 'usd')),
            'order_id'          => (string) $order->id,
            'order_description' => "ZedProxy Order #{$order->order_number}",
            'ipn_callback_url'  => $method->getConfig('ipn_callback_url')
                                    ?? route('webhooks.nowpayments'),
            'success_url'       => $method->getConfig('success_url')
                                    ?? route('dashboard.orders.show', $order),
            'cancel_url'        => $method->getConfig('cancel_url')
                                    ?? route('dashboard.orders.pay', $order),
        ];

        if ($mode === 'direct') {
            $payCurrency = $method->getConfig('default_pay_currency');
            if ($payCurrency) {
                $payload['pay_currency'] = strtolower($payCurrency);
            }
        }

        $client = new NowPaymentsClient($method);

        try {
            $response = $mode === 'direct'
                ? $client->createPayment($payload)
                : $client->createInvoice($payload);
        } catch (\RuntimeException $e) {
            Log::error('NOWPayments create invoice/payment failed', [
                'order_id' => $order->id,
                'mode'     => $mode,
                'error'    => $e->getMessage(),
            ]);

            $errorMsg = str_contains(strtolower($e->getMessage()), 'minimum')
                ? 'مبلغ سفارش برای پرداخت با NOWPayments کمتر از حداقل مجاز یا قابل پرداخت نیست.'
                : 'خطا در اتصال به درگاه پرداخت: ' . $e->getMessage();

            return back()->withErrors(['payment' => $errorMsg]);
        }

        $safeResponse = collect($response)->except(['api_key', 'ipn_secret'])->all();

        $invoiceId  = $response['id'] ?? null;
        $paymentId  = $response['payment_id'] ?? null;
        $invoiceUrl = $response['invoice_url'] ?? null;

        $providerRef = $invoiceId ?? $paymentId;

        if (! $providerRef) {
            Log::warning('NOWPayments: response missing id/payment_id', [
                'order_id' => $order->id,
                'response' => $safeResponse,
            ]);
        }

        $tx = PaymentTransaction::create([
            'order_id'              => $order->id,
            'user_id'               => auth()->id(),
            'payment_method_id'     => $method->id,
            'provider'              => 'nowpayments',
            'method'                => 'nowpayments',
            'payment_purpose'       => 'order_payment',
            'status'                => PaymentTransaction::STATUS_WAITING,
            'amount_toman'          => $order->final_price_toman,
            'currency'              => 'IRT',
            'provider_reference'    => $providerRef,
            'external_id'           => ($mode === 'direct') ? $paymentId : null,
            'gateway_url'           => $invoiceUrl,
            'gateway_status'        => $response['payment_status'] ?? 'waiting',
            'gateway_price_amount'  => $response['price_amount'] ?? $amountUsd,
            'gateway_price_currency' => $response['price_currency'] ?? 'usd',
            'pay_amount'            => $response['pay_amount'] ?? null,
            'pay_currency'          => $response['pay_currency'] ?? null,
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

        if ($invoiceUrl) {
            return redirect()->away($invoiceUrl);
        }

        if ($mode === 'invoice') {
            Log::warning('NOWPayments: invoice created but no invoice_url returned', [
                'order_id' => $order->id,
                'response' => $safeResponse,
            ]);
            return back()->withErrors(['payment' => 'ساخت فاکتور NOWPayments انجام نشد. لطفاً دوباره تلاش کنید.']);
        }

        return redirect()->route('dashboard.orders.nowpayments', $order);
    }

    /**
     * Create a NOWPayments invoice for wallet top-up.
     * Called from WalletController::processTopup.
     */
    public function createWalletTopup(User $user, int $amountToman, PaymentMethod $method)
    {
        try {
            $amountUsd = $this->convertToUsd($amountToman, $method);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['payment' => $e->getMessage()]);
        }

        $mode = $method->getConfig('nowpayments_mode', 'invoice');

        $orderId = 'wallet-' . $user->id . '-' . time();

        $payload = [
            'price_amount'      => round($amountUsd, 2),
            'price_currency'    => strtolower($method->getConfig('price_currency', 'usd')),
            'order_id'          => $orderId,
            'order_description' => "ZedProxy Wallet TopUp - User #{$user->id}",
            'ipn_callback_url'  => $method->getConfig('ipn_callback_url')
                                    ?? route('webhooks.nowpayments'),
            'success_url'       => route('dashboard.wallet'),
            'cancel_url'        => route('dashboard.wallet.topup'),
        ];

        if ($mode === 'direct') {
            $payCurrency = $method->getConfig('default_pay_currency');
            if ($payCurrency) {
                $payload['pay_currency'] = strtolower($payCurrency);
            }
        }

        $client = new NowPaymentsClient($method);

        try {
            $response = $mode === 'direct'
                ? $client->createPayment($payload)
                : $client->createInvoice($payload);
        } catch (\RuntimeException $e) {
            Log::error('NOWPayments wallet topup failed', [
                'user_id' => $user->id,
                'error'   => $e->getMessage(),
            ]);
            return back()->withErrors(['payment' => 'خطا در اتصال به درگاه پرداخت: ' . $e->getMessage()]);
        }

        $safeResponse = collect($response)->except(['api_key', 'ipn_secret'])->all();
        $invoiceId    = $response['id'] ?? null;
        $paymentId    = $response['payment_id'] ?? null;
        $invoiceUrl   = $response['invoice_url'] ?? null;
        $providerRef  = $invoiceId ?? $paymentId;

        $tx = PaymentTransaction::create([
            'order_id'              => null,
            'user_id'               => $user->id,
            'payment_method_id'     => $method->id,
            'provider'              => 'nowpayments',
            'method'                => 'nowpayments',
            'payment_purpose'       => 'wallet_topup',
            'status'                => PaymentTransaction::STATUS_WAITING,
            'amount_toman'          => $amountToman,
            'currency'              => 'IRT',
            'provider_reference'    => $providerRef,
            'external_id'           => ($mode === 'direct') ? $paymentId : null,
            'gateway_url'           => $invoiceUrl,
            'gateway_status'        => $response['payment_status'] ?? 'waiting',
            'gateway_price_amount'  => $response['price_amount'] ?? $amountUsd,
            'gateway_price_currency' => $response['price_currency'] ?? 'usd',
            'pay_amount'            => $response['pay_amount'] ?? null,
            'pay_currency'          => $response['pay_currency'] ?? null,
            'pay_address'           => $response['pay_address'] ?? null,
            'request_payload'       => $payload,
            'response_payload'      => $safeResponse,
        ]);

        if ($invoiceUrl) {
            return redirect()->away($invoiceUrl);
        }

        return back()->withErrors(['payment' => 'ساخت فاکتور NOWPayments انجام نشد. لطفاً دوباره تلاش کنید.']);
    }

    /**
     * Show the NOWPayments payment details page (direct mode or fallback).
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
            ->latest()
            ->first();

        abort_if(! $tx, 404);

        $paymentId = $tx->external_id ?? $tx->provider_reference;

        if (! $paymentId) {
            return redirect()->route('dashboard.orders.nowpayments', $order)
                ->with('info', 'اطلاعات پرداخت هنوز موجود نیست. لطفاً دوباره تلاش کنید.');
        }

        if ($tx->gateway_url && ! $tx->external_id) {
            return redirect()->route('dashboard.orders.nowpayments', $order)
                ->with('info', 'پرداخت هنوز توسط کاربر انتخاب/شروع نشده است. لطفاً پس از انتخاب ارز در صفحه NOWPayments، دوباره بررسی کنید.');
        }

        $method = PaymentMethod::where('type', PaymentMethod::TYPE_NOWPAYMENTS)
            ->where('is_active', true)
            ->firstOrFail();

        $client = new NowPaymentsClient($method);

        try {
            $status = $client->getPaymentStatus($paymentId);
        } catch (\RuntimeException $e) {
            return redirect()->route('dashboard.orders.nowpayments', $order)
                ->withErrors(['status' => 'خطا در بررسی وضعیت: ' . $e->getMessage()]);
        }

        $gatewayStatus = strtolower($status['payment_status'] ?? '');
        $tx->update([
            'gateway_status'   => $gatewayStatus,
            'response_payload' => collect($status)->except(['api_key', 'ipn_secret'])->all(),
        ]);

        if (in_array($gatewayStatus, self::FINISHED_STATUSES)) {
            // Order payment only (wallet topup doesn't use this route)
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
     * Branches by payment_purpose: order_payment or wallet_topup.
     */
    public function ipn(Request $request)
    {
        $signature = $request->header('x-nowpayments-sig', '');
        $payload   = $request->all();

        if (empty($signature)) {
            Log::warning('NOWPayments IPN: missing signature header');
            return response()->json(['error' => 'missing signature'], 400);
        }

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
                'invoice_id' => $payload['invoice_id'] ?? null,
            ]);
            return response()->json(['error' => 'invalid signature'], 401);
        }

        $invoiceId     = (string) ($payload['invoice_id'] ?? '');
        $paymentId     = (string) ($payload['payment_id'] ?? '');
        $orderId       = $payload['order_id'] ?? null;
        $gatewayStatus = strtolower($payload['payment_status'] ?? '');

        $tx = $this->findTransaction($invoiceId, $paymentId, $orderId);

        if (! $tx) {
            Log::warning('NOWPayments IPN: transaction not found', [
                'payment_id' => $paymentId ?: null,
                'invoice_id' => $invoiceId ?: null,
                'order_id'   => $orderId,
            ]);
            return response()->json(['error' => 'transaction not found'], 404);
        }

        $updateData = [
            'gateway_status'       => $gatewayStatus,
            'callback_payload'     => $payload,
            'callback_received_at' => now(),
            'pay_amount'           => $payload['pay_amount'] ?? $tx->pay_amount,
            'pay_currency'         => $payload['pay_currency'] ?? $tx->pay_currency,
            'pay_address'          => $payload['pay_address'] ?? $tx->pay_address,
        ];

        if ($paymentId && ! $tx->external_id) {
            $updateData['external_id'] = $paymentId;
        }

        $tx->update($updateData);

        if (in_array($gatewayStatus, self::FINISHED_STATUSES)) {
            if ($tx->payment_purpose === 'wallet_topup') {
                $txUser = $tx->user;
                if (! $txUser) {
                    Log::error('NOWPayments IPN: wallet_topup tx has no user', ['tx_id' => $tx->id]);
                } elseif ($tx->status === PaymentTransaction::STATUS_APPROVED) {
                    // Already processed — a duplicate IPN delivery. Do not re-credit.
                    Log::info('NOWPayments IPN: wallet_topup already approved, skipping', ['tx_id' => $tx->id]);
                } else {
                    $this->walletService->creditFromPaymentTransaction($txUser, $tx);
                    $tx->update(['status' => PaymentTransaction::STATUS_APPROVED, 'paid_at' => now()]);
                    Log::info('NOWPayments IPN: wallet topped up', [
                        'user_id'      => $txUser->id,
                        'tx_id'        => $tx->id,
                        'amount_toman' => $tx->amount_toman,
                    ]);
                }
            } else {
                $order = $tx->order ? $tx->order->fresh() : null;
                if ($order && $order->payment_status !== Order::PAYMENT_PAID) {
                    $this->markPaidService->markPaid($order, $tx);
                }
                Log::info('NOWPayments IPN: order marked paid', [
                    'order_id'   => $order?->id,
                    'payment_id' => $paymentId ?: null,
                    'invoice_id' => $invoiceId ?: null,
                ]);
            }
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

    private function findTransaction(string $invoiceId, string $paymentId, ?string $orderId): ?PaymentTransaction
    {
        // 1. Match by invoice_id → provider_reference (invoice mode)
        if ($invoiceId) {
            $tx = PaymentTransaction::where('provider_reference', $invoiceId)
                ->where('provider', 'nowpayments')
                ->first();
            if ($tx) {
                return $tx;
            }
        }

        // 2. Match by payment_id → provider_reference (direct mode) or external_id
        if ($paymentId) {
            $tx = PaymentTransaction::where(function ($q) use ($paymentId) {
                $q->where('provider_reference', $paymentId)
                  ->orWhere('external_id', $paymentId);
            })
                ->where('provider', 'nowpayments')
                ->first();
            if ($tx) {
                return $tx;
            }
        }

        // 3. Match by order_id — last resort (order payments only)
        if ($orderId) {
            return PaymentTransaction::where('order_id', $orderId)
                ->where('provider', 'nowpayments')
                ->latest()
                ->first();
        }

        return null;
    }

    private function convertToUsd(int $amountToman, PaymentMethod $method): float
    {
        $siteCurrency = $method->getConfig('site_currency', 'IRT');
        $exchangeRate = (float) $method->getConfig('exchange_rate_usd', 0);

        if ($exchangeRate <= 0) {
            throw new \RuntimeException('نرخ تبدیل دلار تنظیم نشده است. لطفاً با پشتیبانی تماس بگیرید.');
        }

        if (in_array(strtoupper($siteCurrency), ['IRT', 'IRR', 'TOMAN'])) {
            $divisor = strtoupper($siteCurrency) === 'IRR' ? 10 : 1;
            return ($amountToman / $divisor) / $exchangeRate;
        }

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
