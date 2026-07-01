<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use App\Models\User;
use App\Models\UserService;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * Top-of-dashboard KPI cards: total orders, revenue, active users and active
 * services — each coloured, with a real month-over-month trend. All numbers are
 * live aggregate queries (counts/sums), never constants.
 */
class StatsOverviewWidget extends BaseWidget
{
    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        [$startThis, $startLast, $endLast] = [
            now()->startOfMonth(),
            now()->subMonthNoOverflow()->startOfMonth(),
            now()->subMonthNoOverflow()->endOfMonth(),
        ];

        // ── Orders (total + MoM trend on order count) ────────────────────────
        $totalOrders     = Order::count();
        $ordersThisMonth = Order::where('created_at', '>=', $startThis)->count();
        $ordersLastMonth = Order::whereBetween('created_at', [$startLast, $endLast])->count();

        // ── Revenue (paid orders this month + MoM trend) ─────────────────────
        $revenueThisMonth = (int) Order::where('payment_status', Order::PAYMENT_PAID)
            ->where('paid_at', '>=', $startThis)->sum('final_price_toman');
        $revenueLastMonth = (int) Order::where('payment_status', Order::PAYMENT_PAID)
            ->whereBetween('paid_at', [$startLast, $endLast])->sum('final_price_toman');

        // ── Active users (distinct users with an active service) ─────────────
        $activeUsers = UserService::where('status', UserService::STATUS_ACTIVE)
            ->distinct('user_id')->count('user_id');
        $newUsersThisMonth = User::where('created_at', '>=', $startThis)->count();

        // ── Active services ──────────────────────────────────────────────────
        $activeServices  = UserService::where('status', UserService::STATUS_ACTIVE)->count();
        $pendingServices = UserService::where('status', UserService::STATUS_PENDING_PROVISION)->count();

        return [
            $this->trendStat(
                'کل سفارش‌ها', number_format($totalOrders),
                $ordersThisMonth, $ordersLastMonth, 'primary', 'heroicon-m-shopping-cart',
            ),
            $this->trendStat(
                'درآمد (تومان)', number_format($revenueThisMonth),
                $revenueThisMonth, $revenueLastMonth, 'success', 'heroicon-m-banknotes',
            ),
            Stat::make('کاربران فعال', number_format($activeUsers))
                ->description($newUsersThisMonth . ' کاربر جدید این ماه')
                ->descriptionIcon('heroicon-m-user-plus')
                ->color('info'),
            Stat::make('سرویس‌های فعال', number_format($activeServices))
                ->description($pendingServices > 0 ? $pendingServices . ' در انتظار ساخت' : 'همه فعال')
                ->descriptionIcon('heroicon-m-bolt')
                ->color($pendingServices > 0 ? 'warning' : 'success'),
        ];
    }

    /** Build a stat whose description shows the month-over-month percentage change. */
    private function trendStat(string $label, string $value, int $current, int $previous, string $color, string $icon): Stat
    {
        if ($previous > 0) {
            $pct   = (int) round((($current - $previous) / $previous) * 100);
            $up    = $pct >= 0;
            $desc  = ($up ? '+' : '') . $pct . '٪ نسبت به ماه قبل';
            $trend = $up ? 'heroicon-m-arrow-trending-up' : 'heroicon-m-arrow-trending-down';
        } else {
            $desc  = $current > 0 ? 'شروع فعالیت این ماه' : 'بدون داده‌ی ماه قبل';
            $trend = $icon;
        }

        return Stat::make($label, $value)
            ->description($desc)
            ->descriptionIcon($trend)
            ->color($color);
    }
}
