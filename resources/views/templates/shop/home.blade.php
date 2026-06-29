{{-- ============================================================================
     SHOP homepage template (uses layouts.shop). Sales-oriented structure from
     the approved reference. Colours come only from the active theme (--zp-*) via
     the project's Tailwind classes; copy via site_setting(); data from the real
     Plan / Location / Testimonial models.
     ============================================================================ --}}

<!-- shop-home-marker -->

{{-- ===== HERO (two columns) ===== --}}
@if(\App\Models\SiteText::getBool('hero_is_active', true))
<section class="relative overflow-hidden py-16 sm:py-20"
         style="background:
            radial-gradient(ellipse 60% 50% at 70% 0%, color-mix(in srgb, var(--zp-primary) 16%, transparent), transparent),
            radial-gradient(ellipse 50% 40% at 20% 20%, color-mix(in srgb, var(--zp-accent) 12%, transparent), transparent)">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative grid lg:grid-cols-2 gap-10 items-center">
        {{-- Right: copy --}}
        <div>
            <span class="inline-flex items-center gap-2 bg-green-500/10 border border-green-500/25 text-green-300 text-sm px-4 py-1.5 rounded-full mb-5 zed-animate">
                <span class="w-2 h-2 rounded-full bg-green-400 animate-pulse"></span>
                {{ site_setting('shop_hero_pill', '۹۹.۹٪ آپتایم · هم‌اکنون آنلاین') }}
            </span>
            <h1 class="text-4xl sm:text-5xl font-black leading-tight text-white zed-animate">
                {{ site_setting('shop_hero_title', 'سریع‌ترین مسیر به اینترنت آزاد و بدون مرز') }}
            </h1>
            @if($hsub = site_setting('shop_hero_highlight', 'اینترنت آزاد و بدون مرز'))
            <p class="mt-2 text-2xl sm:text-3xl font-black zed-gradient-text zed-animate">{{ $hsub }}</p>
            @endif
            <p class="mt-5 text-lg text-gray-400 max-w-lg zed-animate">
                {{ site_setting('shop_hero_description', 'اتصال امن و پرسرعت با سرورهای اختصاصی. تحویل آنی، پشتیبانی همیشگی و ضمانت بازگشت وجه.') }}
            </p>
            <div class="mt-7 flex flex-wrap gap-3">
                <a href="{{ site_setting('hero_primary_button_url', route('plans')) }}" class="zed-btn zed-btn-primary px-7 py-3 text-base font-bold">
                    {{ site_setting('shop_hero_primary_btn', 'شروع کن') }}
                </a>
                <a href="{{ route('plans') }}" class="zed-btn px-7 py-3 text-base font-bold bg-gray-800 text-cyan-400 border border-gray-700 hover:border-cyan-400 transition">
                    {{ site_setting('shop_hero_secondary_btn', 'مشاهده پلن‌ها') }}
                </a>
            </div>
            <div class="mt-6 flex flex-wrap gap-5 text-sm text-gray-400">
                @foreach([
                    site_setting('shop_mini_1', 'بدون نیاز به کارت'),
                    site_setting('shop_mini_2', 'فعال‌سازی ۳۰ ثانیه‌ای'),
                    site_setting('shop_mini_3', 'لغو آسان'),
                ] as $mini)
                <span class="flex items-center gap-1.5">
                    <svg class="w-4 h-4 text-green-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M20 6 9 17l-5-5"/></svg>{{ $mini }}
                </span>
                @endforeach
            </div>
        </div>

        {{-- Left: globe card (hidden on mobile) --}}
        <div class="hidden lg:block">
            <div class="zed-card p-7 rounded-3xl"
                 style="background:linear-gradient(160deg,var(--zp-surface),var(--zp-bg-soft));box-shadow:0 30px 60px -25px rgba(0,0,0,.7)">
                <div class="relative mx-auto rounded-full border border-gray-700 overflow-hidden"
                     style="width:100%;max-width:300px;aspect-ratio:1;background:radial-gradient(circle at 35% 30%, color-mix(in srgb,var(--zp-primary) 35%,transparent), transparent 60%), conic-gradient(from 0deg,var(--zp-bg-soft),var(--zp-surface),var(--zp-bg-soft))">
                    <span class="zp-ping" style="top:30%;right:28%;background:var(--zp-accent)"></span>
                    <span class="zp-ping" style="top:55%;right:55%;animation-delay:.5s;background:var(--zp-success)"></span>
                    <span class="zp-ping" style="top:42%;right:18%;animation-delay:1s;background:var(--zp-warning)"></span>
                </div>
                <div class="flex justify-around text-center mt-6">
                    <div><div class="text-2xl font-extrabold text-white">{{ $locations->count() ?: site_setting('shop_stat_countries', '۱۲') }}</div><div class="text-[11px] text-gray-400">کشور</div></div>
                    <div><div class="text-2xl font-extrabold text-white">{{ site_setting('shop_stat_users', '۴۰K+') }}</div><div class="text-[11px] text-gray-400">کاربر فعال</div></div>
                    <div><div class="text-2xl font-extrabold text-white">{{ site_setting('shop_stat_uptime', '۹۹.۹٪') }}</div><div class="text-[11px] text-gray-400">آپتایم</div></div>
                </div>
            </div>
        </div>
    </div>
