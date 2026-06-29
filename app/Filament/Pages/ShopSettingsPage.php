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
 * تنظیمات فروشگاه — shop/plans presentation copy. Does NOT touch plans, pricing,
 * discounts or payment logic; only the surrounding marketing texts. Stored in
 * SiteText.
 */
class ShopSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.pages.cms-settings';

    protected static ?string $navigationIcon  = 'heroicon-o-shopping-bag';
    protected static ?string $navigationGroup = 'مدیریت محتوا';
    protected static ?string $navigationLabel = 'تنظیمات فروشگاه';
    protected static ?string $title           = 'تنظیمات فروشگاه';
    protected static ?string $slug            = 'content/shop-settings';
    protected static ?int    $navigationSort  = 105;

    /** @var array<string,mixed> */
    public array $data = [];

    public const KEYS = [
        'shop_page_title'       => 'عنوان صفحه فروشگاه',
        'shop_page_subtitle'    => 'زیرعنوان صفحه فروشگاه',
        'shop_page_description' => 'توضیحات فروشگاه',
        'trust_text'            => 'متن اعتمادسازی',
        'guarantee_text'        => 'متن ضمانت خرید',
        'payment_help_text'     => 'متن راهنمای پرداخت',
        'discount_help_text'    => 'متن راهنمای کد تخفیف',
        'checkout_help_text'    => 'متن راهنمای تسویه/پرداخت سفارش',
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
            Forms\Components\Section::make('سرتیتر فروشگاه')->schema([
                Forms\Components\TextInput::make('shop_page_title')->label(self::KEYS['shop_page_title'])->maxLength(180),
                Forms\Components\TextInput::make('shop_page_subtitle')->label(self::KEYS['shop_page_subtitle'])->maxLength(180),
                Forms\Components\Textarea::make('shop_page_description')->label(self::KEYS['shop_page_description'])->rows(2)->columnSpanFull(),
            ])->columns(2),

            Forms\Components\Section::make('متن‌های راهنما و اعتمادسازی')->schema([
                Forms\Components\Textarea::make('trust_text')->label(self::KEYS['trust_text'])->rows(2),
                Forms\Components\Textarea::make('guarantee_text')->label(self::KEYS['guarantee_text'])->rows(2),
                Forms\Components\Textarea::make('payment_help_text')->label(self::KEYS['payment_help_text'])->rows(2),
                Forms\Components\Textarea::make('discount_help_text')->label(self::KEYS['discount_help_text'])->rows(2),
                Forms\Components\Textarea::make('checkout_help_text')->label(self::KEYS['checkout_help_text'])->rows(2)->columnSpanFull(),
            ])->columns(2),
        ])->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        foreach (self::KEYS as $key => $label) {
            SiteText::set($key, $data[$key] ?? '', ['group' => 'فروشگاه', 'label' => $label]);
        }
        Notification::make()->title('تنظیمات فروشگاه ذخیره شد.')->success()->send();
    }

    protected function getFormActions(): array
    {
        return [Action::make('save')->label('ذخیره تغییرات')->action('save')];
    }
}
