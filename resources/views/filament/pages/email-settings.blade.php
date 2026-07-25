<x-filament-panels::page>

    @php($page = $this)

    <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
            <div>
                <span class="text-gray-500 dark:text-gray-400">Mailer فعلی:</span>
                <span class="font-mono font-semibold">{{ $page->mailerName() }}</span>
            </div>
            <div>
                <span class="text-gray-500 dark:text-gray-400">آدرس فرستنده:</span>
                <span class="font-mono">{{ $page->fromAddress() ?: '—' }}</span>
            </div>
            <div>
                <span class="text-gray-500 dark:text-gray-400">وضعیت پیکربندی:</span>
                @if($page->mailLooksConfigured())
                    <span class="font-semibold text-green-600 dark:text-green-400">قابل استفاده</span>
                @else
                    <span class="font-semibold text-red-600 dark:text-red-400">ناقص / غیرقابل استفاده</span>
                @endif
            </div>
        </div>
    </div>

    @unless($page->mailLooksConfigured())
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-200">
            ⚠ پیکربندی ارسال ایمیل کامل نیست (mailer «log» یا «array» در حالت production پذیرفته نمی‌شود). مقادیر MAIL_* را در فایل .env سرور تنظیم کنید، سپس <code>optimize:clear</code> و <code>config:cache</code> را اجرا و worker صف را ری‌استارت کنید. تا آن زمان نمی‌توانید تایید ایمیل را اجباری کنید.
        </div>
    @endunless

    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex flex-wrap gap-3">
            <x-filament::button type="submit" color="primary">
                ذخیره تنظیمات
            </x-filament::button>

            {{ $this->testEmailAction }}
        </div>
    </form>

    <x-filament-actions::modals />

</x-filament-panels::page>
