@php
    use App\Services\Theme\ThemeManager;
    $zedTheme      = ThemeManager::resolveTheme(ThemeManager::SURFACE_PUBLIC, auth()->user());
    $zedAppearance = ThemeManager::resolveAppearance(auth()->user());
@endphp
<!DOCTYPE html>
<html lang="fa" dir="rtl" class="scroll-smooth {{ ThemeManager::htmlClassFor($zedTheme, $zedAppearance) }}"
      data-theme="{{ $zedTheme }}" data-appearance="{{ $zedAppearance }}"
      style="{{ ThemeManager::inlineStyle() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', site_setting('site_title', 'ZedProxy')) | سرویس VPN و پروکسی</title>
    <meta name="description" content="@yield('description', site_setting('site_description', 'خرید VPN و پروکسی با کیفیت بالا، سرعت فوق‌العاده و قیمت مناسب'))">
    @hasSection('meta_keywords')<meta name="keywords" content="@yield('meta_keywords')">@endif
    <meta property="og:title" content="@yield('og_title', site_setting('site_title', 'ZedProxy'))">
    <meta property="og:description" content="@yield('og_description', site_setting('site_description', ''))">
    <meta property="og:type" content="website">
    @hasSection('og_image')<meta property="og:image" content="@yield('og_image')">@endif
    @if($fav = cms_image('favicon'))<link rel="icon" href="{{ $fav }}">@endif
    <script>{!! ThemeManager::noFoucScript($zedAppearance) !!}</script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>body { font-family: 'Vazirmatn', system-ui, sans-serif; }</style>
    @stack('styles')
</head>
<body class="bg-gray-950 text-gray-100 antialiased">

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

<main>
    @yield('content')
</main>

{{-- ===== Footer (4 columns) ===== --}}
@php
    $footerPages = \App\Models\Page::where('is_active', true)->where('show_in_footer', true)->orderBy('sort_order')->get();
@endphp
<footer class="bg-gray-900/60 border-t border-gray-800">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div class="grid grid-cols-2 md:grid-cols-5 gap-8">
            <div class="col-span-2">
                <div class="flex items-center gap-2.5 font-extrabold text-lg text-white">
                    @if($flogo = cms_image('footer_logo', cms_image('logo')))
                        <img src="{{ $flogo }}" alt="{{ site_setting('site_name', 'ZedProxy') }}" class="h-7 w-auto" style="min-height:1.75rem">
                    @else
                        <span class="w-8 h-8 rounded-lg zed-gradient-bg flex items-center justify-center">
                            <svg class="w-4 h-4 text-white" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 2 4 6v6c0 5 3.4 7.7 8 10 4.6-2.3 8-5 8-10V6l-8-4z"/></svg>
                        </span>
                        <span>{{ site_setting('site_name', 'ZedProxy') }}</span>
                    @endif
                </div>
                <p class="mt-3 text-gray-400 text-sm leading-relaxed max-w-xs">
                    {{ site_setting('footer_text', 'سرویس امن و پرسرعت اتصال به اینترنت آزاد با تحویل آنی و پشتیبانی همیشگی.') }}
                </p>
            </div>
            <div>
                <h4 class="text-sm font-semibold text-white mb-4">محصولات</h4>
                <ul class="space-y-2 text-sm text-gray-400">
                    <li><a href="{{ route('plans') }}" class="hover:text-white transition">پلن‌ها</a></li>
                    <li><a href="{{ route('plans') }}" class="hover:text-white transition">لوکیشن‌ها</a></li>
                    <li><a href="{{ route('status') }}" class="hover:text-white transition">وضعیت سرویس‌ها</a></li>
                </ul>
            </div>
            <div>
                <h4 class="text-sm font-semibold text-white mb-4">{{ site_setting('support_title', 'پشتیبانی') }}</h4>
                <ul class="space-y-2 text-sm text-gray-400">
                    <li><a href="{{ route('contact') }}" class="hover:text-white transition">تماس با ما</a></li>
                    <li><a href="{{ route('faq') }}" class="hover:text-white transition">سوالات متداول</a></li>
                    <li><a href="{{ route('tutorials') }}" class="hover:text-white transition">آموزش‌ها</a></li>
                </ul>
            </div>
            <div>
                <h4 class="text-sm font-semibold text-white mb-4">قانونی</h4>
                <ul class="space-y-2 text-sm text-gray-400">
                    <li><a href="{{ url('/terms') }}" class="hover:text-white transition">قوانین و مقررات</a></li>
                    <li><a href="{{ url('/about') }}" class="hover:text-white transition">درباره ما</a></li>
                    @foreach($footerPages as $fp)
                        <li><a href="{{ route('pages.show', $fp->slug) }}" class="hover:text-white transition">{{ $fp->title }}</a></li>
                    @endforeach
                </ul>
            </div>
        </div>
        <div class="mt-8 pt-6 border-t border-gray-800 text-center text-sm text-gray-500">
            © {{ date('Y') }} {{ site_setting('copyright_text', 'ZedProxy. تمامی حقوق محفوظ است.') }}
        </div>
    </div>
</footer>

<script>
    document.getElementById('shop-menu-btn')?.addEventListener('click', function () {
        document.getElementById('shop-menu')?.classList.toggle('hidden');
    });
</script>
@stack('scripts')
</body>
</html>
