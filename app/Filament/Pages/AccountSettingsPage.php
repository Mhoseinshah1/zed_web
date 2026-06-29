<?php

namespace App\Filament\Pages;

use App\Models\SiteSetting;
use App\Services\Sms\SmsService;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Admin settings for user account requirements: phone verification toggles.
 */
class AccountSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.pages.account-settings';

    protected static ?string $navigationIcon   = 'heroicon-o-identification';
    protected static ?string $navigationGroup   = 'سیستم و یکپارچه‌سازی';
    protected static ?string $navigationLabel   = 'تنظیمات حساب کاربری';
    protected static ?string $title             = 'تنظیمات حساب کاربری و تایید شماره موبایل';
    protected static ?string $slug              = 'settings/account';
    protected static ?int    $navigationSort    = 22;

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'phone_verification_enabled'              => (bool) SiteSetting::get('phone_verification_enabled', false),
            'phone_verification_required_on_register' => (bool) SiteSetting::get('phone_verification_required_on_register', false),
        ]);
    }

    public function smsConfigured(): bool
    {
        return app(SmsService::class)->isConfigured();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('تایید شماره موبایل')
                    ->schema([
                        Forms\Components\Toggle::make('phone_verification_enabled')
                            ->label('فعال بودن تایید شماره موبایل')
                            ->live()
                            ->default(false),

                        Forms\Components\Toggle::make('phone_verification_required_on_register')
                            ->label('اجباری بودن تایید شماره هنگام ثبت نام')
                            ->helperText('فقط زمانی اعمال می‌شود که تایید شماره موبایل فعال باشد.')
                            ->visible(fn (Forms\Get $get) => $get('phone_verification_enabled'))
                            ->default(false),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        SiteSetting::set('phone_verification_enabled', ! empty($data['phone_verification_enabled']) ? 'true' : 'false');
        SiteSetting::set('phone_verification_required_on_register', ! empty($data['phone_verification_required_on_register']) ? 'true' : 'false');

        Notification::make()->title('تنظیمات ذخیره شد.')->success()->send();
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')->label('ذخیره تنظیمات')->action('save'),
        ];
    }
}
