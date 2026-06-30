<?php

namespace App\Filament\Pages;

use Filament\Pages\Dashboard as BaseDashboard;

/**
 * Admin dashboard, placed in its own «داشبورد» navigation group with a clean
 * Persian label. Behaviour is unchanged from Filament's base dashboard.
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $navigationGroup = 'داشبورد';
    protected static ?string $navigationLabel = 'داشبورد مدیریت';
    protected static ?string $title           = 'داشبورد مدیریت';
    protected static ?int    $navigationSort  = 1;
}
