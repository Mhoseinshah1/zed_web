<?php

namespace App\Filament\Pages;

use App\Models\SiteSetting;
use App\Services\Sms\SmsService;
use App\Support\PhoneNumber;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Admin settings for the SMS gateway, OTP rules and phone verification.
 * All values are stored in the database; the API key is stored encrypted and
 * never re-displayed.
 */
class SmsSettingsPage extends Page implements HasForms, HasActions
{
    use InteractsWithForms;
    use InteractsWithActions;

    protected static string $view = 'filament.pages.sms-settings';

    protected static ?string $navigationIcon   = 'heroicon-o-chat-bubble-bottom-center-text';
    protected static ?string $navigationGroup   = 'کاربران';
    protected static ?string $navigationLabel   = 'تنظیمات پیامک و تایید شماره';
    protected static ?string $title             = 'تنظیمات پیامک و تایید شماره موبایل';
    protected static ?string $slug              = 'settings/sms';
    protected static ?int    $navigationSort    = 20;

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'sms_enabled'                             => (bool) SiteSetting::get('sms_enabled', false),
            'sms_provider'                            => (string) SiteSetting::get('sms_provider', 'kavenegar'),
            'sms_api_key_new'                         => null,
            'sms_sender'                              => (string) SiteSetting::get('sms_sender', ''),
            'sms_pattern_code'                        => (string) SiteSetting::get('sms_pattern_code', ''),
            'sms_otp_message'                         => (string) SiteSetting::get('sms_otp_message', SmsService::DEFAULT_OTP_MESSAGE),
            'otp_ttl_minutes'                         => (int) SiteSetting::get('otp_ttl_minutes', 5),
            'otp_max_attempts'                        => (int) SiteSetting::get('otp_max_attempts', 5),
            'otp_resend_cooldown_seconds'             => (int) SiteSetting::get('otp_resend_cooldown_seconds', 60),
            'phone_verification_enabled'              => (bool) SiteSetting::get('phone_verification_enabled', false),
            'phone_verification_required_on_register' => (bool) SiteSetting::get('phone_verification_required_on_register', false),
            'sms_custom_url'                          => (string) SiteSetting::get('sms_custom_url', ''),
            'sms_custom_method'                       => (string) SiteSetting::get('sms_custom_method', 'POST'),
            'sms_custom_headers'                      => (string) SiteSetting::get('sms_custom_headers', ''),
            'sms_custom_body_template'                => (string) SiteSetting::get('sms_custom_body_template', ''),
        ]);
    }

    public function hasApiKey(): bool
    {
        return app(SmsService::class)->apiKey() !== '';
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('ارائه‌دهنده پیامک')
                    ->schema([
                        Forms\Components\Toggle::make('sms_enabled')
                            ->label('فعال بودن ارسال پیامک')
                            ->live()
                            ->default(false),

                        Forms\Components\Select::make('sms_provider')
                            ->label('ارائه‌دهنده پیامک')
                            ->options(SmsService::PROVIDERS)
                            ->live()
                            ->default('kavenegar'),

                        Forms\Components\TextInput::make('sms_api_key_new')
                            ->label('API Key / Token')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->placeholder($this->hasApiKey() ? '•••••••• (برای تغییر، مقدار جدید وارد کنید)' : 'کلید API را وارد کنید')
                            ->helperText('به‌صورت رمزنگاری‌شده ذخیره می‌شود و دیگر نمایش داده نمی‌شود.')
                            ->dehydrated(),

                        Forms\Components\TextInput::make('sms_sender')
                            ->label('شماره ارسال‌کننده')
                            ->nullable(),

                        Forms\Components\TextInput::make('sms_pattern_code')
                            ->label('کد پترن / قالب پیامک')
                            ->nullable()
                            ->helperText('در صورت استفاده از حالت پترن/قالب برای ارسال کد تایید.'),

                        Forms\Components\Textarea::make('sms_otp_message')
                            ->label('متن پیام کد تایید')
                            ->rows(3)
                            ->helperText('متغیرها: {code} کد تایید، {minutes} مدت اعتبار.')
                            ->default(SmsService::DEFAULT_OTP_MESSAGE)
                            ->columnSpanFull(),
                    ])->columns(2),

                Forms\Components\Section::make('تنظیمات API سفارشی')
                    ->visible(fn (Forms\Get $get) => $get('sms_provider') === 'custom')
                    ->schema([
                        Forms\Components\TextInput::make('sms_custom_url')
                            ->label('آدرس API سفارشی')
                            ->url()
                            ->nullable(),

                        Forms\Components\Select::make('sms_custom_method')
                            ->label('متد درخواست')
                            ->options(['GET' => 'GET', 'POST' => 'POST'])
                            ->default('POST'),

                        Forms\Components\Textarea::make('sms_custom_headers')
                            ->label('هدرهای سفارشی (JSON)')
                            ->rows(2)
                            ->placeholder('{"Authorization": "Bearer ..."}')
                            ->nullable()
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('sms_custom_body_template')
                            ->label('قالب بدنه درخواست')
                            ->rows(3)
                            ->helperText('متغیرها: {phone} {code} {message} {sender} {api_key}')
                            ->nullable()
                            ->columnSpanFull(),
                    ])->columns(2),

                Forms\Components\Section::make('قوانین کد تایید (OTP)')
                    ->schema([
                        Forms\Components\TextInput::make('otp_ttl_minutes')
                            ->label('مدت اعتبار کد تایید (دقیقه)')
                            ->numeric()->minValue(1)->default(5),

                        Forms\Components\TextInput::make('otp_max_attempts')
                            ->label('حداکثر تعداد تلاش')
                            ->numeric()->minValue(1)->default(5),

                        Forms\Components\TextInput::make('otp_resend_cooldown_seconds')
                            ->label('فاصله ارسال مجدد کد (ثانیه)')
                            ->numeric()->minValue(0)->default(60),
                    ])->columns(3),

                Forms\Components\Section::make('تایید شماره موبایل')
                    ->schema([
                        Forms\Components\Toggle::make('phone_verification_enabled')
                            ->label('فعال بودن تایید شماره موبایل')
                            ->live()
                            ->default(false),

                        Forms\Components\Toggle::make('phone_verification_required_on_register')
                            ->label('اجباری بودن تایید شماره هنگام ثبت نام')
                            ->helperText('برای فعال‌سازی، ارسال پیامک باید فعال و تنظیم‌شده باشد.')
                            ->visible(fn (Forms\Get $get) => $get('phone_verification_enabled'))
                            ->default(false),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $smsEnabled = ! empty($data['sms_enabled']);
        $provider   = $data['sms_provider'] ?? 'kavenegar';
        $newKey     = $data['sms_api_key_new'] ?? null;
        $hasKey     = $this->hasApiKey() || filled($newKey);

        // ── Validation guards ──────────────────────────────────────────────
        if ($smsEnabled && (blank($provider) || ! $hasKey)) {
            Notification::make()
                ->title('برای فعال‌سازی پیامک، انتخاب ارائه‌دهنده و وارد کردن API Key الزامی است.')
                ->danger()->send();
            return;
        }

        if ($smsEnabled && $provider === 'custom' && blank($data['sms_custom_url'] ?? null)) {
            Notification::make()
                ->title('برای ارائه‌دهنده سفارشی، آدرس API الزامی است.')
                ->danger()->send();
            return;
        }

        $requireOnRegister = ! empty($data['phone_verification_required_on_register']);
        if ($requireOnRegister && (! $smsEnabled || ! $hasKey)) {
            Notification::make()
                ->title('برای اجباری کردن تایید شماره هنگام ثبت نام، ابتدا باید ارسال پیامک فعال و تنظیم شود.')
                ->danger()->send();
            return;
        }

        // ── Persist ────────────────────────────────────────────────────────
        SiteSetting::set('sms_enabled', $smsEnabled ? 'true' : 'false');
        SiteSetting::set('sms_provider', $provider);
        SiteSetting::set('sms_sender', (string) ($data['sms_sender'] ?? ''));
        SiteSetting::set('sms_pattern_code', (string) ($data['sms_pattern_code'] ?? ''));
        SiteSetting::set('sms_otp_message', (string) ($data['sms_otp_message'] ?? SmsService::DEFAULT_OTP_MESSAGE));
        SiteSetting::set('otp_ttl_minutes', (int) ($data['otp_ttl_minutes'] ?? 5));
        SiteSetting::set('otp_max_attempts', (int) ($data['otp_max_attempts'] ?? 5));
        SiteSetting::set('otp_resend_cooldown_seconds', (int) ($data['otp_resend_cooldown_seconds'] ?? 60));
        SiteSetting::set('phone_verification_enabled', ! empty($data['phone_verification_enabled']) ? 'true' : 'false');
        SiteSetting::set('phone_verification_required_on_register', $requireOnRegister ? 'true' : 'false');
        SiteSetting::set('sms_custom_url', (string) ($data['sms_custom_url'] ?? ''));
        SiteSetting::set('sms_custom_method', (string) ($data['sms_custom_method'] ?? 'POST'));
        SiteSetting::set('sms_custom_headers', (string) ($data['sms_custom_headers'] ?? ''));
        SiteSetting::set('sms_custom_body_template', (string) ($data['sms_custom_body_template'] ?? ''));

        // Only overwrite the API key when a new value was entered.
        if (filled($newKey)) {
            SmsService::storeApiKey($newKey);
        }

        // Don't keep the plaintext key in the Livewire component state.
        $this->data['sms_api_key_new'] = null;

        Notification::make()->title('تنظیمات ذخیره شد.')->success()->send();
    }

    public function testSmsAction(): Action
    {
        return Action::make('testSms')
            ->label('ارسال پیامک تست')
            ->color('gray')
            ->icon('heroicon-o-paper-airplane')
            ->form([
                Forms\Components\TextInput::make('test_phone')
                    ->label('شماره موبایل')
                    ->required()
                    ->placeholder('مثلاً 09123456789'),
            ])
            ->action(function (array $data) {
                $normalized = PhoneNumber::normalize($data['test_phone'] ?? '');
                if ($normalized === null) {
                    Notification::make()->title('شماره موبایل معتبر نیست.')->danger()->send();
                    return;
                }

                try {
                    app(SmsService::class)->sendTest($normalized, 'این یک پیامک تست از زدپروکسی است.');
                    Notification::make()->title('پیامک تست با موفقیت ارسال شد.')->success()->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('ارسال پیامک تست ناموفق بود: ' . $e->getMessage())
                        ->danger()->send();
                }
            });
    }
}