</section>
@endif

{{-- Top banners --}}
@if(isset($topBanners) && $topBanners->isNotEmpty())
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4">
    @include('partials.banners', ['banners' => $topBanners])
</div>
@endif

{{-- ===== STATS BAR ===== --}}
<div class="bg-gray-900/60 border-y border-gray-800 py-6">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-5 text-center">
            @php
                $statsBar = [
                    ['n' => site_setting('shop_stat_users_full', '۴۰٬۰۰۰+'), 'l' => 'کاربر راضی'],
                    ['n' => ($locations->count() ?: site_setting('shop_stat_countries', '۱۲')), 'l' => 'لوکیشن سرور'],
                    ['n' => site_setting('shop_stat_uptime', '۹۹.۹٪'), 'l' => 'پایداری اتصال'],
                    ['n' => site_setting('shop_stat_support', '۲۴/۷'), 'l' => 'پشتیبانی زنده'],
                ];
            @endphp
            @foreach($statsBar as $s)
            <div>
                <div class="text-3xl font-black zed-gradient-text">{{ $s['n'] }}</div>
                <div class="text-xs text-gray-400 mt-1">{{ $s['l'] }}</div>
            </div>
            @endforeach
        </div>
    </div>
</div>

{{-- ===== SERVER LOCATIONS (real Location model) ===== --}}
@if($locations->isNotEmpty())
<section class="py-16">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-10">
            <div class="text-sm font-bold text-cyan-400 tracking-wide">{{ site_setting('shop_locations_tag', 'شبکه‌ی جهانی') }}</div>
            <h2 class="text-3xl font-extrabold text-white mt-2">{{ site_setting('shop_locations_title', 'سرورها در سراسر دنیا') }}</h2>
            <p class="text-gray-400 mt-1">{{ site_setting('shop_locations_sub', 'به نزدیک‌ترین و سریع‌ترین سرور وصل شو') }}</p>
        </div>
        <div class="grid grid-cols-3 sm:grid-cols-4 lg:grid-cols-6 gap-3">
            @foreach($locations as $location)
            <div class="bg-gray-900 border border-gray-800 rounded-2xl py-4 px-2 text-center hover:border-cyan-400 hover:-translate-y-1 transition zed-hover-lift">
                @if($location->flag_emoji)
                    <div class="text-3xl">{{ $location->flag_emoji }}</div>
                @else
                    <div class="text-xs font-bold text-cyan-400">{{ $location->country_code }}</div>
                @endif
                <div class="text-sm font-semibold text-white mt-1.5">{{ $location->country_name }}</div>
                @if($location->is_youtube_special)
                    <div class="text-[10px] text-red-400 mt-1">YouTube</div>
                @endif
            </div>
            @endforeach
        </div>
    </div>
</section>
@endif

