<?php

namespace App\Filament\Pages;

use App\Models\SiteSetting;
use App\Services\Referrals\ReferralSettings;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Admin settings for referral mode, representative system and commissions.
 */
class ReferralSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.pages.referral-settings';

    protected static ?string $navigationIcon   = 'heroicon-o-megaphone';
    protected static ?string $navigationGroup   = 'بازاریابی';
    protected static ?string $navigationLabel   = 'تنظیمات نمایندگان';
    protected static ?string $title             = 'تنظیمات نمایندگان و پورسانت';
    protected static ?string $slug              = 'settings/referral';
    protected static ?int    $navigationSort    = 1;

    /** @var array<string,mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'referral_mode'                  => ReferralSettings::mode(),
            'representative_system_enabled'  => ReferralSettings::representativeSystemEnabled(),
            'auto_approve_representatives'   => ReferralSettings::autoApproveRepresentatives(),
            'default_commission_type'        => ReferralSettings::defaultCommissionType(),
            'default_commission_value'       => ReferralSettings::defaultCommissionValue(),
            'commission_on_new_service'      => (bool) SiteSetting::get('commission_on_new_service', true),
            'commission_on_renewal'          => (bool) SiteSetting::get('commission_on_renewal', true),
            'commission_on_extra_traffic'    => (bool) SiteSetting::get('commission_on_extra_traffic', true),
            'commission_on_extra_time'       => (bool) SiteSetting::get('commission_on_extra_time', true),
            'commission_after_discount'      => ReferralSettings::commissionAfterDiscount(),
            'referral_cookie_days'           => ReferralSettings::referralCookieDays(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('حالت زیرمجموعه‌گیری')
                    ->schema([
                        Forms\Components\Radio::make('referral_mode')
                            ->label('چه کسانی می‌توانند کاربر معرفی کنند؟')
                            ->options([
                                ReferralSettings::MODE_ALL_USERS       => 'فعال بودن زیرمجموعه‌گیری برای همه کاربران (همه کاربران)',
                                ReferralSettings::MODE_REPRESENTATIVES => 'فعال بودن زیرمجموعه‌گیری فقط برای نماینده‌ها (فقط نماینده‌های تاییدشده)',
                            ])
                            ->default(ReferralSettings::MODE_ALL_USERS)
                            ->required(),

                        Forms\Components\TextInput::make('referral_cookie_days')
                            ->label('مدت اعتبار کوکی دعوت (روز)')
                            ->numeric()->minValue(1)->default(30),
                    ]),

                Forms\Components\Section::make('سیستم نمایندگان')
                    ->schema([
                        Forms\Components\Toggle::make('representative_system_enabled')
                            ->label('فعال بودن سیستم نمایندگان')
                            ->default(false),

                        Forms\Components\Toggle::make('auto_approve_representatives')
                            ->label('تایید خودکار درخواست نمایندگی')
                            ->default(false),
                    ])->columns(2),

                Forms\Components\Section::make('پورسانت')
                    ->schema([
                        Forms\Components\Select::make('default_commission_type')
                            ->label('نوع پورسانت پیش‌فرض')
                            ->options(['percent' => 'درصدی', 'fixed' => 'مبلغ ثابت'])
                            ->default('percent'),

                        Forms\Components\TextInput::make('default_commission_value')
                            ->label('مقدار پیش‌فرض پورسانت')
                            ->numeric()->minValue(0)->default(0)
                            ->helperText('برای درصدی: عدد درصد. برای ثابت: مبلغ به تومان.'),

                        Forms\Components\Toggle::make('commission_after_discount')
                            ->label('محاسبه پورسانت بعد از تخفیف')
                            ->helperText('فعال: از مبلغ نهایی پس از تخفیف. غیرفعال: از مبلغ اولیه.')
                            ->default(true),

                        Forms\Components\Toggle::make('commission_on_new_service')->label('پورسانت خرید سرویس جدید')->default(true),
                        Forms\Components\Toggle::make('commission_on_renewal')->label('پورسانت تمدید سرویس')->default(true),
                        Forms\Components\Toggle::make('commission_on_extra_traffic')->label('پورسانت خرید حجم اضافه')->default(true),
                        Forms\Components\Toggle::make('commission_on_extra_time')->label('پورسانت خرید زمان اضافه')->default(true),
                    ])->columns(2),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        SiteSetting::set('referral_mode', $data['referral_mode'] ?? ReferralSettings::MODE_ALL_USERS);
        SiteSetting::set('referral_cookie_days', (int) ($data['referral_cookie_days'] ?? 30));
        SiteSetting::set('representative_system_enabled', ! empty($data['representative_system_enabled']) ? 'true' : 'false');
        SiteSetting::set('auto_approve_representatives', ! empty($data['auto_approve_representatives']) ? 'true' : 'false');
        SiteSetting::set('default_commission_type', $data['default_commission_type'] ?? 'percent');
        SiteSetting::set('default_commission_value', (int) ($data['default_commission_value'] ?? 0));
        SiteSetting::set('commission_after_discount', ! empty($data['commission_after_discount']) ? 'true' : 'false');
        SiteSetting::set('commission_on_new_service', ! empty($data['commission_on_new_service']) ? 'true' : 'false');
        SiteSetting::set('commission_on_renewal', ! empty($data['commission_on_renewal']) ? 'true' : 'false');
        SiteSetting::set('commission_on_extra_traffic', ! empty($data['commission_on_extra_traffic']) ? 'true' : 'false');
        SiteSetting::set('commission_on_extra_time', ! empty($data['commission_on_extra_time']) ? 'true' : 'false');

        Notification::make()->title('تنظیمات ذخیره شد.')->success()->send();
    }

    protected function getFormActions(): array
    {
        return [Action::make('save')->label('ذخیره تنظیمات')->action('save')];
    }
}
