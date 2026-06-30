<?php

namespace App\Filament\Pages;

use App\Jobs\RunBackupJob;
use App\Models\BackupLog;
use App\Models\SiteSetting;
use App\Services\Backup\BackupSettings;
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
 * بکاپ و سرور — server backup settings + manual run + last status.
 * The optional archive password is stored encrypted and never re-displayed.
 */
class BackupSettingsPage extends Page implements HasForms, HasActions
{
    use InteractsWithForms;
    use InteractsWithActions;

    protected static string $view = 'filament.pages.backup-settings';

    protected static ?string $navigationIcon  = 'heroicon-o-server-stack';
    protected static ?string $navigationGroup = 'اعلان‌ها و پیام‌ها';
    protected static ?string $navigationLabel = 'بکاپ و سرور';
    protected static ?string $title           = 'بکاپ سرور';
    protected static ?string $slug            = 'backup/settings';
    protected static ?int    $navigationSort  = 40;

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
            'backup_enabled'                 => $s->enabled(),
            'backup_auto_enabled'            => $s->autoEnabled(),
            'backup_schedule_time'           => $s->scheduleTime(),
            'backup_retention_days'          => $s->retentionDays(),
            'backup_storage_path'            => (string) SiteSetting::get('backup_storage_path', ''),
            'backup_include_database'        => $s->includeDatabase(),
            'backup_include_storage'         => $s->includeStorage(),
            'backup_include_uploads'         => $s->includeUploads(),
            'backup_include_project_files'   => $s->includeProjectFiles(),
            'backup_exclude_sensitive_files' => $s->excludeSensitive(),
            'backup_encrypt_enabled'         => $s->encryptEnabled(),
            'backup_password_new'            => null,
            'backup_send_file_to_telegram'   => $s->sendFileToTelegram(),
            'backup_send_report_to_telegram' => $s->sendReportToTelegram(),
            'backup_max_telegram_file_size_mb' => $s->maxTelegramFileMb(),
            'daily_report_enabled'           => (bool) SiteSetting::get('daily_report_enabled', false),
            'daily_report_time'              => (string) SiteSetting::get('daily_report_time', '21:00'),
        ]);
    }

    public function lastBackup(): ?BackupLog
    {
        return BackupLog::latestLog();
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('فعال‌سازی و زمان‌بندی')->schema([
                Forms\Components\Toggle::make('backup_enabled')->label('فعال بودن بکاپ')->default(false),
                Forms\Components\Toggle::make('backup_auto_enabled')->label('بکاپ خودکار زمان‌بندی‌شده')->default(false),
                Forms\Components\TextInput::make('backup_schedule_time')->label('ساعت بکاپ روزانه (HH:MM)')->default('03:00'),
                Forms\Components\TextInput::make('backup_retention_days')->label('نگه‌داری (روز)')->numeric()->minValue(1)->default(7),
                Forms\Components\TextInput::make('backup_storage_path')->label('مسیر ذخیره (اختیاری)')->placeholder(storage_path('app/backups'))->columnSpanFull(),
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

        foreach ([
            'backup_enabled', 'backup_auto_enabled', 'backup_include_database', 'backup_include_storage',
            'backup_include_uploads', 'backup_include_project_files', 'backup_encrypt_enabled',
            'backup_send_file_to_telegram', 'backup_send_report_to_telegram', 'daily_report_enabled',
        ] as $bool) {
            SiteSetting::set($bool, ! empty($data[$bool]) ? 'true' : 'false');
        }
        // Sensitive-file exclusion is always enforced.
        SiteSetting::set('backup_exclude_sensitive_files', 'true');

        SiteSetting::set('backup_schedule_time', $this->validTime($data['backup_schedule_time'] ?? '03:00', '03:00'));
        SiteSetting::set('backup_retention_days', (int) max(1, (int) ($data['backup_retention_days'] ?? 7)));
        SiteSetting::set('backup_storage_path', (string) ($data['backup_storage_path'] ?? ''));
        SiteSetting::set('backup_max_telegram_file_size_mb', (int) max(1, min(50, (int) ($data['backup_max_telegram_file_size_mb'] ?? 50))));
        SiteSetting::set('daily_report_time', $this->validTime($data['daily_report_time'] ?? '21:00', '21:00'));

        if (filled($data['backup_password_new'] ?? null)) {
            $this->settings()->storePassword((string) $data['backup_password_new']);
        }
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
                    Notification::make()->title('ابتدا بکاپ را فعال و ذخیره کنید.')->warning()->send();
                    return;
                }
                RunBackupJob::dispatch(BackupLog::TYPE_MANUAL);
                Notification::make()->title('بکاپ در صف اجرا قرار گرفت. نتیجه در تاپیک تلگرام ارسال می‌شود.')->success()->send();
            });
    }

    private function validTime(string $t, string $default): string
    {
        return preg_match('/^\d{2}:\d{2}$/', $t) ? $t : $default;
    }
}
