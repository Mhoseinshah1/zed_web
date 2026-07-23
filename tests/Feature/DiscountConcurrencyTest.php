<?php

namespace Tests\Feature;

use App\Models\DiscountCode;
use App\Models\DiscountRedemption;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\Discounts\DiscountService;
use App\Support\RetriesDeadlocks;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Discount reservation lifecycle + concurrency guarantees.
 *
 * These run in BOTH the SQLite suite and the PostgreSQL suite. True multi-process
 * concurrency (separate connections) lives in DiscountConcurrencyPgTest.
 */
class DiscountConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    private function user(): User
    {
        $this->seq++;
        return User::factory()->create([
            'username' => "dc_user_{$this->seq}",
            'email'    => "dc_{$this->seq}@test.com",
        ]);
    }

    private function plan(int $price = 100000): Plan
    {
        return Plan::factory()->create([
            'price_toman' => $price, 'is_active' => true, 'traffic_gb' => 20, 'duration_days' => 30,
        ]);
    }

    private function order(User $user, ?Plan $plan = null, array $attrs = []): Order
    {
        $plan ??= $this->plan();
        return Order::create(array_merge([
            'user_id'           => $user->id,
            'plan_id'           => $plan->id,
            'plan_name'         => $plan->name,
            'order_type'        => Order::TYPE_NEW_SERVICE,
            'price_toman'       => $plan->price_toman,
            'final_price_toman' => $plan->price_toman,
            'discount_toman'    => 0,
            'traffic_gb'        => $plan->traffic_gb,
            'duration_days'     => $plan->duration_days,
            'status'            => Order::STATUS_PENDING,
            'payment_status'    => Order::PAYMENT_UNPAID,
        ], $attrs));
    }

    private function code(array $attrs = []): DiscountCode
    {
        return DiscountCode::create(array_merge([
            'title' => 'C', 'code' => 'SAVE', 'type' => DiscountCode::TYPE_PERCENT, 'value' => 10,
            'per_user_usage_limit' => 1, 'is_active' => true,
        ], $attrs));
    }

    private function svc(): DiscountService
    {
        return app(DiscountService::class);
    }

    private function reservedCount(DiscountCode $c): int
    {
        return DiscountRedemption::where('discount_code_id', $c->id)
            ->where('status', DiscountRedemption::STATUS_RESERVED)->count();
    }

    // ── 1. Twenty requests vs a total limit of five ───────────────────────────

    public function test_total_limit_holds_under_many_applies(): void
    {
        $code = $this->code(['total_usage_limit' => 5, 'per_user_usage_limit' => 1]);

        $ok = 0; $rejected = 0;
        for ($i = 0; $i < 20; $i++) {
            $u = $this->user();
            $o = $this->order($u);
            try {
                $this->svc()->applyToOrder($u, $o, 'SAVE');
                $ok++;
            } catch (\RuntimeException $e) {
                $rejected++;
                $this->assertStringContainsString('ظرفیت', $e->getMessage());
            }
        }

        $this->assertSame(5, $ok);
        $this->assertSame(15, $rejected);
        $this->assertSame(5, $this->reservedCount($code));
    }

    // ── 2. Per-user limit ─────────────────────────────────────────────────────

    public function test_per_user_limit_holds(): void
    {
        $code = $this->code(['total_usage_limit' => 100, 'per_user_usage_limit' => 1]);
        $u = $this->user();

        $this->svc()->applyToOrder($u, $this->order($u), 'SAVE');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('حداکثر تعداد استفاده');
        $this->svc()->applyToOrder($u, $this->order($u), 'SAVE');
    }

    // ── 3. One active reservation per order (DB constraint) ───────────────────

    public function test_db_blocks_two_active_reservations_for_one_order(): void
    {
        $code = $this->code(['total_usage_limit' => 100]);
        $u = $this->user();
        $o = $this->order($u);
        DiscountRedemption::create([
            'discount_code_id' => $code->id, 'user_id' => $u->id, 'order_id' => $o->id,
            'status' => DiscountRedemption::STATUS_RESERVED, 'original_amount' => 1, 'discount_amount' => 0,
            'final_amount' => 1, 'reserved_at' => now(), 'expires_at' => now()->addMinutes(30),
        ]);

        $this->expectException(QueryException::class);
        DB::table('discount_redemptions')->insert([
            'discount_code_id' => $code->id, 'user_id' => $u->id, 'order_id' => $o->id,
            'status' => 'reserved', 'original_amount' => 1, 'discount_amount' => 0, 'final_amount' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── 4. Applying the same code twice → still one reservation ───────────────

    public function test_applying_same_code_twice_keeps_one_reservation(): void
    {
        $code = $this->code(['total_usage_limit' => 100, 'per_user_usage_limit' => 5]);
        $u = $this->user();
        $o = $this->order($u);

        $this->svc()->applyToOrder($u, $o, 'SAVE');
        $this->svc()->applyToOrder($u, $o->fresh(), 'SAVE');

        $this->assertSame(1, DiscountRedemption::where('order_id', $o->id)
            ->where('status', DiscountRedemption::STATUS_RESERVED)->count());
    }

    // ── 5. Two different codes on the same order → one active ─────────────────

    public function test_two_codes_on_same_order_leaves_one_active(): void
    {
        $this->code(['code' => 'AAA', 'total_usage_limit' => 100, 'per_user_usage_limit' => 5]);
        $this->code(['code' => 'BBB', 'total_usage_limit' => 100, 'per_user_usage_limit' => 5]);
        $u = $this->user();
        $o = $this->order($u);

        $this->svc()->applyToOrder($u, $o, 'AAA');
        $this->svc()->applyToOrder($u, $o->fresh(), 'BBB');

        $active = DiscountRedemption::where('order_id', $o->id)->where('status', DiscountRedemption::STATUS_RESERVED)->get();
        $this->assertCount(1, $active);
        $this->assertSame('BBB', $o->fresh()->discount_code);
    }

    // ── 6. Replace ────────────────────────────────────────────────────────────

    public function test_replace_updates_snapshot_and_releases_old(): void
    {
        $this->code(['code' => 'P10', 'type' => DiscountCode::TYPE_PERCENT, 'value' => 10, 'per_user_usage_limit' => 5]);
        $this->code(['code' => 'P25', 'type' => DiscountCode::TYPE_PERCENT, 'value' => 25, 'per_user_usage_limit' => 5]);
        $u = $this->user();
        $o = $this->order($u, $this->plan(100000));

        $this->svc()->applyToOrder($u, $o, 'P10');
        $this->svc()->applyToOrder($u, $o->fresh(), 'P25');

        $o->refresh();
        $this->assertSame('P25', $o->discount_code);
        $this->assertSame(25000, $o->discount_toman);
        $this->assertSame(75000, $o->final_price_toman);
        $this->assertSame(1, DiscountRedemption::where('order_id', $o->id)->where('status', DiscountRedemption::STATUS_RELEASED)->count());
    }

    // ── 7. Failed replacement keeps the old reservation ───────────────────────

    public function test_failed_replacement_keeps_old_reservation(): void
    {
        $this->code(['code' => 'GOOD', 'type' => DiscountCode::TYPE_PERCENT, 'value' => 10, 'per_user_usage_limit' => 5]);
        $u = $this->user();
        $o = $this->order($u, $this->plan(100000));

        $this->svc()->applyToOrder($u, $o, 'GOOD');

        try {
            $this->svc()->applyToOrder($u, $o->fresh(), 'NOPE'); // invalid code
            $this->fail('expected rejection');
        } catch (\RuntimeException $e) {
            // expected
        }

        $o->refresh();
        $this->assertSame('GOOD', $o->discount_code);
        $this->assertSame(1, DiscountRedemption::where('order_id', $o->id)->where('status', DiscountRedemption::STATUS_RESERVED)->count());
    }

    // ── 8. Remove ─────────────────────────────────────────────────────────────

    public function test_remove_releases_and_recalculates(): void
    {
        $this->code(['code' => 'P10', 'type' => DiscountCode::TYPE_PERCENT, 'value' => 10, 'per_user_usage_limit' => 5]);
        $u = $this->user();
        $o = $this->order($u, $this->plan(100000));

        $this->svc()->applyToOrder($u, $o, 'P10');
        $this->svc()->removeFromOrder($o->fresh());

        $o->refresh();
        $this->assertNull($o->discount_code);
        $this->assertSame(0, $o->discount_toman);
        $this->assertSame(100000, $o->final_price_toman);
        $this->assertSame(0, $this->reservedCount(DiscountCode::where('code', 'P10')->first()));
    }

    // ── 9 + 10 + 25. Duplicate payment webhook / callback → used once, notify once ─

    public function test_duplicate_payment_marks_used_once_and_notifies_once(): void
    {
        $code = $this->code(['code' => 'P10', 'value' => 10, 'per_user_usage_limit' => 5]);
        $u = $this->user();
        $o = $this->order($u, $this->plan(100000));
        $this->svc()->applyToOrder($u, $o, 'P10');

        $o->update(['payment_status' => Order::PAYMENT_PAID, 'status' => Order::STATUS_PAID]);

        // Simulate browser callback + webhook + a retry.
        $this->svc()->markUsed($o->fresh());
        $this->svc()->markUsed($o->fresh());
        $this->svc()->markUsed($o->fresh());

        $this->assertSame(1, DiscountRedemption::where('order_id', $o->id)->where('status', DiscountRedemption::STATUS_USED)->count());
        $this->assertSame(0, $this->reservedCount($code));
        $this->assertSame(1, Notification::where('dedupe_key', 'discount_used:order:' . $o->id)->count());
    }

    public function test_db_blocks_duplicate_used_for_order_and_code(): void
    {
        $code = $this->code(['code' => 'P10']);
        $u = $this->user();
        $o = $this->order($u);
        DiscountRedemption::create([
            'discount_code_id' => $code->id, 'user_id' => $u->id, 'order_id' => $o->id,
            'status' => DiscountRedemption::STATUS_USED, 'original_amount' => 1, 'discount_amount' => 0,
            'final_amount' => 1, 'used_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('discount_redemptions')->insert([
            'discount_code_id' => $code->id, 'user_id' => $u->id, 'order_id' => $o->id,
            'status' => 'used', 'original_amount' => 1, 'discount_amount' => 0, 'final_amount' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── 11. Reservation expiration ────────────────────────────────────────────

    public function test_expiration_frees_capacity_and_clears_order(): void
    {
        $code = $this->code(['code' => 'P10', 'value' => 10, 'total_usage_limit' => 1, 'per_user_usage_limit' => 5]);
        $u = $this->user();
        $o = $this->order($u, $this->plan(100000));
        $this->svc()->applyToOrder($u, $o, 'P10');

        // Force the hold to have lapsed, then run the expirer.
        DiscountRedemption::where('order_id', $o->id)->update(['expires_at' => now()->subMinute()]);
        $result = $this->svc()->expireDueReservations();

        $this->assertSame(1, $result['expired']);
        $this->assertSame(1, $result['orders_cleared']);
        $this->assertSame(0, $this->reservedCount($code));
        $o->refresh();
        $this->assertNull($o->discount_code);
        $this->assertSame(100000, $o->final_price_toman);

        // Capacity is free again for another user.
        $u2 = $this->user();
        $r = $this->svc()->validateCode($u2, $this->order($u2), 'P10');
        $this->assertTrue($r['valid']);
    }

    // ── 12 + 13. Cancellation / failed payment release ───────────────────────

    public function test_release_on_cancellation_frees_capacity(): void
    {
        $code = $this->code(['code' => 'P10', 'value' => 10, 'total_usage_limit' => 1, 'per_user_usage_limit' => 5]);
        $u = $this->user();
        $o = $this->order($u, $this->plan(100000));
        $this->svc()->applyToOrder($u, $o, 'P10');

        $o->update(['status' => Order::STATUS_CANCELLED]);
        $this->svc()->releaseReservation($o->fresh(), 'cancelled');

        $this->assertSame(0, $this->reservedCount($code));
    }

    public function test_release_never_touches_paid_order(): void
    {
        $code = $this->code(['code' => 'P10', 'value' => 10, 'per_user_usage_limit' => 5]);
        $u = $this->user();
        $o = $this->order($u, $this->plan(100000));
        $this->svc()->applyToOrder($u, $o, 'P10');
        $o->update(['payment_status' => Order::PAYMENT_PAID, 'status' => Order::STATUS_PAID]);
        $this->svc()->markUsed($o->fresh());

        // A stray release must not undo a used redemption.
        $this->svc()->releaseReservation($o->fresh(), 'temp_error');

        $this->assertSame(1, DiscountRedemption::where('order_id', $o->id)->where('status', DiscountRedemption::STATUS_USED)->count());
    }

    // ── 14–17. Eligibility rules ──────────────────────────────────────────────

    public function test_first_purchase_only(): void
    {
        $this->code(['code' => 'FIRST', 'first_purchase_only' => true, 'per_user_usage_limit' => 5]);
        $u = $this->user();
        Order::create([
            'user_id' => $u->id, 'plan_name' => 'x', 'order_type' => Order::TYPE_NEW_SERVICE,
            'price_toman' => 1000, 'final_price_toman' => 1000, 'discount_toman' => 0,
            'status' => Order::STATUS_COMPLETED, 'payment_status' => Order::PAYMENT_PAID, 'paid_at' => now(),
        ]);
        $r = $this->svc()->validateCode($u, $this->order($u), 'FIRST');
        $this->assertFalse($r['valid']);
        $this->assertStringContainsString('اولین خرید', $r['message']);
    }

    public function test_new_users_only(): void
    {
        $this->code(['code' => 'NEW', 'new_users_only' => true, 'per_user_usage_limit' => 5]);
        $u = $this->user();
        Order::create([
            'user_id' => $u->id, 'plan_name' => 'x', 'order_type' => Order::TYPE_NEW_SERVICE,
            'price_toman' => 1000, 'final_price_toman' => 1000, 'discount_toman' => 0,
            'status' => Order::STATUS_COMPLETED, 'payment_status' => Order::PAYMENT_PAID, 'paid_at' => now(),
        ]);
        $r = $this->svc()->validateCode($u, $this->order($u), 'NEW');
        $this->assertFalse($r['valid']);
        $this->assertStringContainsString('کاربران جدید', $r['message']);
    }

    public function test_allowed_plan_restriction(): void
    {
        $p1 = $this->plan(); $p2 = $this->plan();
        $this->code(['code' => 'PLAN', 'allowed_plan_ids' => [$p1->id], 'per_user_usage_limit' => 5]);
        $u = $this->user();
        $r = $this->svc()->validateCode($u, $this->order($u, $p2), 'PLAN');
        $this->assertFalse($r['valid']);
        $this->assertStringContainsString('پلن', $r['message']);
    }

    public function test_allowed_order_type_restriction(): void
    {
        $this->code(['code' => 'TYPE', 'allowed_order_types' => [Order::TYPE_NEW_SERVICE], 'per_user_usage_limit' => 5]);
        $u = $this->user();
        $renewal = $this->order($u, null, ['order_type' => Order::TYPE_RENEWAL]);
        $r = $this->svc()->validateCode($u, $renewal, 'TYPE');
        $this->assertFalse($r['valid']);
        $this->assertStringContainsString('نوع خرید', $r['message']);
    }

    // ── 18–21. Amount math ────────────────────────────────────────────────────

    public function test_percentage_discount(): void
    {
        $this->code(['code' => 'P20', 'type' => DiscountCode::TYPE_PERCENT, 'value' => 20, 'per_user_usage_limit' => 5]);
        $u = $this->user();
        $o = $this->order($u, $this->plan(100000));
        $this->svc()->applyToOrder($u, $o, 'P20');
        $this->assertSame(80000, $o->fresh()->final_price_toman);
    }

    public function test_fixed_discount(): void
    {
        $this->code(['code' => 'F30', 'type' => DiscountCode::TYPE_FIXED, 'value' => 30000, 'per_user_usage_limit' => 5]);
        $u = $this->user();
        $o = $this->order($u, $this->plan(100000));
        $this->svc()->applyToOrder($u, $o, 'F30');
        $this->assertSame(70000, $o->fresh()->final_price_toman);
    }

    public function test_minimum_order_amount(): void
    {
        $this->code(['code' => 'MIN', 'min_order_amount' => 200000, 'per_user_usage_limit' => 5]);
        $u = $this->user();
        $r = $this->svc()->validateCode($u, $this->order($u, $this->plan(100000)), 'MIN');
        $this->assertFalse($r['valid']);
        $this->assertStringContainsString('حداقل مبلغ', $r['message']);
    }

    public function test_discount_larger_than_order_never_negative(): void
    {
        $this->code(['code' => 'BIG', 'type' => DiscountCode::TYPE_FIXED, 'value' => 999999, 'per_user_usage_limit' => 5]);
        $u = $this->user();
        $o = $this->order($u, $this->plan(50000));
        $this->svc()->applyToOrder($u, $o, 'BIG');
        $o->refresh();
        $this->assertSame(50000, $o->discount_toman);
        $this->assertSame(0, $o->final_price_toman);
        $this->assertGreaterThanOrEqual(0, $o->final_price_toman);
    }

    // ── 22. Deadlock retry ────────────────────────────────────────────────────

    public function test_deadlock_is_retried_then_succeeds(): void
    {
        $runner = new class {
            use RetriesDeadlocks;
            public int $calls = 0;
            public function run(): string
            {
                return $this->runWithDeadlockRetries(function () {
                    $this->calls++;
                    if ($this->calls < 3) {
                        throw new QueryException('pgsql', 'update x', [], new \Exception('deadlock detected', 40001));
                    }
                    return 'ok';
                }, 'test');
            }
        };

        $this->assertSame('ok', $runner->run());
        $this->assertSame(3, $runner->calls);
    }

    public function test_validation_errors_are_not_retried(): void
    {
        $runner = new class {
            use RetriesDeadlocks;
            public int $calls = 0;
            public function run(): void
            {
                $this->runWithDeadlockRetries(function () {
                    $this->calls++;
                    throw new \RuntimeException('validation');
                });
            }
        };

        try {
            $runner->run();
        } catch (\RuntimeException $e) {
            // expected
        }
        $this->assertSame(1, $runner->calls); // never retried
    }

    // ── 23 + 24. Diagnostic + scheduler commands ─────────────────────────────

    public function test_find_conflicts_command_clean_and_dirty(): void
    {
        $this->artisan('zedproxy:find-discount-conflicts')->assertExitCode(0);

        $code = $this->code();
        $u = $this->user();
        $o = $this->order($u);
        // Insert two active reservations for one order by dropping the guard.
        DB::statement('DROP INDEX IF EXISTS discount_redemptions_one_active_per_order');
        foreach ([1, 2] as $n) {
            DB::table('discount_redemptions')->insert([
                'discount_code_id' => $code->id, 'user_id' => $u->id, 'order_id' => $o->id,
                'status' => 'reserved', 'original_amount' => 1, 'discount_amount' => 0, 'final_amount' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->artisan('zedproxy:find-discount-conflicts')
            ->expectsOutputToContain('تداخل')
            ->assertExitCode(1);
    }

    public function test_expire_command_reports_counts(): void
    {
        $code = $this->code(['code' => 'P10', 'value' => 10, 'per_user_usage_limit' => 5]);
        $u = $this->user();
        $o = $this->order($u, $this->plan(100000));
        $this->svc()->applyToOrder($u, $o, 'P10');
        DiscountRedemption::where('order_id', $o->id)->update(['expires_at' => now()->subMinute()]);

        $this->artisan('zedproxy:expire-discount-reservations')
            ->expectsOutputToContain('رزروهای منقضی‌شده')
            ->assertExitCode(0);

        $this->assertSame(0, $this->reservedCount($code));
    }

    // ── 26–29. Order types apply successfully ────────────────────────────────

    public function test_apply_on_each_order_type(): void
    {
        $this->code(['code' => 'ANY', 'type' => DiscountCode::TYPE_FIXED, 'value' => 5000, 'per_user_usage_limit' => 20, 'total_usage_limit' => 100]);

        foreach ([Order::TYPE_NEW_SERVICE, Order::TYPE_RENEWAL, Order::TYPE_EXTRA_TRAFFIC, Order::TYPE_EXTRA_TIME] as $type) {
            $u = $this->user();
            $o = $this->order($u, $this->plan(100000), ['order_type' => $type]);
            $result = $this->svc()->applyToOrder($u, $o, 'ANY');
            $this->assertSame(95000, $result->final_price_toman, "order type {$type}");
        }
    }

    // ── Security: cannot apply to another user's order ────────────────────────

    public function test_cannot_apply_to_another_users_order(): void
    {
        $this->code(['code' => 'P10', 'per_user_usage_limit' => 5]);
        $owner = $this->user();
        $other = $this->user();
        $o = $this->order($owner);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('متعلق به شما نیست');
        $this->svc()->applyToOrder($other, $o, 'P10');
    }

    public function test_cannot_apply_to_paid_order(): void
    {
        $this->code(['code' => 'P10', 'per_user_usage_limit' => 5]);
        $u = $this->user();
        $o = $this->order($u, null, ['payment_status' => Order::PAYMENT_PAID, 'status' => Order::STATUS_COMPLETED]);

        $this->expectException(\RuntimeException::class);
        $this->svc()->applyToOrder($u, $o, 'P10');
    }
}
