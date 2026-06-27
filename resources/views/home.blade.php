@extends('layouts.app')

@section('title', 'خانه')
@section('description', 'خرید VPN و پروکسی با کیفیت بالا، سرعت فوق‌العاده و قیمت مناسب - ZedProxy')

@section('content')

{{-- Hero --}}
<section class="relative overflow-hidden bg-gradient-to-b from-gray-900 via-gray-950 to-gray-950 py-24 sm:py-32">
    <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-indigo-900/20 via-transparent to-transparent pointer-events-none"></div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center relative">

        <div class="inline-flex items-center gap-2 bg-indigo-500/10 border border-indigo-500/20 text-indigo-300 text-sm px-4 py-1.5 rounded-full mb-6">
            <span class="w-2 h-2 bg-green-400 rounded-full animate-pulse"></span>
            {{ site_setting('homepage.status.badge', 'سرویس در حال اجراست') }}
        </div>

        @php
            $heroTitle = site_setting('homepage.hero.title', "اینترنت آزاد\nبدون محدودیت");
            [$heroLine1, $heroLine2] = array_pad(explode("\n", $heroTitle, 2), 2, '');
        @endphp
        <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold text-white leading-tight">
            {{ $heroLine1 }}<br>
            <span class="text-transparent bg-clip-text bg-gradient-to-l from-indigo-400 to-purple-400">{{ $heroLine2 }}</span>
        </h1>

        <p class="mt-6 text-lg text-gray-400 max-w-2xl mx-auto leading-relaxed">
            {!! nl2br(e(site_setting('homepage.hero.subtitle', "با ZedProxy از هر نقطه‌ای در جهان به اینترنت آزاد دسترسی داشته باشید.\nسرعت بالا، امنیت کامل و پشتیبانی ۲۴ ساعته."))) !!}
        </p>

        <div class="mt-10 flex flex-col sm:flex-row gap-4 justify-center">
            <a href="{{ route('plans') }}" class="bg-indigo-600 hover:bg-indigo-500 text-white font-semibold px-8 py-3.5 rounded-xl transition text-center shadow-lg shadow-indigo-500/20">
                {{ site_setting('homepage.hero.button_text', 'مشاهده پلن‌ها') }}
            </a>
            <a href="{{ route('tutorials') }}" class="bg-gray-800 hover:bg-gray-700 text-white font-semibold px-8 py-3.5 rounded-xl transition text-center border border-gray-700">
                {{ site_setting('homepage.hero.secondary_button_text', 'آموزش اتصال') }}
            </a>
        </div>
    </div>
</section>

{{-- Features --}}
@if($features->isNotEmpty())
<section class="py-20 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <div class="text-center mb-14">
        <h2 class="text-3xl font-bold text-white">{{ site_setting('homepage.features.title', 'چرا ZedProxy؟') }}</h2>
        <p class="text-gray-400 mt-3">{{ site_setting('homepage.features.subtitle', 'بهترین انتخاب برای اتصال امن و سریع') }}</p>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
        @foreach ($features as $feature)
        <div class="bg-gray-900 border border-gray-800 rounded-2xl p-6 hover:border-indigo-500/50 transition">
            @if($feature->icon)
            <div class="text-3xl mb-4">{{ $feature->icon }}</div>
            @endif
            <h3 class="font-semibold text-white text-lg mb-2">{{ $feature->title }}</h3>
            @if($feature->description)
            <p class="text-gray-400 text-sm leading-relaxed">{{ $feature->description }}</p>
            @endif
        </div>
        @endforeach
    </div>
</section>
@endif

{{-- Plans preview --}}
@if($plans->isNotEmpty())
<section class="py-16 bg-gray-900/50 border-y border-gray-800">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-12">
            <h2 class="text-3xl font-bold text-white">{{ site_setting('homepage.plans.title', 'انتخاب پلن مناسب') }}</h2>
            <p class="text-gray-400 mt-3">{{ site_setting('homepage.plans.subtitle', 'قیمت‌های مناسب برای همه نیازها') }}</p>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            @foreach($plans->take(3) as $plan)
            @include('partials.plan-card', ['plan' => $plan])
            @endforeach
        </div>
        @if($plans->count() > 3)
        <div class="text-center mt-10">
            <a href="{{ route('plans') }}" class="inline-block bg-gray-800 hover:bg-gray-700 text-white font-semibold px-8 py-3 rounded-xl transition border border-gray-700">
                مشاهده همه پلن‌ها
            </a>
        </div>
        @endif
    </div>
</section>
@endif

{{-- Locations --}}
@if($locations->isNotEmpty())
<section class="py-16 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <div class="text-center mb-10">
        <h2 class="text-2xl font-bold text-white">لوکیشن‌های سرور</h2>
        <p class="text-gray-400 mt-2">سرورهای پرسرعت در سراسر جهان</p>
    </div>
    <div class="flex flex-wrap justify-center gap-4">
        @foreach($locations as $location)
        <div class="flex items-center gap-2 bg-gray-900 border border-gray-800 rounded-xl px-5 py-3 hover:border-indigo-500/50 transition">
            @if($location->flag_emoji)
            <span class="text-2xl">{{ $location->flag_emoji }}</span>
            @endif
            <span class="text-gray-200 font-medium">{{ $location->country_name }}</span>
            @if($location->is_youtube_special)
            <span class="text-xs bg-red-500/20 text-red-400 px-2 py-0.5 rounded-full">YouTube</span>
            @endif
        </div>
        @endforeach
    </div>
</section>
@endif

{{-- CTA --}}
<section class="py-16 bg-gradient-to-l from-indigo-900/30 to-purple-900/20 border-y border-gray-800">
    <div class="max-w-3xl mx-auto px-4 text-center">
        <h2 class="text-3xl font-bold text-white mb-4">{{ site_setting('homepage.cta.title', 'همین الان شروع کنید') }}</h2>
        <p class="text-gray-400 mb-8">{{ site_setting('homepage.cta.subtitle', 'ثبت‌نام رایگان، خرید آسان و اتصال فوری') }}</p>
        <a href="{{ route('register') }}" class="bg-indigo-600 hover:bg-indigo-500 text-white font-bold px-10 py-4 rounded-xl transition text-lg shadow-xl shadow-indigo-500/20">
            ثبت‌نام رایگان
        </a>
    </div>
</section>

@endsection
