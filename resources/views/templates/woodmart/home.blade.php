{{-- ============================================================================
     WoodMart homepage body (shell comes from layouts.app → templates/woodmart).
     Chrome via theme classes (bg-base / bg-surface / text-content / border-line)
     so light & dark both work; the fixed orange accent via scoped .wm-* helpers.
     Copy via site_setting('woodmart_*'); ALL data is real:
       • category nav/cards  → PlanCategory (+ real active-plan counts)
       • product grid        → Plan::active() with real price / discount / meta
       • location counts      → Location
       • buy button           → real plans.buy purchase flow
     No fake star ratings (no rating system exists) and no fake ribbons.
     ============================================================================ --}}

<!-- woodmart-home-marker -->

@php
    use App\Models\PlanCategory;

    $wmCats = PlanCategory::query()->where('is_active', true)
        ->withCount(['plans' => fn ($q) => $q->where('is_active', true)])
        ->whereHas('plans', fn ($q) => $q->where('is_active', true))
        ->orderBy('sort_order')->orderBy('id')->get();

    $wmLocCount  = $locations->count();
    $wmProducts  = $plans->take(8)->values();
@endphp

{{-- ===== HERO BANNER + side banners ===== --}}
<section class="max-w-[1180px] mx-auto px-5 py-7">
    <div class="grid lg:grid-cols-[1fr_320px] gap-[18px]">
        {{-- Main banner --}}
        <div class="wm-banner relative rounded-[18px] overflow-hidden px-8 sm:px-10 py-10 sm:py-11 text-white flex flex-col justify-center min-h-[260px]">
            <div class="relative text-[13px] font-bold opacity-90 mb-2.5">{{ site_setting('woodmart_hero_tag', '🔥 پیشنهاد ویژه این هفته') }}</div>
            <h1 class="relative text-3xl sm:text-[36px] font-black leading-[1.3] mb-3">{{ site_setting('woodmart_hero_title', 'اینترنت آزاد، سریع و بدون محدودیت') }}</h1>
            <p class="relative text-[15px] opacity-90 mb-5 max-w-[380px]">{{ site_setting('woodmart_hero_desc', 'پلن‌های پرسرعت با تحویل آنی و پشتیبانی همیشگی. همین حالا انتخاب کن.') }}</p>
            <a href="{{ site_setting('hero_primary_button_url', route('plans')) }}"
               class="relative inline-flex items-center gap-2 bg-white wm-accent-text px-7 py-3 rounded-full font-extrabold text-sm w-fit">
                {{ site_setting('woodmart_hero_cta', 'مشاهده پلن‌ها') }}
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m15 18-6-6 6-6"/></svg>
            </a>
        </div>

        {{-- Side banners --}}
        <div class="flex flex-col gap-[18px]">
            <div class="flex-1 rounded-2xl p-[22px] bg-surface-soft border border-line flex flex-col justify-center">
                <div class="text-base font-extrabold text-content mb-1">{{ site_setting('woodmart_side1_title', '۲ ماه رایگان 🎁') }}</div>
                <div class="text-[12.5px] text-content-muted mb-3">{{ site_setting('woodmart_side1_desc', 'با خرید پلن سالانه') }}</div>
                <a href="{{ route('plans') }}" class="wm-accent-text text-[13px] font-bold flex items-center gap-1.5">{{ site_setting('woodmart_side1_link', 'فعال‌سازی') }} ←</a>
            </div>
            <div class="flex-1 rounded-2xl p-[22px] bg-surface border border-line flex flex-col justify-center">
                <div class="text-base font-extrabold text-content mb-1">{{ $wmLocCount ? $wmLocCount . ' کشور' : site_setting('woodmart_side2_title', 'سرورهای جهانی') }}</div>
                <div class="text-[12.5px] text-content-muted mb-3">{{ site_setting('woodmart_side2_desc', 'سرور در سراسر دنیا') }}</div>
                <a href="{{ route('plans') }}" class="wm-accent-text text-[13px] font-bold flex items-center gap-1.5">{{ site_setting('woodmart_side2_link', 'لوکیشن‌ها') }} ←</a>
            </div>
        </div>
    </div>
</section>

{{-- Top banners (real, admin-managed) --}}
@if(isset($topBanners) && $topBanners->isNotEmpty())
<div class="max-w-[1180px] mx-auto px-5 pb-2">@include('partials.banners', ['banners' => $topBanners])</div>
@endif

