<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\RequiresCommunicationsStepUp;
use App\Mail\TestEmailMail;
use App\Models\EmailTransportSetting;
use App\Models\SiteSetting;
use App\Services\AdminMfa\AdminSecurityAudit;
use App\Services\Email\EmailTransportSettingsService;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Admin settings for email verification (OTP rules + feature flags) and the
 * admin-managed SMTP transport.
 *
 * Non-secret feature settings live in SiteSetting. SMTP settings live in the
 * dedicated email_transport_settings singleton row: username/password are
 * APP_KEY-encrypted at rest (never in the plaintext site_settings table),
 * the stored password is NEVER hydrated into Livewire state (a blank field
 * preserves it; an explicit confirmed action clears it), and every save/
 * clear/test action re-asserts the communications step-up before touching
 * any secret. Saving takes effect immediately — web requests re-resolve at
 * bootstrap and queue workers re-resolve before every job; no config cache
 * rebuild or worker restart is ever required. .env stays the bootstrap/
 * fallback source while the panel override is disabled.
 */
class EmailSettingsPage extends Page implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;
    use RequiresCommunicationsStepUp;

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
        // Step-up gate BEFORE any hydration: while locked the form is never
        // filled and no configuration state reaches the Livewire snapshot.
        // (The upcoming SMTP-management work adds real secrets to this page —
        // the gate is the standing contract they inherit.)
        $this->initializeStepUpState();
        if (! $this->stepUpUnlocked) {
            return;
        }

        // Status/fingerprint displays must reflect the PERSISTED effective
        // configuration (idempotent — bootstrap already applied it).
        $this->transportService()->apply();

        $row = EmailTransportSetting::instanceOrNew();

        // Username may be shown only AFTER step-up succeeded (we are past the
        // gate here). A corrupt/undecryptable value hydrates as empty — the
        // status panel separately reports the configuration as unusable.
        try {
            $username = $row->exists ? $row->username : null;
        } catch (\Throwable) {
            $username = null;
        }

        $this->form->fill([
            'smtp_override_enabled' => (bool) $row->enabled,
            'smtp_host' => $row->host,
            'smtp_port' => $row->port,
            'smtp_security' => $row->security,
            'smtp_username' => $username,
            // The stored password is NEVER hydrated — blank means "keep".
            'smtp_password_new' => null,
            'smtp_from_address' => $row->from_address,
            'smtp_from_name' => $row->from_name,
            'smtp_timeout' => $row->timeout,
            'smtp_local_domain' => $row->local_domain,
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

    protected function transportService(): EmailTransportSettingsService
    {
        return app(EmailTransportSettingsService::class);
    }

    /** Effective source: `panel`, `env`, or `panel_invalid` (fail-closed). */
    public function effectiveSource(): string
    {
        return $this->transportService()->effectiveSource();
    }

    /** Whether an encrypted SMTP password is stored (presence only). */
    public function storedPasswordExists(): bool
    {
        return EmailTransportSetting::instance()?->hasStoredPassword() ?? false;
    }

    /**
     * More than ONE effective delivery leaf (multi-leaf failover/roundrobin):
     * rejected for OTP delivery — the job/lock/redelivery time budget covers
     * exactly one complete transport exchange. Never exposes secrets.
     */
    public function multiLeafMailer(): bool
    {
        $leaves = app(EmailVerificationService::class)->effectiveLeafMailers();

        return $leaves !== null && count($leaves) > 1;
    }

    /** The dedicated Persian explanation for a rejected multi-leaf graph. */
    public const MULTI_LEAF_MESSAGE = 'برای ارسال امن کد تایید، mailer انتخاب‌شده باید فقط یک مسیر ارسال نهایی داشته باشد. Failover یا Round-robin چندمسیره با محدودیت زمانی فعلی پشتیبانی نمی‌شود.';

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
                Forms\Components\Section::make('پیکربندی SMTP (سرور ارسال ایمیل)')
                    ->description('با فعال کردن «استفاده از تنظیمات پنل»، ارسال ایمیل با همین مقادیر انجام می‌شود و تغییرات بلافاصله (بدون نیاز به پاک کردن کش یا ری‌استارت worker) اعمال می‌گردد. تا وقتی غیرفعال باشد، تنظیمات فایل .env سرور معتبر است. نام کاربری و رمز عبور به‌صورت رمزنگاری‌شده ذخیره می‌شوند.')
                    ->schema([
                        Forms\Components\Toggle::make('smtp_override_enabled')
                            ->label('استفاده از تنظیمات SMTP پنل (به جای .env)')
                            ->live()
                            ->default(false)
                            ->columnSpan(2),
                        Forms\Components\TextInput::make('smtp_host')
                            ->label('هاست SMTP')
                            ->placeholder('smtp.example.com')
                            ->maxLength(255)
                            ->required(fn (Forms\Get $get): bool => (bool) $get('smtp_override_enabled')),
                        Forms\Components\TextInput::make('smtp_port')
                            ->label('پورت')
                            ->numeric()->minValue(1)->maxValue(65535)
                            ->placeholder('587')
                            ->required(fn (Forms\Get $get): bool => (bool) $get('smtp_override_enabled')),
                        Forms\Components\Select::make('smtp_security')
                            // The EXACT schemes Symfony Mailer 7 accepts —
                            // never a legacy `encryption` value.
                            ->label('امنیت اتصال')
                            ->options([
                                'smtp' => 'STARTTLS / بدون TLS اجباری (smtp — معمولاً پورت 587)',
                                'smtps' => 'TLS مستقیم (smtps — معمولاً پورت 465)',
                            ])
                            ->native(false)
                            ->required(fn (Forms\Get $get): bool => (bool) $get('smtp_override_enabled')),
                        Forms\Components\TextInput::make('smtp_timeout')
                            ->label('حداکثر زمان هر عملیات (ثانیه)')
                            ->numeric()
                            ->minValue(EmailTransportSettingsService::MIN_TIMEOUT)
                            ->maxValue(EmailTransportSettingsService::MAX_TIMEOUT)
                            ->placeholder('10')
                            ->required(fn (Forms\Get $get): bool => (bool) $get('smtp_override_enabled')),
                        Forms\Components\TextInput::make('smtp_username')
                            ->label('نام کاربری SMTP')
                            ->maxLength(255)
                            ->autocomplete('off'),
                        Forms\Components\TextInput::make('smtp_password_new')
                            ->label('رمز عبور جدید SMTP')
                            ->password()
                            ->autocomplete('new-password')
                            ->maxLength(1024)
                            ->helperText('خالی بگذارید تا رمز ذخیره‌شده فعلی حفظ شود. برای حذف رمز از دکمه «حذف رمز ذخیره‌شده» استفاده کنید.'),
                        Forms\Components\TextInput::make('smtp_from_address')
                            ->label('آدرس فرستنده (From)')
                            ->email()
                            ->maxLength(255)
                            ->required(fn (Forms\Get $get): bool => (bool) $get('smtp_override_enabled')),
                        Forms\Components\TextInput::make('smtp_from_name')
                            ->label('نام فرستنده')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('smtp_local_domain')
                            ->label('دامنه EHLO (اختیاری)')
                            ->maxLength(255)
                            ->helperText('در صورت خالی بودن، از دامنه APP_URL استفاده می‌شود.'),
                    ])->columns(2),

                Forms\Components\Section::make('تایید آدرس ایمیل')
                    ->description('کاربر یک کد ۶ رقمی دریافت و وارد می‌کند.')
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
                            // Floor above the job's claim-time delivery margin
                            // (240s — the full supported SMTP exchange): a
                            // shorter TTL would make every delivery claim skip
                            // the code before it could be sent.
                            ->numeric()->minValue(EmailVerificationService::MIN_TTL_MINUTES)->maxValue(60)->default(EmailVerificationService::CODE_TTL_MINUTES),
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
        // Server-side step-up assertion FIRST — before dehydrating form
        // state, before any guard, transaction, or write.
        if (! $this->assertStepUpForAction()) {
            return;
        }

        try {
            $data = $this->form->getState();
        } catch (ValidationException $e) {
            // A failed validation round-trip must not echo a typed plaintext
            // password back into the Livewire snapshot.
            $this->clearTransientSecretState();
            throw $e;
        }

        // Extract the password replacement, then IMMEDIATELY drop it from
        // component state — it lives in this method's scope only.
        $newPassword = (string) ($data['smtp_password_new'] ?? '');
        $this->clearTransientSecretState();

        if (! $this->persistSmtpSettings($data, $newPassword)) {
            return;
        }

        $enabled = ! empty($data['email_verification_enabled']);
        $requireOnRegister = ! empty($data['email_verification_required_on_register']);

        // The mail guards below apply only when required mode will actually be
        // ACTIVE (enabled AND required). A leftover required=true must never
        // block turning the feature OFF — e.g. disabling verification during a
        // mail outage, when the proof has expired and could not be renewed.
        $requiredWillBeActive = $enabled && $requireOnRegister;

        // ── Timing guard: a multi-leaf composite gets its OWN explanation —
        //    the generic "unconfigured" wording would mislead an admin whose
        //    SMTP settings are actually fine.
        if ($requiredWillBeActive && $this->multiLeafMailer()) {
            Notification::make()->title(self::MULTI_LEAF_MESSAGE)->danger()->send();

            return;
        }

        // ── Validation guard: never allow REQUIRED verification while the
        //    mailer is clearly unconfigured (users could never receive codes).
        if ($requiredWillBeActive && ! $this->mailLooksConfigured()) {
            Notification::make()
                ->title('برای اجباری کردن تایید ایمیل، ابتدا باید پیکربندی مؤثر ایمیل کامل و قابل استفاده باشد — تنظیمات SMTP پنل در صورت فعال بودن، وگرنه تنظیمات .env سرور. mailer فعلی: '.$this->mailerName())
                ->danger()->send();

            return;
        }

        // ── Proof guard: a configuration can LOOK usable (host/port present)
        //    while no SMTP server answers there. Required mode additionally
        //    demands a recent SUCCESSFUL test through the CURRENT
        //    configuration (fingerprint-matched), so admins can never lock
        //    new users behind an unproven transport.
        if ($requiredWillBeActive && ! $this->mailTestVerified()) {
            Notification::make()
                ->title('برای اجباری کردن تایید ایمیل، ابتدا باید یک «ارسال ایمیل تست» موفق با همین پیکربندی انجام شود.')
                ->danger()->send();

            return;
        }

        // (No global "required since" timestamp: the registration transaction
        // stamps every NEW account with the effective policy at that moment —
        // an immutable per-user marker the middleware enforces.)
        //
        // ONE transaction: the enabled/required pair (and the OTP rules) commit
        // atomically, so a concurrent registration can never be stamped from a
        // half-applied policy — enabled from the new save, required from the
        // old one. (The service reads the pair back under shared locks in
        // THIS same key order — enabled, then required — so the two
        // transactions always acquire the rows in one deterministic order
        // and can serialize but never deadlock.)
        DB::transaction(function () use ($data, $enabled, $requireOnRegister): void {
            SiteSetting::set('email_verification_enabled', $enabled ? 'true' : 'false');
            SiteSetting::set('email_verification_required_on_register', $requireOnRegister ? 'true' : 'false');
            SiteSetting::set('email_otp_ttl_minutes', (int) ($data['email_otp_ttl_minutes'] ?? EmailVerificationService::CODE_TTL_MINUTES));
            SiteSetting::set('email_otp_max_attempts', (int) ($data['email_otp_max_attempts'] ?? EmailVerificationService::MAX_ATTEMPTS));
            SiteSetting::set('email_otp_resend_cooldown_seconds', (int) ($data['email_otp_resend_cooldown_seconds'] ?? EmailVerificationService::RESEND_COOLDOWN_SEC));
            SiteSetting::set('email_otp_daily_cap', (int) ($data['email_otp_daily_cap'] ?? EmailVerificationService::DAILY_CAP));
        });

        AdminSecurityAudit::record('email_settings_changed', $this->stepUpUser(), 'success');

        Notification::make()->title('تنظیمات ذخیره شد.')->success()->send();
    }

    /**
     * Persist the SMTP transport settings ATOMICALLY with the proof
     * invalidation: when the save semantically changes the EFFECTIVE mail
     * configuration (fingerprint change — host, port, security, credentials,
     * From identity, timeout, EHLO domain, or the override source), the
     * stored transport-test proof is cleared in the SAME transaction; an
     * identical save leaves a still-valid proof untouched. The new runtime
     * configuration is applied immediately (workers pick it up before their
     * next job via the queue hook). Returns false when the save was refused.
     */
    private function persistSmtpSettings(array $data, string $newPassword): bool
    {
        $overrideEnabled = ! empty($data['smtp_override_enabled']);

        $row = EmailTransportSetting::instanceOrNew();
        $wasEnabled = $row->exists && (bool) $row->getRawOriginal('enabled');

        $str = static fn ($v): ?string => trim((string) ($v ?? '')) === '' ? null : trim((string) $v);
        $int = static fn ($v): ?int => ($v === null || $v === '') ? null : (int) $v;

        $row->fill([
            'enabled' => $overrideEnabled,
            'host' => $str($data['smtp_host'] ?? null),
            'port' => $int($data['smtp_port'] ?? null),
            'security' => $str($data['smtp_security'] ?? null),
            'from_address' => $str($data['smtp_from_address'] ?? null),
            'from_name' => $str($data['smtp_from_name'] ?? null),
            'timeout' => $int($data['smtp_timeout'] ?? null),
            'local_domain' => $str($data['smtp_local_domain'] ?? null),
        ]);
        // Secrets go through the explicit setters ONLY — never mass
        // assignment. A blank password preserves the stored one.
        $row->setUsernameSecret($str($data['smtp_username'] ?? null));
        if ($newPassword !== '') {
            $row->setPasswordSecret($newPassword);
        }

        // Incomplete drafts may be SAVED only while the override stays
        // disabled; ENABLING demands a structurally valid host, port,
        // supported security value, From address, and timeout. (Filament's
        // conditional `required` rules mirror this client-side; this is the
        // authoritative server-side check.)
        if ($overrideEnabled && ! $this->transportService()->rowLooksStructurallyValid($row)) {
            Notification::make()
                ->title('برای فعال کردن تنظیمات SMTP پنل، هاست، پورت (۱ تا ۶۵۵۳۵)، نوع امنیت (smtp یا smtps)، آدرس فرستنده معتبر و زمان انتظار (۱ تا ۲۰ ثانیه) لازم است.')
                ->danger()->send();

            return false;
        }

        $smtpChanged = $row->isDirty() || ! $row->exists;

        $this->commitTransportChange(function () use ($row): void {
            $row->save();
        });

        if ($smtpChanged) {
            AdminSecurityAudit::record('smtp_settings_changed', $this->stepUpUser(), 'success');
        }
        if ($overrideEnabled !== $wasEnabled) {
            AdminSecurityAudit::record(
                $overrideEnabled ? 'smtp_override_enabled' : 'smtp_override_disabled',
                $this->stepUpUser(),
                'success',
            );
        }

        return true;
    }

    /**
     * Run a transport mutation + proof-invalidation pair in ONE database
     * transaction, then leave the RUNTIME configuration consistent with the
     * database whether the transaction committed or rolled back.
     */
    private function commitTransportChange(callable $mutate): void
    {
        $verification = app(EmailVerificationService::class);
        $transport = $this->transportService();

        try {
            DB::transaction(function () use ($mutate, $verification, $transport): void {
                // The fingerprint of the configuration in force BEFORE this
                // change (bootstrap/mount applied the persisted state).
                $oldFingerprint = $verification->mailConfigFingerprint();

                $mutate();

                // Apply the new effective configuration NOW so the new
                // fingerprint is computed against it — and so this very
                // process (and, via the queue hook, every worker) uses it
                // for all subsequent mail operations.
                $transport->apply();

                // Semantic change ⇒ the old transport-test proof no longer
                // certifies the effective configuration: revoke it in the
                // SAME transaction as the settings write. An identical save
                // produces an identical fingerprint and touches nothing.
                if (! hash_equals($oldFingerprint, $verification->mailConfigFingerprint())) {
                    SiteSetting::set('email_mail_test_fingerprint', '');
                    SiteSetting::set('email_mail_test_verified_at', '');
                }
            });
        } catch (\Throwable $e) {
            // The transaction rolled back but apply() may already have
            // mutated in-memory config — re-resolve from the (unchanged)
            // database so runtime state never reflects an uncommitted row.
            $transport->apply();

            throw $e;
        }
    }

    /** Confirmed, step-up-guarded removal of the stored SMTP password. */
    public function clearSmtpPasswordAction(): Action
    {
        return Action::make('clearSmtpPassword')
            ->label('حذف رمز ذخیره‌شده SMTP')
            ->color('danger')
            ->icon('heroicon-o-trash')
            ->requiresConfirmation()
            ->modalHeading('حذف رمز عبور ذخیره‌شده SMTP')
            ->modalDescription('رمز عبور رمزنگاری‌شده حذف می‌شود. اگر تنظیمات پنل فعال باشد، تا وارد کردن رمز جدید ممکن است ارسال ایمیل مختل شود.')
            ->action(function (): void {
                // Step-up re-asserted immediately before touching the secret.
                if (! $this->assertStepUpForAction()) {
                    return;
                }

                $row = EmailTransportSetting::instance();
                if ($row === null || ! $row->hasStoredPassword()) {
                    Notification::make()->title('رمز ذخیره‌شده‌ای وجود ندارد.')->warning()->send();

                    return;
                }

                $this->commitTransportChange(function () use ($row): void {
                    $row->setPasswordSecret(null);
                    $row->save();
                });

                AdminSecurityAudit::record('smtp_password_cleared', $this->stepUpUser(), 'success');

                Notification::make()->title('رمز عبور ذخیره‌شده حذف شد.')->success()->send();
            });
    }

    /** Drop the in-memory plaintext password on any step-up denial. */
    protected function clearTransientSecretState(): void
    {
        $this->data['smtp_password_new'] = null;
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
                // Step-up re-asserted immediately before anything else — an
                // expired grant sends nothing and touches nothing.
                if (! $this->assertStepUpForAction()) {
                    return;
                }

                // The test certifies the PERSISTED effective configuration —
                // never unsaved form values. Idempotent re-apply.
                $this->transportService()->apply();

                AdminSecurityAudit::record('test_email_requested', $this->stepUpUser(), 'success');

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

                // A multi-leaf composite can never be certified for OTP
                // delivery (single-exchange time budget) — refuse with the
                // dedicated explanation instead of a misleading generic one.
                if ($this->multiLeafMailer()) {
                    Notification::make()->title(self::MULTI_LEAF_MESSAGE)->danger()->send();

                    return;
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
                    // verdict. EVERY delivery leaf of the mailer graph is
                    // exercised: a composite (failover/roundrobin) routes
                    // different sends to different children, and one healthy
                    // child accepting a single test proves nothing about the
                    // siblings real OTPs may be routed to. Never includes
                    // secrets.
                    $leaves = array_keys(app(EmailVerificationService::class)->effectiveLeafMailers() ?? []);
                    foreach ($leaves as $leafMailer) {
                        Mail::mailer($leafMailer)
                            ->to((string) $data['test_email'])
                            ->send(new TestEmailMail);
                    }

                    // The transport accepted the message: record the NON-SECRET
                    // proof (config fingerprint + timestamp) that required
                    // mode's save-guard and runtime enforcement depend on.
                    app(EmailVerificationService::class)->recordSuccessfulMailTest();

                    // HONEST wording: the transport ACCEPTED the message —
                    // inbox delivery can never be confirmed from here.
                    Notification::make()
                        ->title(count($leaves) > 1
                            ? 'همه '.count($leaves).' مسیر ارسال پیام تست را پذیرفتند. رسیدن به صندوق ورودی را در مقصد بررسی کنید.'
                            : 'سرور ایمیل پیام تست را پذیرفت. رسیدن به صندوق ورودی را در مقصد بررسی کنید.')
                        ->success()->send();
                } catch (\Throwable $e) {
                    // NEVER show or log raw transport text (it can echo SMTP
                    // credentials) — only the sanitized category.
                    $safe = MailFailure::summarize('test email failed', $e);
                    Log::warning('Admin test email failed', ['error' => $safe]);

                    // A FAILED certification against the current endpoint is
                    // negative evidence: revoke the stored proof so required
                    // mode pauses immediately instead of trusting a
                    // historical success until three OTP jobs finish failing.
                    // A recipient-specific bounce proves nothing about the
                    // endpoint and keeps the proof.
                    if (MailFailure::categorize($e) !== 'recipient_rejected') {
                        SiteSetting::set('email_mail_test_fingerprint', '');
                        SiteSetting::set('email_mail_test_verified_at', '');
                    }
                    Notification::make()
                        ->title('ارسال ایمیل تست ناموفق بود ('.MailFailure::categorize($e).'). جزئیات در لاگ سرور ثبت شد.')
                        ->danger()->send();
                }
            });
    }
}
