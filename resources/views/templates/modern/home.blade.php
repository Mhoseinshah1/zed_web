{{-- ============================================================================
     MODERN homepage template (uses layouts.modern). Structure approved from the
     design reference; colours come exclusively from the active theme (--zp-*)
     via the project's Tailwind classes. All copy is editable via site_setting().
     ============================================================================ --}}

<!-- modern-home-marker -->

{{-- ===== Hero ===== --}}
@if(\App\Models\SiteText::getBool('hero_is_active', true))
<section class="relative overflow-hidden py-20 sm:py-24 text-center"
         style="background:radial-gradient(ellipse at top, color-mix(in srgb, var(--zp-primary) 14%, transparent), transparent 60%)">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        @if($badge = site_setting('hero_badge_text', 'سرویس حرفه‌ای VPN و Proxy'))
        <span class="inline-flex items-center gap-2 bg-indigo-500/10 border border-indigo-500/25 text-indigo-300 text-sm px-4 py-1.5 rounded-full mb-6 zed-animate">
            <span class="w-2 h-2 rounded-full bg-green-400 animate-pulse"></span>{{ $badge }}
        </span>
        @endif

        <h1 class="text-4xl sm:text-5xl font-extrabold leading-tight text-white zed-animate">
            {{ site_setting('hero_title', 'اتصال پایدار، سریع و امن به اینترنت آزاد') }}
        </h1>

        @if($heroSub = site_setting('hero_subtitle', 'اینترنت آزاد، سریع و پایدار'))
        <p class="mt-3 text-2xl sm:text-3xl font-extrabold zed-gradient-text zed-animate">{{ $heroSub }}</p>
        @endif

        <p class="mt-5 text-lg text-gray-400 max-w-xl mx-auto zed-animate">
            {{ site_setting('hero_description', 'سرویس‌های پرسرعت با تحویل آنی، پشتیبانی همیشگی و قیمت مناسب — همین حالا شروع کن.') }}
        </p>

        <div class="mt-9 flex flex-col sm:flex-row gap-3.5 justify-center">
            <a href="{{ site_setting('hero_primary_button_url', route('plans')) }}" class="zed-btn zed-btn-primary px-8 py-3 text-base font-semibold">
                {{ site_setting('hero_primary_button_text', 'خرید سرویس') }}
            </a>
            <a href="{{ site_setting('hero_secondary_button_url', route('tutorials')) }}" class="zed-btn px-8 py-3 text-base font-semibold bg-gray-800 text-cyan-400 border border-gray-700 hover:border-cyan-400 transition">
                {{ site_setting('hero_secondary_button_text', 'آموزش اتصال') }}
            </a>
        </div>
    </div>
</section>
@endif

{{-- Top banners --}}
@if(isset($topBanners) && $topBanners->isNotEmpty())
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-6">
    @include('partials.banners', ['banners' => $topBanners])
</div>
@endif

{{-- ===== Trust strip (4 items, real SVG icons) ===== --}}
<div class="bg-gray-900/60 border-y border-gray-800 py-7">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-5">
            @php
                $trust = [
                    ['t' => site_setting('trust_1_title', 'تحویل سریع'),     's' => site_setting('trust_1_sub', 'فعال‌سازی آنی پس از پرداخت'), 'svg' => '<path d="M13 2 3 14h7l-1 8 10-12h-7l1-8z"/>'],
                    ['t' => site_setting('trust_2_title', 'پشتیبانی ۲۴ ساعته'), 's' => site_setting('trust_2_sub', 'همیشه کنارتیم'),            'svg' => '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>'],
                    ['t' => site_setting('trust_3_title', 'اتصال امن'),      's' => site_setting('trust_3_sub', 'رمزنگاری کامل ترافیک'),       'svg' => '<path d="M12 2 4 6v6c0 5 3.4 7.7 8 10 4.6-2.3 8-5 8-10V6l-8-4z"/>'],
                    ['t' => site_setting('trust_4_title', 'سرعت بالا'),      's' => site_setting('trust_4_sub', 'سرورهای پرسرعت اختصاصی'),     'svg' => '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>'],
                ];
            @endphp
            @foreach($trust as $item)
            <div class="flex items-center gap-3 justify-center sm:justify-start">
                <span class="w-11 h-11 shrink-0 rounded-xl bg-gray-800 text-cyan-400 flex items-center justify-center">
                    <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">{!! $item['svg'] !!}</svg>
                </span>
                <div>
                    <div class="font-bold text-sm text-white">{{ $item['t'] }}</div>
                    <div class="text-xs text-gray-400">{{ $item['s'] }}</div>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</div>

