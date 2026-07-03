<?php

namespace App\Filament\Pages;

use App\Models\SiteSetting;
use App\Services\Theme\AdminAppearanceResolver;
use App\Services\Theme\AppearanceManager;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * تنظیمات ظاهر — the single, simple appearance settings page that replaced the
 * old Theme Studio. Every control here is real: presets/colours flow through
 * AppearanceManager into the public site + user dashboard + admin, and the
 * admin density/sidebar/brand options flow through AdminAppearanceResolver
 * into the --zp-admin-* variables injected on every /zed-admin page.
 */
class AppearanceSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.pages.appearance-settings';

    protected static ?string $navigationIcon  = 'heroicon-o-swatch';
    protected static ?string $navigationGroup = 'ظاهر سایت';
    protected static ?string $navigationLabel = 'تنظیمات ظاهر';
    protected static ?string $title           = 'تنظیمات ظاهر';
    protected static ?string $slug            = 'appearance';
    protected static ?int    $navigationSort  = 10;

    /** @var array<string,mixed> */
    public array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'appearance_mode'              => AppearanceManager::appearanceMode(),
            'site_theme_preset'            => AppearanceManager::activePreset(),
            'primary_color'                => $this->storedColor('primary_color'),
            'accent_color'                 => $this->storedColor('accent_color'),
            'admin_density'                => AdminAppearanceResolver::density(),
            'admin_sidebar_size'           => AdminAppearanceResolver::sidebarSize(),
            'admin_brand_display'          => AdminAppearanceResolver::brandDisplay(),
            'admin_brand_text'             => AdminAppearanceResolver::brandText(),
            'allow_user_appearance_switch' => AppearanceManager::allowUserAppearanceSwitch(),
            'allow_user_theme_switch'      => AppearanceManager::allowUserThemeSwitch(),
        ]);
    }

    public function form(Form $form): Form
    {
        $presetOptions = collect(AppearanceManager::presets())
            ->map(fn ($p) => $p['title'])
            ->all();

        return $form->schema([
            Forms\Components\Section::make('ظاهر عمومی')
                ->description('این تنظیمات روی سایت عمومی، پنل کاربری و پنل مدیریت اعمال می‌شود.')
                ->schema([
                    Forms\Components\Select::make('appearance_mode')->label('حالت نمایش')
                        ->options(['light' => 'روشن', 'dark' => 'تاریک', 'system' => 'سیستم'])
                        ->default('dark')->native(false),

                    Forms\Components\Select::make('site_theme_preset')->label('تم سایت')
                        ->options($presetOptions)
                        ->default(AppearanceManager::DEFAULT_PRESET)->native(false),

                    Forms\Components\ColorPicker::make('primary_color')->label('رنگ اصلی')
                        ->helperText('خالی بگذارید تا رنگ پیش‌فرض تم استفاده شود.'),

                    Forms\Components\ColorPicker::make('accent_color')->label('رنگ تاکیدی')
                        ->helperText('خالی بگذارید تا رنگ پیش‌فرض تم استفاده شود.'),
                ])->columns(2),

            Forms\Components\Section::make('پنل مدیریت')
                ->description('فقط ظاهر /zed-admin را تغییر می‌دهد.')
                ->schema([
                    Forms\Components\Select::make('admin_density')->label('تراکم نمایش')
                        ->options(['compact' => 'فشرده', 'normal' => 'عادی', 'comfortable' => 'راحت'])
                        ->default('normal')->native(false)
                        ->helperText('ارتفاع ردیف جدول‌ها، فاصله کارت‌ها و ارتفاع فیلدهای فرم.'),

                    Forms\Components\Select::make('admin_sidebar_size')->label('اندازه سایدبار')
                        ->options(['small' => 'کوچک', 'normal' => 'عادی', 'large' => 'بزرگ'])
                        ->default('normal')->native(false)
                        ->helperText('پهنای سایدبار، اندازه متن و آیکن‌های منو.'),

                    Forms\Components\Select::make('admin_brand_display')->label('نمایش برند')
                        ->options(['logo' => 'فقط لوگو', 'text' => 'فقط متن', 'logo_text' => 'لوگو و متن'])
                        ->default('text')->native(false),

                    Forms\Components\TextInput::make('admin_brand_text')->label('متن برند')
                        ->placeholder('ZedProxy Admin')->maxLength(60),
                ])->columns(2),

            Forms\Components\Section::make('اختیارات کاربران')->schema([
                Forms\Components\Toggle::make('allow_user_appearance_switch')
                    ->label('کاربر بتواند حالت روشن/تاریک را تغییر دهد')->default(true),
                Forms\Components\Toggle::make('allow_user_theme_switch')
                    ->label('کاربر بتواند تم خود را تغییر دهد')->default(true),
            ])->columns(2),
        ])->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $mode = in_array($data['appearance_mode'] ?? null, ['light', 'dark', 'system'], true)
            ? $data['appearance_mode'] : 'dark';
        // Keep both keys in sync so every resolver (new + legacy) agrees.
        SiteSetting::set('appearance_mode', $mode);
        SiteSetting::set('default_appearance', $mode);

        $preset = array_key_exists($data['site_theme_preset'] ?? '', AppearanceManager::presets())
            ? $data['site_theme_preset'] : AppearanceManager::DEFAULT_PRESET;
        SiteSetting::set('site_theme_preset', $preset);

        SiteSetting::set('primary_color', $this->hexOrEmpty((string) ($data['primary_color'] ?? '')));
        SiteSetting::set('accent_color', $this->hexOrEmpty((string) ($data['accent_color'] ?? '')));

        SiteSetting::set('admin_density', in_array($data['admin_density'] ?? null, ['compact', 'normal', 'comfortable'], true) ? $data['admin_density'] : 'normal');
        SiteSetting::set('admin_sidebar_size', in_array($data['admin_sidebar_size'] ?? null, ['small', 'normal', 'large'], true) ? $data['admin_sidebar_size'] : 'normal');
        SiteSetting::set('admin_brand_display', in_array($data['admin_brand_display'] ?? null, ['logo', 'text', 'logo_text'], true) ? $data['admin_brand_display'] : 'text');
        SiteSetting::set('admin_brand_text', trim((string) ($data['admin_brand_text'] ?? '')) ?: 'ZedProxy Admin');

        SiteSetting::set('allow_user_appearance_switch', ! empty($data['allow_user_appearance_switch']) ? 'true' : 'false');
        SiteSetting::set('allow_user_theme_switch', ! empty($data['allow_user_theme_switch']) ? 'true' : 'false');

        Notification::make()->title('تنظیمات ظاهر ذخیره شد.')->success()->send();
        $this->redirect(static::getUrl()); // re-render with the new appearance
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** Stored colour for the form (null when unset so the picker shows empty). */
    private function storedColor(string $key): ?string
    {
        $v = (string) SiteSetting::get($key, '');
        return preg_match('/^#[0-9a-fA-F]{6}$/', $v) === 1 ? $v : null;
    }

    /** Valid #rrggbb (with or without #) → normalised hex; anything else → ''. */
    private function hexOrEmpty(string $value): string
    {
        $value = trim($value);
        if (preg_match('/^#?[0-9a-fA-F]{6}$/', $value) === 1) {
            return str_starts_with($value, '#') ? $value : '#' . $value;
        }
        return '';
    }
}
