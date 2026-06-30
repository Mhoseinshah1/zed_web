{{-- ============================================================================
     MAP homepage body (shell comes from layouts.app → templates/map). Colours via
     --zp-* only; copy via map_* site_setting(); real Location / Plan / Faq data.
     The heavy world-map partial is included here only (homepage), not site-wide.
     ============================================================================ --}}

<!-- map-home-marker -->

@php
    $mapLocations = $locations->filter(fn ($l) => $l->hasCoordinates())->values();
    $activeCount  = $locations->count();
@endphp

{{-- ===== HERO (two columns: copy + interactive map) ===== --}}
@if(\App\Models\SiteText::getBool('hero_is_active', true))
<section class="py-12 sm:py-16">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 grid lg:grid-cols-[0.85fr_1.15fr] gap-10 items-center">
        <div class="order-2 lg:order-1">
            <span class="inline-flex items-center gap-2 bg-green-500/10 border border-green-500/25 text-green-300 text-sm px-4 py-1.5 rounded-full mb-5 zed-animate">
                <span class="w-2 h-2 rounded-full bg-green-400 animate-pulse"></span>
                {{ site_setting('map_hero_pill', 'آنلاین در ' . $activeCount . ' کشور') }}
            </span>
            <h1 class="text-3xl sm:text-4xl font-black leading-tight text-white zed-animate">
                {{ site_setting('map_hero_title', 'اتصال امن به اینترنت آزاد') }}
            </h1>
            @if($hl = site_setting('map_hero_highlight', 'سرورهای سراسر دنیا'))
            <p class="mt-2 text-2xl font-black zed-gradient-text zed-animate">{{ $hl }}</p>
            @endif
            <p class="mt-5 text-base text-gray-400 max-w-md zed-animate">
                {{ site_setting('map_hero_description', 'سرورهای پرسرعت در سراسر دنیا، با تحویل آنی و پشتیبانی همیشگی. روی نقشه ببین کجا فعالیم.') }}
            </p>
            <div class="mt-7 flex flex-wrap gap-3">
                <a href="{{ site_setting('hero_primary_button_url', route('plans')) }}" class="zed-btn zed-btn-primary px-7 py-3 text-base font-bold">
                    {{ site_setting('map_hero_primary_btn', 'خرید سرویس') }}
                </a>
                <a href="{{ route('plans') }}" class="zed-btn px-7 py-3 text-base font-bold bg-gray-800 text-cyan-400 border border-gray-700 hover:border-cyan-400 transition">
                    {{ site_setting('map_hero_secondary_btn', 'مشاهده پلن‌ها') }}
                </a>
            </div>
        </div>

        <div class="order-1 lg:order-2">
            <div class="zed-card p-4 sm:p-5" style="background:linear-gradient(180deg,var(--zp-surface),var(--zp-bg-soft))">
                <div class="flex items-center justify-between px-1.5 mb-2">
                    <span class="text-[13px] text-gray-400">{{ site_setting('map_card_title', 'سرورهای فعال') }}</span>
                    <span class="text-[13px] font-bold text-cyan-400">{{ $mapLocations->count() }} لوکیشن آنلاین</span>
                </div>
                @include('partials.world-map', ['mapLocations' => $mapLocations])
            </div>
        </div>
    </div>
</section>
@endif

{{-- Top banners --}}
@if(isset($topBanners) && $topBanners->isNotEmpty())
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mb-4">
    @include('partials.banners', ['banners' => $topBanners])
</div>
@endif

{{-- ===== STATS BOX ===== --}}
<div class="bg-gray-900/60 border-y border-gray-800 py-7">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-5 text-center">
            @php
                $stats = [
                    ['n' => site_setting('map_stat_users', '۴۰٬۰۰۰+'), 'l' => 'کاربر راضی'],
                    ['n' => $activeCount, 'l' => 'لوکیشن سرور'],
                    ['n' => site_setting('map_stat_uptime', '۹۹.۹٪'), 'l' => 'پایداری اتصال'],
                    ['n' => site_setting('map_stat_support', '۲۴/۷'), 'l' => 'پشتیبانی زنده'],
                ];
            @endphp
            @foreach($stats as $s)
            <div>
                <div class="text-3xl font-black zed-gradient-text">{{ $s['n'] }}</div>
                <div class="text-xs text-gray-400 mt-1">{{ $s['l'] }}</div>
            </div>
            @endforeach
        </div>
    </div>
</div>

