<?php

namespace App\Filament\Pages;

use App\Models\SiteText;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * لینک‌ها و شبکه‌های اجتماعی — social/support links used in the header, footer,
 * dashboard support cards and contact sections. Stored in SiteText.
 */
class SocialLinksPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.pages.cms-settings';

    protected static ?string $navigationIcon  = 'heroicon-o-link';
    protected static ?string $navigationGroup = 'مدیریت محتوا';
    protected static ?string $navigationLabel = 'لینک‌ها و شبکه‌های اجتماعی';
    protected static ?string $title           = 'لینک‌ها و شبکه‌های اجتماعی';
    protected static ?string $slug            = 'content/social-links';
    protected static ?int    $navigationSort  = 95;

    /** @var array<string,mixed> */
    public array $data = [];

    public const KEYS = [
        'telegram_channel'      => 'نام کانال تلگرام',
        'telegram_channel_url'  => 'لینک کانال تلگرام',
        'telegram_support'      => 'نام پشتیبانی تلگرام',
        'telegram_support_url'  => 'لینک پشتیبانی تلگرام',
        'bot_url'               => 'لینک ربات',
        'instagram'             => 'نام اینستاگرام',
        'instagram_url'         => 'لینک اینستاگرام',
        'youtube'               => 'نام یوتیوب',
        'youtube_url'           => 'لینک یوتیوب',
        'website_url'           => 'لینک وب‌سایت',
        'support_url'           => 'لینک پشتیبانی',
        'status_channel'        => 'لینک کانال وضعیت',
    ];

    public function mount(): void
    {
        $state = [];
        foreach (array_keys(self::KEYS) as $key) {
            $state[$key] = SiteText::get($key, '');
        }
        $this->form->fill($state);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('تلگرام')->schema([
                Forms\Components\TextInput::make('telegram_channel')->label(self::KEYS['telegram_channel'])->maxLength(120),
                Forms\Components\TextInput::make('telegram_channel_url')->label(self::KEYS['telegram_channel_url'])->maxLength(255),
                Forms\Components\TextInput::make('telegram_support')->label(self::KEYS['telegram_support'])->maxLength(120),
                Forms\Components\TextInput::make('telegram_support_url')->label(self::KEYS['telegram_support_url'])->maxLength(255),
                Forms\Components\TextInput::make('bot_url')->label(self::KEYS['bot_url'])->maxLength(255),
            ])->columns(2),

            Forms\Components\Section::make('سایر شبکه‌ها')->schema([
                Forms\Components\TextInput::make('instagram')->label(self::KEYS['instagram'])->maxLength(120),
                Forms\Components\TextInput::make('instagram_url')->label(self::KEYS['instagram_url'])->maxLength(255),
                Forms\Components\TextInput::make('youtube')->label(self::KEYS['youtube'])->maxLength(120),
                Forms\Components\TextInput::make('youtube_url')->label(self::KEYS['youtube_url'])->maxLength(255),
                Forms\Components\TextInput::make('website_url')->label(self::KEYS['website_url'])->maxLength(255),
                Forms\Components\TextInput::make('support_url')->label(self::KEYS['support_url'])->maxLength(255),
                Forms\Components\TextInput::make('status_channel')->label(self::KEYS['status_channel'])->maxLength(255),
            ])->columns(2),
        ])->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        foreach (self::KEYS as $key => $label) {
            SiteText::set($key, $data[$key] ?? '', ['group' => 'لینک‌ها و شبکه‌های اجتماعی', 'label' => $label]);
        }
        Notification::make()->title('لینک‌ها ذخیره شد.')->success()->send();
    }

    protected function getFormActions(): array
    {
        return [Action::make('save')->label('ذخیره تغییرات')->action('save')];
    }
}
