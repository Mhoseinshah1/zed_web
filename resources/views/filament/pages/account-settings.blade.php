<x-filament-panels::page>

    @unless($this->smsConfigured())
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-200">
            ⚠ سرویس ارسال پیامک تنظیم نشده است. کدهای تایید ارسال نخواهند شد تا زمانی که سرویس پیامک فعال شود.
        </div>
    @endunless

    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6">
            <x-filament::button type="submit" color="primary">
                ذخیره تنظیمات
            </x-filament::button>
        </div>
    </form>

</x-filament-panels::page>
