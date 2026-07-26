<x-filament-panels::page>

    @php($page = $this)

    {{-- Non-secret status --}}
    <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm dark:border-gray-700 dark:bg-gray-900">
        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
            <div>
                <span class="text-gray-500 dark:text-gray-400">ورود دومرحله‌ای (TOTP):</span>
                @if($page->totpActive())
                    <span class="font-semibold text-green-600 dark:text-green-400">فعال</span>
                @else
                    <span class="font-semibold text-red-600 dark:text-red-400">غیرفعال — در ورود بعدی اجباری می‌شود</span>
                @endif
            </div>
            <div>
                <span class="text-gray-500 dark:text-gray-400">تاریخ فعال‌سازی:</span>
                <span class="font-mono">{{ $page->enrolledAt() ?? '—' }}</span>
            </div>
            <div>
                <span class="text-gray-500 dark:text-gray-400">کدهای بازیابی باقی‌مانده:</span>
                <span class="font-semibold {{ $page->recoveryCodesRemaining() <= 2 ? 'text-amber-600 dark:text-amber-400' : '' }}">{{ $page->recoveryCodesRemaining() }}</span>
            </div>
            <div>
                <span class="text-gray-500 dark:text-gray-400">آخرین ساخت کدهای بازیابی:</span>
                <span class="font-mono">{{ $page->recoveryCodesGeneratedAt() ?? '—' }}</span>
            </div>
            <div>
                <span class="text-gray-500 dark:text-gray-400">وضعیت MFA نشست فعلی:</span>
                @if($page->sessionMfaVerified())
                    <span class="font-semibold text-green-600 dark:text-green-400">تایید شده{{ $page->sessionViaRecovery() ? ' (با کد بازیابی)' : '' }}</span>
                @else
                    <span class="font-semibold text-red-600 dark:text-red-400">تایید نشده</span>
                @endif
            </div>
            <div>
                <span class="text-gray-500 dark:text-gray-400">دسترسی تنظیمات حساس (step-up):</span>
                @if($page->stepUpActive())
                    <span class="font-semibold text-amber-600 dark:text-amber-400">باز (موقت)</span>
                @else
                    <span class="font-semibold text-gray-600 dark:text-gray-300">قفل</span>
                @endif
            </div>
        </div>
    </div>

    @if($page->sessionViaRecovery())
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-200">
            ⚠ این نشست با «کد بازیابی» وارد شده است. هرچه زودتر برنامه Authenticator خود را جایگزین کنید و کدهای بازیابی جدید بسازید. تا آن زمان، تنظیمات حساس ارتباطی برای این نشست باز نمی‌شود.
        </div>
    @endif

    {{-- One-time recovery-code display --}}
    @if($page->freshRecoveryCodes !== null)
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-900/30 dark:text-amber-200">
            <p class="font-bold mb-2">کدهای بازیابی جدید — فقط همین یک بار نمایش داده می‌شوند:</p>
            <ul class="grid grid-cols-2 gap-2 font-mono" style="direction:ltr">
                @foreach($page->freshRecoveryCodes as $code)
                    <li class="rounded border border-amber-300 bg-white/60 px-2 py-1 text-center dark:bg-black/20">{{ $code }}</li>
                @endforeach
            </ul>
            <div class="mt-3">
                <x-filament::button color="warning" size="sm" wire:click="dismissFreshRecoveryCodes">
                    کدها را در جای امنی ذخیره کردم
                </x-filament::button>
            </div>
        </div>
    @endif

    {{-- Replacement flow --}}
    @if($page->replacing)
        <div class="rounded-xl border border-gray-200 bg-white p-4 text-sm dark:border-gray-700 dark:bg-gray-900">
            <p class="font-bold mb-2">جایگزینی برنامه Authenticator</p>
            <p class="mb-3 text-gray-600 dark:text-gray-300 leading-7">
                کد QR زیر را با دستگاه جدید اسکن کنید (یا کلید را دستی وارد کنید)، سپس کد ۶ رقمی تولیدشده توسط دستگاه جدید را تایید کنید.
                تا پیش از تایید، عامل قبلی همچنان فعال می‌ماند — حساب هیچ لحظه‌ای بدون عامل دوم نمی‌شود.
            </p>
            <div class="bg-white rounded-lg p-3 w-fit mb-3">{!! $page->replacementQr !!}</div>
            <p class="mb-1 text-gray-500 dark:text-gray-400">کلید راه‌اندازی دستی:</p>
            <code class="block mb-4 rounded border border-dashed border-gray-300 p-2 text-center dark:border-gray-600" style="direction:ltr;user-select:all">{{ $page->replacementKey }}</code>

            <form wire:submit="confirmReplacement" class="flex flex-wrap items-center gap-3">
                <x-filament::input.wrapper>
                    <x-filament::input type="text" wire:model="replacement_code" maxlength="6" inputmode="numeric"
                        autocomplete="one-time-code" placeholder="کد دستگاه جدید"
                        style="letter-spacing:.2em;text-align:center;direction:ltr" />
                </x-filament::input.wrapper>
                <x-filament::button type="submit">تایید دستگاه جدید</x-filament::button>
                <x-filament::button color="gray" wire:click="cancelReplacement">انصراف</x-filament::button>
            </form>
        </div>
    @endif

    {{-- Management actions --}}
    <div class="flex flex-wrap gap-3">
        {{ $this->regenerateRecoveryCodesAction }}
        {{ $this->startReplacementAction }}
    </div>

    <div class="rounded-xl border border-gray-200 bg-white p-4 text-xs text-gray-500 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400 leading-6">
        غیرفعال‌سازی مستقیم ورود دومرحله‌ای ممکن نیست — حساب ادمین هرگز بدون عامل دوم رها نمی‌شود.
        در صورت از دست دادن کامل دسترسی (دستگاه و کدهای بازیابی)، مدیر سرور می‌تواند با دستور
        <code style="direction:ltr">php artisan zedproxy:admin-2fa-reset &lt;username&gt;</code>
        عامل دوم را پاک کند تا در ورود بعدی، ثبت‌نام مجدد اجباری شود.
    </div>

    <x-filament-actions::modals />

</x-filament-panels::page>
