<?php

namespace App\Filament\Pages;

use App\Models\SiteText;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;

class WalletSettings extends Page
{
    use Forms\Concerns\InteractsWithForms;

    protected static ?string $navigationIcon  = 'heroicon-o-wallet';
    protected static ?string $navigationGroup = 'تنظیمات';
    protected static ?string $navigationLabel = 'تنظیمات کیف پول';
    protected static ?string $title           = 'تنظیمات کیف پول';
    protected static ?int $navigationSort     = 1;
    protected static ?string $slug            = 'settings/wallet';
    protected static string $view             = 'filament.pages.wallet-settings';

    public ?array $data = [];

    private const BOOLEAN_KEYS = [
        'wallet_enabled',
        'wallet_payment_enabled',
        'wallet_topup_enabled',
        'wallet_topup_nowpayments_enabled',
        'wallet_topup_centralpay_enabled',
        'wallet_admin_adjustment_requires_note',
    ];

    private const ALL_KEYS = [
        'wallet_enabled',
        'wallet_payment_enabled',
        'wallet_topup_enabled',
        'wallet_topup_nowpayments_enabled',
        'wallet_topup_centralpay_enabled',
        'wallet_min_topup_amount',
        'wallet_max_topup_amount',
        'wallet_currency',
        'wallet_topup_preset_amounts',
        'wallet_admin_adjustment_requires_note',
    ];

    public function mount(): void
    {
        $settings = SiteText::whereIn('key', self::ALL_KEYS)->pluck('value', 'key');

        $data = [];
        foreach (self::ALL_KEYS as $key) {
            $value = $settings->get($key, '');
            if (in_array($key, self::BOOLEAN_KEYS)) {
                $data[$key] = $value === '1';
            } elseif ($key === 'wallet_min_topup_amount') {
                $data[$key] = $value !== '' ? (int) $value : null;
            } elseif ($key === 'wallet_max_topup_amount') {
                $data[$key] = ($value !== '' && (int) $value > 0) ? (int) $value : null;
            } else {
                $data[$key] = $value;
            }
        }

        $this->form->fill($data);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('تنظیمات اصلی کیف پول')
                    ->schema([
                        Forms\Components\Toggle::make('wallet_enabled')
                            ->label('فعال بودن کیف پول'),
                        Forms\Components\Toggle::make('wallet_payment_enabled')
                            ->label('امکان پرداخت سفارش با کیف پول'),
                        Forms\Components\Toggle::make('wallet_topup_enabled')
                            ->label('امکان شارژ کیف پول'),
                    ])->columns(3),

                Forms\Components\Section::make('روش‌های شارژ کیف پول')
                    ->schema([
                        Forms\Components\Toggle::make('wallet_topup_nowpayments_enabled')
                            ->label('شارژ کیف پول با NOWPayments'),
                        Forms\Components\Toggle::make('wallet_topup_centralpay_enabled')
                            ->label('شارژ کیف پول با CentralPay')
                            ->helperText('تا زمان آماده‌سازی IP Whitelist غیرفعال نگه‌دارید.'),
                    ])->columns(2),

                Forms\Components\Section::make('محدودیت‌های مبلغ شارژ')
                    ->schema([
                        Forms\Components\TextInput::make('wallet_min_topup_amount')
                            ->label('حداقل مبلغ شارژ کیف پول')
                            ->numeric()
                            ->minValue(1)
                            ->suffix('تومان'),
                        Forms\Components\TextInput::make('wallet_max_topup_amount')
                            ->label('حداکثر مبلغ شارژ کیف پول')
                            ->numeric()
                            ->minValue(0)
                            ->nullable()
                            ->suffix('تومان')
                            ->helperText('خالی بگذارید تا محدودیتی نباشد'),
                        Forms\Components\TextInput::make('wallet_currency')
                            ->label('واحد پول کیف پول')
                            ->required(),
                        Forms\Components\TextInput::make('wallet_topup_preset_amounts')
                            ->label('مبالغ پیشنهادی شارژ کیف پول')
                            ->helperText('مقادیر را با کاما جدا کنید: 100000,250000,500000'),
                    ])->columns(2),

                Forms\Components\Section::make('سایر تنظیمات')
                    ->schema([
                        Forms\Components\Toggle::make('wallet_admin_adjustment_requires_note')
                            ->label('اجباری بودن توضیح برای تغییر دستی موجودی'),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        foreach ($data as $key => $value) {
            if (in_array($key, self::BOOLEAN_KEYS)) {
                $strValue = ($value === true || $value === '1') ? '1' : '0';
            } else {
                $strValue = $value !== null ? (string) $value : '';
            }

            SiteText::where('key', $key)->update(['value' => $strValue]);
            Cache::forget("site_text:{$key}");
        }

        Notification::make()
            ->title('تنظیمات کیف پول با موفقیت ذخیره شد.')
            ->success()
            ->send();
    }
}
