<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\LatestOrdersWidget;
use App\Filament\Widgets\RecentActivityWidget;
use App\Filament\Widgets\StatsOverviewWidget;
use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Admin dashboard: KPI stat cards on top, then a two-column row of «آخرین
 * سفارش‌ها» (wide) and «فعالیت اخیر» (narrow). The default Filament welcome
 * (AccountWidget) card is intentionally omitted. Only display configuration is
 * set here — no business logic, routes, or permissions change.
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $navigationGroup = 'داشبورد';
    protected static ?string $navigationLabel = 'داشبورد مدیریت';
    protected static ?string $title           = 'داشبورد مدیریت';
    protected static ?int    $navigationSort  = 1;

    /** A 6-column grid so the widgets below can span cleanly (4 + 2). */
    public function getColumns(): int|string|array
    {
        return 6;
    }

    /** @return array<class-string> */
    public function getWidgets(): array
    {
        return [
            StatsOverviewWidget::class,
            LatestOrdersWidget::class,
            RecentActivityWidget::class,
        ];
    }
}
