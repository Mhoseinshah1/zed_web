<?php

namespace Tests\Feature;

use App\Models\Notification;
use App\Models\NotificationTemplate;
use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\Plan;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\UserService;
use App\Models\WalletTransaction;
use App\Services\Addons\ServiceAddonService;
use App\Services\Marzban\MarzbanClient;
use App\Services\Notifications\NotificationService;
use App\Services\Orders\MarkOrderAsPaidService;
use App\Services\PaymentService;
use App\Services\Renewals\RenewalService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app(NotificationService::class)->seedDefaults();
        SiteSetting::set('extra_traffic_price_per_gb', 1000);
        SiteSetting::set('extra_time_price_per_day', 2000);
    }

    private function makeUser(array $attrs = []): User
    {
        return User::factory()->create(array_merge(['wallet_balance_toman' => 0], $attrs));
    }

    private function makePlan(int $price = 100000): Plan
    {
        return Plan::create([
            'name'            => 'پلن اعلان',
            'slug'            => 'noti-' . uniqid(),
            'price_toman'     => $price,
            'duration_days'   => 30,
            'traffic_gb'      => 50,
            'is_active'       => true,
            'renewal_enabled' => true,
            'sort_order'      => 0,
        ]);
    }

    private function makeService(User $user, array $overrides = []): UserService
    {
        $plan = $this->makePlan();
        return UserService::create(array_merge([
            'user_id'          => $user->id,
            'plan_id'          => $plan->id,
            'status'           => UserService::STATUS_ACTIVE,
            'provision_status' => UserService::PROVISION_PROVISIONED,
            'plan_name'        => $plan->name,
            'traffic_total_gb' => 20,
            'traffic_used_gb'  => 5,
            'expires_at'       => now()->addDays(10),
            'remote_username'  => 'u1',
        ], $overrides));
    }

    private function newServiceOrder(User $user, Plan $plan, array $attrs = []): Order
    {
        return Order::create(array_merge([
            'order_type'        => Order::TYPE_NEW_SERVICE,
            'user_id'           => $user->id,
            'plan_id'           => $plan->id,
            'plan_name'         => $plan->name,
            'traffic_gb'        => $plan->traffic_gb,
            'duration_days'     => $plan->duration_days,
            'price_toman'       => $plan->price_toman,
            'final_price_toman' => $plan->price_toman,
            'discount_toman'    => 0,
            'status'            => Order::STATUS_AWAITING_PAYMENT,
            'payment_status'    => Order::PAYMENT_UNPAID,
        ], $attrs));
    }

    private function renewalOrder(UserService $service, Plan $plan, array $attrs = []): Order
    {
        return Order::create(array_merge([
            'order_type'        => Order::TYPE_RENEWAL,
            'user_id'           => $service->user_id,
            'user_service_id'   => $service->id,
            'plan_id'           => $plan->id,
            'plan_name'         => $plan->name,
            'duration_days'     => 30,
            'renewal_days'      => 30,
            'price_toman'       => 100000,
            'final_price_toman' => 100000,
            'discount_toman'    => 0,
            'status'            => Order::STATUS_PAID,
            'payment_status'    => Order::PAYMENT_PAID,
            'paid_at'           => now(),
        ], $attrs));
    }

    private function pendingTx(Order $order, User $user): PaymentTransaction
    {
        return PaymentTransaction::create([
            'order_id'        => $order->id,
            'user_id'         => $user->id,
            'provider'        => 'wallet',
            'status'          => PaymentTransaction::STATUS_PENDING,
            'amount_toman'    => $order->final_price_toman,
            'payment_purpose' => 'order_payment',
        ]);
    }

    // ── Template service ─────────────────────────────────────────────────────

    public function test_default_templates_are_seeded(): void
    {
        $this->assertDatabaseHas('notification_templates', [
            'key' => Notification::TYPE_RENEWAL_SUCCESS,
        ]);
        $this->assertGreaterThanOrEqual(10, NotificationTemplate::count());
    }

    public function test_seed_defaults_does_not_overwrite_admin_edits(): void
    {
        $tpl = NotificationTemplate::findByKey(Notification::TYPE_RENEWAL_SUCCESS);
        $tpl->update(['message' => 'پیام سفارشی ادمین']);

        app(NotificationService::class)->seedDefaults();

        $this->assertSame('پیام سفارشی ادمین', $tpl->fresh()->message);
    }

    public function test_template_variables_are_substituted(): void
    {
        [$title, $message] = app(NotificationService::class)->render(
            Notification::TYPE_RENEWAL_CASHBACK_SUCCESS,
            ['cashback_amount' => '5,000', 'user_name' => 'علی']
        );

        $this->assertStringContainsString('کش‌بک', $title . $message);
    }

    public function test_templates_are_editable_from_admin_model(): void
    {
        $tpl = NotificationTemplate::findByKey(Notification::TYPE_DISCOUNT_USED);
        $tpl->update(['title' => 'عنوان جدید', 'is_active' => false]);

        [$title] = app(NotificationService::class)->render(Notification::TYPE_DISCOUNT_USED, []);
        // Inactive template falls back to the built-in default, not the edited title.
        $this->assertNotSame('عنوان جدید', $title);

        $tpl->update(['is_active' => true]);
        [$title2] = app(NotificationService::class)->render(Notification::TYPE_DISCOUNT_USED, []);
        $this->assertSame('عنوان جدید', $title2);
    }

    // ── User notifications from flows ────────────────────────────────────────

    public function test_notification_created_after_new_service_payment(): void
    {
        $user  = $this->makeUser(['wallet_balance_toman' => 200000]);
        $plan  = $this->makePlan();
        $order = $this->newServiceOrder($user, $plan);
        $tx    = $this->pendingTx($order, $user);

        app(MarkOrderAsPaidService::class)->markPaid($order, $tx);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type'    => Notification::TYPE_PAYMENT_SUCCESS,
        ]);
    }

    public function test_notification_created_after_renewal_success(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user);
        $plan    = $this->makePlan();
        $order   = $this->renewalOrder($service, $plan);

        $this->mock(MarzbanClient::class)->shouldReceive('updateUser')->andReturn([]);

        app(RenewalService::class)->applyRenewal($order);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type'    => Notification::TYPE_RENEWAL_SUCCESS,
        ]);
    }

    public function test_notification_created_after_extra_traffic_success(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user);
        $order   = app(ServiceAddonService::class)->createExtraTrafficOrder($service, 20, $user);
        $order->update(['status' => Order::STATUS_PAID, 'payment_status' => Order::PAYMENT_PAID, 'paid_at' => now()]);

        $this->mock(MarzbanClient::class)->shouldReceive('updateUser')->andReturn([]);

        app(ServiceAddonService::class)->applyExtraTraffic($order->fresh());

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type'    => Notification::TYPE_EXTRA_TRAFFIC_SUCCESS,
        ]);
    }

    public function test_notification_created_after_extra_time_success(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user);
        $order   = app(ServiceAddonService::class)->createExtraTimeOrder($service, 7, $user);
        $order->update(['status' => Order::STATUS_PAID, 'payment_status' => Order::PAYMENT_PAID, 'paid_at' => now()]);

        $this->mock(MarzbanClient::class)->shouldReceive('updateUser')->andReturn([]);

        app(ServiceAddonService::class)->applyExtraTime($order->fresh());

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type'    => Notification::TYPE_EXTRA_TIME_SUCCESS,
        ]);
    }

    public function test_wallet_topup_notification_works(): void
    {
        $user = $this->makeUser();
        $tx   = PaymentTransaction::create([
            'user_id'         => $user->id,
            'provider'        => 'centralpay',
            'status'          => PaymentTransaction::STATUS_APPROVED,
            'amount_toman'    => 500000,
            'payment_purpose' => 'wallet_topup',
            'paid_at'         => now(),
        ]);

        app(WalletService::class)->creditFromPaymentTransaction($user, $tx);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type'    => Notification::TYPE_WALLET_TOPUP_SUCCESS,
        ]);
    }

    public function test_wallet_payment_notification_works(): void
    {
        $user  = $this->makeUser(['wallet_balance_toman' => 200000]);
        $plan  = $this->makePlan();
        $order = $this->newServiceOrder($user, $plan);

        app(PaymentService::class)->payWithWallet($order, $user);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type'    => Notification::TYPE_WALLET_PAYMENT_SUCCESS,
        ]);
    }

    public function test_cashback_notification_works(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user);
        $plan    = $this->makePlan();
        $order   = $this->renewalOrder($service, $plan, [
            'renewal_cashback_amount' => 5000,
            'renewal_cashback_status' => 'pending',
        ]);

        $this->mock(MarzbanClient::class)->shouldReceive('updateUser')->andReturn([]);

        app(RenewalService::class)->applyRenewal($order);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type'    => Notification::TYPE_RENEWAL_CASHBACK_SUCCESS,
        ]);
    }

    public function test_discount_used_notification_works(): void
    {
        $user  = $this->makeUser();
        $plan  = $this->makePlan();
        $code  = \App\Models\DiscountCode::create([
            'code' => 'NOTI10', 'type' => \App\Models\DiscountCode::TYPE_PERCENT,
            'value' => 10, 'is_active' => true, 'per_user_usage_limit' => 1,
        ]);
        $order = $this->newServiceOrder($user, $plan, [
            'discount_code_id' => $code->id,
            'discount_code'    => 'NOTI10',
            'discount_toman'   => 10000,
            'final_price_toman'=> 90000,
        ]);
        \App\Models\DiscountRedemption::create([
            'discount_code_id' => $code->id,
            'user_id'          => $user->id,
            'order_id'         => $order->id,
            'status'           => \App\Models\DiscountRedemption::STATUS_RESERVED,
            'original_amount'  => 100000,
            'discount_amount'  => 10000,
            'final_amount'     => 90000,
        ]);

        app(\App\Services\Discounts\DiscountService::class)->markUsed($order);

        $this->assertDatabaseHas('notifications', [
            'user_id' => $user->id,
            'type'    => Notification::TYPE_DISCOUNT_USED,
        ]);
    }

    // ── Admin warning ────────────────────────────────────────────────────────

    public function test_admin_warning_created_on_marzban_renewal_failure(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user, ['remote_username' => 'fail_user']);
        $plan    = $this->makePlan();
        $order   = $this->renewalOrder($service, $plan);

        $this->mock(MarzbanClient::class)
            ->shouldReceive('updateUser')
            ->andThrow(new \Exception('connection error'));

        app(RenewalService::class)->applyRenewal($order);

        $this->assertDatabaseHas('notifications', [
            'user_id' => null,
            'type'    => Notification::TYPE_MARZBAN_UPDATE_FAILED,
        ]);
    }

    public function test_admin_warning_created_on_extra_traffic_marzban_failure(): void
    {
        $user    = $this->makeUser();
        $service = $this->makeService($user, ['remote_username' => 'fail_user']);
        $order   = app(ServiceAddonService::class)->createExtraTrafficOrder($service, 20, $user);
        $order->update(['status' => Order::STATUS_PAID, 'payment_status' => Order::PAYMENT_PAID, 'paid_at' => now()]);

        $this->mock(MarzbanClient::class)
            ->shouldReceive('updateUser')
            ->andThrow(new \Exception('boom'));

        app(ServiceAddonService::class)->applyExtraTraffic($order->fresh());

        $this->assertDatabaseHas('notifications', [
            'user_id' => null,
            'type'    => Notification::TYPE_MARZBAN_UPDATE_FAILED,
        ]);
    }

    // ── Idempotency ──────────────────────────────────────────────────────────

    public function test_duplicate_callback_does_not_create_duplicate_notification(): void
    {
        $user  = $this->makeUser(['wallet_balance_toman' => 200000]);
        $plan  = $this->makePlan();
        $order = $this->newServiceOrder($user, $plan);
        $tx    = $this->pendingTx($order, $user);

        $svc = app(MarkOrderAsPaidService::class);
        $svc->markPaid($order, $tx);
        $svc->markPaid($order->fresh(), $tx->fresh()); // duplicate IPN

        $count = Notification::where('user_id', $user->id)
            ->where('type', Notification::TYPE_PAYMENT_SUCCESS)
            ->count();
        $this->assertSame(1, $count);
    }

    public function test_notify_skips_when_dedupe_key_exists(): void
    {
        $user = $this->makeUser();
        $svc  = app(NotificationService::class);

        $svc->notify(Notification::TYPE_PAYMENT_SUCCESS, $user, [], 'dupe:1');
        $svc->notify(Notification::TYPE_PAYMENT_SUCCESS, $user, [], 'dupe:1');

        $this->assertSame(1, Notification::where('dedupe_key', 'dupe:1')->count());
    }

    // ── User dashboard ───────────────────────────────────────────────────────

    public function test_user_can_view_own_notifications(): void
    {
        $user = $this->makeUser();
        Notification::create([
            'user_id' => $user->id,
            'type'    => Notification::TYPE_PAYMENT_SUCCESS,
            'title'   => 'پرداخت موفق',
            'message' => 'خرید شما پرداخت شد.',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard.notifications'))
            ->assertStatus(200)
            ->assertSee('پرداخت موفق');
    }

    public function test_user_cannot_mark_another_users_notification(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        $notification = Notification::create([
            'user_id' => $owner->id,
            'type'    => Notification::TYPE_PAYMENT_SUCCESS,
            'title'   => 'x',
            'message' => 'y',
        ]);

        $this->actingAs($other)
            ->post(route('dashboard.notifications.read', $notification))
            ->assertStatus(403);
    }

    public function test_user_only_sees_own_notifications(): void
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        Notification::create(['user_id' => $other->id, 'type' => Notification::TYPE_PAYMENT_SUCCESS, 'title' => 'سفارش دیگری', 'message' => 'm']);

        $this->actingAs($owner)
            ->get(route('dashboard.notifications'))
            ->assertStatus(200)
            ->assertDontSee('سفارش دیگری');
    }

    public function test_mark_all_read(): void
    {
        $user = $this->makeUser();
        Notification::create(['user_id' => $user->id, 'type' => Notification::TYPE_PAYMENT_SUCCESS, 'title' => 'a', 'message' => 'm']);
        Notification::create(['user_id' => $user->id, 'type' => Notification::TYPE_RENEWAL_SUCCESS, 'title' => 'b', 'message' => 'm']);

        $this->actingAs($user)->post(route('dashboard.notifications.read-all'))->assertRedirect();

        $this->assertSame(0, Notification::forUser($user->id)->unread()->count());
    }

    // ── Admin notification center ────────────────────────────────────────────

    public function test_admin_can_view_system_notifications(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Notification::create([
            'user_id' => null,
            'type'    => Notification::TYPE_MARZBAN_UPDATE_FAILED,
            'title'   => 'خطای Marzban',
            'message' => 'بررسی شود',
        ]);

        $this->actingAs($admin)
            ->get('/zed-admin/notifications')
            ->assertStatus(200);
    }
}
