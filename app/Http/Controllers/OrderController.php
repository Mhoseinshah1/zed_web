<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Services\Discounts\DiscountService;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function index()
    {
        $orders = auth()->user()->orders()->latest()->paginate(15);
        return view('dashboard.orders.index', compact('orders'));
    }

    public function show(Order $order)
    {
        abort_if($order->user_id !== auth()->id(), 403);
        return view('dashboard.orders.show', compact('order'));
    }

    public function applyDiscount(Request $request, Order $order, DiscountService $discountService)
    {
        abort_if($order->user_id !== auth()->id(), 403);

        if ($order->payment_status === Order::PAYMENT_PAID) {
            return back()->withErrors(['discount_code' => 'این سفارش قبلاً پرداخت شده است.']);
        }

        if (in_array($order->status, [Order::STATUS_CANCELLED, Order::STATUS_FAILED])) {
            return back()->withErrors(['discount_code' => 'این سفارش لغو یا ناموفق است.']);
        }

        $request->validate(['discount_code' => ['required', 'string', 'max:64']]);

        try {
            $discountService->applyToOrder(auth()->user(), $order, $request->discount_code);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['discount_code' => $e->getMessage()]);
        }

        return back()->with('discount_success', 'کد تخفیف با موفقیت اعمال شد.');
    }

    public function removeDiscount(Order $order, DiscountService $discountService)
    {
        abort_if($order->user_id !== auth()->id(), 403);

        if ($order->payment_status === Order::PAYMENT_PAID) {
            return back()->withErrors(['discount_code' => 'این سفارش قبلاً پرداخت شده است.']);
        }

        $discountService->removeFromOrder($order);

        return back()->with('discount_success', 'کد تخفیف حذف شد.');
    }
}
