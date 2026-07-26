<x-filament-panels::page>

    @php($page = $this)

    @unless($page->stepUpUnlocked)
        @include('filament.pages.partials.step-up-lock')
    @else

    @include('filament.pages.partials.step-up-status')

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
            <div>
                <span class="text-gray-500 dark:text-gray-400">منبع مؤثر تنظیمات:</span>
                @if($page->effectiveSource() === \App\Services\Email\EmailTransportSettingsService::SOURCE_PANEL)
                    <span class="font-semibold text-green-600 dark:text-green-400">پنل مدیریت</span>
                @elseif($page->effectiveSource() === \App\Services\Email\EmailTransportSettingsService::SOURCE_PANEL_INVALID)
                    <span class="font-semibold text-red-600 dark:text-red-400">پنل (نامعتبر — ارسال متوقف است)</span>
                @else
                    <span class="font-semibold">فایل .env سرور</span>
                @endif
            </div>
            <div>
                <span class="text-gray-500 dark:text-gray-400">رمز عبور SMTP ذخیره‌شده:</span>
                @if($page->storedPasswordExists())
                    <span class="font-semibold text-green-600 dark:text-green-400">دارد</span>
                @else
                    <span class="font-semibold text-gray-600 dark:text-gray-300">ندارد</span>
                @endif
            </div>
            <div>
                <span class="text-gray-500 dark:text-gray-400">آخرین تست موفق:</span>
                <span class="font-mono">{{ $page->lastMailTestAt() ?? '—' }}</span>
            </div>
            <div class="sm:col-span-2">
                <span class="text-gray-500 dark:text-gray-400">اعتبار تست برای پیکربندی فعلی:</span>
                @if($page->mailTestVerified())
                    <span class="font-semibold text-green-600 dark:text-green-400">تایید شده</span>
                @else
                    <span class="font-semibold text-amber-600 dark:text-amber-400">تست جدید لازم است</span>
                @endif
            </div>
        </div>
    </div>

    @if($page->mailLooksConfigured() && ! $page->mailTestVerified())
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-200">
            ⚠ برای این پیکربندی، تست موفقی ثبت نشده است (یا پیکربندی از زمان آخرین تست تغییر کرده است). قبل از اجباری کردن تایید ایمیل، یک «ارسال ایمیل تست» موفق انجام دهید. اگر حالت اجباری قبلاً فعال بوده، تا ثبت تست موفق جدید به‌صورت خودکار به حالت اختیاری برمی‌گردد تا کاربران جدید پشت پیکربندی اثبات‌نشده قفل نشوند.
        </div>
    @endif

    @if($page->multiLeafMailer())
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-200">
            ⚠ {{ \App\Filament\Pages\EmailSettingsPage::MULTI_LEAF_MESSAGE }} برای استقرار فعلی از <code>MAIL_MAILER=smtp</code> استفاده کنید.
        </div>
    @endif

    @unless($page->mailLooksConfigured())
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-200">
            ⚠ پیکربندی ارسال ایمیل کامل نیست (mailer «log» یا «array» در حالت production پذیرفته نمی‌شود). می‌توانید در بخش «پیکربندی SMTP» همین صفحه تنظیمات را وارد و فعال کنید — تغییرات بلافاصله و بدون نیاز به پاک کردن کش یا ری‌استارت worker اعمال می‌شود. (فایل .env سرور همچنان به عنوان منبع پایه/جایگزین وقتی تنظیمات پنل غیرفعال است معتبر است.) تا وقتی پیکربندی قابل استفاده نباشد نمی‌توانید تایید ایمیل را اجباری کنید.
        </div>
    @endunless

    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex flex-wrap gap-3">
            <x-filament::button type="submit" color="primary">
                ذخیره تنظیمات
            </x-filament::button>

            {{ $this->testEmailAction }}

            @if($page->storedPasswordExists())
                {{ $this->clearSmtpPasswordAction }}
            @endif
        </div>
    </form>

    <x-filament-actions::modals />

    @endunless

</x-filament-panels::page>
