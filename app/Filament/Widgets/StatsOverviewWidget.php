<?php

namespace App\Filament\Widgets;

use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

class StatsOverviewWidget extends BaseWidget
{
    protected function getStats(): array
    {
        $userCount  = DB::table('users')->count();
        $adminCount = DB::table('users')->where('is_admin', true)->count();

        return [
            Stat::make('کل کاربران', number_format($userCount))
                ->description('کاربران ثبت‌نام‌شده')
                ->color('primary'),

            Stat::make('مدیران سیستم', number_format($adminCount))
                ->description('دارای دسترسی ادمین')
                ->color('warning'),

            Stat::make('سفارش‌ها', '۰')
                ->description('در انتظار پیاده‌سازی')
                ->color('success'),

            Stat::make('درآمد امروز', '۰ تومان')
                ->description('در انتظار پیاده‌سازی')
                ->color('danger'),
        ];
    }
}
