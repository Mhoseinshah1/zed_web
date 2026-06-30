<x-filament-panels::page>

    @unless(app(\App\Services\Telegram\TelegramSettings::class)->isReady())
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-200">
            ⚠ بات مدیریت تلگرام هنوز فعال یا کامل تنظیم نشده است (فعال‌سازی، توکن و شناسه گروه لازم است). تا آن زمان اعلان‌ها فقط در لاگ ثبت می‌شوند و ارسال نمی‌گردند.
        </div>
    @endunless

    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex flex-wrap gap-3">
            <x-filament::button type="submit" color="primary">
                ذخیره تنظیمات
            </x-filament::button>

            {{ $this->testConnectionAction }}
            {{ $this->getChatAction }}
            {{ $this->sendTestAction }}
            {{ $this->sendTestPerTopicAction }}
        </div>

        <div class="mt-4 border-t border-line pt-4">
            <div class="text-sm font-semibold text-content mb-2">Webhook (دریافت دستورها)</div>
            <div class="flex flex-wrap gap-3">
                {{ $this->registerWebhookAction }}
                {{ $this->webhookStatusAction }}
                {{ $this->deleteWebhookAction }}
            </div>
            <p class="mt-2 text-xs text-content-muted">آدرس Webhook: <code>{{ route('telegram.webhook') }}</code> — توکن مخفی هرگز نمایش داده نمی‌شود.</p>
        </div>
    </form>

    <x-filament-actions::modals />

</x-filament-panels::page>