{{-- ===== FEATURE STRIP ===== --}}
<section class="border-y border-line bg-base-soft">
    <div class="max-w-[1180px] mx-auto px-5 grid grid-cols-2 lg:grid-cols-4 gap-4 py-5">
        @php
            $wmFeatures = [
                ['t' => site_setting('woodmart_feat1_title', 'تحویل آنی'),   'd' => site_setting('woodmart_feat1_desc', 'فعال‌سازی خودکار'), 'p' => 'M13 2 3 14h7l-1 8 10-12h-7l1-8z'],
                ['t' => site_setting('woodmart_feat2_title', 'اتصال امن'),   'd' => site_setting('woodmart_feat2_desc', 'رمزنگاری کامل'),    'p' => 'M12 2 4 6v6c0 5 3.4 7.7 8 10 4.6-2.3 8-5 8-10V6l-8-4z'],
                ['t' => site_setting('woodmart_feat3_title', 'پشتیبانی ۲۴/۷'), 'd' => site_setting('woodmart_feat3_desc', 'همیشه کنارت'),   'p' => 'M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z'],
                ['t' => site_setting('woodmart_feat4_title', 'پرداخت امن'),  'd' => site_setting('woodmart_feat4_desc', 'درگاه معتبر'),     'p' => 'M21 12V7H5a2 2 0 0 1 0-4h14v4M3 5v14a2 2 0 0 0 2 2h16v-5M18 12a2 2 0 0 0 0 4h4v-4z'],
            ];
        @endphp
        @foreach($wmFeatures as $f)
        <div class="flex items-center gap-3">
            <div class="wm-accent-soft-bg w-[42px] h-[42px] rounded-[11px] flex items-center justify-center shrink-0">
                <svg class="w-[21px] h-[21px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="{{ $f['p'] }}"/></svg>
            </div>
            <div>
                <div class="text-[13.5px] font-bold text-content">{{ $f['t'] }}</div>
                <div class="text-[11.5px] text-content-muted">{{ $f['d'] }}</div>
            </div>
        </div>
        @endforeach
    </div>
</section>

