{{-- ============================================================================
     WoodMart template — site-wide header (top utility bar + e-commerce header +
     category nav). Chrome via theme classes (bg-surface / text-content / …);
     fixed orange accent via scoped .wm-* helpers. Category nav from real
     PlanCategory; cart badge from the user's real unpaid orders.
     ============================================================================ --}}
<!-- woodmart-template-marker -->

@php
    use App\Models\PlanCategory;
    use App\Models\Order;

    $wmCategories = PlanCategory::query()->where('is_active', true)
        ->whereHas('plans', fn ($q) => $q->where('is_active', true))
        ->orderBy('sort_order')->orderBy('id')->get();

    $wmCartCount = auth()->check()
        ? Order::where('user_id', auth()->id())
            ->whereIn('payment_status', [Order::PAYMENT_UNPAID, Order::PAYMENT_PENDING])
            ->count()
        : 0;
@endphp

{{-- ===== Top utility / trust bar ===== --}}
<div class="bg-base-soft border-b border-line text-[12.5px] text-content-muted">
    <div class="max-w-[1180px] mx-auto px-5 h-[38px] flex items-center justify-between gap-3">
        <div class="flex items-center gap-4">
            <span class="flex items-center gap-1.5">
                <svg class="w-[13px] h-[13px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2 3 14h7l-1 8 10-12h-7l1-8z"/></svg>
                {{ site_setting('woodmart_topbar_delivery', 'تحویل آنی') }}
            </span>
            <span class="flex items-center gap-1.5">
                <svg class="w-[13px] h-[13px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.4 8.4 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.4 8.4 0 0 1-3.8-.9L3 21l1.9-5.7a8.4 8.4 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.4 8.4 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                {{ site_setting('woodmart_topbar_support', 'پشتیبانی ۲۴ ساعته') }}
            </span>
        </div>
        <span class="hidden sm:inline">{{ site_setting('woodmart_topbar_guarantee', '۷ روز ضمانت بازگشت وجه') }}</span>
    </div>
</div>

