<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Plan;
use App\Models\PurchaseIntent;
use App\Models\User;
use App\Services\Orders\OrderIdempotencyService;
use App\Support\PurchaseToken;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Scenario 26 — TRUE multi-process concurrency against a real PostgreSQL server.
 *
 * 20 forked workers, each on its own DB connection, submit the SAME signed
 * purchase token at once. The unique purchase_intents.key plus the partial
 * unique index on orders(user_id, purchase_fingerprint) must serialize them so
 * EXACTLY ONE order is created — never a duplicate — and every worker that
 * "succeeds" returns that same order id.
 *
 * Skipped unless running on PostgreSQL with the pcntl extension (the CI pgsql
 * job). Deliberately does NOT use RefreshDatabase: forked children need to read
 * COMMITTED setup data on their own connections, so this test owns its data and
 * cleans it up afterwards.
 */
class OrderIdempotencyPgTest extends TestCase
{
    private const PREFIX = 'pgidem_';

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

    public function test_twenty_forked_workers_create_exactly_one_order(): void
    {
        $user = User::create([
            'name' => 'Idem', 'username' => self::PREFIX.'user',
            'email' => self::PREFIX.'user@test.com', 'password' => bcrypt('x'),
        ]);

        $plan = Plan::create([
            'name' => self::PREFIX.'plan', 'slug' => self::PREFIX.'plan',
            'price_toman' => 100000, 'is_active' => true, 'traffic_gb' => 20, 'duration_days' => 30,
        ]);

        // One shared, signed token → one idempotency key for every worker.
        $token = PurchaseToken::issue($user->id, PurchaseIntent::OP_NEW_SERVICE, $plan->id, null);

        $pids = [];
        for ($i = 0; $i < 20; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('pcntl_fork failed');
            }
            if ($pid === 0) {
                // Child: fresh connection, submit the shared token.
                DB::purge();
                DB::reconnect();
                $status = 1;
                try {
                    $u = User::find($user->id);
                    $fresh = Plan::find($plan->id);
                    app(OrderIdempotencyService::class)->createOrReturn(
                        $u,
                        PurchaseIntent::OP_NEW_SERVICE,
                        ['plan_id' => $fresh->id],
                        $token,
                        fn (string $fp): Order => Order::create([
                            'order_type' => Order::TYPE_NEW_SERVICE,
                            'user_id' => $u->id,
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
                        ]),
                    );
                    $status = 0;
                } catch (\Throwable) {
                    $status = 1; // transient loss of a race is acceptable
                }
                exit($status);
            }
            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $s);
        }

        DB::reconnect();

        $orders = Order::where('user_id', $user->id)->get();

        // The hard guarantee: never more than one order, regardless of races.
        $this->assertLessThanOrEqual(1, $orders->count(), 'concurrent submissions must not create duplicate orders');
        $this->assertSame(1, $orders->count(), 'exactly one order should exist after 20 concurrent submissions');

        // Exactly one intent, consumed and linked to that single order.
        $intents = PurchaseIntent::where('user_id', $user->id)->get();
        $this->assertSame(1, $intents->count(), 'the unique key must yield exactly one intent');
        $this->assertSame(PurchaseIntent::STATUS_CONSUMED, $intents->first()->status);
        $this->assertSame($orders->first()->id, (int) $intents->first()->order_id);
    }

    private function cleanup(): void
    {
        try {
            $userIds = DB::table('users')->where('username', 'like', self::PREFIX.'%')->pluck('id');
            if ($userIds->isNotEmpty()) {
                DB::table('purchase_intents')->whereIn('user_id', $userIds)->delete();
                DB::table('orders')->whereIn('user_id', $userIds)->delete();
                DB::table('users')->whereIn('id', $userIds)->delete();
            }
            DB::table('plans')->where('slug', 'like', self::PREFIX.'%')->delete();
        } catch (\Throwable) {
            // best-effort cleanup
        }
    }
}
