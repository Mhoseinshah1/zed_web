<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Plan;

class CheckoutController extends Controller
{
    public function buy(Plan $plan)
    {
        abort_if(! $plan->is_active, 404);

        $order = Order::create([
            'user_id'           => auth()->id(),
            'plan_id'           => $plan->id,
            'plan_name'         => $plan->name,
            'plan_slug'         => $plan->slug,
            'traffic_gb'        => $plan->traffic_gb,
            'duration_days'     => $plan->duration_days,
            'price_toman'       => $plan->price_toman,
            'final_price_toman' => $plan->price_toman,
            'discount_toman'    => 0,
            'status'            => Order::STATUS_PENDING,
            'payment_status'    => Order::PAYMENT_UNPAID,
        ]);

        return redirect()->route('dashboard.orders.show', $order)
            ->with('success', 'سفارش شما با موفقیت ثبت شد.');
    }
}
