@extends('layouts.app')

@section('title', site_setting('home_meta_title') ?: 'خانه')
@section('description', site_setting('home_meta_description', site_setting('hero_description', 'خرید VPN و پروکسی با کیفیت بالا، سرعت فوق‌العاده و قیمت مناسب - ZedProxy')))
@if($k = site_setting('home_meta_keywords'))@section('meta_keywords', $k)@endif
@if($ot = site_setting('home_og_title'))@section('og_title', $ot)@endif
@if($od = site_setting('home_og_description'))@section('og_description', $od)@endif
@if($oi = cms_image('home_og_image'))@section('og_image', $oi)@endif

@section('content')

{{-- Hero --}}
@if(\App\Models\SiteText::getBool('hero_is_active', true))
@php $heroBg = cms_image('hero_background_image'); @endphp
<section class="relative overflow-hidden bg-gradient-to-b from-gray-900 via-gray-950 to-gray-950 py-24 sm:py-32"
         @if($heroBg) style="background-image:linear-gradient(rgba(8,10,18,.82),rgba(8,10,18,.92)),url('{{ $heroBg }}');background-size:cover;background-position:center" @endif>
    <div class="absolute inset-0 bg-[radial-gradient(ellipse_at_top,_var(--tw-gradient-stops))] from-indigo-900/20 via-transparent to-transparent pointer-events-none"></div>
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center relative">

        @if($badge = site_setting('hero_badge_text', site_setting('homepage.status.badge', 'سرویس حرفه‌ای VPN و Proxy')))
        <div class="inline-flex items-center gap-2 bg-indigo-500/10 border border-indigo-500/20 text-indigo-300 text-sm px-4 py-1.5 rounded-full mb-6 zed-animate">
            <span class="w-2 h-2 bg-green-400 rounded-full animate-pulse"></span>
            {{ $badge }}
        </div>
        @endif

        <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold text-white leading-tight zed-animate">
            {{ site_setting('hero_title', 'ZedProxy؛ اتصال پایدار، سریع و امن') }}
        </h1>

        @if($heroSub = site_setting('hero_subtitle'))
        <p class="mt-4 text-xl text-indigo-200/80 font-medium zed-animate">{{ $heroSub }}</p>
        @endif

        <p class="mt-6 text-lg text-gray-400 max-w-2xl mx-auto leading-relaxed zed-animate">
            {{ site_setting('hero_description', 'سرویس‌های پرسرعت برای اتصال امن و پایدار') }}
        </p>

        <div class="mt-10 flex flex-col sm:flex-row gap-4 justify-center">
            <a href="{{ site_setting('hero_primary_button_url', route('plans')) }}" class="zed-btn zed-btn-primary font-semibold px-8 py-3.5 text-center shadow-lg shadow-indigo-500/20">
                {{ site_setting('hero_primary_button_text', 'خرید سرویس') }}
            </a>
            <a href="{{ site_setting('hero_secondary_button_url', route('tutorials')) }}" class="zed-btn bg-gray-800 hover:bg-gray-700 text-white font-semibold px-8 py-3.5 text-center border border-gray-700">
                {{ site_setting('hero_secondary_button_text', 'ورود به داشبورد') }}
            </a>
        </div>
    </div>
</section>
@endif

{{-- Top banners --}}
@if(isset($topBanners) && $topBanners->isNotEmpty())
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-8">
    @include('partials.banners', ['banners' => $topBanners])
</div>
@endif

{{-- Custom landing sections (trust / steps / custom etc.) --}}
@if(isset($sections) && $sections->isNotEmpty())
@foreach($sections as $section)
    @continue(in_array($section->type, ['hero', 'features', 'locations', 'plans_preview', 'faq']))
    <section class="py-12 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="zed-card zed-animate p-8 text-center">
            @if($section->title)<h2 class="text-2xl font-bold text-white">{{ $section->title }}</h2>@endif
            @if($section->subtitle)<p class="text-gray-400 mt-2">{{ $section->subtitle }}</p>@endif
            @if($section->content)<p class="text-gray-300 mt-4 max-w-2xl mx-auto leading-relaxed">{{ $section->content }}</p>@endif
            @if(is_array($section->items) && count($section->items))
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-6 text-right">
                @foreach($section->items as $item)
                <div class="zed-surface-soft rounded-xl p-4">
                    @if(!empty($item['icon']))<div class="text-2xl mb-2">{{ $item['icon'] }}</div>@endif
                    @if(!empty($item['title']))<p class="font-semibold text-white">{{ $item['title'] }}</p>@endif
                    @if(!empty($item['description']))<p class="text-sm text-gray-400 mt-1">{{ $item['description'] }}</p>@endif
                </div>
                @endforeach
            </div>
            @endif
            @if($section->button_text && $section->button_url)
            <a href="{{ $section->button_url }}" class="zed-btn zed-btn-primary inline-block mt-6 px-6 py-2.5 font-semibold">{{ $section->button_text }}</a>
            @endif
        </div>
    </section>
@endforeach
@endif

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

{{-- Middle banners --}}
@if(isset($middleBanners) && $middleBanners->isNotEmpty())
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
    @include('partials.banners', ['banners' => $middleBanners])
</div>
@endif

{{-- FAQ preview --}}
@if(isset($faqs) && $faqs->isNotEmpty())
<section class="py-16 max-w-3xl mx-auto px-4 sm:px-6 lg:px-8" x-data>
    <div class="text-center mb-10">
        <h2 class="text-3xl font-bold text-white">سوالات متداول</h2>
        <p class="text-gray-400 mt-2">پاسخ پرسش‌های رایج</p>
    </div>
    <div class="space-y-3">
        @foreach($faqs as $faq)
        <div class="zed-card overflow-hidden" x-data="{ open: false }">
            <button @click="open = !open" class="w-full flex items-center justify-between p-4 text-right hover:bg-gray-800/50 transition">
                <span class="font-medium text-white text-sm">{{ $faq->question }}</span>
                <svg class="w-5 h-5 text-gray-400 transition-transform" :class="{ 'rotate-180': open }" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
            </button>
            <div x-show="open" x-transition class="px-4 pb-4 text-gray-400 text-sm leading-relaxed border-t border-gray-800"><div class="pt-3">{{ $faq->answer }}</div></div>
        </div>
        @endforeach
    </div>
    <div class="text-center mt-8">
        <a href="{{ route('faq') }}" class="text-indigo-400 hover:text-indigo-300 text-sm font-medium">مشاهده همه سوالات →</a>
    </div>
</section>
@endif

{{-- CTA --}}
<section class="py-16 bg-gradient-to-l from-indigo-900/30 to-purple-900/20 border-y border-gray-800">
    <div class="max-w-3xl mx-auto px-4 text-center">
        <h2 class="text-3xl font-bold text-white mb-4">{{ site_setting('homepage.cta.title', 'همین الان شروع کنید') }}</h2>
        <p class="text-gray-400 mb-8">{{ site_setting('homepage.cta.subtitle', 'ثبت‌نام رایگان، خرید آسان و اتصال فوری') }}</p>
        <a href="{{ route('register') }}" class="zed-btn zed-btn-primary font-bold px-10 py-4 text-lg shadow-xl shadow-indigo-500/20 inline-block">
            ثبت‌نام رایگان
        </a>
    </div>
</section>

@endsection
@push('scripts')
<script src="//unpkg.com/alpinejs" defer></script>
@endpush