{{-- ===== LOCATIONS GRID (real Location data) ===== --}}
@if($locations->isNotEmpty())
<section class="py-14">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-9">
            <div class="text-sm font-bold text-cyan-400">{{ site_setting('map_locations_tag', 'شبکه‌ی جهانی') }}</div>
            <h2 class="text-3xl font-extrabold text-white mt-2">{{ site_setting('map_locations_title', 'لوکیشن‌های موجود') }}</h2>
            <p class="text-gray-400 mt-1">{{ site_setting('map_locations_sub', 'به نزدیک‌ترین سرور وصل شو') }}</p>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
            @foreach($locations as $location)
            <div class="bg-gray-900 border border-gray-800 rounded-2xl py-4 px-2 text-center hover:border-cyan-400 hover:-translate-y-1 transition zed-hover-lift">
                @if($location->flag_emoji)
                    <div class="text-2xl">{{ $location->flag_emoji }}</div>
                @else
                    <div class="text-xs font-bold text-cyan-400">{{ $location->country_code }}</div>
                @endif
                <div class="text-[13px] font-semibold text-white mt-1.5">{{ $location->country_name }}</div>
                <div class="text-[11px] text-green-400 mt-0.5">{{ $location->ping_ms ? $location->ping_ms . 'ms' : 'آنلاین' }}</div>
            </div>
            @endforeach
        </div>
    </div>
</section>
@endif

{{-- ===== PLANS SUMMARY (real data, featured highlighted) ===== --}}
@if($plans->isNotEmpty())
<section class="py-14 bg-gray-900/60 border-y border-gray-800">
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-10">
            <div class="text-sm font-bold text-cyan-400">{{ site_setting('map_plans_tag', 'قیمت‌گذاری شفاف') }}</div>
            <h2 class="text-3xl font-extrabold text-white mt-2">{{ site_setting('map_plans_title', 'پلن مناسبت رو انتخاب کن') }}</h2>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            @foreach($plans->take(3) as $plan)
                @include('partials.plan-card', ['plan' => $plan])
            @endforeach
        </div>
        @if($plans->count() > 3)
        <div class="text-center mt-10">
            <a href="{{ route('plans') }}" class="zed-btn px-8 py-3 font-bold bg-gray-800 text-white border border-gray-700 hover:bg-gray-700 inline-block">مشاهده همه پلن‌ها</a>
        </div>
        @endif
    </div>
</section>
@endif

{{-- ===== CONNECT IN 3 STEPS ===== --}}
<section class="py-14">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-9">
            <div class="text-sm font-bold text-cyan-400">{{ site_setting('map_steps_tag', 'ساده و سریع') }}</div>
            <h2 class="text-3xl font-extrabold text-white mt-2">{{ site_setting('map_steps_title', 'در ۳ قدم متصل شو') }}</h2>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            @php
                $steps = [
                    ['n' => '۱', 't' => site_setting('map_step_1_title', 'پلن رو انتخاب کن'), 'd' => site_setting('map_step_1_desc', 'پلن متناسب با نیازت رو انتخاب کن.')],
                    ['n' => '۲', 't' => site_setting('map_step_2_title', 'پرداخت کن'),       'd' => site_setting('map_step_2_desc', 'با کیف پول، کریپتو یا کارت بانکی — تحویل آنیه.')],
                    ['n' => '۳', 't' => site_setting('map_step_3_title', 'وصل شو'),          'd' => site_setting('map_step_3_desc', 'لینک اتصال رو بگیر و در چند ثانیه وصل شو.')],
                ];
            @endphp
            @foreach($steps as $step)
            <div class="zed-card p-7">
                <div class="w-11 h-11 rounded-xl zed-gradient-bg flex items-center justify-center font-extrabold text-lg text-white mb-4">{{ $step['n'] }}</div>
                <h3 class="text-lg font-bold text-white mb-2">{{ $step['t'] }}</h3>
                <p class="text-sm text-gray-400">{{ $step['d'] }}</p>
            </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ===== FAQ (real Faq data) ===== --}}
@if(isset($faqs) && $faqs->isNotEmpty())
<section class="py-14 max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
    <div class="text-center mb-8">
        <h2 class="text-2xl font-bold text-white">{{ site_setting('map_faq_title', 'سوالات متداول') }}</h2>
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
    <div class="text-center mt-6">
        <a href="{{ route('faq') }}" class="text-cyan-400 hover:text-cyan-300 text-sm font-medium">مشاهده همه سوالات →</a>
    </div>
</section>
@endif

{{-- Middle banners --}}
@if(isset($middleBanners) && $middleBanners->isNotEmpty())
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    @include('partials.banners', ['banners' => $middleBanners])
</div>
@endif

{{-- ===== FINAL CTA ===== --}}
<section class="py-12">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="relative rounded-3xl px-6 py-14 text-center zed-gradient-bg overflow-hidden">
            <h2 class="relative text-3xl font-black text-white mb-3">{{ site_setting('map_cta_title', 'همین حالا به شبکه‌ی جهانی ما وصل شو') }}</h2>
            <p class="relative text-white/85 mb-7">{{ site_setting('map_cta_subtitle', 'تحویل آنی · ۷ روز ضمانت بازگشت وجه') }}</p>
            <a href="{{ site_setting('hero_primary_button_url', route('plans')) }}" class="relative inline-block bg-white text-indigo-700 font-black px-9 py-3.5 rounded-xl text-base hover:bg-indigo-50 transition">
                {{ site_setting('map_cta_button', 'خرید سرویس') }}
            </a>
        </div>
    </div>
</section>

@push('scripts')
<script src="//unpkg.com/alpinejs" defer></script>
@endpush
