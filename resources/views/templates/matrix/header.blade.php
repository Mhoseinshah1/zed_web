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

{{-- ===== Top utility bar ===== --}}
<div class="relative z-10 bg-gray-900/60 border-b border-gray-800 text-xs text-gray-400">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 h-10 flex items-center justify-between gap-3 flex-wrap">
        <div class="flex items-center gap-4">
            <span class="flex items-center gap-1.5 text-green-400">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                {{ site_setting('matrix_topbar_1', 'رمزنگاری AES-256') }}
            </span>
            <span class="flex items-center gap-1.5 text-cyan-400">
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2 4 6v6c0 5 3.4 7.7 8 10 4.6-2.3 8-5 8-10V6l-8-4z"/></svg>
                {{ site_setting('matrix_topbar_2', 'بدون ثبت لاگ') }}
            </span>
        </div>
        <span class="hidden sm:inline">{{ site_setting('matrix_topbar_3', 'پشتیبانی ۲۴ ساعته · تحویل آنی') }}</span>
    </div>
</div>

{{-- ===== Main nav ===== --}}
<header class="sticky top-0 z-50 bg-gray-950/75 backdrop-blur border-b border-gray-800">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <nav class="flex items-center justify-between h-[68px]">
            <a href="{{ route('home') }}" class="flex items-center gap-2.5 font-black text-xl text-white">
                @if($logo = cms_image('logo'))
                    <img src="{{ $logo }}" alt="{{ site_setting('site_name', 'ZedProxy') }}" class="h-9 w-auto" style="min-height:2.25rem">
                @else
                    <span class="w-9 h-9 rounded-[11px] zed-gradient-bg flex items-center justify-center" style="box-shadow:0 0 24px -6px color-mix(in srgb,var(--zp-primary) 60%,transparent)">
                        <svg class="w-5 h-5" style="color:var(--zp-bg)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 2 4 6v6c0 5 3.4 7.7 8 10 4.6-2.3 8-5 8-10V6l-8-4z"/></svg>
                    </span>
                    <span>Zed<b class="text-green-400">Proxy</b></span>
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
                       class="zed-btn inline-flex items-center gap-1.5 px-5 py-2.5 text-sm font-bold bg-gray-800 text-green-400 border border-gray-700 hover:border-green-400 transition">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                        پنل کاربری
                    </a>
                @else
                    <a href="{{ route('login') }}" class="hidden sm:inline text-sm text-gray-400 hover:text-white transition px-3 py-2">ورود</a>
                    <a href="{{ route('register') }}" class="zed-btn zed-btn-primary px-5 py-2.5 text-sm font-bold">ثبت‌نام</a>
                @endauth

                <button id="matrix-menu-btn" class="lg:hidden text-gray-400 hover:text-white p-2" aria-label="منو">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
            </div>
        </nav>

        <div id="matrix-menu" class="hidden lg:hidden pb-4 space-y-1">
            @foreach($navLinks as $link)
                <a href="{{ $link['url'] }}" class="block py-2 px-2 rounded-lg text-sm {{ $link['active'] ? 'text-white bg-gray-800' : 'text-gray-300 hover:text-white hover:bg-gray-800' }}">{{ $link['label'] }}</a>
            @endforeach
        </div>
    </div>
</header>
