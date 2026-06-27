@extends('layouts.app')

@section('title', 'آموزش‌ها')
@section('description', 'راهنمای اتصال به VPN و پروکسی روی دستگاه‌های مختلف')

@section('content')
<section class="py-16 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
    <div class="text-center mb-14">
        <h1 class="text-4xl font-extrabold text-white">آموزش‌های اتصال</h1>
        <p class="text-gray-400 mt-3">راهنمای گام‌به‌گام اتصال روی تمام دستگاه‌ها</p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
        @foreach ([
            ['os' => 'اندروید', 'icon' => '🤖', 'app' => 'V2RayNG', 'steps' => ['دانلود V2RayNG از Google Play', 'در پنل کاربری، لینک اشتراک را کپی کنید', 'در V2RayNG گزینه + سپس Import config from URL را بزنید', 'لینک را paste کنید و اتصال را شروع کنید']],
            ['os' => 'iOS (آیفون)', 'icon' => '🍎', 'app' => 'V2Box', 'steps' => ['دانلود V2Box از App Store', 'در پنل کاربری، لینک اشتراک را کپی کنید', 'در V2Box گزینه Subscriptions را بزنید', 'لینک را اضافه کنید و اتصال را شروع کنید']],
            ['os' => 'ویندوز', 'icon' => '🪟', 'app' => 'Hiddify', 'steps' => ['دانلود Hiddify از سایت رسمی', 'در پنل کاربری، لینک اشتراک را کپی کنید', 'در Hiddify گزینه Add Profile را بزنید', 'لینک را paste کنید و Connect را بزنید']],
            ['os' => 'مک (macOS)', 'icon' => '💻', 'app' => 'V2Box', 'steps' => ['دانلود V2Box از Mac App Store', 'در پنل کاربری، لینک اشتراک را کپی کنید', 'در V2Box گزینه Subscriptions را بزنید', 'لینک را اضافه کنید و اتصال را شروع کنید']],
        ] as $tutorial)
        <div class="bg-gray-900 border border-gray-800 rounded-2xl p-6 hover:border-gray-700 transition">
            <div class="flex items-center gap-3 mb-5">
                <span class="text-3xl">{{ $tutorial['icon'] }}</span>
                <div>
                    <h3 class="font-bold text-white text-lg">{{ $tutorial['os'] }}</h3>
                    <p class="text-indigo-400 text-sm">اپلیکیشن: {{ $tutorial['app'] }}</p>
                </div>
            </div>
            <ol class="space-y-3">
                @foreach ($tutorial['steps'] as $i => $step)
                <li class="flex items-start gap-3 text-sm text-gray-300">
                    <span class="flex-shrink-0 w-6 h-6 rounded-full bg-indigo-600/30 text-indigo-300 text-xs flex items-center justify-center font-bold mt-0.5">{{ $i + 1 }}</span>
                    {{ $step }}
                </li>
                @endforeach
            </ol>
        </div>
        @endforeach
    </div>

    <div class="mt-10 bg-yellow-500/10 border border-yellow-500/20 rounded-xl p-6 text-sm text-yellow-200">
        <strong>نکته:</strong> پس از خرید پلن، از پنل کاربری خود لینک اشتراک را دریافت کنید.
        این لینک به‌صورت خودکار به‌روزرسانی می‌شود و نیازی به تغییر تنظیمات دستی ندارید.
    </div>
</section>
@endsection
