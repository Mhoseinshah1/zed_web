{{-- Unlocked-state banner: remaining validity + explicit relock. --}}
<div class="rounded-xl border border-green-300 bg-green-50 p-3 text-sm text-green-800 dark:border-green-700 dark:bg-green-900/30 dark:text-green-200 flex flex-wrap items-center justify-between gap-3">
    <span>
        🔓 تنظیمات حساس برای مدت محدودی باز است —
        حداکثر {{ ceil($this->stepUpRemainingSeconds() / 60) }} دقیقه دیگر به‌صورت خودکار قفل می‌شود.
        هر عملیات ذخیره یا تست، اعتبار دسترسی را دوباره در سمت سرور بررسی می‌کند.
    </span>
    <x-filament::button color="warning" size="sm" icon="heroicon-o-lock-closed" wire:click="lockSensitiveSettingsNow">
        قفل کردن همین حالا
    </x-filament::button>
</div>
