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
 * صفحه اصلی — hero content + homepage SEO. Stored in the SiteText key/value
 * store; the public homepage reads these via site_setting().
 */
class HomeSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.pages.cms-settings';

    protected static ?string $navigationIcon  = 'heroicon-o-home';
    protected static ?string $navigationGroup = 'مدیریت محتوا';
    protected static ?string $navigationLabel = 'صفحه اصلی';
    protected static ?string $title           = 'تنظیمات صفحه اصلی';
    protected static ?string $slug            = 'content/home';
    protected static ?int    $navigationSort  = 20;

    /** @var array<string,mixed> */
    public array $data = [];

    public const KEYS = [
        'hero_title'                 => 'عنوان اصلی',
        'hero_subtitle'              => 'زیرعنوان',
        'hero_description'           => 'توضیحات',
        'hero_badge_text'            => 'متن برچسب',
        'hero_primary_button_text'   => 'متن دکمه اصلی',
        'hero_primary_button_url'    => 'لینک دکمه اصلی',
        'hero_secondary_button_text' => 'متن دکمه دوم',
        'hero_secondary_button_url'  => 'لینک دکمه دوم',
        'hero_image'                 => 'تصویر اصلی',
        'hero_background_image'      => 'تصویر پس‌زمینه',
        'hero_is_active'             => 'فعال بودن بخش',
        'background_style'           => 'سبک پس‌زمینه',
        'home_meta_title'            => 'عنوان سئو',
        'home_meta_description'      => 'توضیحات سئو',
        'home_meta_keywords'         => 'کلمات کلیدی سئو',
        'home_og_title'              => 'عنوان اشتراک‌گذاری',
        'home_og_description'        => 'توضیحات اشتراک‌گذاری',
        'home_og_image'              => 'تصویر اشتراک‌گذاری',
    ];

    public function mount(): void
    {
        $state = [];
        foreach (array_keys(self::KEYS) as $key) {
            $state[$key] = $key === 'hero_is_active'
                ? SiteText::getBool($key, true)
                : SiteText::get($key, '');
        }
        $this->form->fill($state);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('بخش هیرو')->schema([
                Forms\Components\TextInput::make('hero_title')->label(self::KEYS['hero_title'])->maxLength(180),
                Forms\Components\TextInput::make('hero_subtitle')->label(self::KEYS['hero_subtitle'])->maxLength(180),
                Forms\Components\Textarea::make('hero_description')->label(self::KEYS['hero_description'])->rows(2)->columnSpanFull(),
                Forms\Components\TextInput::make('hero_badge_text')->label(self::KEYS['hero_badge_text'])->maxLength(120),
                Forms\Components\Select::make('background_style')->label(self::KEYS['background_style'])
                    ->options(['gradient' => 'گرادینت', 'solid' => 'یکدست', 'image' => 'تصویر'])->native(false),
                Forms\Components\TextInput::make('hero_primary_button_text')->label(self::KEYS['hero_primary_button_text'])->maxLength(80),
                Forms\Components\TextInput::make('hero_primary_button_url')->label(self::KEYS['hero_primary_button_url'])->maxLength(255),
                Forms\Components\TextInput::make('hero_secondary_button_text')->label(self::KEYS['hero_secondary_button_text'])->maxLength(80),
                Forms\Components\TextInput::make('hero_secondary_button_url')->label(self::KEYS['hero_secondary_button_url'])->maxLength(255),
                Forms\Components\FileUpload::make('hero_image')->label(self::KEYS['hero_image'])
                    ->image()->disk('public')->directory('home')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'image/svg+xml'])->maxSize(2048),
                Forms\Components\FileUpload::make('hero_background_image')->label(self::KEYS['hero_background_image'])
                    ->image()->disk('public')->directory('home')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(2048),
                Forms\Components\Toggle::make('hero_is_active')->label(self::KEYS['hero_is_active'])->default(true),
            ])->columns(2),

            Forms\Components\Section::make('سئو صفحه اصلی')->collapsed()->schema([
                Forms\Components\TextInput::make('home_meta_title')->label(self::KEYS['home_meta_title'])->maxLength(180),
                Forms\Components\TextInput::make('home_meta_keywords')->label(self::KEYS['home_meta_keywords'])->maxLength(255),
                Forms\Components\Textarea::make('home_meta_description')->label(self::KEYS['home_meta_description'])->rows(2)->columnSpanFull(),
                Forms\Components\TextInput::make('home_og_title')->label(self::KEYS['home_og_title'])->maxLength(180),
                Forms\Components\Textarea::make('home_og_description')->label(self::KEYS['home_og_description'])->rows(2),
                Forms\Components\FileUpload::make('home_og_image')->label(self::KEYS['home_og_image'])
                    ->image()->disk('public')->directory('home')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])->maxSize(2048),
            ])->columns(2),
        ])->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        foreach (self::KEYS as $key => $label) {
            $value = $data[$key] ?? '';
            if ($key === 'hero_is_active') {
                $value = ! empty($data[$key]) ? '1' : '0';
            }
            SiteText::set($key, (string) $value, ['group' => 'صفحه اصلی', 'label' => $label]);
        }
        Notification::make()->title('تنظیمات صفحه اصلی ذخیره شد.')->success()->send();
    }

    protected function getFormActions(): array
    {
        return [Action::make('save')->label('ذخیره تغییرات')->action('save')];
    }
}
