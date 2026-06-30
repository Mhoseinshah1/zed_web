<?php

namespace App\Filament\Pages;

use App\Services\Theme\TemplateManager;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Homepage Template selector — chooses the STRUCTURE/LAYOUT of the homepage
 * (classic | modern), independent of the colour Theme Studio. The active
 * template is persisted to the DB (SiteSetting) via {@see persist()}.
 */
class TemplateStudio extends Page
{
    protected static string $view = 'filament.pages.template-studio';

    protected static ?string $navigationIcon  = 'heroicon-o-rectangle-group';
    protected static ?string $navigationGroup = 'ظاهر سایت';
    protected static ?string $navigationLabel = 'قالب‌های سایت';
    protected static ?string $title           = 'قالب‌های صفحه اصلی';
    protected static ?string $slug            = 'templates';
    protected static ?int    $navigationSort  = 20;

    /** Data handed to the Alpine front-end. */
    public function getViewData(): array
    {
        return [
            'templates' => TemplateManager::templates(),
            'active'    => TemplateManager::activeTemplate(),
        ];
    }

    /** Persist the chosen homepage template. Called from the Blade gallery. */
    public function persist(string $template): void
    {
        if (! TemplateManager::isValid($template)) {
            Notification::make()->title('قالب نامعتبر است.')->danger()->send();
            return;
        }

        TemplateManager::setActiveTemplate($template);

        $title = TemplateManager::templates()[$template]['title'] ?? $template;
        Notification::make()->title("قالب «{$title}» فعال شد.")->success()->send();
    }
}
