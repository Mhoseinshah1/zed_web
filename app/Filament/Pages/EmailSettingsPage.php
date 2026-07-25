<?php

namespace App\Filament\Pages;

use App\Mail\EmailOtpMail;
use App\Models\SiteSetting;
use App\Services\Email\EmailVerificationService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Admin settings for email verification (OTP rules + feature flags).
 *
 * ONLY non-secret feature settings live in SiteSetting. SMTP host/username/
 * password stay in .env and are NEVER stored here or displayed — this page
 * shows just the current mailer name, the From address, and whether the
 * configuration looks usable.
 */
class EmailSettingsPage extends Page implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    protected static string $view = 'filament.pages.email-settings';

    protected static ?string $navigationIcon = 'heroicon-o-envelope';

    protected static ?string $navigationGroup = 'کاربران';

    protected static ?string $navigationLabel = 'تنظیمات ایمیل و تایید ایمیل';

    protected static ?string $title = 'تنظیمات ایمیل و تایید آدرس ایمیل';

    protected static ?string $slug = 'settings/email';

    protected static ?int $navigationSort = 21;

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'email_verification_enabled' => (bool) SiteSetting::get('email_verification_enabled', false),
            'email_verification_required_on_register' => (bool) SiteSetting::get('email_verification_required_on_register', false),
            'email_otp_ttl_minutes' => (int) SiteSetting::get('email_otp_ttl_minutes', EmailVerificationService::CODE_TTL_MINUTES),
            'email_otp_max_attempts' => (int) SiteSetting::get('email_otp_max_attempts', EmailVerificationService::MAX_ATTEMPTS),
            'email_otp_resend_cooldown_seconds' => (int) SiteSetting::get('email_otp_resend_cooldown_seconds', EmailVerificationService::RESEND_COOLDOWN_SEC),
            'email_otp_daily_cap' => (int) SiteSetting::get('email_otp_daily_cap', EmailVerificationService::DAILY_CAP),
        ]);
    }

    public function mailerName(): string
    {
        return (string) config('mail.default');
    }

    public function fromAddress(): string
    {
        return (string) config('mail.from.address');
    }

    public function mailLooksConfigured(): bool
    {
        return app(EmailVerificationService::class)->isMailConfigured();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('تایید آدرس ایمیل')
                    ->description('کاربر یک کد ۶ رقمی دریافت و وارد می‌کند. اطلاعات SMTP (هاست/نام کاربری/رمز) فقط در فایل .env سرور تنظیم می‌شود و اینجا نمایش داده نمی‌شود.')
                    ->schema([
                        Forms\Components\Toggle::make('email_verification_enabled')
                            ->label('فعال بودن تایید ایمیل')
                            ->live()
                            ->default(false),
                        Forms\Components\Toggle::make('email_verification_required_on_register')
                            ->label('اجباری بودن تایید ایمیل هنگام ثبت‌نام')
                            ->helperText('فقط زمانی قابل فعال‌سازی است که پیکربندی ایمیل سرور قابل استفاده باشد.')
                            ->live()
                            ->default(false),
                    ])->columns(2),

                Forms\Components\Section::make('قوانین کد تایید')
                    ->schema([
                        Forms\Components\TextInput::make('email_otp_ttl_minutes')
                            ->label('مدت اعتبار کد (دقیقه)')
                            ->numeric()->minValue(1)->maxValue(60)->default(EmailVerificationService::CODE_TTL_MINUTES),
                        Forms\Components\TextInput::make('email_otp_max_attempts')
                            ->label('حداکثر تلاش برای هر کد')
                            ->numeric()->minValue(1)->maxValue(10)->default(EmailVerificationService::MAX_ATTEMPTS),
                        Forms\Components\TextInput::make('email_otp_resend_cooldown_seconds')
                            ->label('فاصله ارسال مجدد (ثانیه)')
                            ->numeric()->minValue(0)->maxValue(3600)->default(EmailVerificationService::RESEND_COOLDOWN_SEC),
                        Forms\Components\TextInput::make('email_otp_daily_cap')
                            ->label('سقف ارسال روزانه برای هر کاربر')
                            ->numeric()->minValue(1)->maxValue(100)->default(EmailVerificationService::DAILY_CAP),
                    ])->columns(4),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $requireOnRegister = ! empty($data['email_verification_required_on_register']);

        // ── Validation guard: never allow REQUIRED verification while the
        //    mailer is clearly unconfigured (users could never receive codes).
        if ($requireOnRegister && ! $this->mailLooksConfigured()) {
            Notification::make()
                ->title('برای اجباری کردن تایید ایمیل، ابتدا باید پیکربندی ایمیل سرور (.env) کامل و قابل استفاده باشد. mailer فعلی: '.$this->mailerName())
                ->danger()->send();

            return;
        }

        SiteSetting::set('email_verification_enabled', ! empty($data['email_verification_enabled']) ? 'true' : 'false');
        SiteSetting::set('email_verification_required_on_register', $requireOnRegister ? 'true' : 'false');
        SiteSetting::set('email_otp_ttl_minutes', (int) ($data['email_otp_ttl_minutes'] ?? EmailVerificationService::CODE_TTL_MINUTES));
        SiteSetting::set('email_otp_max_attempts', (int) ($data['email_otp_max_attempts'] ?? EmailVerificationService::MAX_ATTEMPTS));
        SiteSetting::set('email_otp_resend_cooldown_seconds', (int) ($data['email_otp_resend_cooldown_seconds'] ?? EmailVerificationService::RESEND_COOLDOWN_SEC));
        SiteSetting::set('email_otp_daily_cap', (int) ($data['email_otp_daily_cap'] ?? EmailVerificationService::DAILY_CAP));

        Notification::make()->title('تنظیمات ذخیره شد.')->success()->send();
    }

    public function testEmailAction(): Action
    {
        return Action::make('testEmail')
            ->label('ارسال ایمیل تست')
            ->color('gray')
            ->icon('heroicon-o-paper-airplane')
            ->form([
                Forms\Components\TextInput::make('test_email')
                    ->label('آدرس ایمیل مقصد')
                    ->email()
                    ->required()
                    ->placeholder('you@example.com'),
            ])
            ->action(function (array $data) {
                // Rate-limited: 3 test emails per 10 minutes per admin.
                $key = 'email-settings-test:'.(auth()->id() ?? 'guest');
                if (! RateLimiter::attempt($key, 3, fn () => null, 600)) {
                    Notification::make()
                        ->title('تعداد ایمیل‌های تست بیش از حد مجاز است. چند دقیقه دیگر تلاش کنید.')
                        ->danger()->send();

                    return;
                }

                try {
                    // Harmless test message, sent synchronously so the result
                    // is the TRUE transport outcome. Never includes secrets.
                    Mail::to((string) $data['test_email'])
                        ->send(new EmailOtpMail('000000', 1));
                    Notification::make()->title('ایمیل تست با موفقیت ارسال شد.')->success()->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title('ارسال ایمیل تست ناموفق بود: '.$e->getMessage())
                        ->danger()->send();
                }
            });
    }
}
