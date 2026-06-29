<?php

namespace App\Filament\Pages;

use App\Models\SiteSetting;
use App\Services\Theme\ThemeManager;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Admin settings for the dynamic theme system: default themes per surface,
 * enabled presets, light/dark defaults, user switch permissions and the
 * shape/density/animation tokens that drive the whole UI.
 */
class AppearanceSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.pages.appearance-settings';

    protected static ?string $navigationIcon  = 'heroicon-o-swatch';
    protected static ?string $navigationGroup = 'ظاهر سایت';
    protected static ?string $navigationLabel = 'تنظیمات ظاهر و تم';
    protected static ?string $title           = 'تنظیمات ظاهر و تم';
    protected static ?string $slug            = 'settings/appearance';
    protected static ?int    $navigationSort  = 1;

    /** @var array<string,mixed> */
    public array $data = [];

    /** @return array<string,string> preset key => persian name */
    protected function presetOptions(): array
    {
        $opts = [];
        foreach (ThemeManager::presets() as $key => $preset) {
            $opts[$key] = $preset['name'];
        }
        return $opts;
    }

    public function mount(): void
    {
        $this->form->fill([
            'default_theme_public'        => ThemeManager::defaultTheme(ThemeManager::SURFACE_PUBLIC),
            'default_theme_user'          => ThemeManager::defaultTheme(ThemeManager::SURFACE_USER),
            'default_theme_admin'         => ThemeManager::defaultTheme(ThemeManager::SURFACE_ADMIN),
            'default_appearance'          => (string) SiteSetting::get('default_appearance', 'dark'),
            'enabled_themes'              => ThemeManager::enabledThemes(),
            'allow_user_theme_switch'     => (bool) SiteSetting::get('allow_user_theme_switch', true),
            'allow_user_appearance_switch' => (bool) SiteSetting::get('allow_user_appearance_switch', true),
            'force_global_theme'          => (bool) SiteSetting::get('force_global_theme', false),
            'animation_intensity'         => ThemeManager::animationIntensity(),
            'card_radius'                 => (string) SiteSetting::get('card_radius', '0.85rem'),
            'button_radius'               => (string) SiteSetting::get('button_radius', '0.6rem'),
            'icon_size'                   => (string) SiteSetting::get('icon_size', '1.25rem'),
            'sidebar_icon_size'           => (string) SiteSetting::get('sidebar_icon_size', '1.25rem'),
            'logo_size'                   => (string) SiteSetting::get('logo_size', '1.125rem'),
            'font_scale'                  => (int) SiteSetting::get('font_scale', 100),
            'table_density'               => (string) SiteSetting::get('table_density', 'comfortable'),
            'card_density'                => (string) SiteSetting::get('card_density', 'comfortable'),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('تم پیش‌فرض هر بخش')
                    ->description('تم پایه برای سایت عمومی، پنل کاربری و پنل مدیریت.')
                    ->schema([
                        Forms\Components\Select::make('default_theme_public')
                            ->label('تم سایت عمومی')
                            ->options($this->presetOptions())->searchable()->native(false),
                        Forms\Components\Select::make('default_theme_user')
                            ->label('تم پنل کاربری')
                            ->options($this->presetOptions())->searchable()->native(false),
                        Forms\Components\Select::make('default_theme_admin')
                            ->label('تم پنل مدیریت')
                            ->options($this->presetOptions())->searchable()->native(false),
                        Forms\Components\Select::make('default_appearance')
                            ->label('حالت پیش‌فرض روشن/تاریک')
                            ->options(['light' => 'روشن', 'dark' => 'تاریک', 'system' => 'سیستم'])
                            ->native(false),
                    ])->columns(2),

                Forms\Components\Section::make('تم‌های فعال')
                    ->description('تنها تم‌های انتخاب‌شده برای کاربران قابل انتخاب خواهند بود.')
                    ->schema([
                        Forms\Components\CheckboxList::make('enabled_themes')
                            ->label('تم‌های در دسترس کاربر')
                            ->options($this->presetOptions())
                            ->columns(3)
                            ->gridDirection('row'),
                    ]),

                Forms\Components\Section::make('اجازه‌های کاربر')
                    ->schema([
                        Forms\Components\Toggle::make('allow_user_theme_switch')
                            ->label('اجازه تغییر تم توسط کاربر')->default(true),
                        Forms\Components\Toggle::make('allow_user_appearance_switch')
                            ->label('اجازه تغییر حالت روشن/تاریک توسط کاربر')->default(true),
                        Forms\Components\Toggle::make('force_global_theme')
                            ->label('اعمال اجباری تم سراسری (نادیده‌گرفتن انتخاب کاربر)')->default(false),
                    ])->columns(3),

                Forms\Components\Section::make('شکل، تراکم و انیمیشن')
                    ->schema([
                        Forms\Components\Select::make('animation_intensity')
                            ->label('شدت انیمیشن')
                            ->options(['none' => 'بدون انیمیشن', 'subtle' => 'ملایم', 'rich' => 'پررنگ'])
                            ->native(false)->default('subtle'),
                        Forms\Components\TextInput::make('font_scale')
                            ->label('مقیاس فونت (٪)')->numeric()->minValue(80)->maxValue(130)->default(100),
                        Forms\Components\Select::make('card_radius')
                            ->label('گردی گوشه کارت‌ها')
                            ->options([
                                '0.35rem' => 'کم', '0.6rem' => 'متوسط', '0.85rem' => 'پیش‌فرض',
                                '1.1rem' => 'زیاد', '1.5rem' => 'خیلی زیاد',
                            ])->native(false),
                        Forms\Components\Select::make('button_radius')
                            ->label('گردی گوشه دکمه‌ها')
                            ->options([
                                '0.3rem' => 'کم', '0.5rem' => 'متوسط', '0.6rem' => 'پیش‌فرض',
                                '0.85rem' => 'زیاد', '9999px' => 'کاملاً گرد',
                            ])->native(false),
                        Forms\Components\Select::make('table_density')
                            ->label('تراکم جدول‌ها')
                            ->options(['compact' => 'فشرده', 'comfortable' => 'استاندارد'])
                            ->native(false),
                        Forms\Components\Select::make('card_density')
                            ->label('تراکم کارت‌ها')
                            ->options(['compact' => 'فشرده', 'comfortable' => 'استاندارد'])
                            ->native(false),
                        Forms\Components\Select::make('icon_size')
                            ->label('اندازه آیکون‌ها')
                            ->options([
                                '1rem' => 'کوچک', '1.25rem' => 'پیش‌فرض', '1.5rem' => 'بزرگ',
                            ])->native(false),
                        Forms\Components\Select::make('sidebar_icon_size')
                            ->label('اندازه آیکون منوی کناری')
                            ->options([
                                '1rem' => 'کوچک', '1.25rem' => 'پیش‌فرض', '1.5rem' => 'بزرگ',
                            ])->native(false),
                        Forms\Components\Select::make('logo_size')
                            ->label('اندازه لوگو')
                            ->options([
                                '1rem' => 'کوچک', '1.125rem' => 'پیش‌فرض', '1.4rem' => 'بزرگ',
                            ])->native(false),
                    ])->columns(3),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $themeKeys = ThemeManager::presetKeys();
        $valid = fn ($k, $default) => in_array($k, $themeKeys, true) ? $k : $default;

        SiteSetting::set('default_theme_public', $valid($data['default_theme_public'] ?? null, 'cyber-dark'));
        SiteSetting::set('default_theme_user', $valid($data['default_theme_user'] ?? null, 'cyber-dark'));
        SiteSetting::set('default_theme_admin', $valid($data['default_theme_admin'] ?? null, 'cyber-dark'));
        SiteSetting::set('default_appearance', in_array($data['default_appearance'] ?? null, ['light', 'dark', 'system'], true) ? $data['default_appearance'] : 'dark');

        $enabled = array_values(array_filter((array) ($data['enabled_themes'] ?? []), fn ($k) => in_array($k, $themeKeys, true)));
        if (empty($enabled)) {
            $enabled = $themeKeys; // never lock everyone out
        }
        SiteSetting::set('enabled_themes', implode(',', $enabled));

        SiteSetting::set('allow_user_theme_switch', ! empty($data['allow_user_theme_switch']) ? 'true' : 'false');
        SiteSetting::set('allow_user_appearance_switch', ! empty($data['allow_user_appearance_switch']) ? 'true' : 'false');
        SiteSetting::set('force_global_theme', ! empty($data['force_global_theme']) ? 'true' : 'false');

        SiteSetting::set('animation_intensity', in_array($data['animation_intensity'] ?? null, ['none', 'subtle', 'rich'], true) ? $data['animation_intensity'] : 'subtle');
        SiteSetting::set('font_scale', (int) max(80, min(130, (int) ($data['font_scale'] ?? 100))));
        SiteSetting::set('card_radius', $data['card_radius'] ?? '0.85rem');
        SiteSetting::set('button_radius', $data['button_radius'] ?? '0.6rem');
        SiteSetting::set('icon_size', $data['icon_size'] ?? '1.25rem');
        SiteSetting::set('sidebar_icon_size', $data['sidebar_icon_size'] ?? '1.25rem');
        SiteSetting::set('logo_size', $data['logo_size'] ?? '1.125rem');
        SiteSetting::set('table_density', $data['table_density'] ?? 'comfortable');
        SiteSetting::set('card_density', $data['card_density'] ?? 'comfortable');

        Notification::make()->title('تنظیمات ظاهر ذخیره شد.')->success()->send();
    }

    protected function getFormActions(): array
    {
        return [Action::make('save')->label('ذخیره تنظیمات')->action('save')];
    }
}