{{-- ===== HOW IT WORKS (3 steps) ===== --}}
<section class="py-16 bg-gray-900/60 border-y border-gray-800">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-10">
            <div class="text-sm font-bold text-cyan-400 tracking-wide">{{ site_setting('shop_steps_tag', 'ساده و سریع') }}</div>
            <h2 class="text-3xl font-extrabold text-white mt-2">{{ site_setting('shop_steps_title', 'در ۳ قدم متصل شو') }}</h2>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            @php
                $steps = [
                    ['n' => '۱', 't' => site_setting('shop_step_1_title', 'پلن رو انتخاب کن'), 'd' => site_setting('shop_step_1_desc', 'پلن متناسب با نیازت رو از بین گزینه‌های متنوع انتخاب کن.')],
                    ['n' => '۲', 't' => site_setting('shop_step_2_title', 'پرداخت کن'),       'd' => site_setting('shop_step_2_desc', 'با کیف پول، کریپتو یا کارت بانکی پرداخت کن — تحویل آنیه.')],
                    ['n' => '۳', 't' => site_setting('shop_step_3_title', 'وصل شو'),          'd' => site_setting('shop_step_3_desc', 'لینک اتصال و QR رو بگیر و در چند ثانیه به اینترنت آزاد وصل شو.')],
                ];
            @endphp
            @foreach($steps as $step)
            <div class="zed-card p-7 relative">
                <div class="w-11 h-11 rounded-xl zed-gradient-bg flex items-center justify-center font-extrabold text-lg text-white mb-4">{{ $step['n'] }}</div>
                <h3 class="text-lg font-bold text-white mb-2">{{ $step['t'] }}</h3>
                <p class="text-sm text-gray-400">{{ $step['d'] }}</p>
            </div>
            @endforeach
        </div>
    </div>
</section>

{{-- ===== PLANS (display-only billing toggle + featured) ===== --}}
@if($plans->isNotEmpty())
@php
    $shownPlans = $plans->take(3)->values();
    $featuredId = optional($shownPlans->firstWhere('is_featured', true))->id
        ?? optional($shownPlans->get(intdiv($shownPlans->count(), 2)))->id;
@endphp
<section class="py-16" x-data="{ billing: 'monthly' }">
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-8">
            <div class="text-sm font-bold text-cyan-400 tracking-wide">{{ site_setting('shop_plans_tag', 'قیمت‌گذاری شفاف') }}</div>
            <h2 class="text-3xl font-extrabold text-white mt-2">{{ site_setting('shop_plans_title', 'پلن مناسبت رو انتخاب کن') }}</h2>
        </div>

        {{-- Display-only billing toggle: this project prices each plan by its own
             duration, so the toggle is informational and never alters prices. --}}
        <div class="flex flex-col items-center gap-2 mb-9">
            <div class="inline-flex gap-1 p-1.5 rounded-full bg-gray-800 border border-gray-700">
                <button type="button" @click="billing = 'monthly'"
                        :class="billing === 'monthly' ? 'zed-btn-primary text-white' : 'text-gray-400'"
                        class="px-6 py-2 rounded-full text-sm font-semibold transition">ماهانه</button>
                <button type="button" @click="billing = 'yearly'"
                        :class="billing === 'yearly' ? 'zed-btn-primary text-white' : 'text-gray-400'"
                        class="px-6 py-2 rounded-full text-sm font-semibold transition">دوره‌ای</button>
            </div>
            <span class="text-xs text-gray-500">{{ site_setting('shop_billing_note', 'قیمت هر پلن بر اساس مدت همان پلن محاسبه می‌شود.') }}</span>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 max-w-4xl mx-auto">
            @foreach($shownPlans as $plan)
                @php $isFeat = $plan->id === $featuredId; @endphp
                <div class="relative rounded-2xl p-7 flex flex-col transition zed-hover-lift border
                    {{ $isFeat ? 'zed-gradient-bg border-cyan-400 shadow-2xl shadow-indigo-500/40' : 'bg-gray-900 border-gray-800 hover:border-gray-700' }}">

                    @if($isFeat)
                    <span class="absolute -top-3 right-1/2 translate-x-1/2 bg-amber-400 text-gray-950 text-xs font-extrabold px-4 py-1 rounded-full whitespace-nowrap">
                        {{ site_setting('featured_badge_text', 'پرفروش‌ترین') }}
                    </span>
                    @endif

                    <div class="text-sm font-bold {{ $isFeat ? 'text-blue-100' : 'text-gray-400' }}">{{ $plan->name }}</div>
                    <div class="mt-3 text-4xl font-black text-white">
                        {{ $plan->formattedPrice() }} <small class="text-[15px] font-normal {{ $isFeat ? 'text-blue-100' : 'text-gray-400' }}">تومان</small>
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
                       class="text-center py-3 rounded-xl font-extrabold text-sm transition
                       {{ $isFeat ? 'bg-white text-indigo-700 hover:bg-indigo-50' : 'bg-gray-800 text-white border border-gray-700 hover:bg-gray-700' }}">
                        انتخاب پلن
                    </a>
                </div>
            @endforeach
        </div>
    </div>
