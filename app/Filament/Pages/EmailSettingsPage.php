<?php

namespace App\Filament\Pages;

use App\Mail\TestEmailMail;
use App\Models\SiteSetting;
use App\Services\Email\EmailVerificationService;
use App\Support\MailFailure;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;
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

    /** Last successful transport test, or null. Never exposes secrets. */
    public function lastMailTestAt(): ?string
    {
        return app(EmailVerificationService::class)->mailTestVerifiedAt()?->format('Y-m-d H:i');
    }

    /** Whether a valid transport-test proof exists for the CURRENT config. */
    public function mailTestVerified(): bool
    {
        return app(EmailVerificationService::class)->hasVerifiedMailTest();
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

        // ── Proof guard: a configuration can LOOK usable (host/port present)
        //    while no SMTP server answers there. Required mode additionally
        //    demands a recent SUCCESSFUL test through the CURRENT
        //    configuration (fingerprint-matched), so admins can never lock
        //    new users behind an unproven transport.
        if ($requireOnRegister && ! $this->mailTestVerified()) {
            Notification::make()
                ->title('برای اجباری کردن تایید ایمیل، ابتدا باید یک «ارسال ایمیل تست» موفق با همین پیکربندی انجام شود.')
                ->danger()->send();

            return;
        }

        // Record every OFF → ON transition of required mode: accounts created
        // BEFORE this moment registered under a policy that never asked them
        // to verify, and the middleware grandfathers them by this timestamp.
        $wasRequired = filter_var(SiteSetting::get('email_verification_required_on_register', false), FILTER_VALIDATE_BOOLEAN);
        if ($requireOnRegister && ! $wasRequired) {
            SiteSetting::set('email_verification_required_since', now()->toIso8601String());
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
                // ONE shared policy with the routes: consume the named
                // `email-test-send` limiter's own limits — an independent
                // per-admin bucket (followed across IPs) AND an independent
                // per-IP bucket (followed across admin accounts). No
                // duplicated thresholds, no combined user|IP pair bucket.
                $limits = (array) app(\Illuminate\Cache\RateLimiter::class)
                    ->limiter('email-test-send')(request());

                foreach ($limits as $limit) {
                    if (RateLimiter::tooManyAttempts($limit->key, $limit->maxAttempts)) {
                        Notification::make()
                            ->title('تعداد ایمیل‌های تست بیش از حد مجاز است. چند دقیقه دیگر تلاش کنید.')
                            ->danger()->send();

                        return;
                    }
                }
                foreach ($limits as $limit) {
                    RateLimiter::hit($limit->key, $limit->decaySeconds);
                }

                // A test through log/array (or a failover chain containing
                // them, or an undefined mailer) would "succeed" without any
                // real delivery — refuse instead of reporting a false pass.
                if (! $this->mailLooksConfigured()) {
                    Notification::make()
                        ->title('پیکربندی ایمیل قابل استفاده نیست — ارسال تست انجام نشد. mailer فعلی: '.$this->mailerName())
                        ->danger()->send();

                    return;
                }

                try {
                    // Dedicated harmless test message (never a fake OTP), sent
                    // synchronously so the result is the transport's own
                    // verdict. Never includes secrets.
                    Mail::to((string) $data['test_email'])
                        ->send(new TestEmailMail);

                    // The transport accepted the message: record the NON-SECRET
                    // proof (config fingerprint + timestamp) that required
                    // mode's save-guard and runtime enforcement depend on.
                    app(EmailVerificationService::class)->recordSuccessfulMailTest();

                    // HONEST wording: the transport ACCEPTED the message —
                    // inbox delivery can never be confirmed from here.
                    Notification::make()
                        ->title('سرور ایمیل پیام تست را پذیرفت. رسیدن به صندوق ورودی را در مقصد بررسی کنید.')
                        ->success()->send();
                } catch (\Throwable $e) {
                    // NEVER show or log raw transport text (it can echo SMTP
                    // credentials) — only the sanitized category.
                    $safe = MailFailure::summarize('test email failed', $e);
                    Log::warning('Admin test email failed', ['error' => $safe]);
                    Notification::make()
                        ->title('ارسال ایمیل تست ناموفق بود ('.MailFailure::categorize($e).'). جزئیات در لاگ سرور ثبت شد.')
                        ->danger()->send();
                }
            });
    }
}
