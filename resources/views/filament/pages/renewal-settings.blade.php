<x-filament-panels::page>

    <div class="max-w-xl rounded-xl border border-gray-200 bg-white p-6 shadow-sm dark:border-gray-700 dark:bg-gray-800">
        <h3 class="mb-4 text-sm font-semibold text-gray-700 dark:text-gray-300">تنظیمات تمدید سرویس</h3>

        <form wire:submit="save">
            {{ $this->form }}

            <div class="mt-6">
                <x-filament::button type="submit" color="primary">
                    ذخیره تنظیمات
                </x-filament::button>
            </div>
        </form>
    </div>

</x-filament-panels::page>
