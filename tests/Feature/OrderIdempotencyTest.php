<?php

namespace Tests\Feature;

use App\Models\DiscountCode;
use App\Models\DiscountRedemption;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\PurchaseIntent;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\UserService;
use App\Services\Discounts\DiscountService;
use App\Services\Orders\OrderIdempotencyService;
use App\Support\PurchaseToken;
use Illuminate\Cache\RateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Idempotency of order creation: every double-click, retry, duplicate tab and
 * concurrent submission must converge on exactly ONE order. Covers scenarios
 * 1–25 of the specification; scenario 26 (true multi-process concurrency) lives
 * in OrderIdempotencyPgTest against a real PostgreSQL server.
 */
class OrderIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge(['wallet_balance_toman' => 0], $overrides));
    }

    private function makePlan(array $attrs = []): Plan
    {
        return Plan::factory()->create(array_merge([
            'name' => 'Starter',
            'slug' => 'starter-'.uniqid(),
            'price_toman' => 49000,
            'traffic_gb' => 50,
            'duration_days' => 30,
            'is_active' => true,
        ], $attrs));
    }

    private function service(): OrderIdempotencyService
    {
        return app(OrderIdempotencyService::class);
    }

    /** A signed token for the new-service buy flow. */
    private function buyToken(User $user, Plan $plan): string
    {
        return PurchaseToken::issue($user->id, PurchaseIntent::OP_NEW_SERVICE, $plan->id, null);
    }

    /** Run the exact creator CheckoutController uses. */
    private function buy(User $user, Plan $plan, ?string $token): array
    {
        return $this->service()->createOrReturn(
            $user,
            PurchaseIntent::OP_NEW_SERVICE,
            ['plan_id' => $plan->id],
            $token,
            function (string $fp) use ($user, $plan): Order {
                $fresh = Plan::whereKey($plan->id)->lockForUpdate()->first();
                if (! $fresh || ! $fresh->is_active) {
                    throw new \RuntimeException(OrderIdempotencyService::MSG_PLAN_INACTIVE);
                }

                return Order::create([
                    'order_type' => Order::TYPE_NEW_SERVICE,
                    'user_id' => $user->id,
                    'purchase_fingerprint' => $fp,
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
    }

    // ── 1. Normal purchase ────────────────────────────────────────────────────

    public function test_1_normal_purchase_creates_exactly_one_order(): void
    {
        $user = $this->makeUser();
        $plan = $this->makePlan();

        $result = $this->buy($user, $plan, $this->buyToken($user, $plan));

        $this->assertFalse($result['reused']);
        $this->assertSame(1, Order::count());
        $this->assertSame($plan->id, $result['order']->plan_id);
    }

    // ── 2. Double-click (same token twice) ────────────────────────────────────

    public function test_2_double_click_same_token_returns_one_order(): void
    {
        $user = $this->makeUser();
        $plan = $this->makePlan();
        $token = $this->buyToken($user, $plan);

        $first = $this->buy($user, $plan, $token);
        $second = $this->buy($user, $plan, $token);

        $this->assertSame(1, Order::count());
        $this->assertSame($first['order']->id, $second['order']->id);
        $this->assertTrue($second['reused']);
        $this->assertSame(OrderIdempotencyService::MSG_ALREADY, $second['message']);
    }

    // ── 3. Retried HTTP request (route level, same token) ─────────────────────

    public function test_3_retried_http_request_creates_one_order(): void
    {
        $user = $this->makeUser();
        $plan = $this->makePlan();
        $token = $this->buyToken($user, $plan);

        $this->actingAs($user)->post(route('plans.buy', $plan), ['purchase_token' => $token])
            ->assertRedirectContains('/dashboard/orders/');
        $this->actingAs($user)->post(route('plans.buy', $plan), ['purchase_token' => $token])
            ->assertRedirectContains('/dashboard/orders/');

        $this->assertSame(1, Order::count());
        $this->assertSame(1, PurchaseIntent::where('status', PurchaseIntent::STATUS_CONSUMED)->count());
    }

    // ── 4. Two "concurrent" submissions sharing one key ───────────────────────

    public function test_4_two_submissions_same_key_converge(): void
    {
        $user = $this->makeUser();
        $plan = $this->makePlan();
        $token = $this->buyToken($user, $plan);

        $a = $this->buy($user, $plan, $token);
        $b = $this->buy($user, $plan, $token);

        $this->assertSame($a['order']->id, $b['order']->id);
        $this->assertSame(1, Order::count());
    }

    // ── 5. Two tabs — different tokens, same target ───────────────────────────

    public function test_5_two_tabs_different_tokens_same_target(): void
    {
        $user = $this->makeUser();
        $plan = $this->makePlan();

        $tab1 = $this->buy($user, $plan, $this->buyToken($user, $plan));
        $tab2 = $this->buy($user, $plan, $this->buyToken($user, $plan));

        // The fingerprint dedup routes the second tab to the first tab's order.
        $this->assertSame($tab1['order']->id, $tab2['order']->id);
        $this->assertSame(1, Order::count());
        $this->assertTrue($tab2['reused']);
        $this->assertSame(OrderIdempotencyService::MSG_HAS_PENDING, $tab2['message']);
    }

    // ── 6. Consumed key returns the original order ────────────────────────────

    public function test_6_consumed_key_returns_original(): void
    {
        $user = $this->makeUser();
        $plan = $this->makePlan();
        $token = $this->buyToken($user, $plan);

        $first = $this->buy($user, $plan, $token);
        // The intent is now consumed and linked; replay returns the same order.
        $replay = $this->buy($user, $plan, $token);

        $this->assertSame($first['order']->id, $replay['order']->id);
        $this->assertSame(OrderIdempotencyService::MSG_ALREADY, $replay['message']);
    }

    // ── 7. Another user's key is rejected ─────────────────────────────────────

    public function test_7_another_users_token_is_rejected(): void
    {
        $owner = $this->makeUser();
        $intruder = $this->makeUser();
        $plan = $this->makePlan();

        $ownerToken = $this->buyToken($owner, $plan);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(OrderIdempotencyService::MSG_EXPIRED);
        $this->buy($intruder, $plan, $ownerToken);
    }

    public function test_7b_cross_user_token_creates_no_order(): void
    {
        $owner = $this->makeUser();
        $intruder = $this->makeUser();
        $plan = $this->makePlan();
        $token = $this->buyToken($owner, $plan);

        try {
            $this->buy($intruder, $plan, $token);
        } catch (\RuntimeException) {
            // expected
        }
        $this->assertSame(0, Order::count());
    }

    // ── 8. Expired key ────────────────────────────────────────────────────────

    public function test_8_expired_token_is_rejected(): void
    {
        $user = $this->makeUser();
        $plan = $this->makePlan();

        $ttl = (int) config('zedproxy.purchase.intent_ttl_minutes', 30);
        // Issue a token whose iat is older than the TTL.
        $token = null;
        $this->travel(-($ttl + 5))->minutes(function () use (&$token, $user, $plan) {
            $token = $this->buyToken($user, $plan);
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(OrderIdempotencyService::MSG_EXPIRED);
        $this->buy($user, $plan, $token);
    }

    // ── 9. Invalid / tampered key ─────────────────────────────────────────────

    public function test_9_invalid_token_is_rejected(): void
    {
        $user = $this->makeUser();
        $plan = $this->makePlan();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(OrderIdempotencyService::MSG_EXPIRED);
        $this->buy($user, $plan, 'not-a-real-token');
    }

    // ── 10. Modified plan id after the token was issued ───────────────────────

    public function test_10_modified_plan_id_is_rejected(): void
    {
        $user = $this->makeUser();
        $planA = $this->makePlan();
        $planB = $this->makePlan();

        $tokenForA = $this->buyToken($user, $planA);

        // Attacker keeps the token but submits it against a different plan.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(OrderIdempotencyService::MSG_EXPIRED);
        $this->buy($user, $planB, $tokenForA);
    }

    // ── 11. Inactive plan ─────────────────────────────────────────────────────

    public function test_11_inactive_plan_creates_no_order(): void
    {
        $user = $this->makeUser();
        $plan = $this->makePlan(['is_active' => false]);

        // Route guard returns 404 before the service is even reached.
        $this->actingAs($user)
            ->post(route('plans.buy', $plan), ['purchase_token' => 'x'])
            ->assertNotFound();
        $this->assertSame(0, Order::count());
    }

    public function test_11b_plan_deactivated_after_render_is_rejected_in_transaction(): void
    {
        $user = $this->makeUser();
        $plan = $this->makePlan();
        $token = $this->buyToken($user, $plan);

        // Plan goes inactive between form render and submit; the creator re-reads
        // it under lock and refuses.
        $plan->update(['is_active' => false]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(OrderIdempotencyService::MSG_PLAN_INACTIVE);
        $this->buy($user, $plan, $token);
    }

    // ── 12. Plan price changed after render ───────────────────────────────────

    public function test_12_plan_price_change_uses_server_side_price(): void
    {
        $user = $this->makeUser();
        $plan = $this->makePlan(['price_toman' => 50000]);
        $token = $this->buyToken($user, $plan);

        $plan->update(['price_toman' => 99000]);

        $result = $this->buy($user, $plan, $token);

        // The order reflects the current server price, never a stale/render price.
        $this->assertSame(99000, $result['order']->price_toman);
        $this->assertSame(99000, $result['order']->final_price_toman);
    }

    // ── 13. Client-supplied price is ignored ──────────────────────────────────

    public function test_13_client_supplied_price_is_ignored(): void
    {
        $user = $this->makeUser();
        $plan = $this->makePlan(['price_toman' => 49000]);
        $token = $this->buyToken($user, $plan);

        $this->actingAs($user)->post(route('plans.buy', $plan), [
            'purchase_token' => $token,
            'price_toman' => 1,
            'final_price_toman' => 1,
            'discount_toman' => 48999,
        ])->assertRedirectContains('/dashboard/orders/');

        $order = Order::first();
        $this->assertSame(49000, $order->price_toman);
        $this->assertSame(49000, $order->final_price_toman);
        $this->assertSame(0, $order->discount_toman);
    }

    // ── 14. Existing recent pending order → reuse ─────────────────────────────

    public function test_14_recent_pending_order_is_reused(): void
    {
        $user = $this->makeUser();
        $plan = $this->makePlan();

        $first = $this->buy($user, $plan, $this->buyToken($user, $plan));

        // A fresh token, same target, within the reuse window.
        $second = $this->buy($user, $plan, $this->buyToken($user, $plan));

        $this->assertSame($first['order']->id, $second['order']->id);
        $this->assertTrue($second['reused']);
        $this->assertSame(OrderIdempotencyService::MSG_HAS_PENDING, $second['message']);
        $this->assertSame(1, Order::count());
    }

    // ── 15. Existing expired pending order → NOT reused ───────────────────────

    public function test_15_expired_pending_order_is_not_reused(): void
    {
        $user = $this->makeUser();
        $plan = $this->makePlan();

        $first = $this->buy($user, $plan, $this->buyToken($user, $plan));

        // Age the pending order past the reuse window.
        $window = (int) config('zedproxy.purchase.pending_reuse_minutes', 30);
        Order::whereKey($first['order']->id)->update([
            'created_at' => now()->subMinutes($window + 5),
            'purchase_fingerprint' => null, // stale order leaves the dedup window
        ]);

        $second = $this->buy($user, $plan, $this->buyToken($user, $plan));

        $this->assertNotSame($first['order']->id, $second['order']->id);
        $this->assertSame(2, Order::count());
    }

    // ── 16. Existing PAID order → new purchase allowed ────────────────────────

    public function test_16_paid_order_does_not_block_new_purchase(): void
    {
        $user = $this->makeUser();
        $plan = $this->makePlan();

        $first = $this->buy($user, $plan, $this->buyToken($user, $plan));

        // Simulate the order having been paid — it leaves the partial unique index.
        Order::whereKey($first['order']->id)->update([
            'payment_status' => Order::PAYMENT_PAID,
            'status' => Order::STATUS_PAID,
        ]);

        $second = $this->buy($user, $plan, $this->buyToken($user, $plan));

        $this->assertNotSame($first['order']->id, $second['order']->id);
        $this->assertSame(2, Order::count());
    }

    // ── 17. Existing CANCELLED order → new purchase allowed ───────────────────

    public function test_17_cancelled_order_does_not_block_new_purchase(): void
    {
        $user = $this->makeUser();
        $plan = $this->makePlan();

        $first = $this->buy($user, $plan, $this->buyToken($user, $plan));

        // Admin cancels the order; payment_status stays unpaid but status=cancelled.
        Order::whereKey($first['order']->id)->update(['status' => Order::STATUS_CANCELLED]);

        $second = $this->buy($user, $plan, $this->buyToken($user, $plan));

        $this->assertNotSame($first['order']->id, $second['order']->id);
        $this->assertSame(2, Order::count());
    }

    // ── 18. Duplicate payment-method submission ───────────────────────────────

    public function test_18_duplicate_manual_payment_submission_creates_one_transaction(): void
    {
        $user = $this->makeUser();
        $plan = $this->makePlan();
        $order = $this->buy($user, $plan, $this->buyToken($user, $plan))['order'];

        $method = PaymentMethod::create([
            'title' => 'کارت به کارت',
            'type' => PaymentMethod::TYPE_MANUAL_RIAL,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $payload = ['payment_method_id' => $method->id, 'transaction_reference' => 'REF123'];

        $this->actingAs($user)->post(route('dashboard.orders.pay.submit', $order), $payload);
        $this->actingAs($user)->post(route('dashboard.orders.pay.submit', $order), $payload);

        $this->assertSame(1, PaymentTransaction::where('order_id', $order->id)->count());
    }

    // ── 19. Discount reservation stays single under replay ────────────────────

    public function test_19_discount_reservation_is_not_duplicated_on_replay(): void
    {
        $user = $this->makeUser();
        $plan = $this->makePlan(['price_toman' => 100000]);
        $token = $this->buyToken($user, $plan);

        $code = DiscountCode::create([
            'title' => 'welcome', 'code' => 'WELCOME10',
            'type' => DiscountCode::TYPE_FIXED, 'value' => 10000,
            'total_usage_limit' => 5, 'per_user_usage_limit' => 1, 'is_active' => true,
        ]);

        $creator = function (string $fp) use ($user, $plan): Order {
            $order = Order::create([
                'order_type' => Order::TYPE_NEW_SERVICE,
                'user_id' => $user->id,
                'purchase_fingerprint' => $fp,
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'plan_slug' => $plan->slug,
                'traffic_gb' => $plan->traffic_gb,
                'duration_days' => $plan->duration_days,
                'price_toman' => $plan->price_toman,
                'final_price_toman' => $plan->price_toman,
                'discount_toman' => 0,
                'status' => Order::STATUS_PENDING,
                'payment_status' => Order::PAYMENT_UNPAID,
            ]);

            return app(DiscountService::class)->applyToOrder($user, $order, 'WELCOME10');
        };

        $a = $this->service()->createOrReturn($user, PurchaseIntent::OP_NEW_SERVICE, ['plan_id' => $plan->id], $token, $creator);
        $b = $this->service()->createOrReturn($user, PurchaseIntent::OP_NEW_SERVICE, ['plan_id' => $plan->id], $token, $creator);

        $this->assertSame($a['order']->id, $b['order']->id);
        $this->assertSame(1, Order::count());
        $this->assertSame(
            1,
            DiscountRedemption::where('discount_code_id', $code->id)->count(),
            'a replay must never create a second discount reservation'
        );
    }

    // ── 20. New-service regression (route) ────────────────────────────────────

    public function test_20_new_service_route_still_creates_order(): void
    {
        $user = $this->makeUser();
        $plan = $this->makePlan();

        $this->actingAs($user)
            ->post(route('plans.buy', $plan), ['purchase_token' => $this->buyToken($user, $plan)])
            ->assertRedirectContains('/dashboard/orders/');

        $this->assertSame(1, Order::where('order_type', Order::TYPE_NEW_SERVICE)->count());
    }

    // ── 21. Renewal regression + double submit ────────────────────────────────

    public function test_21_renewal_double_submit_creates_one_order(): void
    {
        $user = $this->makeUser();
        $plan = $this->makePlan(['renewal_enabled' => true]);
        $service = $this->makeRenewableService($user);

        $token = PurchaseToken::issue($user->id, PurchaseIntent::OP_RENEWAL, null, $service->id);

        $this->actingAs($user)->post(route('dashboard.services.renew.submit', $service), [
            'plan_id' => $plan->id, 'purchase_token' => $token,
        ]);
        $this->actingAs($user)->post(route('dashboard.services.renew.submit', $service), [
            'plan_id' => $plan->id, 'purchase_token' => $token,
        ]);

        $this->assertSame(1, Order::where('order_type', Order::TYPE_RENEWAL)->count());
    }

    // ── 22. Extra-traffic regression + double submit ──────────────────────────

    public function test_22_extra_traffic_double_submit_creates_one_order(): void
    {
        SiteSetting::set('extra_traffic_price_per_gb', 1000);

        $user = $this->makeUser();
        $service = $this->makeAddonService($user);

        $token = PurchaseToken::issue(
            $user->id, PurchaseIntent::OP_EXTRA_TRAFFIC, null, $service->id, ['amount_gb' => 5]
        );

        $payload = ['amount_gb' => 5, 'purchase_token' => $token];
        $this->actingAs($user)->post(route('dashboard.services.extra-traffic.submit', $service), $payload);
        $this->actingAs($user)->post(route('dashboard.services.extra-traffic.submit', $service), $payload);

        $this->assertSame(1, Order::where('order_type', Order::TYPE_EXTRA_TRAFFIC)->count());
    }

    // ── 23. Extra-time regression + double submit ─────────────────────────────

    public function test_23_extra_time_double_submit_creates_one_order(): void
    {
        SiteSetting::set('extra_time_price_per_day', 2000);

        $user = $this->makeUser();
        $service = $this->makeAddonService($user);

        $token = PurchaseToken::issue(
            $user->id, PurchaseIntent::OP_EXTRA_TIME, null, $service->id, ['amount_days' => 7]
        );

        $payload = ['amount_days' => 7, 'purchase_token' => $token];
        $this->actingAs($user)->post(route('dashboard.services.extra-time.submit', $service), $payload);
        $this->actingAs($user)->post(route('dashboard.services.extra-time.submit', $service), $payload);

        $this->assertSame(1, Order::where('order_type', Order::TYPE_EXTRA_TIME)->count());
    }

    // ── 24. Cleanup command ───────────────────────────────────────────────────

    public function test_24_prune_command_removes_expired_but_keeps_linked(): void
    {
        $user = $this->makeUser();
        $plan = $this->makePlan();

        // Expired, unconsumed → prunable.
        $expired = PurchaseIntent::create([
            'key' => 'expired-key', 'user_id' => $user->id,
            'operation_type' => PurchaseIntent::OP_NEW_SERVICE, 'plan_id' => $plan->id,
            'request_fingerprint' => str_repeat('a', 64),
            'status' => PurchaseIntent::STATUS_PENDING, 'expires_at' => now()->subHour(),
        ]);

        // Consumed + linked to an order → must be kept.
        $order = $this->buy($user, $plan, $this->buyToken($user, $plan))['order'];
        $linked = PurchaseIntent::where('order_id', $order->id)->first();
        $this->assertNotNull($linked);

        // A still-valid pending intent → must be kept.
        $valid = PurchaseIntent::create([
            'key' => 'valid-key', 'user_id' => $user->id,
            'operation_type' => PurchaseIntent::OP_NEW_SERVICE, 'plan_id' => $plan->id,
            'request_fingerprint' => str_repeat('b', 64),
            'status' => PurchaseIntent::STATUS_PENDING, 'expires_at' => now()->addHour(),
        ]);

        $this->artisan('zedproxy:prune-purchase-intents')->assertSuccessful();

        $this->assertDatabaseMissing('purchase_intents', ['id' => $expired->id]);
        $this->assertDatabaseHas('purchase_intents', ['id' => $linked->id]);
        $this->assertDatabaseHas('purchase_intents', ['id' => $valid->id]);
    }

    public function test_24b_prune_is_idempotent(): void
    {
        $user = $this->makeUser();
        PurchaseIntent::create([
            'key' => 'e1', 'user_id' => $user->id,
            'operation_type' => PurchaseIntent::OP_NEW_SERVICE,
            'request_fingerprint' => str_repeat('c', 64),
            'status' => PurchaseIntent::STATUS_PENDING, 'expires_at' => now()->subHour(),
        ]);

        $this->artisan('zedproxy:prune-purchase-intents')->assertSuccessful();
        // Second run has nothing to do and must still succeed.
        $this->artisan('zedproxy:prune-purchase-intents')->assertSuccessful();

        $this->assertSame(0, PurchaseIntent::count());
    }

    // ── 25. Rate limiting (separate limiters) ─────────────────────────────────

    public function test_25_named_purchase_limiters_are_registered(): void
    {
        $limiter = app(RateLimiter::class);
        $this->assertNotNull($limiter->limiter('purchase-intent'));
        $this->assertNotNull($limiter->limiter('purchase-submit'));
    }

    public function test_25b_submit_route_is_rate_limited(): void
    {
        $user = $this->makeUser();
        $plan = $this->makePlan();
        $token = $this->buyToken($user, $plan);

        $hitLimit = false;
        // purchase-submit allows 12/min; the 13th distinct request must be blocked.
        for ($i = 0; $i < 20; $i++) {
            $response = $this->actingAs($user)
                ->post(route('plans.buy', $plan), ['purchase_token' => $token]);
            if ($response->getStatusCode() === 429) {
                $hitLimit = true;
                break;
            }
        }

        $this->assertTrue($hitLimit, 'the purchase-submit limiter should eventually return 429');
        // Despite many submissions, idempotency still yielded a single order.
        $this->assertSame(1, Order::count());
    }

    // ── Helpers for renewal / add-on services ─────────────────────────────────

    private function makeRenewableService(User $user): UserService
    {
        $plan = Plan::create([
            'name' => 'svc', 'slug' => 'svc-'.uniqid(),
            'price_toman' => 100000, 'duration_days' => 30, 'traffic_gb' => 50,
            'is_active' => false, 'renewal_enabled' => false, 'sort_order' => 0,
        ]);

        return UserService::create([
            'user_id' => $user->id, 'plan_id' => $plan->id,
            'status' => UserService::STATUS_ACTIVE,
            'provision_status' => UserService::PROVISION_PROVISIONED,
            'plan_name' => $plan->name, 'expires_at' => now()->addDays(10),
        ]);
    }

    private function makeAddonService(User $user): UserService
    {
        $service = $this->makeRenewableService($user);
        $service->update(['traffic_total_gb' => 20, 'traffic_used_gb' => 5]);

        return $service->refresh();
    }
}
