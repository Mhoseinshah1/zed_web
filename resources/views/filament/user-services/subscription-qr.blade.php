<div class="p-4 space-y-4">
    {{-- Service info --}}
    <div class="text-sm text-gray-600 dark:text-gray-400 space-y-1">
        <div><span class="font-medium">سرویس:</span> {{ $service->service_number }}</div>
        <div><span class="font-medium">پلن:</span> {{ $service->plan_name }}</div>
        @if($service->remote_username)
        <div><span class="font-medium">نام کاربری:</span> <code class="font-mono text-xs">{{ $service->remote_username }}</code></div>
        @endif
    </div>

    {{-- QR Code --}}
    <div class="flex flex-col items-center gap-3">
        <div class="bg-white p-4 rounded-xl shadow-sm border border-gray-100">
            {!! QrCode::format('svg')->size(220)->errorCorrection('M')->generate($service->subscription_link) !!}
        </div>
        <p class="text-xs text-gray-500">بارکد لینک اشتراک</p>
    </div>

    {{-- Subscription link --}}
    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3">
        <p class="text-xs font-medium text-gray-500 mb-1">لینک اشتراک:</p>
        <code class="text-xs break-all text-indigo-600 dark:text-indigo-400 font-mono leading-5 block">
            {{ $service->subscription_link }}
        </code>
    </div>

    {{-- Config QR if exists --}}
    @if($service->config_link)
    <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
        <p class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">بارکد لینک کانفیگ مستقیم:</p>
        <div class="flex flex-col items-center gap-3">
            <div class="bg-white p-4 rounded-xl shadow-sm border border-gray-100">
                {!! QrCode::format('svg')->size(180)->errorCorrection('M')->generate($service->config_link) !!}
            </div>
        </div>
        <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3 mt-3">
            <p class="text-xs font-medium text-gray-500 mb-1">لینک کانفیگ:</p>
            <code class="text-xs break-all text-gray-600 dark:text-gray-400 font-mono leading-5 block">
                {{ $service->config_link }}
            </code>
        </div>
    </div>
    @endif
</div>
