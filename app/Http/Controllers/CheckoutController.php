<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Plan;
use App\Models\PurchaseIntent;
use App\Services\Orders\OrderIdempotencyService;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function buy(Request $request, Plan $plan, OrderIdempotencyService $idempotency)
    {
        abort_if(! $plan->is_active, 404);

        $user = $request->user();
        $token = $request->input('purchase_token');

        try {
            $result = $idempotency->createOrReturn(
                $user,
                PurchaseIntent::OP_NEW_SERVICE,
                ['plan_id' => $plan->id],
                $token,
                function (string $fingerprint) use ($user, $plan): Order {
                    // Re-read + lock the plan INSIDE the transaction and verify it
                    // is still purchasable; the price comes from the DB, never the client.
                    $fresh = Plan::whereKey($plan->id)->lockForUpdate()->first();
                    if (! $fresh || ! $fresh->is_active) {
                        throw new \RuntimeException(OrderIdempotencyService::MSG_PLAN_INACTIVE);
                    }

                    return Order::create([
                        'order_type' => Order::TYPE_NEW_SERVICE,
                        'user_id' => $user->id,
                        'purchase_fingerprint' => $fingerprint,
                        'plan_id' => $fresh->id,
                        'plan_name' => $fresh->name,
                        'plan_slug' => $fresh->slug,
                        'traffic_gb' => $fresh->traffic_gb,
                        'duration_days' => $fresh->duration_days,
                        'price_toman' => $fresh->price_toman,
                        'final_price_toman' => $fresh->price_toman,
                        'discount_toman' => 0,
                        'status' => Order::STATUS_PENDING,
                        'payment_status' => Order::PAYMENT_UNPAID,
                    ]);
                },
            );
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $order = $result['order'];
        $flash = $result['message'] ?? 'سفارش شما با موفقیت ثبت شد.';

        return redirect()
            ->route('dashboard.orders.show', $order)
            ->with($result['reused'] ? 'info' : 'success', $flash);
    }
}
