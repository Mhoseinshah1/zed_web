<x-filament-panels::page>
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        @foreach ($checks as $key => $check)
            <div class="fi-wi-stats-overview-stat relative rounded-xl bg-white p-6 shadow-sm ring-1 dark:bg-gray-900
                {{ $check['ok'] ? 'ring-green-500/20' : 'ring-red-500/30' }}">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-full
                        {{ $check['ok'] ? 'bg-green-100 dark:bg-green-500/10' : 'bg-red-100 dark:bg-red-500/10' }}">
                        @if ($check['ok'])
                            <x-heroicon-o-check-circle class="h-6 w-6 text-green-600 dark:text-green-400"/>
                        @else
                            <x-heroicon-o-x-circle class="h-6 w-6 text-red-600 dark:text-red-400"/>
                        @endif
                    </div>
                    <div>
                        <p class="text-sm font-medium text-gray-600 dark:text-gray-400">{{ $check['label'] }}</p>
                        <p class="text-xs mt-0.5 {{ $check['ok'] ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                            {{ $check['detail'] }}
                        </p>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="mt-8 fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
        <div class="fi-section-header flex flex-col gap-y-1 px-6 py-4">
            <h3 class="fi-section-heading text-base font-semibold leading-6 text-gray-950 dark:text-white">
                اطلاعات سیستم
            </h3>
        </div>
        <div class="fi-section-content border-t border-gray-200 px-6 py-4 dark:border-white/10">
            <dl class="grid grid-cols-2 md:grid-cols-4 gap-4 text-sm">
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">PHP</dt>
                    <dd class="font-medium text-gray-900 dark:text-white">{{ PHP_VERSION }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Laravel</dt>
                    <dd class="font-medium text-gray-900 dark:text-white">{{ app()->version() }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">محیط</dt>
                    <dd class="font-medium text-gray-900 dark:text-white">{{ app()->environment() }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Cache</dt>
                    <dd class="font-medium text-gray-900 dark:text-white">{{ config('cache.default') }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Queue</dt>
                    <dd class="font-medium text-gray-900 dark:text-white">{{ config('queue.default') }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Session</dt>
                    <dd class="font-medium text-gray-900 dark:text-white">{{ config('session.driver') }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">دیتابیس</dt>
                    <dd class="font-medium text-gray-900 dark:text-white">{{ config('database.default') }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500 dark:text-gray-400">Debug</dt>
                    <dd class="font-medium {{ config('app.debug') ? 'text-yellow-500' : 'text-green-500' }}">
                        {{ config('app.debug') ? 'روشن' : 'خاموش' }}
                    </dd>
                </div>
            </dl>
        </div>
    </div>
</x-filament-panels::page>