</section>
@endif

{{-- ===== TESTIMONIALS (DB-backed, admin-managed, gated) ===== --}}
@php
    $shopTestimonials = \App\Models\SiteSetting::get('shop_testimonials_enabled', false)
        ? \App\Models\Testimonial::active()->ordered()->get()
        : collect();
@endphp
@if($shopTestimonials->isNotEmpty())
<section class="py-16 bg-gray-900/60 border-y border-gray-800">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-10">
            <div class="text-sm font-bold text-cyan-400 tracking-wide">{{ site_setting('shop_testimonials_tag', 'رضایت کاربران') }}</div>
            <h2 class="text-3xl font-extrabold text-white mt-2">{{ site_setting('shop_testimonials_title', 'چی می‌گن درباره‌مون') }}</h2>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
            @foreach($shopTestimonials as $t)
            <div class="zed-card p-6">
                <div class="text-amber-400 text-[15px] mb-2.5">{{ str_repeat('★', $t->stars()) }}<span class="text-gray-700">{{ str_repeat('★', 5 - $t->stars()) }}</span></div>
                <p class="text-sm text-gray-200 mb-4 leading-relaxed">«{{ $t->body }}»</p>
                <div class="flex items-center gap-3">
                    <span class="w-9 h-9 rounded-full zed-gradient-bg flex items-center justify-center font-bold text-white">{{ $t->initial() }}</span>
                    <div>
                        <div class="text-sm font-semibold text-white">{{ $t->name }}</div>
                        @if($t->role)<div class="text-xs text-gray-400">{{ $t->role }}</div>@endif
                    </div>
                </div>
            </div>
            @endforeach
        </div>
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
            <h2 class="relative text-3xl font-black text-white mb-3">{{ site_setting('shop_cta_title', 'آماده‌ای به اینترنت آزاد وصل شی؟') }}</h2>
            <p class="relative text-white/85 mb-7">{{ site_setting('shop_cta_subtitle', 'همین حالا شروع کن — تحویل آنی، ۷ روز ضمانت بازگشت وجه.') }}</p>
            <a href="{{ site_setting('hero_primary_button_url', route('plans')) }}" class="relative inline-block bg-white text-indigo-700 font-extrabold px-9 py-3.5 rounded-xl text-base hover:bg-indigo-50 transition">
                {{ site_setting('shop_cta_button', 'خرید سرویس') }}
            </a>
        </div>
    </div>
</section>

@push('styles')
<style>
    .zp-ping { position:absolute; width:9px; height:9px; border-radius:50%;
        box-shadow:0 0 0 0 color-mix(in srgb, var(--zp-accent) 50%, transparent); animation: zp-ping 2s infinite; }
    @keyframes zp-ping { 0%{box-shadow:0 0 0 0 color-mix(in srgb,var(--zp-accent) 50%,transparent)} 70%{box-shadow:0 0 0 12px transparent} 100%{box-shadow:0 0 0 0 transparent} }
    @media (prefers-reduced-motion: reduce) { .zp-ping { animation: none; } }
</style>
@endpush
@push('scripts')
<script src="//unpkg.com/alpinejs" defer></script>
@endpush
