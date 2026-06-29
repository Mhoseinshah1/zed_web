<?php

namespace App\Filament\Pages;

use App\Models\SiteSetting;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Admin settings for custom extra-traffic / extra-time purchases.
 * All values are stored in the site_settings table; defaults are only used as
 * fallbacks at read time and never overwrite admin-edited values.
 */
class ExtraAddonSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.pages.extra-addon-settings';

    protected static ?string $navigationIcon  = 'heroicon-o-plus-circle';
    protected static ?string $navigationGroup = 'سرویس‌ها';
    protected static ?string $navigationLabel = 'تنظیمات خرید حجم و زمان اضافه';
    protected static ?string $title           = 'تنظیمات خرید حجم و زمان اضافه';
    protected static ?string $slug            = 'settings/extra-addons';
    protected static ?int    $navigationSort  = 90;

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'extra_traffic_enabled'                 => (bool) SiteSetting::get('extra_traffic_enabled', true),
            'extra_traffic_price_per_gb'            => SiteSetting::get('extra_traffic_price_per_gb', null),
            'extra_traffic_min_gb'                  => (int) SiteSetting::get('extra_traffic_min_gb', 1),
            'extra_traffic_max_gb'                  => (int) SiteSetting::get('extra_traffic_max_gb', 100),
            'extra_time_enabled'                    => (bool) SiteSetting::get('extra_time_enabled', true),
            'extra_time_price_per_day'              => SiteSetting::get('extra_time_price_per_day', null),
            'extra_time_min_days'                   => (int) SiteSetting::get('extra_time_min_days', 1),
            'extra_time_max_days'                   => (int) SiteSetting::get('extra_time_max_days', 30),
            'extra_addon_apply_to_expired_services' => (bool) SiteSetting::get('extra_addon_apply_to_expired_services', true),
            'extra_addon_admin_note'                => SiteSetting::get('extra_addon_admin_note', null),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('خرید حجم اضافه')
                    ->schema([
                        Forms\Components\Toggle::make('extra_traffic_enabled')
                            ->label('فعال بودن خرید حجم اضافه')
                            ->default(true),

                        Forms\Components\TextInput::make('extra_traffic_price_per_gb')
                            ->label('قیمت هر گیگ حجم اضافه')
                            ->numeric()
                            ->minValue(0)
                            ->suffix('تومان')
                            ->helperText('اگر خالی باشد، خرید حجم اضافه برای کاربران غیرفعال می‌شود.'),

                        Forms\Components\TextInput::make('extra_traffic_min_gb')
                            ->label('حداقل حجم قابل خرید')
                            ->numeric()
                            ->minValue(1)
                            ->suffix('GB')
                            ->default(1),

                        Forms\Components\TextInput::make('extra_traffic_max_gb')
                            ->label('حداکثر حجم قابل خرید')
                            ->numeric()
                            ->minValue(1)
                            ->suffix('GB')
                            ->default(100),
                    ])->columns(2),

                Forms\Components\Section::make('خرید زمان اضافه')
                    ->schema([
                        Forms\Components\Toggle::make('extra_time_enabled')
                            ->label('فعال بودن خرید زمان اضافه')
                            ->default(true),

                        Forms\Components\TextInput::make('extra_time_price_per_day')
                            ->label('قیمت هر روز زمان اضافه')
                            ->numeric()
                            ->minValue(0)
                            ->suffix('تومان')
                            ->helperText('اگر خالی باشد، خرید زمان اضافه برای کاربران غیرفعال می‌شود.'),

                        Forms\Components\TextInput::make('extra_time_min_days')
                            ->label('حداقل روز قابل خرید')
                            ->numeric()
                            ->minValue(1)
                            ->suffix('روز')
                            ->default(1),

                        Forms\Components\TextInput::make('extra_time_max_days')
                            ->label('حداکثر روز قابل خرید')
                            ->numeric()
                            ->minValue(1)
                            ->suffix('روز')
                            ->default(30),
                    ])->columns(2),

                Forms\Components\Section::make('سایر تنظیمات')
                    ->schema([
                        Forms\Components\Toggle::make('extra_addon_apply_to_expired_services')
                            ->label('امکان خرید برای سرویس منقضی‌شده')
                            ->default(true),

                        Forms\Components\Textarea::make('extra_addon_admin_note')
                            ->label('توضیحات ادمین')
                            ->rows(3)
                            ->nullable(),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        SiteSetting::set('extra_traffic_enabled', $data['extra_traffic_enabled'] ? 'true' : 'false');
        SiteSetting::set('extra_traffic_min_gb', (int) ($data['extra_traffic_min_gb'] ?? 1));
        SiteSetting::set('extra_traffic_max_gb', (int) ($data['extra_traffic_max_gb'] ?? 100));
        SiteSetting::set('extra_time_enabled', $data['extra_time_enabled'] ? 'true' : 'false');
        SiteSetting::set('extra_time_min_days', (int) ($data['extra_time_min_days'] ?? 1));
        SiteSetting::set('extra_time_max_days', (int) ($data['extra_time_max_days'] ?? 30));
        SiteSetting::set('extra_addon_apply_to_expired_services', $data['extra_addon_apply_to_expired_services'] ? 'true' : 'false');

        // Nullable price fields — store empty string when cleared so the feature disables.
        SiteSetting::set('extra_traffic_price_per_gb', $data['extra_traffic_price_per_gb'] === null ? '' : (int) $data['extra_traffic_price_per_gb']);
        SiteSetting::set('extra_time_price_per_day', $data['extra_time_price_per_day'] === null ? '' : (int) $data['extra_time_price_per_day']);
        SiteSetting::set('extra_addon_admin_note', (string) ($data['extra_addon_admin_note'] ?? ''));

        Notification::make()->title('تنظیمات ذخیره شد.')->success()->send();
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('ذخیره تنظیمات')
                ->action('save'),
        ];
    }
}
