<?php

namespace App\Filament\Support;

use Filament\Forms;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Reusable Filament SEO form fragments shared by SeoPageResource, PageResource
 * and TutorialResource so the SEO UX (Persian labels, character counters,
 * recommended lengths, previews, warnings) is defined once.
 */
class SeoFormFields
{
    /** Live character-count hint for a text field. */
    public static function counter(int $recommended, int $max): \Closure
    {
        return fn ($state) => trim((string) $state) === ''
            ? "طول پیشنهادی حدود {$recommended} کاراکتر"
            : mb_strlen((string) $state)." / {$max} کاراکتر";
    }

    /** Meta title/description/keywords/canonical with counters + previews. */
    public static function metaFields(): array
    {
        return [
            Forms\Components\TextInput::make('meta_title')
                ->label('عنوان سئو')->maxLength(70)->live(onBlur: true)
                ->hint(self::counter(60, 70))
                ->helperText('طول پیشنهادی: ۵۰ تا ۶۰ کاراکتر.'),

            Forms\Components\TextInput::make('meta_keywords')
                ->label('کلمات کلیدی')->maxLength(255)
                ->helperText('با کاما جدا کنید (اختیاری).'),

            Forms\Components\Textarea::make('meta_description')
                ->label('توضیحات سئو')->rows(2)->maxLength(180)->live(onBlur: true)
                ->hint(self::counter(155, 180))
                ->helperText('طول پیشنهادی: ۱۲۰ تا ۱۶۰ کاراکتر.')
                ->columnSpanFull(),

            Forms\Components\TextInput::make('canonical_url')
                ->label('آدرس کنونیکال')->url()->maxLength(255)
                ->placeholder('خالی بگذارید تا به‌صورت خودکار از آدرس صفحه ساخته شود')
                ->columnSpanFull(),

            // Google result preview (live).
            Forms\Components\Placeholder::make('google_preview')
                ->label('پیش‌نمایش نتیجه گوگل')
                ->content(fn (Forms\Get $get) => new HtmlString(
                    '<div style="border:1px solid var(--gray-200);border-radius:8px;padding:12px;max-width:600px" dir="rtl">'
                    .'<div style="color:#1a0dab;font-size:18px;line-height:1.3">'.e($get('meta_title') ?: 'عنوان صفحه').'</div>'
                    .'<div style="color:#006621;font-size:13px">'.e(config('app.url')).'</div>'
                    .'<div style="color:#545454;font-size:13px">'.e(Str::limit($get('meta_description') ?: 'توضیحات صفحه اینجا نمایش داده می‌شود.', 160)).'</div>'
                    .'</div>'
                ))
                ->columnSpanFull(),

            // Warnings for missing description/image.
            Forms\Components\Placeholder::make('seo_warnings')
                ->label('')
                ->content(function (Forms\Get $get) {
                    $warn = [];
                    if (trim((string) $get('meta_description')) === '') {
                        $warn[] = 'توضیحات سئو خالی است — برای نتایج بهتر آن را پر کنید.';
                    }
                    // FileUpload state is an array of files; normalise before check.
                    $img = $get('og_image');
                    $img = is_array($img) ? (reset($img) ?: '') : $img;
                    if (trim((string) $img) === '') {
                        $warn[] = 'تصویر اشتراک‌گذاری تنظیم نشده — از تصویر پیش‌فرض استفاده می‌شود.';
                    }
                    if ($warn === []) {
                        return new HtmlString('<span style="color:#16a34a">✓ سئوی این صفحه کامل است.</span>');
                    }

                    return new HtmlString('<span style="color:#d97706">⚠ '.e(implode(' | ', $warn)).'</span>');
                })
                ->columnSpanFull(),
        ];
    }

    /** Robots + Twitter + schema + sitemap controls. */
    public static function advancedFields(): array
    {
        return [
            Forms\Components\Toggle::make('robots_index')->label('اجازه ایندکس')->default(true)
                ->helperText('اگر خاموش باشد، این صفحه در نتایج جستجو ایندکس نمی‌شود.'),
            Forms\Components\Toggle::make('robots_follow')->label('اجازه دنبال‌کردن لینک‌ها')->default(true),

            Forms\Components\TextInput::make('og_title')->label('عنوان اشتراک‌گذاری')->maxLength(255),
            Forms\Components\Textarea::make('og_description')->label('توضیحات اشتراک‌گذاری')->rows(2),

            Forms\Components\Select::make('twitter_card')->label('نوع کارت توییتر')
                ->options(['summary' => 'ساده', 'summary_large_image' => 'تصویر بزرگ'])
                ->default('summary_large_image')->native(false),
            Forms\Components\TextInput::make('twitter_title')->label('عنوان توییتر')->maxLength(255),
            Forms\Components\Textarea::make('twitter_description')->label('توضیحات توییتر')->rows(2),

            Forms\Components\TextInput::make('schema_type')->label('نوع اسکیما')
                ->placeholder('WebPage, Article, FAQPage, …')->maxLength(60),
            Forms\Components\Textarea::make('schema_json_override')
                ->label('اسکیمای سفارشی (JSON)')->rows(3)->columnSpanFull()
                ->rules(['nullable', 'json'])
                ->helperText('در صورت پر بودن باید JSON معتبر باشد؛ مقدار نامعتبر ذخیره نمی‌شود.'),
        ];
    }

    /** Sitemap controls. */
    public static function sitemapFields(): array
    {
        return [
            Forms\Components\Toggle::make('include_in_sitemap')->label('نمایش در سایت‌مپ')->default(true),
            Forms\Components\TextInput::make('sitemap_priority')->label('اولویت سایت‌مپ')
                ->numeric()->minValue(0)->maxValue(1)->step(0.1)->default(0.6),
            Forms\Components\Select::make('sitemap_change_frequency')->label('بازه تغییر محتوا')
                ->options([
                    'always' => 'همیشه', 'hourly' => 'ساعتی', 'daily' => 'روزانه',
                    'weekly' => 'هفتگی', 'monthly' => 'ماهانه', 'yearly' => 'سالانه', 'never' => 'هرگز',
                ])->default('weekly')->native(false),
        ];
    }
}
