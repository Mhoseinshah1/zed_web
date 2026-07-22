<?php

namespace App\Filament\Pages;

use App\Services\Seo\SeoSettings;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * تنظیمات سئو — global SEO defaults (site name, default title/description/OG
 * image, organization data, schema toggles, verification tags). Stored as
 * cached seo_* SiteText keys. No secrets are stored here.
 */
class SeoSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.pages.seo-settings';

    protected static ?string $navigationIcon   = 'heroicon-o-globe-alt';
    protected static ?string $navigationGroup  = 'مدیریت محتوا';
    protected static ?string $navigationLabel  = 'تنظیمات سئو';
    protected static ?string $title            = 'تنظیمات سئو';
    protected static ?string $slug             = 'seo/settings';
    protected static ?int    $navigationSort   = 86;

    /** @var array<string,mixed> */
    public array $data = [];

    public function mount(): void
    {
        $values = [];
        foreach (array_keys(SeoSettings::DEFAULTS) as $key) {
            $values[$key] = SeoSettings::get($key, SeoSettings::DEFAULTS[$key]);
        }
        // Booleans as real bools for the toggles.
        foreach (['seo_sitemap_enabled', 'seo_schema_breadcrumb_enabled', 'seo_schema_organization_enabled', 'seo_schema_website_enabled'] as $b) {
            $values[$b] = SeoSettings::bool($b, true);
        }
        $this->form->fill($values);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('هویت سایت')->schema([
                Forms\Components\TextInput::make('seo_site_name')->label('نام سایت')->maxLength(120),
                Forms\Components\TextInput::make('seo_default_locale')->label('زبان پیش‌فرض')->default('fa-IR')->maxLength(10),
                Forms\Components\TextInput::make('seo_default_title')->label('عنوان پیش‌فرض سئو')->maxLength(180)->columnSpanFull(),
                Forms\Components\Textarea::make('seo_default_description')->label('توضیحات پیش‌فرض سئو')->rows(2)->maxLength(255)->columnSpanFull(),
                Forms\Components\FileUpload::make('seo_default_og_image')->label('تصویر پیش‌فرض اشتراک‌گذاری')
                    ->image()->disk('public')->directory('seo')->maxSize(2048)->columnSpanFull(),
            ])->columns(2),

            Forms\Components\Section::make('شبکه‌های اجتماعی')->collapsed()->schema([
                Forms\Components\TextInput::make('seo_twitter_username')->label('نام کاربری توییتر/X')->placeholder('@username'),
                Forms\Components\TextInput::make('seo_facebook_url')->label('آدرس صفحه فیسبوک')->url(),
                Forms\Components\TextInput::make('seo_telegram_url')->label('آدرس تلگرام')->url(),
            ])->columns(3),

            Forms\Components\Section::make('اطلاعات سازمان (Schema)')->collapsed()->schema([
                Forms\Components\TextInput::make('seo_org_name')->label('نام سازمان')->maxLength(120),
                Forms\Components\TextInput::make('seo_org_alternate_name')->label('نام جایگزین سازمان')->maxLength(120),
                Forms\Components\FileUpload::make('seo_schema_logo')->label('لوگوی اسکیما')->image()->disk('public')->directory('seo')->maxSize(2048),
                Forms\Components\TextInput::make('seo_telephone')->label('تلفن')->tel(),
                Forms\Components\TextInput::make('seo_email')->label('ایمیل')->email(),
                Forms\Components\TextInput::make('seo_country')->label('کشور')->default('IR')->maxLength(2),
                Forms\Components\Textarea::make('seo_address')->label('آدرس')->rows(2)->columnSpanFull(),
            ])->columns(3),

            Forms\Components\Section::make('اسکیمای ساختاریافته')->schema([
                Forms\Components\Toggle::make('seo_schema_organization_enabled')->label('اسکیمای Organization')->default(true),
                Forms\Components\Toggle::make('seo_schema_website_enabled')->label('اسکیمای WebSite')->default(true),
                Forms\Components\Toggle::make('seo_schema_breadcrumb_enabled')->label('اسکیمای Breadcrumb')->default(true),
            ])->columns(3),

            Forms\Components\Section::make('سایت‌مپ و robots')->schema([
                Forms\Components\Toggle::make('seo_sitemap_enabled')->label('فعال بودن سایت‌مپ')->default(true),
                Forms\Components\Textarea::make('seo_custom_robots')->label('قوانین سفارشی robots.txt')->rows(4)->columnSpanFull()
                    ->helperText('قوانین امنیتی اجباری (دیس‌الو پنل مدیریت، پرداخت‌ها و …) قابل حذف نیستند.'),
            ])->columns(1),

            Forms\Components\Section::make('تایید موتور جستجو و آنالیتیکس')->collapsed()->schema([
                Forms\Components\TextInput::make('seo_google_verification')->label('کد تایید گوگل')->maxLength(120),
                Forms\Components\TextInput::make('seo_bing_verification')->label('کد تایید Bing')->maxLength(120),
                Forms\Components\TextInput::make('seo_google_analytics_id')->label('شناسه Google Analytics')->placeholder('G-XXXXXXX')->maxLength(40),
            ])->columns(3),
        ])->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        foreach (array_keys(SeoSettings::DEFAULTS) as $key) {
            if (! array_key_exists($key, $data)) {
                continue;
            }
            $value = $data[$key];
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            }
            SeoSettings::set($key, (string) $value);
        }
        SeoSettings::flush();
        \Illuminate\Support\Facades\Cache::forget('seo_sitemap:index');

        Notification::make()->title('تنظیمات سئو ذخیره شد.')->success()->send();
    }
}
