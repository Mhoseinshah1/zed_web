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
    protected static ?string $navigationGroup = 'سفارش‌ها و مالی';
    protected static ?string $navigationLabel = 'تنظیمات کیف پول';
    protected static ?string $title           = 'تنظیمات کیف پول';
    protected static ?int    $navigationSort  = 50;
    protected static ?string $slug            = 'settings/wallet';
    protected static string  $view            = 'filament.pages.wallet-settings';

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

    private const DEFAULTS = [
        'wallet_enabled'                       => '1',
        'wallet_payment_enabled'               => '1',
        'wallet_topup_enabled'                 => '1',
        'wallet_topup_nowpayments_enabled'     => '1',
        'wallet_topup_centralpay_enabled'      => '0',
        'wallet_min_topup_amount'              => '100000',
        'wallet_max_topup_amount'              => '',
        'wallet_currency'                      => 'IRT',
        'wallet_topup_preset_amounts'          => '100000,250000,500000,1000000,2000000',
        'wallet_admin_adjustment_requires_note' => '1',
    ];

    public function mount(): void
    {
        $this->form->fill($this->loadFormData());
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
                            ->label('واحد پول کیف پول'),
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

        foreach (self::ALL_KEYS as $key) {
            $value    = $data[$key] ?? null;
            $strValue = $this->castToString($key, $value);

            // updateOrCreate guarantees the row is created when missing,
            // fixing the silent-no-op bug from plain ->update().
            SiteText::updateOrCreate(
                ['key' => $key],
                [
                    'value'      => $strValue,
                    'group'      => 'wallet',
                    'label'      => $key,
                    'type'       => in_array($key, self::BOOLEAN_KEYS) ? 'boolean' : 'text',
                    'sort_order' => array_search($key, self::ALL_KEYS) + 100,
                ]
            );

            // Explicitly bust the per-key cache (model saved event also does this,
            // but being explicit here is safe and makes the intent clear).
            Cache::forget("site_text:{$key}");
        }

        // Re-fill the form from DB so what's displayed matches what was persisted.
        $this->form->fill($this->loadFormData());

        Notification::make()
            ->title('تنظیمات کیف پول ذخیره شد.')
            ->success()
            ->send();
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function loadFormData(): array
    {
        $stored = SiteText::whereIn('key', self::ALL_KEYS)->pluck('value', 'key');

        $data = [];
        foreach (self::ALL_KEYS as $key) {
            $raw = $stored->get($key, self::DEFAULTS[$key] ?? '');

            if (in_array($key, self::BOOLEAN_KEYS)) {
                $data[$key] = $this->isTruthy($raw);
            } elseif ($key === 'wallet_min_topup_amount') {
                $data[$key] = $raw !== '' ? (int) $raw : null;
            } elseif ($key === 'wallet_max_topup_amount') {
                $data[$key] = ($raw !== '' && (int) $raw > 0) ? (int) $raw : null;
            } else {
                $data[$key] = $raw;
            }
        }

        return $data;
    }

    private function castToString(string $key, mixed $value): string
    {
        if (in_array($key, self::BOOLEAN_KEYS)) {
            return $this->isTruthy($value) ? '1' : '0';
        }

        return $value !== null ? (string) $value : '';
    }

    /**
     * Accept true/false, 1/0, "1"/"0", "true"/"false" as truthy/falsy.
     */
    private function isTruthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        $str = strtolower((string) $value);
        return $str === '1' || $str === 'true' || $str === 'yes';
    }
}
