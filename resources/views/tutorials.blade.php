@extends('layouts.app')

@section('title', 'آموزش‌ها')
@section('description', 'راهنمای اتصال به VPN و پروکسی روی دستگاه‌های مختلف')

@section('content')
<section class="py-16 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
    <div class="text-center mb-14">
        <h1 class="text-4xl font-extrabold text-white">آموزش‌های اتصال</h1>
        <p class="text-gray-400 mt-3">راهنمای گام‌به‌گام اتصال روی تمام دستگاه‌ها</p>
    </div>

    @if(isset($tutorials) && $tutorials->isNotEmpty())
        @php $platforms = \App\Models\Tutorial::platforms(); @endphp
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
            @foreach($tutorials as $tutorial)
                <a href="{{ route('tutorials.show', $tutorial->slug) }}"
                   class="zed-card zed-hover-lift block p-6 group">
                    @if($img = cms_asset_url($tutorial->image))
                        <img src="{{ $img }}" alt="{{ $tutorial->title }}" class="h-32 w-full object-cover rounded-xl mb-4">
                    @endif
                    <span class="inline-block text-[11px] px-2 py-0.5 rounded-full bg-indigo-600/15 text-indigo-300 mb-3">
                        {{ $platforms[$tutorial->platform] ?? $tutorial->platform }}
                    </span>
                    <h3 class="font-bold text-white text-lg group-hover:text-indigo-300 transition">{{ $tutorial->title }}</h3>
                    @if($tutorial->short_description)
                        <p class="text-gray-400 text-sm mt-2">{{ $tutorial->short_description }}</p>
                    @endif
                    <span class="inline-flex items-center gap-1 text-indigo-400 text-sm mt-4">مشاهده آموزش
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 17l-5-5 5-5M18 17l-5-5 5-5"/></svg>
                    </span>
                </a>
            @endforeach
        </div>
    @else
        {{-- Fallback content if no tutorials are configured yet --}}
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
            @foreach ([
                ['os' => 'اندروید', 'icon' => '🤖', 'app' => 'V2RayNG'],
                ['os' => 'iOS (آیفون)', 'icon' => '🍎', 'app' => 'V2Box'],
                ['os' => 'ویندوز', 'icon' => '🪟', 'app' => 'Hiddify'],
                ['os' => 'مک (macOS)', 'icon' => '💻', 'app' => 'V2Box'],
            ] as $t)
            <div class="zed-card p-6">
                <div class="flex items-center gap-3">
                    <span class="text-3xl">{{ $t['icon'] }}</span>
                    <div>
                        <h3 class="font-bold text-white text-lg">{{ $t['os'] }}</h3>
                        <p class="text-indigo-400 text-sm">اپلیکیشن: {{ $t['app'] }}</p>
                    </div>
                </div>
            </div>
            @endforeach
        </div>
    @endif

    <div class="mt-10 bg-yellow-500/10 border border-yellow-500/20 rounded-xl p-6 text-sm text-yellow-200">
        <strong>نکته:</strong> پس از خرید پلن، از پنل کاربری خود لینک اشتراک را دریافت کنید.
        این لینک به‌صورت خودکار به‌روزرسانی می‌شود و نیازی به تغییر تنظیمات دستی ندارید.
    </div>
</section>
@endsection
