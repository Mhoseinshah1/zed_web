<?php

namespace App\Filament\Pages;

use App\Jobs\RunBackupJob;
use App\Models\BackupLog;
use App\Models\SiteSetting;
use App\Services\Backup\BackupFailure;
use App\Services\Backup\BackupPathPolicy;
use App\Services\Backup\BackupScheduler;
use App\Services\Backup\BackupService;
use App\Services\Backup\BackupSettings;
use App\Services\Telegram\TelegramAdminNotifier;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;

/**
 * بکاپ و سرور — server backup settings + manual run + last status.
 * The optional archive password is stored encrypted and never re-displayed.
 */
class BackupSettingsPage extends Page implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    protected static string $view = 'filament.pages.backup-settings';

    protected static ?string $navigationIcon = 'heroicon-o-server-stack';

    protected static ?string $navigationGroup = 'اعلان‌ها و پیام‌ها';

    protected static ?string $navigationLabel = 'بکاپ و سرور';

    protected static ?string $title = 'بکاپ سرور';

    protected static ?string $slug = 'backup/settings';

    protected static ?int $navigationSort = 40;

    /** @var array<string,mixed> */
    public array $data = [];

    protected function settings(): BackupSettings
    {
        return app(BackupSettings::class);
    }

    public function mount(): void
    {
        $s = $this->settings();
        $this->form->fill([
            'backup_enabled' => $s->enabled(),
            'backup_auto_enabled' => $s->autoEnabled(),
            'backup_schedule_mode' => $s->scheduleMode(),
            'backup_interval_minutes' => $s->intervalMinutes(),
            'backup_schedule_time' => $s->scheduleTime(),
            'backup_retention_days' => $s->retentionDays(),
            'backup_storage_path' => (string) SiteSetting::get('backup_storage_path', ''),
            'backup_include_database' => $s->includeDatabase(),
            'backup_include_storage' => $s->includeStorage(),
            'backup_include_uploads' => $s->includeUploads(),
            'backup_include_project_files' => $s->includeProjectFiles(),
            'backup_exclude_sensitive_files' => $s->excludeSensitive(),
            'backup_encrypt_enabled' => $s->encryptEnabled(),
            'backup_password_new' => null,
            'backup_send_file_to_telegram' => $s->sendFileToTelegram(),
            'backup_send_report_to_telegram' => $s->sendReportToTelegram(),
            'backup_max_telegram_file_size_mb' => $s->maxTelegramFileMb(),
            'daily_report_enabled' => (bool) SiteSetting::get('daily_report_enabled', false),
            'daily_report_time' => (string) SiteSetting::get('daily_report_time', '21:00'),
        ]);
    }

    public function lastBackup(): ?BackupLog
    {
        return BackupLog::latestLog();
    }

    /** Next automatic run for the status panel (null = auto backup off). */
    public function nextRunAt(): ?Carbon
    {
        return app(BackupScheduler::class)->nextRunAt();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('فعال‌سازی و زمان‌بندی')->schema([
                Forms\Components\Toggle::make('backup_enabled')->label('فعال بودن سیستم بکاپ')->default(false)
                    ->helperText('کلید اصلی. در حالت غیرفعال، بکاپ دستی و خودکار و دستور تلگرام هیچ‌کدام اجرا نمی‌شوند.'),
                Forms\Components\Toggle::make('backup_auto_enabled')->label('فعال بودن بکاپ خودکار')->default(false)
                    ->helperText('فقط بکاپ زمان‌بندی‌شده را کنترل می‌کند؛ بکاپ دستی همچنان ممکن است.'),

                Forms\Components\Select::make('backup_schedule_mode')->label('نوع زمان‌بندی بکاپ')
                    ->options([
                        BackupSettings::MODE_FIXED_TIME => 'در ساعت مشخص (روزانه)',
                        BackupSettings::MODE_INTERVAL => 'هر چند دقیقه/ساعت یک‌بار',
                    ])
                    ->default(BackupSettings::MODE_FIXED_TIME)->native(false)->live(),

                Forms\Components\TextInput::make('backup_schedule_time')->label('ساعت اجرای بکاپ (HH:MM)')->default('03:00')
                    ->visible(fn (Forms\Get $get) => $get('backup_schedule_mode') !== BackupSettings::MODE_INTERVAL),

                Forms\Components\TextInput::make('backup_interval_minutes')->label('فاصله اجرای بکاپ (دقیقه)')
                    ->numeric()->minValue($this->settings()->minIntervalMinutes())->default(60)
                    ->visible(fn (Forms\Get $get) => $get('backup_schedule_mode') === BackupSettings::MODE_INTERVAL)
                    ->helperText('مثال: ۵، ۱۵، ۳۰، ۶۰، ۳۶۰، ۷۲۰، ۱۴۴۰. حداقل '.$this->settings()->minIntervalMinutes()
                        .' دقیقه. بکاپ با فاصله زمانی خیلی کوتاه ممکن است فشار زیادی به سرور وارد کند.'),

                Forms\Components\TextInput::make('backup_retention_days')->label('روزهای نگهداری بکاپ')->numeric()->minValue(1)->default(7),
                Forms\Components\TextInput::make('backup_storage_path')->label('مسیر ذخیره بکاپ (اختیاری)')
                    ->placeholder('پیش‌فرض: storage/app/backups')->columnSpanFull()
                    ->helperText('باید یک مسیر مطلق باشد (با / شروع شود). برای استفاده از مسیر پیش‌فرض خالی بگذارید.')
                    ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                        try {
                            // Raw value in — the policy rejects control
                            // characters BEFORE any trimming/normalization.
                            app(BackupPathPolicy::class)->normalizeForStorage((string) $value);
                        } catch (BackupFailure $e) {
                            $fail($e->publicMessage());
                        }
                    }),
            ])->columns(2),

            Forms\Components\Section::make('محتوای بکاپ')->schema([
                Forms\Components\Toggle::make('backup_include_database')->label('پایگاه داده (pg_dump)')->default(true),
                Forms\Components\Toggle::make('backup_include_storage')->label('فایل‌های storage')->default(true),
                Forms\Components\Toggle::make('backup_include_uploads')->label('آپلودهای کاربران')->default(true),
                Forms\Components\Toggle::make('backup_include_project_files')->label('فایل‌های پروژه (app/resources)')->default(false),
                Forms\Components\Toggle::make('backup_exclude_sensitive_files')->label('حذف فایل‌های حساس (.env، کلیدها، اسرار)')
                    ->default(true)->disabled()->dehydrated()
                    ->helperText('همیشه فعال است؛ فایل‌های حساس هرگز در بکاپ قرار نمی‌گیرند.'),
            ])->columns(2),

            Forms\Components\Section::make('رمزنگاری')->schema([
                Forms\Components\Toggle::make('backup_encrypt_enabled')->label('رمزگذاری فایل بکاپ')->live()->default(false),
                Forms\Components\TextInput::make('backup_password_new')->label('رمز عبور بکاپ')
                    ->password()->revealable()->autocomplete('new-password')
                    ->placeholder($this->settings()->hasPassword() ? '•••••••• (برای تغییر مقدار جدید وارد کنید)' : 'رمز عبور را وارد کنید')
                    ->helperText('رمزنگاری‌شده ذخیره می‌شود و دیگر نمایش داده نمی‌شود.')
                    ->visible(fn (Forms\Get $get) => $get('backup_encrypt_enabled'))->dehydrated(),
            ])->columns(2),

            Forms\Components\Section::make('ارسال به تلگرام')->schema([
                Forms\Components\Toggle::make('backup_send_report_to_telegram')->label('ارسال گزارش به تلگرام')->default(true),
                Forms\Components\Toggle::make('backup_send_file_to_telegram')->label('ارسال فایل بکاپ به تلگرام')->default(false),
                Forms\Components\TextInput::make('backup_max_telegram_file_size_mb')->label('حداکثر حجم فایل تلگرام (مگابایت)')
                    ->numeric()->minValue(1)->maxValue(50)->default(50)
                    ->helperText('فایل بزرگ‌تر از این مقدار ارسال نمی‌شود؛ فقط گزارش ارسال می‌گردد.'),
            ])->columns(3),

            Forms\Components\Section::make('گزارش روزانه')->schema([
                Forms\Components\Toggle::make('daily_report_enabled')->label('ارسال گزارش روزانه')->default(false),
                Forms\Components\TextInput::make('daily_report_time')->label('ساعت گزارش روزانه (HH:MM)')->default('21:00'),
            ])->columns(2),
        ])->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        // ── Phase 1: EVERYTHING that can fail happens BEFORE any write ─────
        // A failure in this phase aborts the save with nothing persisted, so
        // the previous settings state stays fully intact.

        // FAIL-CLOSED ENCRYPTION ACTIVATION: turning encryption on requires a
        // usable password. First activation (or activation while the stored
        // ciphertext is unreadable) is refused unless a new password is
        // submitted in the same save — "enabled without a password" can never
        // be stored. An existing valid password keeps working without
        // re-entry, and this never clears the toggle or overwrites a stored
        // password on its own.
        if (! empty($data['backup_encrypt_enabled'])
            && ! filled($data['backup_password_new'] ?? null)
            && $this->settings()->passwordState() !== BackupSettings::PASSWORD_OK) {
            Notification::make()
                ->title('برای فعال‌کردن رمزگذاری بکاپ، ابتدا یک رمز عبور معتبر وارد و همراه همین ذخیره ثبت کنید.')
                ->danger()->send();

            return;
        }

        // Same authoritative policy the runtime uses: the RAW submitted value
        // goes in (control characters are rejected before any trimming) and
        // either '' (use default) or the normalized absolute path comes out.
        try {
            $storedPath = app(BackupPathPolicy::class)->normalizeForStorage((string) ($data['backup_storage_path'] ?? ''));
        } catch (BackupFailure $e) {
            Notification::make()->title($e->publicMessage())->danger()->send();

            return;
        }

        // Precompute the new password ciphertext BEFORE any setting is
        // written: if encryption of the submitted password fails, the save
        // aborts here and neither the toggle nor any other setting moves —
        // "encryption enabled without usable stored credentials" cannot be
        // produced by a partial save.
        $newPasswordCiphertext = null;
        if (filled($data['backup_password_new'] ?? null)) {
            try {
                $newPasswordCiphertext = $this->settings()->encryptPassword((string) $data['backup_password_new']);
            } catch (\Throwable) {
                Notification::make()
                    ->title('ذخیره رمز عبور بکاپ ممکن نشد؛ هیچ تنظیمی تغییر نکرد. دوباره تلاش کنید.')
                    ->danger()->send();

                return;
            }
        }

        // ── Phase 2: writes only (password FIRST, then the toggle) ─────────
        if ($newPasswordCiphertext !== null) {
            SiteSetting::set('backup_password', $newPasswordCiphertext);
        }

        foreach ([
            'backup_enabled', 'backup_auto_enabled', 'backup_include_database', 'backup_include_storage',
            'backup_include_uploads', 'backup_include_project_files', 'backup_encrypt_enabled',
            'backup_send_file_to_telegram', 'backup_send_report_to_telegram', 'daily_report_enabled',
        ] as $bool) {
            SiteSetting::set($bool, ! empty($data[$bool]) ? 'true' : 'false');
        }
        // Sensitive-file exclusion is always enforced.
        SiteSetting::set('backup_exclude_sensitive_files', 'true');

        $mode = ($data['backup_schedule_mode'] ?? '') === BackupSettings::MODE_INTERVAL
            ? BackupSettings::MODE_INTERVAL : BackupSettings::MODE_FIXED_TIME;
        SiteSetting::set('backup_schedule_mode', $mode);
        SiteSetting::set('backup_interval_minutes', (int) max(
            $this->settings()->minIntervalMinutes(),
            (int) ($data['backup_interval_minutes'] ?? 60),
        ));
        SiteSetting::set('backup_schedule_time', $this->validTime($data['backup_schedule_time'] ?? '03:00', '03:00'));
        SiteSetting::set('backup_retention_days', (int) max(1, (int) ($data['backup_retention_days'] ?? 7)));
        SiteSetting::set('backup_storage_path', $storedPath);
        $this->data['backup_storage_path'] = $storedPath;
        SiteSetting::set('backup_max_telegram_file_size_mb', (int) max(1, min(50, (int) ($data['backup_max_telegram_file_size_mb'] ?? 50))));
        SiteSetting::set('daily_report_time', $this->validTime($data['daily_report_time'] ?? '21:00', '21:00'));

        $this->data['backup_password_new'] = null;

        Notification::make()->title('تنظیمات بکاپ ذخیره شد.')->success()->send();
    }

    public function runBackupAction(): Action
    {
        return Action::make('runBackup')
            ->label('اجرای بکاپ دستی')->color('primary')->icon('heroicon-o-play')
            ->requiresConfirmation()
            ->action(function () {
                if (! $this->settings()->enabled()) {
                    Notification::make()->title('سیستم بکاپ در حال حاضر غیرفعال است.')->warning()->send();

                    return;
                }
                RunBackupJob::dispatch(BackupLog::TYPE_MANUAL);
                Notification::make()->title('بکاپ در صف اجرا قرار گرفت. نتیجه در تاپیک تلگرام ارسال می‌شود.')->success()->send();
            });
    }

    public function cleanupOldAction(): Action
    {
        return Action::make('cleanupOld')
            ->label('پاکسازی بکاپ‌های قدیمی')->color('warning')->icon('heroicon-o-trash')
            ->requiresConfirmation()
            ->modalDescription('بکاپ‌های قدیمی‌تر از دوره نگهداری حذف می‌شوند.')
            ->action(function () {
                try {
                    // Standalone cleanup goes through the SAME canonical-root
                    // resolver as backup runs (symlink→/ rejected, missing
                    // dir ⇒ nothing to clean).
                    $outcome = app(BackupService::class)->cleanupOld();
                } catch (BackupFailure $e) {
                    Notification::make()->title($e->publicMessage())->danger()->send();

                    return;
                }
                $note = Notification::make()->title("پاکسازی انجام شد ({$outcome['removed']} فایل حذف شد).");
                $outcome['complete']
                    ? $note->success()->send()
                    : $note->warning()->body('برخی فایل‌ها قابل حذف نبودند؛ جزئیات در لاگ سرور ثبت شد.')->send();
            });
    }

    public function sendTestReportAction(): Action
    {
        return Action::make('sendTestReport')
            ->label('ارسال گزارش تست به تلگرام')->color('gray')->icon('heroicon-o-paper-airplane')
            ->action(function () {
                $last = BackupLog::latestLog();
                $text = $last
                    ? "💾 آخرین بکاپ: {$last->status} — ".$last->updated_at->format('Y/m/d H:i')
                    : '💾 هنوز بکاپی انجام نشده است.';
                app(TelegramAdminNotifier::class)
                    ->send('backup_status', 'backup_server', 'وضعیت بکاپ', $text);
                Notification::make()->title('گزارش تست به تلگرام ارسال شد.')->success()->send();
            });
    }

    private function validTime(string $t, string $default): string
    {
        return preg_match('/^\d{2}:\d{2}$/', $t) ? $t : $default;
    }
}
