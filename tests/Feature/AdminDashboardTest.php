<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource;
use App\Filament\Widgets\LatestOrdersWidget;
use App\Filament\Widgets\RecentActivityWidget;
use App\Filament\Widgets\StatsOverviewWidget;
use App\Models\Order;
use App\Models\SupportTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Structural admin-panel work: dashboard widgets (stats / latest orders / recent
 * activity) and the Orders sidebar badge. Confirms the widgets render with REAL
 * data and the badge reflects a real count — display config only, no logic.
 */
class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['is_admin' => true, 'username' => 'dash_admin']);
    }

    public function test_stats_widget_renders_the_four_kpis_from_real_data(): void
    {
        $admin = $this->admin();
        Order::factory()->create(['user_id' => $admin->id, 'payment_status' => Order::PAYMENT_PAID, 'paid_at' => now(), 'plan_name' => 'Y', 'price_toman' => 5000, 'final_price_toman' => 5000]);

        Livewire::actingAs($admin)->test(StatsOverviewWidget::class)
            ->assertSuccessful()
            ->assertSee('کل سفارش‌ها')
            ->assertSee('درآمد')
            ->assertSee('کاربران فعال')
            ->assertSee('سرویس‌های فعال');
    }

    public function test_latest_orders_widget_lists_real_orders(): void
    {
        $admin = $this->admin();
        Order::factory()->create(['user_id' => $admin->id, 'plan_name' => 'پلن-داشبورد-تست', 'price_toman' => 1000, 'final_price_toman' => 1000]);

        Livewire::actingAs($admin)->test(LatestOrdersWidget::class)
            ->assertSuccessful()
            ->assertSee('آخرین سفارش‌ها')
            ->assertSee('پلن-داشبورد-تست');
    }

    public function test_recent_activity_widget_merges_real_events(): void
    {
        $admin = $this->admin();
        Order::factory()->create(['user_id' => $admin->id, 'payment_status' => Order::PAYMENT_PAID, 'paid_at' => now(), 'plan_name' => 'Z', 'price_toman' => 5000, 'final_price_toman' => 5000]);
        SupportTicket::create(['ticket_number' => 'T-9', 'user_id' => $admin->id, 'subject' => 'تیکت-فعالیت-تست', 'status' => SupportTicket::STATUS_OPEN]);

        Livewire::actingAs($admin)->test(RecentActivityWidget::class)
            ->assertSuccessful()
            ->assertSee('فعالیت اخیر')
            ->assertSee('پرداخت موفق')
            ->assertSee('تیکت-فعالیت-تست');
    }

    public function test_orders_navigation_badge_counts_pending_payments(): void
    {
        $admin = $this->admin();
        $this->assertNull(OrderResource::getNavigationBadge()); // none yet

        Order::factory()->count(2)->create(['user_id' => $admin->id, 'payment_status' => Order::PAYMENT_PENDING, 'plan_name' => 'P', 'price_toman' => 1000, 'final_price_toman' => 1000]);
        Order::factory()->create(['user_id' => $admin->id, 'payment_status' => Order::PAYMENT_PAID, 'plan_name' => 'P', 'price_toman' => 1000, 'final_price_toman' => 1000]);

        $this->assertSame('2', OrderResource::getNavigationBadge()); // only the pending ones
        $this->assertSame('warning', OrderResource::getNavigationBadgeColor());
    }

    public function test_dashboard_page_loads_without_the_welcome_card(): void
    {
        $admin = $this->admin();
        $res = $this->actingAs($admin)->get('/zed-admin');
        $res->assertSuccessful();
        // Filament's AccountWidget welcome card must not be present.
        $this->assertStringNotContainsString('fi-account-widget', $res->getContent());
    }
}
