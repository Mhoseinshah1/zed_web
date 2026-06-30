<!-- shop-template-marker -->

@php
    $navLinks = [
        ['label' => 'خانه',          'url' => route('home'),      'active' => request()->routeIs('home')],
        ['label' => 'محصولات',       'url' => route('plans'),     'active' => request()->routeIs('plans')],
        ['label' => 'آموزش‌ها',      'url' => route('tutorials'), 'active' => request()->routeIs('tutorials')],
        ['label' => 'وضعیت سرویس‌ها', 'url' => route('status'),    'active' => request()->routeIs('status')],
        ['label' => 'درباره ما',     'url' => url('/about'),      'active' => false],
        ['label' => 'قوانین',        'url' => url('/terms'),      'active' => false],
        ['label' => 'پشتیبانی',      'url' => route('contact'),   'active' => request()->routeIs('contact')],
    ];
@endphp

{{-- ===== Top utility / trust bar ===== --}}
<div class="bg-gray-900/60 border-b border-gray-800 text-xs text-gray-400">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-10 flex items-center justify-between gap-3 flex-wrap">
        <div class="flex items-center gap-4">
            <span class="flex items-center gap-1.5 text-green-400">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2 3 14h7l-1 8 10-12h-7l1-8z"/></svg>
                {{ site_setting('topbar_delivery_text', 'تحویل آنی و خودکار') }}
            </span>
            <span class="flex items-center gap-1.5 text-cyan-400">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                {{ site_setting('topbar_support_text', 'پشتیبانی ۲۴ ساعته') }}
            </span>
        </div>
        <span class="hidden sm:inline">{{ site_setting('topbar_guarantee_text', '۷ روز ضمانت بازگشت وجه · بدون قطعی') }}</span>
    </div>
</div>

{{-- ===== Main nav ===== --}}
<header class="sticky top-0 z-50 bg-gray-900/85 backdrop-blur border-b border-gray-800">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <nav class="flex items-center justify-between h-[68px]">
            <a href="{{ route('home') }}" class="flex items-center gap-2.5 font-extrabold text-xl text-white">
                @if($logo = cms_image('logo'))
                    <img src="{{ $logo }}" alt="{{ site_setting('site_name', 'ZedProxy') }}" class="h-9 w-auto" style="min-height:2.25rem">
                @else
                    <span class="w-9 h-9 rounded-[10px] zed-gradient-bg flex items-center justify-center shadow-lg shadow-indigo-500/40">
                        <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 2 4 6v6c0 5 3.4 7.7 8 10 4.6-2.3 8-5 8-10V6l-8-4z"/></svg>
                    </span>
                    <span>{{ site_setting('site_name', 'ZedProxy') }}</span>
                @endif
            </a>

            <div class="hidden lg:flex items-center gap-1">
                @foreach($navLinks as $link)
                    <a href="{{ $link['url'] }}"
                       class="px-3 py-2 rounded-lg text-sm font-medium transition {{ $link['active'] ? 'text-white bg-gray-800' : 'text-gray-400 hover:text-white hover:bg-gray-800' }}">
                        {{ $link['label'] }}
                    </a>
                @endforeach
            </div>

            <div class="flex items-center gap-2">
                @auth
                    <a href="{{ route('dashboard.index') }}"
                       class="zed-btn inline-flex items-center gap-1.5 px-5 py-2.5 text-sm font-bold bg-gray-800 text-cyan-400 border border-gray-700 hover:border-cyan-400 transition">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        پنل کاربری
                    </a>
                @else
                    <a href="{{ route('login') }}" class="hidden sm:inline text-sm text-gray-400 hover:text-white transition px-3 py-2">ورود</a>
                    <a href="{{ route('register') }}" class="zed-btn zed-btn-primary px-5 py-2.5 text-sm font-bold">ثبت‌نام</a>
                @endauth

                <button id="shop-menu-btn" class="lg:hidden text-gray-400 hover:text-white p-2" aria-label="منو">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
            </div>
        </nav>

        <div id="shop-menu" class="hidden lg:hidden pb-4 space-y-1">
            @foreach($navLinks as $link)
                <a href="{{ $link['url'] }}" class="block py-2 px-2 rounded-lg text-sm {{ $link['active'] ? 'text-white bg-gray-800' : 'text-gray-300 hover:text-white hover:bg-gray-800' }}">{{ $link['label'] }}</a>
            @endforeach
        </div>
    </div>
</header>
