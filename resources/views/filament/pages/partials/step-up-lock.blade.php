{{-- Shared locked screen for sensitive communication settings (email + SMS).
     NOTHING sensitive is hydrated, decrypted, or snapshotted behind this
     screen — the page mounts with an empty form until the server-side
     step-up grant exists. --}}
<div class="max-w-xl mx-auto">
    <x-filament::section>
        <div class="flex flex-col gap-4">
            <div class="flex items-center gap-3">
                <x-filament::icon icon="heroicon-o-lock-closed" class="h-8 w-8 text-warning-500" />
                <h2 class="text-base font-bold">تایید دومرحله‌ای برای تنظیمات حساس</h2>
            </div>

            <p class="text-sm text-gray-500 dark:text-gray-400 leading-7">
                این بخش شامل تنظیمات حساس ارتباطی (اطلاعات اتصال و کلیدهای سرویس) است.
                برای باز شدن آن، کد ۶ رقمی <strong>جدید</strong> برنامه Authenticator خود را وارد کنید —
                کدی که هنگام ورود استفاده کرده‌اید معتبر نیست و کد بازیابی نیز پذیرفته نمی‌شود.
                دسترسی پس از حداکثر ۵ دقیقه به‌صورت خودکار قفل می‌شود.
            </p>

            <form wire:submit="unlockSensitiveSettings" class="flex flex-col gap-3">
                <x-filament::input.wrapper>
                    <x-filament::input
                        type="text"
                        wire:model="step_up_code"
                        inputmode="numeric"
                        maxlength="6"
                        autocomplete="one-time-code"
                        placeholder="کد ۶ رقمی"
                        style="letter-spacing:.25em;text-align:center;direction:ltr"
                    />
                </x-filament::input.wrapper>

                <x-filament::button type="submit" icon="heroicon-o-key">
                    باز کردن تنظیمات حساس
                </x-filament::button>
            </form>
        </div>
    </x-filament::section>
</div>
