<?php

namespace App\Filament\Pages;

use App\Models\SiteSetting;
use App\Models\TelegramAdminTopic;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramSettings;
use App\Services\Telegram\TelegramTemplates;
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
 * تنظیمات بات تلگرام — admin-only management bot settings. The bot token is
 * stored encrypted (via TelegramSettings) and is NEVER re-displayed: the form
 * only shows a placeholder, and the value is overwritten only when a new one is
 * entered.
 */
class TelegramSettingsPage extends Page implements HasForms, HasActions
{
    use InteractsWithForms;
    use InteractsWithActions;

    protected static string $view = 'filament.pages.telegram-settings';

    protected static ?string $navigationIcon  = 'heroicon-o-paper-airplane';
    protected static ?string $navigationGroup = 'اعلان‌ها و پیام‌ها';
    protected static ?string $navigationLabel = 'تنظیمات بات تلگرام';
    protected static ?string $title           = 'تنظیمات بات مدیریت تلگرام';
    protected static ?string $slug            = 'telegram/settings';
    protected static ?int    $navigationSort  = 30;

    /** @var array<string,mixed> */
    public array $data = [];

    protected function settings(): TelegramSettings
    {
        return app(TelegramSettings::class);
    }

    public function mount(): void
    {
        // Make sure topics + templates exist so the rest of the panel works.
        TelegramAdminTopic::seedDefaults();
        app(TelegramTemplates::class)->seedDefaults();

        $s = $this->settings();

        $categories = [];
        foreach (TelegramSettings::CATEGORIES as $cat) {
            $categories['cat_' . $cat] = $s->categoryEnabled($cat);
        }

        $this->form->fill(array_merge([
            'telegram_admin_enabled'        => $s->enabled(),
            'telegram_bot_token_new'        => null,
            'telegram_admin_chat_id'        => $s->chatId(),
            'telegram_admin_user_ids'       => (string) SiteSetting::get('telegram_admin_user_ids', ''),
            'telegram_parse_mode'           => $s->parseMode(),
            'telegram_silent'               => $s->silent(),
            'telegram_rate_limit_duplicates' => $s->rateLimitDuplicates(),
        ], $categories));
    }

