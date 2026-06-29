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
    {{-- Open Graph --}}
    <meta property="og:title" content="@yield('og_title', site_setting('site_title', 'ZedProxy'))">
    <meta property="og:description" content="@yield('og_description', site_setting('site_description', ''))">
    <meta property="og:type" content="website">
    @hasSection('og_image')<meta property="og:image" content="@yield('og_image')">@endif
    @if($fav = cms_image('favicon'))<link rel="icon" href="{{ $fav }}">@endif
    <script>{!! ThemeManager::noFoucScript($zedAppearance) !!}</script>

    <!-- Fonts: Vazirmatn (Persian) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        body { font-family: 'Vazirmatn', system-ui, sans-serif; }
    </style>
    @stack('styles')
</head>
<body class="bg-gray-950 text-gray-100 antialiased">

    <!-- Navigation -->
    <header class="sticky top-0 z-50 bg-gray-900/95 backdrop-blur border-b border-gray-800">
        <nav class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-16">
                <!-- Logo -->
                <a href="{{ route('home') }}" class="flex items-center gap-2 text-xl font-bold text-white">
                    @if($logo = cms_image('logo'))
                        <img src="{{ $logo }}" alt="{{ site_setting('site_name', 'ZedProxy') }}" class="h-8 w-auto" style="height:var(--zp-logo-size,1.15rem);min-height:2rem">
                    @else
                        <span class="text-indigo-400">{{ site_setting('site_name', 'ZedProxy') }}</span>
                    @endif
                </a>

                <!-- Desktop menu -->
                <div class="hidden md:flex items-center gap-6 text-sm font-medium">
                    <a href="{{ route('home') }}" class="text-gray-300 hover:text-white transition">خانه</a>
                    <a href="{{ route('plans') }}" class="text-gray-300 hover:text-white transition">پلن‌ها</a>
                    <a href="{{ route('tutorials') }}" class="text-gray-300 hover:text-white transition">آموزش‌ها</a>
                    <a href="{{ route('status') }}" class="text-gray-300 hover:text-white transition">وضعیت سرویس</a>
                    <a href="{{ route('faq') }}" class="text-gray-300 hover:text-white transition">سوالات متداول</a>
                    <a href="{{ route('contact') }}" class="text-gray-300 hover:text-white transition">پشتیبانی</a>
                </div>

                <!-- Auth buttons -->
                <div class="flex items-center gap-3">
                    @auth
                        <a href="{{ route('dashboard.index') }}" class="text-sm text-indigo-400 hover:text-indigo-300 transition">پنل کاربری</a>
                    @else
                        <a href="{{ route('login') }}" class="text-sm text-gray-300 hover:text-white transition">ورود</a>
                        <a href="{{ route('register') }}" class="bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-4 py-2 rounded-lg transition">ثبت‌نام</a>
                    @endauth

                    <!-- Mobile menu button -->
                    <button id="mobile-menu-btn" class="md:hidden text-gray-400 hover:text-white p-2">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Mobile menu -->
            <div id="mobile-menu" class="hidden md:hidden pb-4 space-y-2 text-sm font-medium">
                <a href="{{ route('home') }}" class="block py-2 text-gray-300 hover:text-white">خانه</a>
                <a href="{{ route('plans') }}" class="block py-2 text-gray-300 hover:text-white">پلن‌ها</a>
                <a href="{{ route('tutorials') }}" class="block py-2 text-gray-300 hover:text-white">آموزش‌ها</a>
                <a href="{{ route('status') }}" class="block py-2 text-gray-300 hover:text-white">وضعیت سرویس</a>
                <a href="{{ route('faq') }}" class="block py-2 text-gray-300 hover:text-white">سوالات متداول</a>
                <a href="{{ route('contact') }}" class="block py-2 text-gray-300 hover:text-white">پشتیبانی</a>
            </div>
        </nav>
    </header>

    <!-- Main content -->
    <main>
        @yield('content')
    </main>

    <!-- Footer -->
    @php
        $footerPages = \App\Models\Page::where('is_active', true)->where('show_in_footer', true)
            ->orderBy('sort_order')->get();
        $socials = collect([
            ['url' => site_setting('telegram_channel_url'), 'label' => 'کانال تلگرام'],
            ['url' => site_setting('telegram_support_url'), 'label' => 'پشتیبانی تلگرام'],
            ['url' => site_setting('instagram_url'),        'label' => 'اینستاگرام'],
            ['url' => site_setting('youtube_url'),          'label' => 'یوتیوب'],
            ['url' => site_setting('bot_url'),              'label' => 'ربات تلگرام'],
        ])->filter(fn ($s) => filled($s['url']));
    @endphp
    <footer class="bg-gray-900 border-t border-gray-800 mt-20">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
                <div class="col-span-1 md:col-span-2">
                    <a href="{{ route('home') }}" class="text-xl font-bold text-white">
                        @if($flogo = cms_image('footer_logo', cms_image('logo')))
                            <img src="{{ $flogo }}" alt="{{ site_setting('site_name', 'ZedProxy') }}" class="h-8 w-auto" style="min-height:2rem">
                        @else
                            <span class="text-indigo-400">{{ site_setting('site_name', 'ZedProxy') }}</span>
                        @endif
                    </a>
                    <p class="mt-3 text-gray-400 text-sm leading-relaxed">
                        {{ site_setting('footer_text', 'ارائه‌دهنده خدمات VPN و پروکسی با کیفیت بالا، سرعت فوق‌العاده و پشتیبانی ۲۴ ساعته.') }}
                    </p>
                    @if($socials->isNotEmpty())
                        <div class="mt-4 flex flex-wrap gap-2">
                            @foreach($socials as $s)
                                <a href="{{ $s['url'] }}" target="_blank" rel="noopener"
                                   class="text-xs px-3 py-1.5 rounded-lg bg-gray-800 text-gray-300 hover:text-white hover:bg-gray-700 transition">{{ $s['label'] }}</a>
                            @endforeach
                        </div>
                    @endif
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-white mb-4">لینک‌های سریع</h3>
                    <ul class="space-y-2 text-sm text-gray-400">
                        <li><a href="{{ route('plans') }}" class="hover:text-white transition">پلن‌های خرید</a></li>
                        <li><a href="{{ route('tutorials') }}" class="hover:text-white transition">آموزش‌ها</a></li>
                        <li><a href="{{ route('faq') }}" class="hover:text-white transition">سوالات متداول</a></li>
                        <li><a href="{{ route('status') }}" class="hover:text-white transition">وضعیت سرویس</a></li>
                        @foreach($footerPages as $fp)
                            <li><a href="{{ route('pages.show', $fp->slug) }}" class="hover:text-white transition">{{ $fp->title }}</a></li>
                        @endforeach
                    </ul>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-white mb-4">{{ site_setting('support_title', 'پشتیبانی') }}</h3>
                    <ul class="space-y-2 text-sm text-gray-400">
                        <li><a href="{{ route('contact') }}" class="hover:text-white transition">تماس با ما</a></li>
                        @if($se = site_setting('support_email'))<li><a href="mailto:{{ $se }}" class="hover:text-white transition">{{ $se }}</a></li>@endif
                        @if($sp = site_setting('support_phone'))<li><span class="text-gray-400">{{ $sp }}</span></li>@endif
                    </ul>
                </div>
            </div>
            <div class="mt-8 pt-8 border-t border-gray-800 text-center text-sm text-gray-500">
                © {{ date('Y') }} {{ site_setting('copyright_text', 'ZedProxy. تمامی حقوق محفوظ است.') }}
            </div>
        </div>
    </footer>

    <script>
        document.getElementById('mobile-menu-btn').addEventListener('click', function () {
            document.getElementById('mobile-menu').classList.toggle('hidden');
        });
    </script>
    @stack('scripts')
</body>
</html>