<div class="max-w-[1180px] mx-auto px-5">

    {{-- ===== CATEGORY CARDS (real categories + real active-plan counts) ===== --}}
    @if($wmCats->isNotEmpty())
    <div class="flex justify-between items-end mt-10 mb-5">
        <div>
            <h2 class="text-2xl font-extrabold text-content">{{ site_setting('woodmart_cats_title', 'دسته‌بندی سرویس‌ها') }}</h2>
            <div class="text-[13px] text-content-muted mt-0.5">{{ site_setting('woodmart_cats_sub', 'سرویس مناسب خودت رو پیدا کن') }}</div>
        </div>
        <a href="{{ route('plans') }}" class="wm-accent-text text-[13px] font-bold flex items-center gap-1.5 shrink-0">{{ site_setting('woodmart_more', 'همه') }} ←</a>
    </div>
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        @foreach($wmCats as $cat)
        <a href="{{ route('plans', ['cat' => $cat->id]) }}" class="wm-cat bg-surface border border-line rounded-xl p-[22px] text-center block">
            <div class="wm-accent-soft-bg w-[54px] h-[54px] mx-auto mb-3 rounded-[14px] flex items-center justify-center text-2xl">
                @if($cat->icon)<span>{{ $cat->icon }}</span>
                @else<svg class="w-[26px] h-[26px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20 15 15 0 0 1 0-20z"/></svg>@endif
            </div>
            <div class="text-[14.5px] font-bold text-content">{{ $cat->title }}</div>
            <div class="text-xs text-content-muted mt-0.5">{{ $cat->plans_count }} محصول</div>
        </a>
        @endforeach
    </div>
    @endif

    {{-- ===== PRODUCT GRID (real Plan records) ===== --}}
    @if($wmProducts->isNotEmpty())
    <div class="flex justify-between items-end mt-10 mb-5">
        <div>
            <h2 class="text-2xl font-extrabold text-content">{{ site_setting('woodmart_products_title', 'پرفروش‌ترین پلن‌ها') }}</h2>
            <div class="text-[13px] text-content-muted mt-0.5">{{ site_setting('woodmart_products_sub', 'محبوب‌ترین انتخاب کاربران') }}</div>
        </div>
        <a href="{{ route('plans') }}" class="wm-accent-text text-[13px] font-bold flex items-center gap-1.5 shrink-0">{{ site_setting('woodmart_products_more', 'مشاهده همه') }} ←</a>
    </div>
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-[18px]">
        @foreach($wmProducts as $plan)
            @php
                $hasDiscount = $plan->old_price_toman && $plan->old_price_toman > $plan->price_toman;
                $discountPct = $hasDiscount ? (int) round(($plan->old_price_toman - $plan->price_toman) / $plan->old_price_toman * 100) : null;
                $isNew       = $plan->created_at && $plan->created_at->gt(now()->subDays(14));
            @endphp
            <div class="wm-product bg-surface border border-line rounded-2xl overflow-hidden relative flex flex-col {{ $plan->is_featured ? 'wm-accent-border border-2' : '' }}">
                {{-- Ribbon — only when truly applicable (no fake ribbons) --}}
                @if($hasDiscount)
                    <span class="wm-accent-bg absolute top-3.5 right-3.5 z-[2] text-[11px] font-extrabold px-2.5 py-1 rounded-full">{{ $discountPct }}٪ تخفیف</span>
                @elseif($plan->is_featured)
                    <span class="wm-accent-bg absolute top-3.5 right-3.5 z-[2] text-[11px] font-extrabold px-2.5 py-1 rounded-full">{{ $plan->badge ?: 'ویژه' }}</span>
                @elseif($plan->badge)
                    <span class="wm-accent-bg absolute top-3.5 right-3.5 z-[2] text-[11px] font-extrabold px-2.5 py-1 rounded-full">{{ $plan->badge }}</span>
                @elseif($isNew)
                    <span class="wm-ok-bg absolute top-3.5 right-3.5 z-[2] text-[11px] font-extrabold px-2.5 py-1 rounded-full">جدید</span>
                @endif

                {{-- Image area --}}
                <div class="h-[140px] bg-surface-soft border-b border-line flex items-center justify-center">
                    <div class="wm-globe w-[90px] h-[90px] rounded-full border-2 flex items-center justify-center">
                        <svg class="w-[42px] h-[42px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20 15 15 0 0 1 0-20z"/></svg>
                    </div>
                </div>

                {{-- Body --}}
                <div class="p-4 flex flex-col flex-1">
                    <div class="wm-accent-text text-[11px] font-bold mb-1.5">{{ $plan->category?->title ?: $plan->durationLabel() }}</div>
                    <div class="text-[15px] font-bold text-content mb-2">{{ $plan->name }}</div>
                    <div class="flex gap-2.5 text-[11.5px] text-content-muted mb-3 flex-wrap">
                        <span class="flex items-center gap-1">
                            <svg class="w-[13px] h-[13px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2 3 14h7l-1 8 10-12h-7l1-8z"/></svg>{{ $plan->trafficLabel() }}
                        </span>
                        @if($wmLocCount)<span>{{ $wmLocCount }} لوکیشن</span>@endif
                        <span>{{ $plan->durationLabel() }}</span>
                    </div>

                    {{-- Footer: real price (+ struck old price only on a real discount) + buy --}}
                    <div class="mt-auto flex items-center justify-between gap-2">
                        <div class="text-[18px] font-black text-content leading-tight">
                            @if($hasDiscount)
                                <span class="block text-xs text-content-muted line-through font-medium">{{ number_format($plan->old_price_toman) }}</span>
                            @endif
                            {{ number_format($plan->price_toman) }} <small class="text-[11px] text-content-muted font-medium">تومان</small>
                        </div>
                        @auth
                            <form method="POST" action="{{ route('plans.buy', $plan) }}">
                                @csrf
                                <button type="submit" aria-label="خرید {{ $plan->name }}" class="wm-accent-bg w-[42px] h-[42px] rounded-[11px] flex items-center justify-center transition">
                                    <svg class="w-[19px] h-[19px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                                </button>
                            </form>
                        @else
                            <a href="{{ route('login') }}" aria-label="ورود برای خرید" class="wm-accent-bg w-[42px] h-[42px] rounded-[11px] flex items-center justify-center transition">
                                <svg class="w-[19px] h-[19px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            </a>
                        @endauth
                    </div>
                </div>
            </div>
        @endforeach
    </div>
    @endif

    {{-- Middle banners (real, admin-managed) --}}
    @if(isset($middleBanners) && $middleBanners->isNotEmpty())
    <div class="py-6">@include('partials.banners', ['banners' => $middleBanners])</div>
    @endif
</div>
