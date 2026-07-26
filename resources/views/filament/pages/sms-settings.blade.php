<x-filament-panels::page>

    @unless($this->stepUpUnlocked)
        @include('filament.pages.partials.step-up-lock')
    @else

    @unless(app(\App\Services\Sms\SmsService::class)->isConfigured())
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-200">
            ⚠ سرویس ارسال پیامک فعال یا کامل تنظیم نشده است. تا زمان تکمیل تنظیمات، کدهای تایید ارسال نمی‌شوند و نمی‌توانید تایید شماره را هنگام ثبت‌نام اجباری کنید.
        </div>
    @endunless

    @include('filament.pages.partials.step-up-status')

    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex flex-wrap gap-3">
            <x-filament::button type="submit" color="primary">
                ذخیره تنظیمات
            </x-filament::button>

            {{ $this->testSmsAction }}
        </div>
    </form>

    <x-filament-actions::modals />

    @endunless

</x-filament-panels::page>