{{-- ===== Plans (middle/featured highlighted) ===== --}}
@if($plans->isNotEmpty())
@php
    $shownPlans = $plans->take(3)->values();
    $featuredId = optional($shownPlans->firstWhere('is_featured', true))->id
        ?? optional($shownPlans->get(intdiv($shownPlans->count(), 2)))->id;
@endphp
<section class="py-16">
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-11">
            <h2 class="text-3xl font-bold text-white">{{ site_setting('homepage.plans.title', 'انتخاب پلن مناسب') }}</h2>
            <p class="text-gray-400 mt-2.5">{{ site_setting('homepage.plans.subtitle', 'قیمت‌های مناسب برای همه نیازها') }}</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 max-w-4xl mx-auto">
            @foreach($shownPlans as $plan)
                @php $isFeat = $plan->id === $featuredId; @endphp
                <div class="relative rounded-2xl p-7 flex flex-col transition zed-hover-lift border
                    {{ $isFeat
                        ? 'zed-gradient-bg border-cyan-400 shadow-2xl shadow-indigo-500/40'
                        : 'bg-gray-900 border-gray-800 hover:border-gray-700' }}">

                    @if($isFeat)
                    <span class="absolute -top-3 right-1/2 translate-x-1/2 bg-amber-400 text-gray-950 text-xs font-bold px-4 py-1 rounded-full whitespace-nowrap">
                        {{ site_setting('featured_badge_text', 'پرفروش‌ترین') }}
                    </span>
                    @endif

                    <div class="text-sm font-semibold {{ $isFeat ? 'text-blue-100' : 'text-gray-400' }}">{{ $plan->name }}</div>
                    <div class="mt-3 text-3xl font-extrabold text-white">
                        {{ $plan->formattedPrice() }} <small class="text-base font-normal {{ $isFeat ? 'text-blue-100' : 'text-gray-400' }}">تومان</small>
                    </div>
                    <div class="mt-1 text-xs {{ $isFeat ? 'text-blue-200' : 'text-gray-400' }}">
                        {{ $plan->duration_days }} روزه · {{ $plan->traffic_gb ? $plan->traffic_gb . ' گیگابایت' : 'نامحدود' }}
                    </div>

                    <ul class="my-6 flex-1 space-y-2.5">
                        @php
                            $lines = is_array($plan->feature_list) && count($plan->feature_list)
                                ? $plan->feature_list
                                : ($plan->features->isNotEmpty()
                                    ? $plan->features->pluck('title')->all()
                                    : array_filter([
                                        'حجم: ' . ($plan->traffic_gb ? $plan->traffic_gb . ' گیگابایت' : 'نامحدود'),
                                        'سرعت نامحدود',
                                    ]));
                        @endphp
                        @foreach($lines as $line)
                        <li class="flex items-center gap-2 text-sm {{ $isFeat ? 'text-blue-50' : 'text-gray-200' }}">
                            <svg class="w-4 h-4 shrink-0 {{ $isFeat ? 'text-amber-300' : 'text-green-400' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            {{ $line }}
                        </li>
                        @endforeach
                    </ul>

                    <a href="{{ route('plans') }}"
                       class="text-center py-3 rounded-xl font-bold text-sm transition
                       {{ $isFeat ? 'bg-white text-indigo-700 hover:bg-indigo-50' : 'bg-gray-800 text-white border border-gray-700 hover:bg-gray-700' }}">
                        خرید این پلن
                    </a>
                </div>
            @endforeach
        </div>

        @if($plans->count() > 3)
        <div class="text-center mt-10">
            <a href="{{ route('plans') }}" class="zed-btn px-8 py-3 font-semibold bg-gray-800 text-white border border-gray-700 hover:bg-gray-700 inline-block">مشاهده همه پلن‌ها</a>
        </div>
        @endif
    </div>
</section>
@endif

{{-- Middle banners --}}
@if(isset($middleBanners) && $middleBanners->isNotEmpty())
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    @include('partials.banners', ['banners' => $middleBanners])
</div>
@endif

{{-- ===== FAQ preview ===== --}}
@if(isset($faqs) && $faqs->isNotEmpty())
<section class="py-14 max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
    <div class="text-center mb-8">
        <h2 class="text-2xl font-bold text-white">سوالات متداول</h2>
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
        <a href="{{ route('faq') }}" class="text-indigo-400 hover:text-indigo-300 text-sm font-medium">مشاهده همه سوالات →</a>
    </div>
</section>
@endif

@push('scripts')
<script src="//unpkg.com/alpinejs" defer></script>
@endpush
