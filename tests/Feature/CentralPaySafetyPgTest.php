<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserService;
use App\Models\WalletTransaction;
use App\Services\Orders\MarkOrderAsPaidService;
use App\Services\WalletService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TRUE multi-process concurrency for the CentralPay money paths, against a
 * real PostgreSQL server.
 *
 * The single-process tests in `CentralPaySafetyTest` prove the "exactly once"
 * property holds when the calls are sequential. That is the easy half. The
 * dangerous case is two settlements landing at the same instant — the browser
 * return URL and an admin re-verify, or two gateway retries — where a
 * check-then-act guard in application code can be straddled by both callers.
 *
 * Each worker here is a separate OS process on its own database connection, so
 * the serialisation being tested is the database's, not PHP's. A single
 * duplicate credit or a double provisioning trigger is a real financial defect,
 * so the assertion is exact equality, never "at most a few".
 *
 * Deliberately no RefreshDatabase: forked children read COMMITTED setup data on
 * their own connections, so this class owns its fixtures and cleans them up.
 */
class CentralPaySafetyPgTest extends TestCase
{
    private const PREFIX = 'cpsafe_';

    private const WORKERS = 20;

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

    // ── Wallet top-up ──────────────────────────────────────────────────────

    public function test_concurrent_settlements_credit_a_topup_exactly_once(): void
    {
        $user = $this->makeUser('topup');
        $method = $this->makeMethod('topup');

        $tx = PaymentTransaction::create([
            'order_id' => null,
            'user_id' => $user->id,
            'payment_method_id' => $method->id,
            'provider' => 'centralpay',
            'method' => 'centralpay',
            'payment_purpose' => 'wallet_topup',
            'status' => PaymentTransaction::STATUS_WAITING,
            'amount_toman' => 250000,
            'gateway_amount' => 250000,
            'gateway_currency' => 'TOMAN',
            'gateway_status' => 'verified',
        ]);

        $this->forkWorkers(function () use ($user, $tx) {
            app(WalletService::class)->creditFromPaymentTransaction(
                User::find($user->id),
                PaymentTransaction::find($tx->id),
            );
        });

        $credits = WalletTransaction::where('payment_transaction_id', $tx->id)->get();

        $this->assertCount(1, $credits, self::WORKERS.' concurrent settlements must produce exactly ONE credit');
        $this->assertSame(250000, (int) $credits->first()->amount_toman);

        // The balance is the property that actually costs money if it drifts.
        $this->assertSame(250000, (int) User::find($user->id)->wallet_balance_toman);

        // And the ledger must reconcile: balance_after of the only credit.
        $this->assertSame(0, (int) $credits->first()->balance_before_toman);
        $this->assertSame(250000, (int) $credits->first()->balance_after_toman);
    }

    public function test_concurrent_settlements_do_not_double_notify_a_topup(): void
    {
        $user = $this->makeUser('notify');
        $method = $this->makeMethod('notify');

        $tx = PaymentTransaction::create([
            'order_id' => null,
            'user_id' => $user->id,
            'payment_method_id' => $method->id,
            'provider' => 'centralpay',
            'method' => 'centralpay',
            'payment_purpose' => 'wallet_topup',
            'status' => PaymentTransaction::STATUS_WAITING,
            'amount_toman' => 180000,
            'gateway_amount' => 180000,
            'gateway_currency' => 'TOMAN',
            'gateway_status' => 'verified',
        ]);

        $this->forkWorkers(function () use ($user, $tx) {
            app(WalletService::class)->creditFromPaymentTransaction(
                User::find($user->id),
                PaymentTransaction::find($tx->id),
            );
        });

        // A user told twice that their wallet was topped up assumes it happened
        // twice — the dedupe key has to hold under real concurrency, not just
        // in a sequential replay.
        $this->assertSame(
            1,
            Notification::where('dedupe_key', 'wallet_topup_success:tx:'.$tx->id)->count(),
            'exactly one top-up notification, however many settlements raced',
        );
    }

    // ── Order payment ──────────────────────────────────────────────────────

