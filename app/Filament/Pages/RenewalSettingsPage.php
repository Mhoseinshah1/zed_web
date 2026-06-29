<?php

namespace App\Filament\Pages;

use App\Models\SiteSetting;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class RenewalSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static string $view = 'filament.pages.renewal-settings';

    protected static ?string $navigationIcon  = 'heroicon-o-cog-6-tooth';
    protected static ?string $navigationGroup = 'کاربران و سفارش‌ها';
    protected static ?string $navigationLabel = 'تنظیمات تمدید';
    protected static ?string $title           = 'تنظیمات تمدید سرویس';
    protected static ?string $slug            = 'settings/renewal';
    protected static ?int    $navigationSort  = 99;

    public bool $renewal_enabled = true;

    public function mount(): void
    {
        $this->renewal_enabled = (bool) SiteSetting::get('renewal_enabled', true);
        $this->form->fill(['renewal_enabled' => $this->renewal_enabled]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Toggle::make('renewal_enabled')
                    ->label('فعال بودن تمدید سرویس')
                    ->helperText('اگر غیرفعال باشد، دکمه تمدید از صفحه سرویس کاربر مخفی می‌شود و مسیر تمدید مسدود می‌گردد.')
                    ->default(true),
            ])
            ->statePath('');
    }

    public function save(): void
    {
        $data = $this->form->getState();
        SiteSetting::set('renewal_enabled', $data['renewal_enabled'] ? 'true' : 'false');

        Notification::make()
            ->title('تنظیمات ذخیره شد.')
            ->success()
            ->send();
    }

    protected function getFormActions(): array
    {
        return [
            Action::make('save')
                ->label('ذخیره تنظیمات')
                ->action('save'),
        ];
    }
}