    public function form(Form $form): Form
    {
        $catToggles = [];
        foreach (TelegramSettings::CATEGORIES as $cat) {
            $catToggles[] = Forms\Components\Toggle::make('cat_' . $cat)
                ->label($this->categoryLabel($cat))
                ->default(true)->inline(false);
        }

        return $form
            ->schema([
                Forms\Components\Section::make('اتصال بات')
                    ->schema([
                        Forms\Components\Toggle::make('telegram_admin_enabled')
                            ->label('فعال بودن بات مدیریت')->default(false),

                        Forms\Components\TextInput::make('telegram_bot_token_new')
                            ->label('توکن بات (Bot Token)')
                            ->password()->revealable()->autocomplete('new-password')
                            ->placeholder($this->settings()->hasToken() ? '•••••••• (برای تغییر، توکن جدید وارد کنید)' : 'توکن بات را وارد کنید')
                            ->helperText('به‌صورت رمزنگاری‌شده ذخیره می‌شود و دیگر نمایش داده نمی‌شود.')
                            ->dehydrated(),

                        Forms\Components\TextInput::make('telegram_admin_chat_id')
                            ->label('شناسه گروه مدیریت (Chat ID)')
                            ->placeholder('-100xxxxxxxxxx')
                            ->helperText('گروه باید Forum/Topics فعال داشته باشد.'),

                        Forms\Components\Textarea::make('telegram_admin_user_ids')
                            ->label('شناسه‌های ادمین مجاز تلگرام')
                            ->rows(2)
                            ->placeholder('123456789, 987654321')
                            ->helperText('برای فاز دستورات بات (با کاما یا فاصله جدا کنید).')
                            ->columnSpanFull(),
                    ])->columns(2),

                Forms\Components\Section::make('رفتار ارسال')
                    ->schema([
                        Forms\Components\Select::make('telegram_parse_mode')
                            ->label('حالت قالب‌بندی')
                            ->options([TelegramSettings::PARSE_HTML => 'HTML', TelegramSettings::PARSE_MARKDOWN_V2 => 'MarkdownV2'])
                            ->default(TelegramSettings::PARSE_HTML),
                        Forms\Components\Toggle::make('telegram_silent')
                            ->label('ارسال بی‌صدا')->default(false),
                        Forms\Components\Toggle::make('telegram_rate_limit_duplicates')
                            ->label('محدودسازی هشدارهای تکراری پر سروصدا')->default(true),
                    ])->columns(3),

                Forms\Components\Section::make('دسته‌بندی‌های فعال')
                    ->description('هر دسته به تاپیک خودش ارسال می‌شود.')
                    ->schema($catToggles)
                    ->columns(3),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        SiteSetting::set('telegram_admin_enabled', ! empty($data['telegram_admin_enabled']) ? 'true' : 'false');
        SiteSetting::set('telegram_admin_chat_id', (string) ($data['telegram_admin_chat_id'] ?? ''));
        SiteSetting::set('telegram_admin_user_ids', (string) ($data['telegram_admin_user_ids'] ?? ''));
        SiteSetting::set('telegram_parse_mode', $data['telegram_parse_mode'] ?? TelegramSettings::PARSE_HTML);
        SiteSetting::set('telegram_silent', ! empty($data['telegram_silent']) ? 'true' : 'false');
        SiteSetting::set('telegram_rate_limit_duplicates', ! empty($data['telegram_rate_limit_duplicates']) ? 'true' : 'false');

        foreach (TelegramSettings::CATEGORIES as $cat) {
            $this->settings()->setCategoryEnabled($cat, ! empty($data['cat_' . $cat]));
        }

        // Only overwrite the token when a new value was entered.
        if (filled($data['telegram_bot_token_new'] ?? null)) {
            $this->settings()->storeToken((string) $data['telegram_bot_token_new']);
        }
        $this->data['telegram_bot_token_new'] = null;

        Notification::make()->title('تنظیمات تلگرام ذخیره شد.')->success()->send();
    }

    // ── Actions ──────────────────────────────────────────────────────────────

    public function testConnectionAction(): Action
    {
        return Action::make('testConnection')
            ->label('تست اتصال (getMe)')->color('gray')->icon('heroicon-o-signal')
            ->action(function () {
                try {
                    $me = app(TelegramClient::class)->getMe();
                    Notification::make()->title('اتصال موفق: @' . ($me['username'] ?? '—'))->success()->send();
                } catch (\Throwable $e) {
                    Notification::make()->title('اتصال ناموفق: ' . $e->getMessage())->danger()->send();
                }
            });
    }

    public function getChatAction(): Action
    {
        return Action::make('getChat')
            ->label('اطلاعات گروه (getChat)')->color('gray')->icon('heroicon-o-information-circle')
            ->action(function () {
                try {
                    $chat = app(TelegramClient::class)->getChat();
                    $name = $chat['title'] ?? $chat['username'] ?? (string) ($chat['id'] ?? '—');
                    Notification::make()->title('گروه: ' . $name . ' (نوع: ' . ($chat['type'] ?? '—') . ')')->success()->send();
                } catch (\Throwable $e) {
                    Notification::make()->title('دریافت اطلاعات گروه ناموفق: ' . $e->getMessage())->danger()->send();
                }
            });
    }

    public function sendTestAction(): Action
    {
        return Action::make('sendTest')
            ->label('ارسال پیام تست')->color('primary')->icon('heroicon-o-paper-airplane')
            ->action(function () {
                try {
                    app(TelegramClient::class)->sendMessage('✅ پیام تست از زدپروکسی — اتصال بات مدیریت برقرار است.');
                    Notification::make()->title('پیام تست ارسال شد.')->success()->send();
                } catch (\Throwable $e) {
                    Notification::make()->title('ارسال پیام تست ناموفق: ' . $e->getMessage())->danger()->send();
                }
            });
    }

    public function sendTestPerTopicAction(): Action
    {
        return Action::make('sendTestPerTopic')
            ->label('ارسال تست به همه تاپیک‌ها')->color('gray')->icon('heroicon-o-queue-list')
            ->requiresConfirmation()
            ->action(function () {
                $client = app(TelegramClient::class);
                $ok = 0; $fail = 0;
                foreach (TelegramAdminTopic::where('is_active', true)->get() as $topic) {
                    try {
                        $client->sendMessage('✅ تست تاپیک: ' . $topic->title, $topic->message_thread_id);
                        $ok++;
                    } catch (\Throwable $e) {
                        $fail++;
                    }
                }
                Notification::make()->title("ارسال تست تاپیک‌ها — موفق: {$ok} / ناموفق: {$fail}")
                    ->{$fail === 0 ? 'success' : 'warning'}()->send();
            });
    }

    public function registerWebhookAction(): Action
    {
        return Action::make('registerWebhook')
            ->label('ثبت Webhook')->color('primary')->icon('heroicon-o-link')
            ->requiresConfirmation()
            ->modalDescription('یک توکن مخفی جدید ساخته و Webhook روی آدرس سایت ثبت می‌شود.')
            ->action(function () {
                try {
                    $secret = $this->settings()->rotateWebhookSecret();
                    app(TelegramClient::class)->setWebhook(route('telegram.webhook'), $secret);
                    Notification::make()->title('Webhook با موفقیت ثبت شد.')->success()->send();
                } catch (\Throwable $e) {
                    Notification::make()->title('ثبت Webhook ناموفق: ' . $e->getMessage())->danger()->send();
                }
            });
    }

    public function deleteWebhookAction(): Action
    {
        return Action::make('deleteWebhook')
            ->label('حذف Webhook')->color('danger')->icon('heroicon-o-trash')
            ->requiresConfirmation()
            ->action(function () {
                try {
                    app(TelegramClient::class)->deleteWebhook();
                    Notification::make()->title('Webhook حذف شد.')->success()->send();
                } catch (\Throwable $e) {
                    Notification::make()->title('حذف Webhook ناموفق: ' . $e->getMessage())->danger()->send();
                }
            });
    }

    public function webhookStatusAction(): Action
    {
        return Action::make('webhookStatus')
            ->label('وضعیت Webhook')->color('gray')->icon('heroicon-o-information-circle')
            ->action(function () {
                try {
                    $info = app(TelegramClient::class)->getWebhookInfo();
                    // NEVER show the secret — only safe status fields.
                    $url     = $info['url'] ?? '—';
                    $pending = (int) ($info['pending_update_count'] ?? 0);
                    $lastErr = $info['last_error_message'] ?? '—';
                    Notification::make()
                        ->title('وضعیت Webhook')
                        ->body("آدرس: " . ($url !== '' ? 'ثبت‌شده' : 'ثبت‌نشده') . " — در صف: {$pending} — آخرین خطا: {$lastErr}")
                        ->info()->send();
                } catch (\Throwable $e) {
                    Notification::make()->title('دریافت وضعیت ناموفق: ' . $e->getMessage())->danger()->send();
                }
            });
    }

    private function categoryLabel(string $cat): string
    {
        return [
            'sales' => 'فروش و سفارش‌ها', 'payments' => 'پرداخت‌ها', 'wallet' => 'کیف پول',
            'tickets' => 'تیکت‌ها', 'users' => 'کاربران', 'services' => 'سرویس‌ها',
            'panels' => 'پنل‌های VPN', 'errors' => 'خطاها', 'representatives' => 'نمایندگان',
            'admin' => 'تغییرات ادمین', 'system' => 'سیستم',
        ][$cat] ?? $cat;
    }
}
