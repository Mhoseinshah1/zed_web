<?php

namespace Tests\Feature;

use App\Models\DiscountCode;
use App\Models\DiscountRedemption;
use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\Discounts\DiscountService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TRUE multi-process concurrency against a real PostgreSQL server: 20 forked
 * workers, each on its own connection, race to apply one discount code with a
 * total limit of 5. The discount-code row lock must serialize them so EXACTLY 5
 * reservations are created — never more.
 *
 * Skipped unless running on PostgreSQL with the pcntl extension (the CI pgsql
 * job). Deliberately does NOT use RefreshDatabase: forked children need to see
 * COMMITTED setup data on their own connections, so this test manages its own
 * data and cleans up afterwards.
 */
class DiscountConcurrencyPgTest extends TestCase
{
    private const PREFIX = 'pgfork_';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Real multi-connection concurrency requires PostgreSQL (CI pgsql job).');
        }
        if (! \function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension required for the fork-based concurrency test.');
        }

        $this->cleanup();
    }

    protected function tearDown(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->cleanup();
        }
        parent::tearDown();
    }

    public function test_twenty_forked_workers_never_exceed_the_limit(): void
    {
        $plan = Plan::create([
            'name' => self::PREFIX . 'plan', 'slug' => self::PREFIX . 'plan',
            'price_toman' => 100000, 'is_active' => true, 'traffic_gb' => 20, 'duration_days' => 30,
        ]);

        $code = DiscountCode::create([
            'title' => 'fork', 'code' => self::PREFIX . 'CODE',
            'type' => DiscountCode::TYPE_FIXED, 'value' => 10000,
            'total_usage_limit' => 5, 'per_user_usage_limit' => 1, 'is_active' => true,
        ]);

        // One user + order per worker (committed).
        $orderIds = [];
        for ($i = 0; $i < 20; $i++) {
            $user = User::create([
                'name' => 'F', 'username' => self::PREFIX . $i,
                'email' => self::PREFIX . $i . '@test.com', 'password' => bcrypt('x'),
            ]);
            $order = Order::create([
                'user_id' => $user->id, 'plan_id' => $plan->id, 'plan_name' => $plan->name,
                'order_type' => Order::TYPE_NEW_SERVICE, 'price_toman' => 100000, 'final_price_toman' => 100000,
                'discount_toman' => 0, 'traffic_gb' => 20, 'duration_days' => 30,
                'status' => Order::STATUS_PENDING, 'payment_status' => Order::PAYMENT_UNPAID,
            ]);
            $orderIds[$user->id] = $order->id;
        }

        // Fork one worker per (user, order). Each opens its own DB connection.
        $pids = [];
        foreach ($orderIds as $userId => $orderId) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('pcntl_fork failed');
            }
            if ($pid === 0) {
                // Child: fresh connection, attempt the reservation.
                DB::purge();
                DB::reconnect();
                $status = 0;
                try {
                    $user  = User::find($userId);
                    $order = Order::find($orderId);
                    app(DiscountService::class)->applyToOrder($user, $order, self::PREFIX . 'CODE');
                } catch (\Throwable $e) {
                    $status = 1; // rejected (capacity full) or transient
                }
                exit($status);
            }
            $pids[] = $pid;
        }

        $succeeded = 0;
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            if (pcntl_wexitstatus($status) === 0) {
                $succeeded++;
            }
        }

        DB::reconnect();
        $reserved = DiscountRedemption::where('discount_code_id', $code->id)
            ->where('status', DiscountRedemption::STATUS_RESERVED)->count();

        // The hard guarantee: never more than the limit, regardless of races.
        $this->assertLessThanOrEqual(5, $reserved, 'reservations must not exceed the total limit');
        $this->assertSame(5, $reserved, 'exactly the limit should be reserved under contention');
        $this->assertSame(5, $succeeded, 'exactly five workers should have succeeded');
    }

    private function cleanup(): void
    {
        try {
            $userIds = DB::table('users')->where('username', 'like', self::PREFIX . '%')->pluck('id');
            if ($userIds->isNotEmpty()) {
                DB::table('discount_redemptions')->whereIn('user_id', $userIds)->delete();
                DB::table('orders')->whereIn('user_id', $userIds)->delete();
                DB::table('users')->whereIn('id', $userIds)->delete();
            }
            DB::table('discount_codes')->where('code', 'like', self::PREFIX . '%')->delete();
            DB::table('plans')->where('slug', 'like', self::PREFIX . '%')->delete();
        } catch (\Throwable) {
            // best-effort cleanup
        }
    }
}