{{-- ===== Main e-commerce header ===== --}}
<header class="sticky top-0 z-40 bg-surface border-b border-line">
    <div class="max-w-[1180px] mx-auto px-5">
        <div class="flex items-center gap-5 h-[70px]">
            {{-- Logo --}}
            <a href="{{ route('home') }}" class="flex items-center gap-2.5 font-black text-xl text-content shrink-0">
                @if($logo = cms_image('logo'))
                    <img src="{{ $logo }}" alt="{{ site_setting('site_name', 'ZedProxy') }}" class="h-9 w-auto" style="min-height:2.25rem">
                @else
                    <span class="wm-logo-badge w-9 h-9 rounded-[10px] flex items-center justify-center text-white">
                        <svg class="w-[19px] h-[19px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 2 4 6v6c0 5 3.4 7.7 8 10 4.6-2.3 8-5 8-10V6l-8-4z"/></svg>
                    </span>
                    <span>{{ site_setting('site_name', 'ZedProxy') }}</span>
                @endif
            </a>

            {{-- Center search (desktop) --}}
            <form action="{{ route('plans') }}" method="GET"
                  class="hidden md:flex flex-1 max-w-[440px] items-center gap-2 bg-surface-soft border border-line rounded-full px-[18px] py-2.5 text-content-muted">
                <svg class="w-[17px] h-[17px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                <input type="text" name="q" value="{{ request('q') }}"
                       placeholder="{{ site_setting('woodmart_search_placeholder', 'جستجوی پلن، لوکیشن...') }}"
                       class="bg-transparent border-none outline-none text-content w-full text-[13.5px] placeholder:text-content-muted">
            </form>

            {{-- Icons + login (desktop) --}}
            <div class="hidden md:flex items-center gap-2 mr-auto">
                {{-- Wishlist → browse products --}}
                <a href="{{ route('plans') }}" aria-label="علاقه‌مندی‌ها"
                   class="w-[42px] h-[42px] rounded-full bg-surface-soft hover:bg-surface-hover text-content flex items-center justify-center transition">
                    <svg class="w-[19px] h-[19px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1a5.5 5.5 0 0 0-7.8 7.8l1 1L12 21l7.8-7.5 1-1a5.5 5.5 0 0 0 0-7.8z"/></svg>
                </a>
                {{-- Cart → orders (authed) / login; badge = real unpaid orders --}}
                <a href="{{ auth()->check() ? route('dashboard.orders') : route('login') }}" aria-label="سبد سفارش"
                   class="relative w-[42px] h-[42px] rounded-full bg-surface-soft hover:bg-surface-hover text-content flex items-center justify-center transition">
                    @if($wmCartCount > 0)
                        <span class="wm-accent-bg absolute -top-0.5 -left-0.5 min-w-[18px] h-[18px] rounded-full text-[10px] font-bold flex items-center justify-center px-1">{{ $wmCartCount }}</span>
                    @endif
                    <svg class="w-[19px] h-[19px]" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/></svg>
                </a>
                @auth
                    <a href="{{ route('dashboard.index') }}" class="wm-accent-bg flex items-center gap-1.5 px-[18px] py-2.5 rounded-full font-bold text-[13.5px] transition">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        پنل کاربری
                    </a>
                @else
                    <a href="{{ route('login') }}" class="wm-accent-bg flex items-center gap-1.5 px-[18px] py-2.5 rounded-full font-bold text-[13.5px] transition">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        {{ site_setting('woodmart_login_label', 'ورود / پنل') }}
                    </a>
                @endauth
            </div>

            {{-- Mobile hamburger --}}
            <button id="wm-menu-btn" class="md:hidden mr-auto w-[42px] h-[42px] rounded-full bg-surface-soft text-content flex items-center justify-center" aria-label="منو">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
        </div>
    </div>

    {{-- ===== Category nav (desktop) ===== --}}
    <div class="border-t border-line bg-surface hidden md:block">
        <div class="max-w-[1180px] mx-auto px-5">
            <nav class="flex items-center gap-1 h-[50px] overflow-x-auto">
                <a href="{{ route('plans') }}"
                   class="wm-accent-bg flex items-center gap-1.5 px-[15px] py-2 rounded-lg text-sm font-semibold whitespace-nowrap shrink-0">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
                    {{ site_setting('woodmart_nav_all', 'همه محصولات') }}
                </a>
                @foreach($wmCategories as $cat)
                    <a href="{{ route('plans', ['cat' => $cat->id]) }}"
                       class="wm-navlink {{ request('cat') == $cat->id ? 'is-on' : '' }} flex items-center gap-1.5 px-[15px] py-2 rounded-lg text-sm font-semibold text-content-muted whitespace-nowrap shrink-0">
                        @if($cat->icon)<span>{{ $cat->icon }}</span>@endif{{ $cat->title }}
                    </a>
                @endforeach
                <a href="{{ route('tutorials') }}" class="wm-navlink {{ request()->routeIs('tutorials') ? 'is-on' : '' }} px-[15px] py-2 rounded-lg text-sm font-semibold text-content-muted whitespace-nowrap shrink-0">{{ site_setting('woodmart_nav_tutorials', 'آموزش‌ها') }}</a>
                <a href="{{ route('contact') }}" class="wm-navlink {{ request()->routeIs('contact') ? 'is-on' : '' }} px-[15px] py-2 rounded-lg text-sm font-semibold text-content-muted whitespace-nowrap shrink-0">{{ site_setting('woodmart_nav_support', 'پشتیبانی') }}</a>
            </nav>
        </div>
    </div>

    {{-- ===== Mobile menu (search + nav + account) ===== --}}
    <div id="wm-menu" class="hidden md:hidden border-t border-line bg-surface">
        <div class="max-w-[1180px] mx-auto px-5 py-4 space-y-3">
            <form action="{{ route('plans') }}" method="GET"
                  class="flex items-center gap-2 bg-surface-soft border border-line rounded-full px-4 py-2.5 text-content-muted">
                <svg class="w-[17px] h-[17px] shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
                <input type="text" name="q" value="{{ request('q') }}"
                       placeholder="{{ site_setting('woodmart_search_placeholder', 'جستجوی پلن، لوکیشن...') }}"
                       class="bg-transparent border-none outline-none text-content w-full text-sm placeholder:text-content-muted">
            </form>
            <div class="space-y-1">
                <a href="{{ route('plans') }}" class="block py-2 px-2 rounded-lg text-sm font-semibold wm-accent-soft-bg">{{ site_setting('woodmart_nav_all', 'همه محصولات') }}</a>
                @foreach($wmCategories as $cat)
                    <a href="{{ route('plans', ['cat' => $cat->id]) }}" class="block py-2 px-2 rounded-lg text-sm text-content-muted hover:text-content">{{ $cat->title }}</a>
                @endforeach
                <a href="{{ route('tutorials') }}" class="block py-2 px-2 rounded-lg text-sm text-content-muted hover:text-content">{{ site_setting('woodmart_nav_tutorials', 'آموزش‌ها') }}</a>
                <a href="{{ route('contact') }}" class="block py-2 px-2 rounded-lg text-sm text-content-muted hover:text-content">{{ site_setting('woodmart_nav_support', 'پشتیبانی') }}</a>
            </div>
            <div class="pt-2 border-t border-line">
                @auth
                    <a href="{{ route('dashboard.index') }}" class="wm-accent-bg block text-center py-2.5 rounded-full font-bold text-sm">پنل کاربری</a>
                @else
                    <a href="{{ route('login') }}" class="wm-accent-bg block text-center py-2.5 rounded-full font-bold text-sm">{{ site_setting('woodmart_login_label', 'ورود / پنل') }}</a>
                @endauth
            </div>
        </div>
    </div>
</header>