    public function test_concurrent_settlements_mark_an_order_paid_exactly_once(): void
    {
        $user = $this->makeUser('order');
        $method = $this->makeMethod('order');
        $plan = Plan::create([
            'name' => self::PREFIX.'plan', 'slug' => self::PREFIX.'plan',
            'price_toman' => 200000, 'is_active' => true, 'traffic_gb' => 20, 'duration_days' => 30,
        ]);

        $order = Order::create([
            'order_type' => Order::TYPE_NEW_SERVICE,
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'price_toman' => 200000,
            'final_price_toman' => 200000,
            'status' => Order::STATUS_AWAITING_PAYMENT,
            'payment_status' => Order::PAYMENT_UNPAID,
        ]);

        $tx = PaymentTransaction::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'payment_method_id' => $method->id,
            'provider' => 'centralpay',
            'method' => 'centralpay',
            'payment_purpose' => 'order_payment',
            'status' => PaymentTransaction::STATUS_WAITING,
            'amount_toman' => 200000,
            'gateway_amount' => 200000,
            'gateway_currency' => 'TOMAN',
            'gateway_status' => 'verified',
        ]);

        // The order already carries its service, so `markPaid` routes past
        // provisioning: this test is about the payment state machine, and a
        // real panel call would make the outcome depend on an external service.
        // The provisioning race itself is pinned by the database index test
        // below.
        UserService::create([
            'service_number' => self::PREFIX.'svc',
            'user_id' => $user->id,
            'order_id' => $order->id,
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'status' => UserService::STATUS_ACTIVE,
            'traffic_total_gb' => 20,
            'duration_days' => 30,
        ]);

        $this->forkWorkers(function () use ($order, $tx) {
            app(MarkOrderAsPaidService::class)->markPaid(
                Order::find($order->id),
                PaymentTransaction::find($tx->id),
            );
        });

        $settled = Order::find($order->id);
        $this->assertSame(Order::PAYMENT_PAID, $settled->payment_status);
        $this->assertNotNull($settled->paid_at);
        $this->assertSame(PaymentTransaction::STATUS_APPROVED, PaymentTransaction::find($tx->id)->status);

        // A duplicate "payment succeeded" message is how a user learns they
        // were charged twice, whether or not they were.
        $this->assertSame(
            1,
            Notification::where('dedupe_key', 'payment_success:order:'.$order->id)->count(),
            'exactly one payment-success notification, however many callbacks raced',
        );

        // The order must never sprout a second wallet movement along the way.
        $this->assertSame(0, WalletTransaction::where('user_id', $user->id)->count());
    }

    /**
     * The last line under a provisioning race is a PARTIAL UNIQUE index on
     * `user_services.order_id`. No application-level check-then-act can be
     * trusted across processes; this one cannot be straddled. Pin it, so
     * dropping it in a future migration fails here rather than quietly
     * permitting two services for one paid order.
     */
    public function test_the_database_refuses_a_second_service_for_the_same_order(): void
    {
        $user = $this->makeUser('svcuniq');
        $plan = Plan::create([
            'name' => self::PREFIX.'svcplan', 'slug' => self::PREFIX.'svcplan',
            'price_toman' => 200000, 'is_active' => true, 'traffic_gb' => 20, 'duration_days' => 30,
        ]);
        $order = Order::create([
            'order_type' => Order::TYPE_NEW_SERVICE,
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'price_toman' => 200000,
            'final_price_toman' => 200000,
            'status' => Order::STATUS_PAID,
            'payment_status' => Order::PAYMENT_PAID,
        ]);

        $attributes = [
            'user_id' => $user->id,
            'order_id' => $order->id,
            'plan_id' => $plan->id,
            'plan_name' => $plan->name,
            'status' => UserService::STATUS_ACTIVE,
            'traffic_total_gb' => 20,
            'duration_days' => 30,
        ];

        UserService::create($attributes + ['service_number' => self::PREFIX.'svc1']);

        $this->expectException(QueryException::class);
        UserService::create($attributes + ['service_number' => self::PREFIX.'svc2']);
    }

    // ── Machinery ──────────────────────────────────────────────────────────

    /**
     * Run $body in WORKERS separate processes, each on its own connection.
     * A worker losing a race is acceptable; a worker producing a DUPLICATE is
     * what the assertions catch.
     */
    private function forkWorkers(callable $body): int
    {
        $pids = [];

        for ($i = 0; $i < self::WORKERS; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->fail('pcntl_fork failed');
            }
            if ($pid === 0) {
                // The child inherited the parent's sockets; reusing them would
                // interleave two processes on one connection.
                DB::purge();
                DB::reconnect();

                try {
                    $body();
                    exit(0);
                } catch (\Throwable) {
                    exit(1); // losing a race is fine; duplicating is not
                }
            }
            $pids[] = $pid;
        }

        $succeeded = 0;
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            if (pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0) {
                $succeeded++;
            }
        }

        DB::reconnect();

        // Without this the whole class could pass vacuously: if 19 workers died
        // on startup, "exactly one credit" would be true because there was only
        // ever one caller, and nothing about concurrency would have been tested.
        $this->assertGreaterThan(
            1,
            $succeeded,
            'at least two workers must have completed, or no race was exercised',
        );

        return $succeeded;
    }

    private function makeUser(string $tag): User
    {
        return User::create([
            'name' => 'CP Safety',
            'username' => self::PREFIX.$tag,
            'email' => self::PREFIX.$tag.'@test.com',
            'password' => bcrypt('x'),
            'wallet_balance_toman' => 0,
        ]);
    }

    private function makeMethod(string $tag): PaymentMethod
    {
        return PaymentMethod::create([
            'title' => 'پرداخت ریالی',
            'slug' => self::PREFIX.$tag,
            'type' => PaymentMethod::TYPE_CENTRALPAY,
            'is_active' => true,
            'sort_order' => 3,
            'api_key' => 'test-api-key-cp',
            'config' => ['base_url' => 'https://centralapi.org/webservice/basic'],
        ]);
    }

    private function cleanup(): void
    {
        try {
            $userIds = DB::table('users')->where('username', 'like', self::PREFIX.'%')->pluck('id');
            if ($userIds->isNotEmpty()) {
                $txIds = DB::table('payment_transactions')->whereIn('user_id', $userIds)->pluck('id');
                DB::table('wallet_transactions')->whereIn('user_id', $userIds)->delete();
                if ($txIds->isNotEmpty()) {
                    DB::table('payment_transactions')->whereIn('id', $txIds)->delete();
                }
                DB::table('notifications')->whereIn('user_id', $userIds)->delete();
                DB::table('user_services')->whereIn('user_id', $userIds)->delete();
                DB::table('orders')->whereIn('user_id', $userIds)->delete();
                DB::table('users')->whereIn('id', $userIds)->delete();
            }
            DB::table('payment_methods')->where('slug', 'like', self::PREFIX.'%')->delete();
            DB::table('plans')->where('slug', 'like', self::PREFIX.'%')->delete();
        } catch (\Throwable) {
            // best-effort cleanup
        }
    }
}
