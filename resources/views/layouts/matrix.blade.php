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

    <style>
        body { font-family: 'Vazirmatn', system-ui, sans-serif; }
        .zm-mono { font-family: 'Courier New', monospace; direction: ltr; unicode-bidi: embed; }
        #zm-matrix { position: fixed; inset: 0; z-index: 0; opacity: .16; pointer-events: none; }
        .zm-bg-glow { position: fixed; inset: 0; z-index: 0; pointer-events: none;
            background:
                radial-gradient(ellipse 50% 40% at 70% 5%, color-mix(in srgb, var(--zp-primary) 16%, transparent), transparent),
                radial-gradient(ellipse 45% 40% at 20% 30%, color-mix(in srgb, var(--zp-accent) 12%, transparent), transparent); }
        .zm-scanline { position: fixed; inset: 0; z-index: 1; pointer-events: none;
            background: repeating-linear-gradient(0deg, transparent 0 2px, rgba(0,0,0,.12) 2px 4px); opacity: .4; }
        @media (prefers-reduced-motion: reduce) {
            #zm-matrix { display: none; }
            .zm-blink, .zm-pkt, .zm-glitch::before, .zm-glitch::after { animation: none !important; }
        }
    </style>
    @stack('styles')
</head>
<body class="bg-gray-950 text-gray-100 antialiased">

<!-- matrix-template-marker -->
<canvas id="zm-matrix"></canvas>
<div class="zm-bg-glow"></div>
<div class="zm-scanline"></div>

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

<main class="relative z-10">
    @yield('content')
</main>

{{-- ===== Footer (4 columns) ===== --}}
@php
    $footerPages = \App\Models\Page::where('is_active', true)->where('show_in_footer', true)->orderBy('sort_order')->get();
@endphp
<footer class="relative z-10 bg-gray-900/60 border-t border-gray-800">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div class="grid grid-cols-2 md:grid-cols-5 gap-8">
            <div class="col-span-2">
                <div class="flex items-center gap-2.5 font-black text-lg text-white">
                    @if($flogo = cms_image('footer_logo', cms_image('logo')))
                        <img src="{{ $flogo }}" alt="{{ site_setting('site_name', 'ZedProxy') }}" class="h-7 w-auto" style="min-height:1.75rem">
                    @else
                        <span>Zed<b class="text-green-400">Proxy</b></span>
                    @endif
                </div>
                <p class="mt-3 text-gray-400 text-sm leading-relaxed max-w-xs">
                    {{ site_setting('footer_text', 'اتصال امن و رمزنگاری‌شده به اینترنت آزاد، بدون ثبت لاگ و با پشتیبانی همیشگی.') }}
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
                    <li><a href="{{ url('/terms') }}" class="hover:text-white transition">قوانین</a></li>
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
    document.getElementById('matrix-menu-btn')?.addEventListener('click', function () {
        document.getElementById('matrix-menu')?.classList.toggle('hidden');
    });

    // ===== Matrix code-rain — theme-coloured, fps-capped, battery-friendly =====
    (function () {
        var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var canvas = document.getElementById('zm-matrix');
        if (!canvas || reduce || innerWidth < 640) { if (canvas) canvas.style.display = 'none'; return; }

        var ctx = canvas.getContext('2d');
        var css = getComputedStyle(document.documentElement);
        var rain = (css.getPropertyValue('--zp-accent') || '#10b981').trim();
        var bg   = (css.getPropertyValue('--zp-bg') || '#060a08').trim();
        var chars = '01ﾊﾐﾋｰｳｼﾅﾓﾆｻﾜｲｸﾘ';
        var fs = 14, cols = 0, drops = [];

        function resize() {
            canvas.width = innerWidth; canvas.height = innerHeight;
            cols = Math.floor(canvas.width / fs);
            drops = new Array(cols).fill(1);
        }
        resize();
        addEventListener('resize', resize);

        // Cap at ~20fps so the animation never saturates the CPU.
        var last = 0, interval = 1000 / 20, running = true, raf;
        function frame(now) {
            if (!running) return;
            raf = requestAnimationFrame(frame);
            if (now - last < interval) return;
            last = now;
            ctx.fillStyle = bg.length ? hexToRgba(bg, 0.10) : 'rgba(6,10,8,.10)';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.fillStyle = rain; ctx.font = fs + 'px monospace';
            for (var i = 0; i < drops.length; i++) {
                ctx.fillText(chars[Math.floor(Math.random() * chars.length)], i * fs, drops[i] * fs);
                if (drops[i] * fs > canvas.height && Math.random() > 0.975) drops[i] = 0;
                drops[i]++;
            }
        }
        function hexToRgba(h, a) {
            h = h.replace('#', '');
            if (h.length === 3) h = h.split('').map(function (c) { return c + c; }).join('');
            var n = parseInt(h, 16);
            return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + a + ')';
        }
        // Pause when the tab is hidden to avoid wasting CPU/battery.
        document.addEventListener('visibilitychange', function () {
            running = !document.hidden;
            if (running) { last = 0; raf = requestAnimationFrame(frame); }
        });
        raf = requestAnimationFrame(frame);
    })();
</script>
@stack('scripts')
</body>
</html>
