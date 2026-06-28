<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Services\PaymentService;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function __construct(private PaymentService $payment) {}

    public function show(Order $order)
    {
        abort_if($order->user_id !== auth()->id(), 403);
        abort_if(in_array($order->status, [Order::STATUS_COMPLETED, Order::STATUS_CANCELLED]), 404);
        abort_if($order->payment_status === Order::PAYMENT_PAID, 302, route('dashboard.orders.show', $order));

        if ($order->payment_status === Order::PAYMENT_PAID) {
            return redirect()->route('dashboard.orders.show', $order);
        }

        $methods = PaymentMethod::active()->ordered()->get();
        $user    = auth()->user();

        return view('dashboard.orders.pay', compact('order', 'methods', 'user'));
    }

    public function submit(Request $request, Order $order)
    {
        abort_if($order->user_id !== auth()->id(), 403);
        abort_if(in_array($order->status, [Order::STATUS_COMPLETED, Order::STATUS_CANCELLED]), 403);
        abort_if($order->payment_status === Order::PAYMENT_PAID, 403);

        $request->validate([
            'payment_method_id'     => ['required', 'exists:payment_methods,id'],
            'transaction_reference' => ['nullable', 'string', 'max:255'],
            'user_note'             => ['nullable', 'string', 'max:1000'],
        ]);

        $method = PaymentMethod::findOrFail($request->payment_method_id);

        // NOWPayments — delegate to dedicated controller
        if ($method->isNowPayments()) {
            return app(\App\Http\Controllers\NowPaymentsController::class)
                ->create($request, $order);
        }

        // Check for existing pending/submitted payment
        $existing = PaymentTransaction::where('order_id', $order->id)
            ->whereIn('status', [PaymentTransaction::STATUS_PENDING, PaymentTransaction::STATUS_SUBMITTED])
            ->exists();

        if ($existing) {
            // If there's an active NOWPayments transaction, redirect to its page
            $activeNowpayments = PaymentTransaction::where('order_id', $order->id)
                ->whereIn('status', [
                    PaymentTransaction::STATUS_WAITING,
                    PaymentTransaction::STATUS_CONFIRMING,
                    PaymentTransaction::STATUS_PARTIAL,
                ])
                ->where('provider', 'nowpayments')
                ->exists();

            if ($activeNowpayments) {
                return redirect()->route('dashboard.orders.nowpayments', $order);
            }

            return back()->withErrors(['payment_method_id' => 'یک پرداخت در انتظار تایید برای این سفارش وجود دارد.']);
        }

        // Wallet payment — immediate approval
        if ($method->type === PaymentMethod::TYPE_WALLET) {
            $user = auth()->user();

            if ($user->wallet_balance_toman < $order->final_price_toman) {
                return back()->withErrors(['payment_method_id' => 'موجودی کیف پول کافی نیست.']);
            }

            try {
                $this->payment->payWithWallet($order, $user);
            } catch (\RuntimeException $e) {
                return back()->withErrors(['payment_method_id' => $e->getMessage()]);
            }

            return redirect()->route('dashboard.orders.show', $order)
                ->with('success', 'پرداخت از کیف پول با موفقیت انجام شد.');
        }

        // Manual payment — awaiting admin review
        PaymentTransaction::create([
            'order_id'              => $order->id,
            'user_id'               => auth()->id(),
            'payment_method_id'     => $method->id,
            'provider'              => 'manual',
            'method'                => $method->type,
            'status'                => PaymentTransaction::STATUS_SUBMITTED,
            'amount_toman'          => $order->final_price_toman,
            'transaction_reference' => $request->transaction_reference,
            'user_note'             => $request->user_note,
        ]);

        $order->update([
            'payment_status' => Order::PAYMENT_PENDING,
            'status'         => Order::STATUS_AWAITING_PAYMENT,
        ]);

        return redirect()->route('dashboard.orders.show', $order)
            ->with('success', 'رسید پرداخت ثبت شد و پس از تایید مدیریت، سفارش شما پردازش می‌شود.');
    }
}
