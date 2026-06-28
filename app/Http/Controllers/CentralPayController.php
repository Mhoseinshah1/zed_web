<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Services\Orders\MarkOrderAsPaidService;
use App\Services\Payments\CentralPay\CentralPayClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CentralPayController extends Controller
{
    public function __construct(private readonly MarkOrderAsPaidService $markPaidService) {}

    /**
     * Initiate a CentralPay payment. Called from PaymentController::submit.
     *
     * Creates a PaymentTransaction, calls CentralPay getLink, and redirects
     * the user to the hosted payment page.
     */
    public function initiate(Request $request, Order $order)
    {
        abort_if($order->user_id !== auth()->id(), 403);
        abort_if($order->payment_status === Order::PAYMENT_PAID, 403);

        $request->validate([
            'payment_method_id' => ['required', 'exists:payment_methods,id'],
        ]);

        $method = PaymentMethod::findOrFail($request->payment_method_id);
        abort_if(! $method->isCentralPay() || ! $method->is_active, 422);

        // Resolve client — throws RuntimeException if api_key is not configured
        try {
            $client = new CentralPayClient($method);
        } catch (\RuntimeException $e) {
            Log::warning('CentralPay initiate: api_key not configured', ['method_id' => $method->id]);
            return back()->withErrors(['payment' => 'درگاه پرداخت ریالی در حال حاضر پیکربندی نشده است. لطفاً با پشتیبانی تماس بگیرید.']);
        }

        // Idempotency: reuse existing active CentralPay transaction
        $existing = PaymentTransaction::where('order_id', $order->id)
            ->where('provider', 'centralpay')
            ->whereIn('status', [PaymentTransaction::STATUS_PENDING, PaymentTransaction::STATUS_WAITING])
            ->whereNotNull('gateway_url')
            ->latest()
            ->first();

        if ($existing) {
            return redirect()->away($existing->gateway_url);
        }

        $amount = $client->toCentralPayTomanAmount($order);

        // Create the transaction record FIRST so we have an ID for the returnUrl and CentralPay orderId.
        // Using transaction->id as CentralPay orderId prevents duplicate_orderId on retries.
        $tx = PaymentTransaction::create([
            'order_id'          => $order->id,
            'user_id'           => auth()->id(),
            'payment_method_id' => $method->id,
            'provider'          => 'centralpay',
            'method'            => 'centralpay',
            'status'            => PaymentTransaction::STATUS_PENDING,
            'amount_toman'      => $order->final_price_toman,
            'gateway_amount'    => $amount,
            'gateway_currency'  => 'TOMAN',
            'gateway_status'    => 'created',
        ]);

        try {
            $response = $client->createPaymentLink($order, $tx);
        } catch (\RuntimeException $e) {
            $tx->update([
                'gateway_status' => 'failed',
                'failure_reason' => $e->getMessage(),
                'status'         => PaymentTransaction::STATUS_FAILED,
                'failed_at'      => now(),
            ]);
            Log::error('CentralPay getLink HTTP error', [
                'order_id' => $order->id,
                'tx_id'    => $tx->id,
                'error'    => $e->getMessage(),
            ]);
            return back()->withErrors(['payment' => 'اتصال به درگاه پرداخت ناموفق بود. لطفاً دوباره تلاش کنید.']);
        }

        // Store sanitized request/response (never log api_key)
        $safePayload = $client->sanitizePayload([
            'type'      => $client->getType(),
            'amount'    => $amount,
            'userId'    => $order->user_id,
            'orderId'   => $tx->id,
            'returnUrl' => $client->buildReturnUrl($tx->id),
        ]);
        $tx->update([
            'request_payload'  => $safePayload,
            'response_payload' => $response,
        ]);

        if (! ($response['success'] ?? false)) {
            $reason = $client->normalizeError($response);
            $tx->update([
                'gateway_status' => 'failed',
                'failure_reason' => $reason,
                'status'         => PaymentTransaction::STATUS_FAILED,
                'failed_at'      => now(),
            ]);
            Log::warning('CentralPay getLink failed', [
                'order_id' => $order->id,
                'tx_id'    => $tx->id,
                'reason'   => $reason,
            ]);
            return back()->withErrors(['payment' => 'اتصال به درگاه پرداخت ناموفق بود. لطفاً دوباره تلاش کنید.']);
        }

        $redirectUrl = $response['data']['redirectUrl'] ?? null;

        if (! $redirectUrl) {
            $tx->update([
                'gateway_status' => 'failed',
                'failure_reason' => 'no_redirect_url',
                'status'         => PaymentTransaction::STATUS_FAILED,
                'failed_at'      => now(),
            ]);
            Log::warning('CentralPay getLink: no redirectUrl in response', [
                'order_id' => $order->id,
                'tx_id'    => $tx->id,
            ]);
            return back()->withErrors(['payment' => 'اتصال به درگاه پرداخت ناموفق بود. لطفاً دوباره تلاش کنید.']);
        }

        $tx->update([
            'gateway_url'    => $redirectUrl,
            'gateway_status' => 'created',
            'status'         => PaymentTransaction::STATUS_WAITING,
        ]);

        $order->update([
            'payment_status' => Order::PAYMENT_PENDING,
            'status'         => Order::STATUS_AWAITING_PAYMENT,
        ]);

        return redirect()->away($redirectUrl);
    }

    /**
     * Callback from CentralPay after user completes or cancels payment.
     *
     * CentralPay redirects via GET with ?orderId={transaction_id}.
     * We verify server-to-server before trusting any payment outcome.
     */
    public function callback(Request $request)
    {
        $txId = $request->query('orderId');

        if (! $txId) {
            return redirect()->route('dashboard.orders')
                ->with('error', 'اطلاعات بازگشت از درگاه ناقص است. وضعیت سفارش خود را بررسی کنید.');
        }

        // orderId sent to CentralPay = payment_transactions.id
        $tx = PaymentTransaction::where('id', (int) $txId)
            ->where('provider', 'centralpay')
            ->first();

        if (! $tx) {
            Log::warning('CentralPay callback: transaction not found', ['tx_id' => $txId]);
            return redirect()->route('dashboard.orders')
                ->with('error', 'تراکنش پرداخت یافت نشد.');
        }

        $order = $tx->order;

        // Idempotency: already paid
        if ($order->payment_status === Order::PAYMENT_PAID) {
            return redirect()->route('dashboard.orders.show', $order)
                ->with('success', 'پرداخت قبلاً تایید شده است.');
        }

        // Resolve CentralPay method for client config (api_key, base_url, etc.)
        $method = $tx->paymentMethod
            ?? PaymentMethod::where('type', PaymentMethod::TYPE_CENTRALPAY)->first();

        if (! $method) {
            Log::error('CentralPay callback: no payment method found', ['tx_id' => $tx->id]);
            return redirect()->route('dashboard.orders.show', $order)
                ->with('error', 'درگاه پرداخت ریالی تنظیم نشده است. با پشتیبانی تماس بگیرید.');
        }

        // Verify server-to-server — never trust GET callback alone
        try {
            $client = new CentralPayClient($method);
        } catch (\RuntimeException $e) {
            Log::error('CentralPay callback: api_key not configured', ['tx_id' => $tx->id]);
            return redirect()->route('dashboard.orders.show', $order)
                ->with('error', 'درگاه پرداخت ریالی در حال حاضر پیکربندی نشده است. با پشتیبانی تماس بگیرید.');
        }

        try {
            $verify = $client->verifyPayment($tx->id);
        } catch (\RuntimeException $e) {
            Log::error('CentralPay verify HTTP error', [
                'tx_id' => $tx->id,
                'error' => $e->getMessage(),
            ]);
            return redirect()->route('dashboard.orders.show', $order)
                ->with('error', 'پرداخت تایید نشد. اگر مبلغ از حساب شما کسر شده، با پشتیبانی تماس بگیرید.');
        }

        $tx->update([
            'callback_payload'     => $verify,
            'callback_received_at' => now(),
        ]);

        if (! ($verify['success'] ?? false)) {
            $reason = $client->normalizeError($verify);
            $tx->update([
                'gateway_status' => 'failed',
                'failure_reason' => $reason,
                'status'         => PaymentTransaction::STATUS_FAILED,
                'failed_at'      => now(),
            ]);
            Log::warning('CentralPay verify failed', [
                'tx_id'  => $tx->id,
                'reason' => $reason,
            ]);
            return redirect()->route('dashboard.orders.show', $order)
                ->with('error', 'پرداخت تایید نشد. اگر مبلغ از حساب شما کسر شده، با پشتیبانی تماس بگیرید.');
        }

        $data           = $verify['data'] ?? [];
        $verifiedAmount = (int) ($data['amount'] ?? 0);
        $verifiedUserId = (int) ($data['userId'] ?? 0);
        $referenceId    = $data['referenceId'] ?? null;
        $cardNumber     = (string) ($data['userCardNumber'] ?? '');

        // Amount mismatch — do not mark order paid
        if ($verifiedAmount !== (int) $tx->gateway_amount) {
            $tx->update([
                'gateway_status' => 'amount_mismatch',
                'failure_reason' => "amount_mismatch: expected {$tx->gateway_amount}, got {$verifiedAmount}",
                'status'         => PaymentTransaction::STATUS_FAILED,
                'failed_at'      => now(),
            ]);
            Log::warning('CentralPay amount mismatch', [
                'tx_id'    => $tx->id,
                'expected' => $tx->gateway_amount,
                'got'      => $verifiedAmount,
            ]);
            return redirect()->route('dashboard.orders.show', $order)
                ->with('error', 'مبلغ تاییدشده با مبلغ سفارش مطابقت ندارد. لطفاً با پشتیبانی تماس بگیرید.');
        }

        // userId mismatch — do not mark order paid
        if ($verifiedUserId && $verifiedUserId !== (int) $order->user_id) {
            $tx->update([
                'gateway_status' => 'user_mismatch',
                'failure_reason' => "user_mismatch: expected {$order->user_id}, got {$verifiedUserId}",
                'status'         => PaymentTransaction::STATUS_FAILED,
                'failed_at'      => now(),
            ]);
            Log::warning('CentralPay userId mismatch', [
                'tx_id'    => $tx->id,
                'expected' => $order->user_id,
                'got'      => $verifiedUserId,
            ]);
            return redirect()->route('dashboard.orders.show', $order)
                ->with('error', 'مبلغ تاییدشده با مبلغ سفارش مطابقت ندارد. لطفاً با پشتیبانی تماس بگیرید.');
        }

        // Success — store masked card, mark paid, provision
        $maskedCard = self::maskCardNumber($cardNumber);

        $tx->update([
            'gateway_status'     => 'verified',
            'provider_reference' => (string) $referenceId,
            'verified_at'        => now(),
            'response_payload'   => array_merge($tx->response_payload ?? [], [
                'referenceId'        => $referenceId,
                'masked_card_number' => $maskedCard,
            ]),
        ]);

        $this->markPaidService->markPaid($order, $tx);

        Log::info('CentralPay payment verified and order marked paid', [
            'order_id'     => $order->id,
            'tx_id'        => $tx->id,
            'reference_id' => $referenceId,
        ]);

        return redirect()->route('dashboard.orders.show', $order)
            ->with('success', 'پرداخت شما با موفقیت تایید شد و سرویس در حال فعال‌سازی است.');
    }

    /**
     * Admin action: re-verify a CentralPay transaction.
     * Called from PaymentTransactionResource Filament action.
     *
     * @throws \RuntimeException  on any failure (shown as admin notification)
     */
    public static function adminVerify(PaymentTransaction $tx, MarkOrderAsPaidService $markPaidService): void
    {
        $order = $tx->order;

        if ($order->payment_status === Order::PAYMENT_PAID) {
            throw new \RuntimeException('سفارش قبلاً پرداخت شده است.');
        }

        $method = $tx->paymentMethod
            ?? PaymentMethod::where('type', PaymentMethod::TYPE_CENTRALPAY)->first();

        if (! $method) {
            throw new \RuntimeException('روش پرداخت CentralPay یافت نشد.');
        }

        try {
            $client = new CentralPayClient($method);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('کلید API CentralPay ثبت نشده است. لطفاً از پانل ادمین تنظیم کنید.');
        }

        try {
            $verify = $client->verifyPayment($tx->id);
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('خطا در اتصال به CentralPay: ' . $e->getMessage());
        }

        $tx->update(['callback_payload' => $verify]);

        if (! ($verify['success'] ?? false)) {
            $reason = $client->normalizeError($verify);
            $tx->update([
                'gateway_status' => 'failed',
                'failure_reason' => $reason,
                'status'         => PaymentTransaction::STATUS_FAILED,
                'failed_at'      => now(),
            ]);
            throw new \RuntimeException('پرداخت تایید نشد: ' . $reason);
        }

        $data           = $verify['data'] ?? [];
        $verifiedAmount = (int) ($data['amount'] ?? 0);
        $verifiedUserId = (int) ($data['userId'] ?? 0);
        $referenceId    = $data['referenceId'] ?? null;
        $cardNumber     = (string) ($data['userCardNumber'] ?? '');

        if ($verifiedAmount !== (int) $tx->gateway_amount) {
            $tx->update([
                'gateway_status' => 'amount_mismatch',
                'failure_reason' => "amount_mismatch: expected {$tx->gateway_amount}, got {$verifiedAmount}",
                'status'         => PaymentTransaction::STATUS_FAILED,
                'failed_at'      => now(),
            ]);
            throw new \RuntimeException('مبلغ تاییدشده با مبلغ سفارش مطابقت ندارد.');
        }

        if ($verifiedUserId && $verifiedUserId !== (int) $order->user_id) {
            $tx->update([
                'gateway_status' => 'user_mismatch',
                'failure_reason' => "user_mismatch: expected {$order->user_id}, got {$verifiedUserId}",
                'status'         => PaymentTransaction::STATUS_FAILED,
                'failed_at'      => now(),
            ]);
            throw new \RuntimeException('شناسه کاربر با اطلاعات سفارش مطابقت ندارد.');
        }

        $maskedCard = self::maskCardNumber($cardNumber);

        $tx->update([
            'gateway_status'     => 'verified',
            'provider_reference' => (string) $referenceId,
            'verified_at'        => now(),
            'response_payload'   => array_merge($tx->response_payload ?? [], [
                'referenceId'        => $referenceId,
                'masked_card_number' => $maskedCard,
            ]),
        ]);

        $markPaidService->markPaid($order, $tx);
    }

    /**
     * Mask a card number: keep first 6 and last 4 digits, replace middle with ******.
     * Example: 1111222233334444 → 111122******4444
     */
    public static function maskCardNumber(string $cardNumber): string
    {
        $digits = preg_replace('/\D/', '', $cardNumber);
        if (strlen($digits) < 10) {
            return str_repeat('*', strlen($digits));
        }
        return substr($digits, 0, 6) . '******' . substr($digits, -4);
    }
}
