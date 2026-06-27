@extends('layouts.app')

@section('title', 'وضعیت سرویس')

@section('content')
<section class="py-16 max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
    <div class="text-center mb-14">
        <h1 class="text-4xl font-extrabold text-white">وضعیت سرویس</h1>
        <p class="text-gray-400 mt-3">آخرین وضعیت سرورها و سرویس‌های ZedProxy</p>
    </div>

    <div class="bg-green-500/10 border border-green-500/30 rounded-2xl p-6 flex items-center gap-4 mb-8">
        <span class="w-4 h-4 bg-green-400 rounded-full animate-pulse flex-shrink-0"></span>
        <div>
            <div class="text-green-300 font-semibold">تمام سرویس‌ها در حال اجرا هستند</div>
            <div class="text-green-400/70 text-sm mt-0.5">آخرین بررسی: {{ now()->format('H:i - Y/m/d') }}</div>
        </div>
    </div>

    <div class="space-y-3">
        @foreach ([
            ['name' => 'سرور اروپا ۱ (آلمان)', 'ping' => '۱۸ms', 'uptime' => '۹۹.۹٪', 'status' => 'ok'],
            ['name' => 'سرور اروپا ۲ (هلند)', 'ping' => '۲۲ms', 'uptime' => '۹۹.۸٪', 'status' => 'ok'],
            ['name' => 'سرور آمریکا (نیویورک)', 'ping' => '۱۱۰ms', 'uptime' => '۹۹.۷٪', 'status' => 'ok'],
            ['name' => 'سرور آسیا (ژاپن)', 'ping' => '۸۵ms', 'uptime' => '۹۹.۹٪', 'status' => 'ok'],
            ['name' => 'پنل کاربری', 'ping' => '-', 'uptime' => '۱۰۰٪', 'status' => 'ok'],
            ['name' => 'درگاه پرداخت', 'ping' => '-', 'uptime' => '۹۹.۵٪', 'status' => 'ok'],
        ] as $service)
        <div class="bg-gray-900 border border-gray-800 rounded-xl px-6 py-4 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span class="w-2.5 h-2.5 rounded-full {{ $service['status'] === 'ok' ? 'bg-green-400' : 'bg-red-400' }}"></span>
                <span class="text-white font-medium">{{ $service['name'] }}</span>
            </div>
            <div class="flex items-center gap-8 text-sm text-gray-400">
                @if ($service['ping'] !== '-')
                <span>پینگ: {{ $service['ping'] }}</span>
                @endif
                <span>آپتایم: {{ $service['uptime'] }}</span>
                <span class="{{ $service['status'] === 'ok' ? 'text-green-400' : 'text-red-400' }} font-medium">
                    {{ $service['status'] === 'ok' ? 'فعال' : 'مشکل دارد' }}
                </span>
            </div>
        </div>
        @endforeach
    </div>

    <p class="text-center text-gray-600 text-xs mt-8">
        این صفحه هر ۵ دقیقه به‌روزرسانی می‌شود. داده‌ها نمونه هستند و در نسخه نهایی از سیستم مانیتورینگ واقعی می‌آیند.
    </p>
</section>
@endsection
