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
 * تنظیمات سایت — branding, support, footer and CTA copy. All values are stored
 * in the existing SiteText key/value store (read everywhere via site_setting()).
 */
class SiteSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.pages.cms-settings';

    protected static ?string $navigationIcon  = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationGroup = 'مدیریت محتوا';
    protected static ?string $navigationLabel = 'تنظیمات سایت';
    protected static ?string $title           = 'تنظیمات سایت';
    protected static ?string $slug            = 'content/site-settings';
    protected static ?int    $navigationSort  = 10;

    /** @var array<string,mixed> */
    public array $data = [];

    /** Setting key => Persian label. */
    public const KEYS = [
        'site_name'           => 'نام سایت',
        'brand_name'          => 'نام برند',
        'site_title'          => 'عنوان سایت',
        'site_description'    => 'توضیحات سایت',
        'logo'                => 'لوگو',
        'dark_logo'           => 'لوگوی حالت تاریک',
        'footer_logo'         => 'لوگوی فوتر',
        'favicon'             => 'فاوآیکن',
        'support_title'       => 'عنوان پشتیبانی',
        'support_description' => 'توضیحات پشتیبانی',
        'support_email'       => 'ایمیل پشتیبانی',
        'support_phone'       => 'شماره پشتیبانی',
        'footer_text'         => 'متن فوتر',
        'copyright_text'      => 'متن کپی‌رایت',
        'primary_cta_text'    => 'متن دکمه اصلی',
        'primary_cta_url'     => 'لینک دکمه اصلی',
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
            Forms\Components\Section::make('برندینگ')->schema([
                Forms\Components\TextInput::make('site_name')->label(self::KEYS['site_name'])->maxLength(120),
                Forms\Components\TextInput::make('brand_name')->label(self::KEYS['brand_name'])->maxLength(120),
                Forms\Components\TextInput::make('site_title')->label(self::KEYS['site_title'])->maxLength(180),
                Forms\Components\Textarea::make('site_description')->label(self::KEYS['site_description'])->rows(2)->columnSpanFull(),
                Forms\Components\FileUpload::make('logo')->label(self::KEYS['logo'])
                    ->image()->disk('public')->directory('branding')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'])->maxSize(1024),
                Forms\Components\FileUpload::make('dark_logo')->label(self::KEYS['dark_logo'])
                    ->image()->disk('public')->directory('branding')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'])->maxSize(1024),
                Forms\Components\FileUpload::make('footer_logo')->label(self::KEYS['footer_logo'])
                    ->image()->disk('public')->directory('branding')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'])->maxSize(1024),
                Forms\Components\FileUpload::make('favicon')->label(self::KEYS['favicon'])
                    ->disk('public')->directory('branding')
                    ->acceptedFileTypes(['image/png', 'image/x-icon', 'image/svg+xml', 'image/webp'])->maxSize(512),
            ])->columns(2),

            Forms\Components\Section::make('پشتیبانی')->schema([
                Forms\Components\TextInput::make('support_title')->label(self::KEYS['support_title'])->maxLength(120),
                Forms\Components\TextInput::make('support_email')->label(self::KEYS['support_email'])->email()->maxLength(180),
                Forms\Components\TextInput::make('support_phone')->label(self::KEYS['support_phone'])->maxLength(40),
                Forms\Components\Textarea::make('support_description')->label(self::KEYS['support_description'])->rows(2)->columnSpanFull(),
            ])->columns(2),

            Forms\Components\Section::make('فوتر و دکمه اصلی')->schema([
                Forms\Components\Textarea::make('footer_text')->label(self::KEYS['footer_text'])->rows(2)->columnSpanFull(),
                Forms\Components\TextInput::make('copyright_text')->label(self::KEYS['copyright_text'])->maxLength(180),
                Forms\Components\TextInput::make('primary_cta_text')->label(self::KEYS['primary_cta_text'])->maxLength(80),
                Forms\Components\TextInput::make('primary_cta_url')->label(self::KEYS['primary_cta_url'])->maxLength(255),
            ])->columns(2),
        ])->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        foreach (self::KEYS as $key => $label) {
            SiteText::set($key, $data[$key] ?? '', ['group' => 'تنظیمات سایت', 'label' => $label]);
        }
        Notification::make()->title('تنظیمات سایت ذخیره شد.')->success()->send();
    }

    protected function getFormActions(): array
    {
        return [Action::make('save')->label('ذخیره تغییرات')->action('save')];
    }
}
