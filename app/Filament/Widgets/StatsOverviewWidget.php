<?php

namespace App\Filament\Widgets;

use App\Models\Order;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Models\WalletTransaction;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverviewWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $userCount  = User::count();
        $adminCount = User::where('is_admin', true)->count();

        $salesToday = Order::where('payment_status', Order::PAYMENT_PAID)
            ->whereDate('paid_at', today())
            ->sum('final_price_toman');

        $salesMonth = Order::where('payment_status', Order::PAYMENT_PAID)
            ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('final_price_toman');

        $pendingPayments = PaymentTransaction::whereIn('status', [
            PaymentTransaction::STATUS_PENDING,
            PaymentTransaction::STATUS_SUBMITTED,
            PaymentTransaction::STATUS_WAITING,
            PaymentTransaction::STATUS_CONFIRMING,
        ])->count();

        $provisioningFailed = Order::where('status', Order::STATUS_PROVISIONING_FAILED)->count();

        $totalWallet = (int) User::sum('wallet_balance_toman');

        return [
            Stat::make('کل کاربران', number_format($userCount))
                ->description('کاربران ثبت‌نام‌شده')
                ->color('primary'),

            Stat::make('فروش امروز', number_format($salesToday) . ' تومان')
                ->description('درآمد از سفارش‌های پرداخت‌شده (شارژ کیف پول محاسبه نشده)')
                ->color('success'),

            Stat::make('فروش این ماه', number_format($salesMonth) . ' تومان')
                ->description('جمع فروش از ابتدای ماه')
                ->color('success'),

            Stat::make('پرداخت‌های در انتظار', number_format($pendingPayments))
                ->description('نیاز به بررسی')
                ->color($pendingPayments > 0 ? 'warning' : 'success'),

            Stat::make('موجودی کل کیف پول‌ها', number_format($totalWallet) . ' تومان')
                ->description('مجموع موجودی همه کاربران')
                ->color('info'),

            Stat::make('خطاهای ساخت سرویس', number_format($provisioningFailed))
                ->description($provisioningFailed > 0 ? 'نیاز به اقدام فوری' : 'همه سرویس‌ها سالم')
                ->color($provisioningFailed > 0 ? 'danger' : 'success'),
        ];
    }
}
