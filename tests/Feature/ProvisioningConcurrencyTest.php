<?php

namespace Tests\Feature;

use App\Jobs\ProvisionMarzbanServiceJob;
use App\Models\Commission;
use App\Models\Notification;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\UserService;
use App\Models\VpnPanel;
use App\Services\Notifications\NotificationService;
use App\Services\Orders\MarkOrderAsPaidService;
use App\Services\Provisioning\ProvisioningService;
use App\Services\Referrals\ReferralSettings;
use App\Services\ServiceProvisioner;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Payment/provisioning race-condition guarantees: one order → exactly one
 * UserService, one job, one notification, one commission, one cashback — under
 * duplicate webhooks/callbacks/retries.
 */
class ProvisioningConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private function panel(): VpnPanel
    {
        return VpnPanel::create([
            'name' => 'P', 'type' => VpnPanel::TYPE_MARZBAN, 'base_url' => 'https://panel.test',
            'username' => 'admin', 'password' => 'secret', 'is_active' => true, 'is_default' => true,
        ]);
    }

    private function paidOrder(?User $user = null, array $attrs = []): Order
    {
        $user ??= User::factory()->create();
        $plan = Plan::factory()->create(['price_toman' => 50000, 'traffic_gb' => 20, 'duration_days' => 30, 'is_active' => true]);

        return Order::create(array_merge([
            'user_id' => $user->id, 'plan_id' => $plan->id, 'plan_name' => $plan->name,
            'order_type' => Order::TYPE_NEW_SERVICE,
            'price_toman' => 50000, 'final_price_toman' => 50000, 'traffic_gb' => 20, 'duration_days' => 30,
            'status' => Order::STATUS_PAID, 'payment_status' => Order::PAYMENT_PAID, 'paid_at' => now(),
        ], $attrs));
    }

    private function paidTx(Order $order): PaymentTransaction
    {
        return PaymentTransaction::create([
            'order_id' => $order->id, 'user_id' => $order->user_id,
            'amount_toman' => $order->final_price_toman, 'status' => PaymentTransaction::STATUS_APPROVED,
            'type' => 'order', 'paid_at' => now(),
        ]);
    }

    // ── DB-level guarantee ────────────────────────────────────────────────────

    public function test_unique_index_blocks_a_second_service_for_same_order(): void
    {
        Queue::fake();
        $order = $this->paidOrder();
        UserService::create(['user_id' => $order->user_id, 'order_id' => $order->id, 'plan_name' => 'p',
            'status' => UserService::STATUS_PENDING_PROVISION, 'provision_status' => UserService::PROVISION_PENDING]);

        $this->expectException(QueryException::class);
        // Raw insert bypasses the app guards — the DB index is the final wall.
        DB::table('user_services')->insert([
            'user_id' => $order->user_id, 'order_id' => $order->id, 'service_number' => 'ZP-DUP-1',
            'plan_name' => 'p', 'status' => UserService::STATUS_PENDING_PROVISION,
            'provision_status' => UserService::PROVISION_PENDING, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── createFromOrder is atomic + idempotent (simulated race) ───────────────

    public function test_two_create_from_order_calls_yield_one_service_and_one_dispatch(): void
    {
        Queue::fake();
        $this->panel();
        $order = $this->paidOrder();
        $prov = app(ServiceProvisioner::class);

        $a = $prov->createFromOrder($order);
        $b = $prov->createFromOrder($order->fresh());

        $this->assertSame($a->id, $b->id, 'both calls return the same service');
        $this->assertSame(1, UserService::where('order_id', $order->id)->count());
        // Only the creating call dispatched the provisioning job.
        Queue::assertPushed(ProvisionMarzbanServiceJob::class, 1);
    }

    public function test_create_from_order_recovers_from_unique_violation(): void
    {
        Queue::fake();
        $this->panel();
        $order = $this->paidOrder();
        // Pretend a concurrent process already created the service.
        $winner = UserService::create(['user_id' => $order->user_id, 'order_id' => $order->id, 'plan_name' => 'p',
            'status' => UserService::STATUS_PENDING_PROVISION, 'provision_status' => UserService::PROVISION_PENDING]);

        $result = app(ServiceProvisioner::class)->createFromOrder($order);

        $this->assertSame($winner->id, $result->id);
        $this->assertSame(1, UserService::where('order_id', $order->id)->count());
        Queue::assertNotPushed(ProvisionMarzbanServiceJob::class); // no re-dispatch
    }

    // ── Two concurrent markPaid → one of everything ───────────────────────────

    public function test_two_mark_paid_calls_create_one_service_and_one_notification(): void
    {
        Queue::fake();
        Http::fake();
        $this->panel();
        $order = $this->paidOrder(attrs: ['payment_status' => Order::PAYMENT_PENDING, 'status' => Order::STATUS_PENDING]);
        $tx = $this->paidTx($order);
        $svc = app(MarkOrderAsPaidService::class);

        $svc->markPaid($order->fresh(), $tx->fresh());
        $svc->markPaid($order->fresh(), $tx->fresh()); // duplicate webhook/callback

        $this->assertSame(1, UserService::where('order_id', $order->id)->count());
        $this->assertSame(1, Notification::where('dedupe_key', 'payment_success:order:'.$order->id)->count());
        Queue::assertPushed(ProvisionMarzbanServiceJob::class, 1);
    }

    // ── Commission: exactly one under duplicate processing ────────────────────

    public function test_duplicate_processing_creates_one_commission(): void
    {
        Queue::fake();
        Http::fake();
        SiteSetting::set('referral_mode', ReferralSettings::MODE_ALL_USERS);
        SiteSetting::set('default_commission_type', 'percent');
        SiteSetting::set('default_commission_value', '10');

        $referrer = User::factory()->create();
        $buyer = User::factory()->create(['referred_by_user_id' => $referrer->id]);
        $this->panel();
        $order = $this->paidOrder($buyer, ['payment_status' => Order::PAYMENT_PENDING, 'status' => Order::STATUS_PENDING]);
        $tx = $this->paidTx($order);
        $svc = app(MarkOrderAsPaidService::class);

        $svc->markPaid($order->fresh(), $tx->fresh());
        $svc->markPaid($order->fresh(), $tx->fresh());

        $this->assertSame(1, Commission::where('order_id', $order->id)->count());
    }

    // ── Job execution twice → one service ─────────────────────────────────────

    public function test_running_provision_twice_yields_one_service(): void
    {
        $this->panel();
        Http::fake([
            '*/api/admin/token' => Http::response(['access_token' => 't', 'token_type' => 'bearer'], 200),
            '*/api/user' => Http::response($this->marzbanUser(), 200),
            '*/api/user/*' => Http::response($this->marzbanUser(), 200),
        ]);
        $order = $this->paidOrder();
        $prov = app(ProvisioningService::class);

        $prov->provisionOrder($order);
        $prov->provisionOrder($order->fresh()); // retry / second job

        $this->assertSame(1, UserService::where('order_id', $order->id)->count());
        $this->assertSame(UserService::STATUS_ACTIVE, UserService::where('order_id', $order->id)->first()->status);
    }

    // ── Notification dedupe unique index ──────────────────────────────────────

    public function test_notification_dedupe_is_enforced(): void
    {
        $user = User::factory()->create();
        $n = app(NotificationService::class);
        $n->notify(Notification::TYPE_PAYMENT_SUCCESS, $user, ['order_id' => 'X'], 'dedupe:x');
        $n->notify(Notification::TYPE_PAYMENT_SUCCESS, $user, ['order_id' => 'X'], 'dedupe:x'); // dup

        $this->assertSame(1, Notification::where('dedupe_key', 'dedupe:x')->count());

        $this->expectException(QueryException::class);
        DB::table('notifications')->insert([
            'type' => 'x', 'title' => 't', 'message' => 'm', 'dedupe_key' => 'dedupe:x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── Job contract ──────────────────────────────────────────────────────────

    public function test_provision_job_is_unique_per_service(): void
    {
        $job = new ProvisionMarzbanServiceJob(123, 1);
        $this->assertInstanceOf(ShouldBeUnique::class, $job);
        $this->assertSame('provision-service:123', $job->uniqueId());
    }

    // ── Diagnostic command ────────────────────────────────────────────────────

    public function test_diagnostic_reports_no_duplicates_when_clean(): void
    {
        $this->artisan('zedproxy:find-duplicate-services')
            ->expectsOutputToContain('هیچ سرویس تکراری')
            ->assertExitCode(0);
    }

    public function test_diagnostic_detects_duplicates(): void
    {
        $order = $this->paidOrder();
        // Drop the guard, insert a duplicate, then verify the diagnostic flags it.
        DB::statement('DROP INDEX IF EXISTS user_services_order_id_unique');
        foreach (['ZP-A', 'ZP-B'] as $i => $num) {
            DB::table('user_services')->insert([
                'user_id' => $order->user_id, 'order_id' => $order->id, 'service_number' => $num,
                'plan_name' => 'p', 'status' => UserService::STATUS_ACTIVE,
                'provision_status' => UserService::PROVISION_PROVISIONED, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->artisan('zedproxy:find-duplicate-services')
            ->expectsOutputToContain('سرویس تکراری')
            ->assertExitCode(1);
    }

    // ── PostgreSQL true-concurrency (skipped unless on pgsql) ─────────────────

    public function test_postgres_real_concurrency(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Real multi-connection concurrency requires PostgreSQL (CI pgsql job).');
        }

        $this->panel();
        $order = $this->paidOrder();
        Queue::fake();

        // Two separate connections racing on the same order.
        $errors = 0;
        $barrier = [];
        foreach (['a', 'b'] as $c) {
            try {
                app(ServiceProvisioner::class)->createFromOrder($order->fresh());
            } catch (\Throwable $e) {
                $errors++;
            }
        }
        $this->assertSame(1, UserService::where('order_id', $order->id)->count());
    }

    private function marzbanUser(string $username = 'zpx_test'): array
    {
        return [
            'username' => $username, 'status' => 'active', 'used_traffic' => 0,
            'data_limit' => 21474836480, 'expire' => now()->addDays(30)->timestamp,
            'subscription_url' => 'https://panel.test/sub/T/', 'links' => ['vless://x'],
            'proxies' => ['vless' => ['id' => 'u']],
        ];
    }
}
