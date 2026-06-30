<?php

namespace App\Filament\Pages;

use App\Models\SiteSetting;
use App\Services\Theme\AdminAppearanceResolver;
use App\Services\Theme\AppearanceManager;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * تنظیمات ظاهر — the single, simple, reliable appearance settings page that
 * replaces the old Theme Studio. Practical controls only; every one maps to a
 * real CSS variable that is injected into the public site, user dashboard and
 * the Filament admin panel.
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

    public function getSubheading(): ?string
    {
        return 'مدیریت ساده و کاربردی ظاهر سایت، داشبورد کاربر و پنل مدیریت';
    }

    public function mount(): void
    {
        $this->form->fill([
            'appearance_mode'              => AppearanceManager::appearanceMode(),
            'site_theme_preset'            => AppearanceManager::activePreset(),
            'primary_color'                => (string) SiteSetting::get('primary_color', ''),
            'accent_color'                 => (string) SiteSetting::get('accent_color', ''),
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
        $presetOptions = [];
        foreach (AppearanceManager::presets() as $key => $p) {
            $presetOptions[$key] = $p['title'];
        }

        return $form
            ->schema([
                Forms\Components\Section::make('حالت نمایش')
                    ->description('حالت روشن/تاریک پیش‌فرض برای سایت، داشبورد و پنل مدیریت')
                    ->icon('heroicon-o-sun')
                    ->schema([
                        Forms\Components\ToggleButtons::make('appearance_mode')
                            ->label('حالت نمایش')
                            ->options(['light' => 'روشن', 'dark' => 'تاریک', 'system' => 'سیستم'])
                            ->icons(['light' => 'heroicon-o-sun', 'dark' => 'heroicon-o-moon', 'system' => 'heroicon-o-computer-desktop'])
                            ->inline()
                            ->default('dark'),
                    ]),

                Forms\Components\Section::make('رنگ‌بندی سایت')
                    ->description('یک قالب رنگی انتخاب کنید و در صورت نیاز رنگ اصلی/تأکیدی را سفارشی کنید')
                    ->icon('heroicon-o-paint-brush')
                    ->schema([
                        Forms\Components\Select::make('site_theme_preset')
                            ->label('قالب رنگی')
                            ->options($presetOptions)
                            ->native(false)
                            ->required()
                            ->default(AppearanceManager::DEFAULT_PRESET),
                        Forms\Components\ColorPicker::make('primary_color')
                            ->label('رنگ اصلی')
                            ->helperText('اگر خالی باشد از رنگ قالب استفاده می‌شود'),
                        Forms\Components\ColorPicker::make('accent_color')
                            ->label('رنگ تأکیدی')
                            ->helperText('اگر خالی باشد از رنگ قالب استفاده می‌شود'),
                    ])->columns(3),

                Forms\Components\Section::make('پنل مدیریت')
                    ->description('فشردگی و اندازهٔ سایدبار پنل مدیریت (/zed-admin)')
                    ->icon('heroicon-o-computer-desktop')
                    ->schema([
                        Forms\Components\Select::make('admin_density')
                            ->label('تراکم پنل مدیریت')
                            ->options(['compact' => 'فشرده', 'normal' => 'عادی', 'comfortable' => 'راحت'])
                            ->native(false)->required()->default('normal'),
                        Forms\Components\Select::make('admin_sidebar_size')
                            ->label('اندازهٔ سایدبار')
                            ->options(['small' => 'کوچک', 'normal' => 'عادی', 'large' => 'بزرگ'])
                            ->native(false)->required()->default('normal'),
                        Forms\Components\Select::make('admin_brand_display')
                            ->label('نمایش برند')
                            ->options(['logo' => 'فقط لوگو', 'text' => 'فقط متن', 'logo_text' => 'لوگو و متن'])
                            ->native(false)->required()->default('text'),
                        Forms\Components\TextInput::make('admin_brand_text')
                            ->label('عنوان برند پنل مدیریت')
                            ->maxLength(60)->default('ZedProxy Admin'),
                    ])->columns(2),

                Forms\Components\Section::make('دسترسی کاربران')
                    ->description('اجازهٔ تغییر ظاهر توسط کاربران در داشبورد')
                    ->icon('heroicon-o-user-group')
                    ->schema([
                        Forms\Components\Toggle::make('allow_user_appearance_switch')
                            ->label('اجازه تغییر حالت روشن/تاریک توسط کاربر')->default(true),
                        Forms\Components\Toggle::make('allow_user_theme_switch')
                            ->label('اجازه تغییر رنگ‌بندی توسط کاربر')->default(true),
                    ])->columns(2),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $mode = in_array($data['appearance_mode'] ?? null, ['light', 'dark', 'system'], true) ? $data['appearance_mode'] : 'dark';
        SiteSetting::set('appearance_mode', $mode);
        SiteSetting::set('default_appearance', $mode); // keep legacy resolver in sync

        $preset = array_key_exists($data['site_theme_preset'] ?? '', AppearanceManager::presets())
            ? $data['site_theme_preset'] : AppearanceManager::DEFAULT_PRESET;
        SiteSetting::set('site_theme_preset', $preset);

        SiteSetting::set('primary_color', $this->normalizeColor($data['primary_color'] ?? ''));
        SiteSetting::set('accent_color', $this->normalizeColor($data['accent_color'] ?? ''));

        SiteSetting::set('admin_density', in_array($data['admin_density'] ?? null, ['compact', 'normal', 'comfortable'], true) ? $data['admin_density'] : 'normal');
        SiteSetting::set('admin_sidebar_size', in_array($data['admin_sidebar_size'] ?? null, ['small', 'normal', 'large'], true) ? $data['admin_sidebar_size'] : 'normal');
        SiteSetting::set('admin_brand_display', in_array($data['admin_brand_display'] ?? null, ['logo', 'text', 'logo_text'], true) ? $data['admin_brand_display'] : 'text');
        SiteSetting::set('admin_brand_text', mb_substr(trim((string) ($data['admin_brand_text'] ?? 'ZedProxy Admin')) ?: 'ZedProxy Admin', 0, 60));

        SiteSetting::set('allow_user_appearance_switch', ! empty($data['allow_user_appearance_switch']) ? 'true' : 'false');
        SiteSetting::set('allow_user_theme_switch', ! empty($data['allow_user_theme_switch']) ? 'true' : 'false');

        Notification::make()->title('تنظیمات ظاهر با موفقیت ذخیره و اعمال شد.')->success()->send();
        // Reload so the freshly-injected variables take effect everywhere.
        $this->redirect(static::getUrl());
    }

    protected function getFormActions(): array
    {
        return [Action::make('save')->label('ذخیره و اعمال')->action('save')];
    }

    /** Normalise a hex colour or empty string. */
    protected function normalizeColor(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        return preg_match('/^#?[0-9a-fA-F]{6}$/', $value) === 1
            ? (str_starts_with($value, '#') ? $value : '#' . $value)
            : '';
    }

    /** Data for the preview in the view. */
    public function getViewData(): array
    {
        return [
            'admin'  => AdminAppearanceResolver::resolve(),
            'colors' => AppearanceManager::colorVars(),
        ];
    }
}
