@php
    use App\Services\Theme\ThemeManager;
    use App\Services\Theme\TemplateManager;
    $zedTheme      = ThemeManager::resolveTheme(ThemeManager::SURFACE_USER, auth()->user());
    $zedAppearance = ThemeManager::resolveAppearance(auth()->user());
    // The active site template only lends the panel its ACCENT ("clothes"), never
    // its structure. data-template carries the template's scoped accent styles in;
    // the panel keeps its own fixed sidebar/layout.
    $tpl = TemplateManager::activeTemplate();
@endphp
<!DOCTYPE html>
<html lang="fa" dir="rtl" class="scroll-smooth {{ ThemeManager::htmlClassFor($zedTheme, $zedAppearance) }}"
      data-theme="{{ $zedTheme }}" data-appearance="{{ $zedAppearance }}" data-template="{{ $tpl }}"
      style="{{ ThemeManager::inlineStyle() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'داشبورد') | ZedProxy</title>
    <script>{!! ThemeManager::noFoucScript($zedAppearance) !!}</script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <style>body { font-family: 'Vazirmatn', system-ui, sans-serif; }</style>
    {{-- User account dropdown menu — scoped .zp-um-* (user panel only, no leak).
         Chrome from the theme vars; accent follows the active template via
         --zp-tpl-accent (falls back to the theme primary); logout uses danger. --}}
    <style>
        .zp-um-wrap {
            position: relative;
            --zp-um-accent: var(--zp-tpl-accent, var(--zp-primary, #6366f1));
            --zp-um-accent-soft: color-mix(in srgb, var(--zp-um-accent) 12%, transparent);
            --zp-um-danger: var(--zp-danger, #f43f5e);
        }
        .zp-um-trigger {
            display: flex; align-items: center; gap: .55rem; cursor: pointer;
            padding: .3rem .5rem .3rem .75rem; border-radius: 999px;
            border: 1px solid var(--zp-border); background: var(--zp-surface-soft);
            color: var(--zp-text); transition: background-color .15s;
        }
        .zp-um-trigger:hover { background: var(--zp-surface-hover); }
        .zp-um-trigger:focus-visible { outline: 2px solid var(--zp-um-accent); outline-offset: 2px; }
        .zp-um-info { text-align: left; line-height: 1.25; }
        .zp-um-hi { font-size: 11px; color: var(--zp-text-muted); }
        .zp-um-nm { font-size: 13px; font-weight: 700; color: var(--zp-text); }
        .zp-um-av {
            width: 2.25rem; height: 2.25rem; border-radius: 50%; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            color: #fff; font-weight: 800; font-size: 15px;
            background: linear-gradient(135deg, var(--zp-um-accent), color-mix(in srgb, var(--zp-um-accent) 55%, #ffffff));
        }
        .zp-um-av-lg { width: 2.85rem; height: 2.85rem; font-size: 18px; }
        .zp-um-chev { width: 15px; height: 15px; color: var(--zp-text-muted); transition: transform .2s; }
        .zp-um-trigger[aria-expanded="true"] .zp-um-chev { transform: rotate(180deg); }

        .zp-um-menu {
            position: absolute; top: calc(100% + .5rem); left: 0;
            width: 15rem; max-width: calc(100vw - 2rem);
            background: var(--zp-surface); border: 1px solid var(--zp-border);
            border-radius: 1rem; overflow: hidden; z-index: 50;
            box-shadow: 0 10px 40px -10px rgba(0, 0, 0, .35);
        }
        .zp-um-head {
            padding: 1rem; display: flex; align-items: center; gap: .75rem;
            border-bottom: 1px solid var(--zp-border); background: var(--zp-surface-soft);
        }
        .zp-um-sub {
            font-size: 11.5px; color: var(--zp-text-muted); margin-top: 2px;
            direction: ltr; text-align: right;
        }
        .zp-um-chip {
            margin: .75rem 1rem; padding: .7rem .9rem; border-radius: .75rem;
            background: var(--zp-um-accent-soft); display: flex; align-items: center;
            justify-content: space-between; text-decoration: none;
        }
        .zp-um-chip .l { display: flex; align-items: center; gap: .5rem; font-size: 12px; color: var(--zp-text-muted); }
        .zp-um-chip .l svg { width: 16px; height: 16px; color: var(--zp-um-accent); }
        .zp-um-chip .v { font-size: 14px; font-weight: 800; color: var(--zp-text); white-space: nowrap; }
        .zp-um-chip .v small { font-size: 10px; color: var(--zp-text-muted); font-weight: 500; }

        .zp-um-items { padding: .4rem; }
        .zp-um-item {
            display: flex; align-items: center; gap: .7rem; width: 100%;
            padding: .6rem .75rem; border-radius: .6rem; color: var(--zp-text);
            font-size: 13px; font-weight: 500; text-decoration: none; cursor: pointer;
            font-family: inherit; background: none; border: 0; text-align: start;
            transition: background-color .13s;
        }
        .zp-um-item:hover { background: var(--zp-surface-hover); }
        .zp-um-item > svg:first-child { width: 17px; height: 17px; color: var(--zp-text-muted); flex-shrink: 0; }
        .zp-um-item:hover > svg:first-child { color: var(--zp-um-accent); }
        .zp-um-item .ar { margin-inline-start: auto; width: 14px; height: 14px; color: var(--zp-text-muted); }
        .zp-um-sep { height: 1px; background: var(--zp-border); margin: .4rem .75rem; }
        .zp-um-danger { color: var(--zp-um-danger); }
        .zp-um-danger > svg:first-child { color: var(--zp-um-danger); }
        .zp-um-danger:hover { background: color-mix(in srgb, var(--zp-um-danger) 12%, transparent); }
        .zp-um-danger:hover > svg:first-child { color: var(--zp-um-danger); }
    </style>
    {{-- Active template's scoped accent styles (defines --zp-tpl-accent for the
         panel). Only templates with a fixed accent ship a styles view; others
         fall back to the theme's default accent. Never changes chrome/structure. --}}
    @includeIf("templates.$tpl.styles")
</head>
<body class="zp-user-panel bg-base text-content antialiased">

<div class="flex min-h-screen">
    <!-- Mobile drawer backdrop -->
    <div id="panel-backdrop" class="fixed inset-0 z-40 bg-black/50 hidden lg:hidden" aria-hidden="true"></div>

    <!-- Sidebar: docked on lg+, off-canvas drawer (from the right, RTL) below lg -->
    <aside id="panel-sidebar"
           class="fixed inset-y-0 right-0 z-50 w-64 bg-surface border-l border-line flex flex-col shrink-0 overflow-y-auto
                  transform translate-x-full transition-transform duration-300 ease-out
                  lg:static lg:z-auto lg:translate-x-0 lg:transition-none"
           aria-label="منوی پنل کاربری">
        <div class="p-6 border-b border-line flex items-start justify-between gap-2">
            <div>
                <a href="{{ route('home') }}" class="text-lg font-bold text-content">
                    <span class="text-indigo-400">Zed</span>Proxy
                </a>
                <p class="text-xs text-content-muted mt-1">پنل کاربری</p>
            </div>
            <!-- Close (mobile only) -->
            <button id="panel-close-btn" type="button"
                    class="lg:hidden -mt-1 -ml-1 p-2 rounded-lg text-content-muted hover:text-content hover:bg-surface-soft focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 transition"
                    aria-label="بستن منو">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <nav class="flex-1 p-4 space-y-1 text-sm">
            <a href="{{ route('dashboard.index') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-lg {{ request()->routeIs('dashboard.index') ? 'bg-indigo-600 text-white' : 'text-content-muted hover:text-content hover:bg-surface-soft' }} transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7h18M3 12h18M3 17h18"/></svg>
                داشبورد
            </a>
            <a href="{{ route('dashboard.services') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-lg {{ request()->routeIs('dashboard.services') ? 'bg-indigo-600 text-white' : 'text-content-muted hover:text-content hover:bg-surface-soft' }} transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M12 5l7 7-7 7"/></svg>
                سرویس‌های من
            </a>
            <a href="{{ route('dashboard.orders') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-lg {{ request()->routeIs('dashboard.orders*') ? 'bg-indigo-600 text-white' : 'text-content-muted hover:text-content hover:bg-surface-soft' }} transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2"/></svg>
                سفارش‌های من
            </a>
            <a href="{{ route('dashboard.wallet') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-lg {{ request()->routeIs('dashboard.wallet') ? 'bg-indigo-600 text-white' : 'text-content-muted hover:text-content hover:bg-surface-soft' }} transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/></svg>
                کیف پول
            </a>
            @php $unreadNotifications = \App\Models\Notification::forUser(auth()->id())->unread()->count(); @endphp
            <a href="{{ route('dashboard.notifications') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-lg {{ request()->routeIs('dashboard.notifications') ? 'bg-indigo-600 text-white' : 'text-content-muted hover:text-content hover:bg-surface-soft' }} transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                <span>اعلان‌ها</span>
                @if($unreadNotifications > 0)
                <span class="mr-auto inline-flex items-center justify-center min-w-5 h-5 px-1.5 text-[11px] font-bold rounded-full bg-red-500 text-white">
                    {{ $unreadNotifications > 99 ? '۹۹+' : $unreadNotifications }}
                </span>
                @endif
            </a>
            @php
                $repMode = \App\Services\Referrals\ReferralSettings::mode();
                $showRep = $repMode === \App\Services\Referrals\ReferralSettings::MODE_ALL_USERS
                    || auth()->user()->isApprovedRepresentative()
                    || \App\Services\Referrals\ReferralSettings::representativeSystemEnabled();
            @endphp
            @if($showRep)
            <a href="{{ route('dashboard.representative') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-lg {{ request()->routeIs('dashboard.representative*') ? 'bg-indigo-600 text-white' : 'text-content-muted hover:text-content hover:bg-surface-soft' }} transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6 0a3 3 0 10-2.83-4"/></svg>
                نمایندگی
            </a>
            @endif
            @php $unreadTickets = \App\Models\SupportTicket::forUser(auth()->id())->where('user_unread', true)->count(); @endphp
            <a href="{{ route('dashboard.tickets') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-lg {{ request()->routeIs('dashboard.tickets*') ? 'bg-indigo-600 text-white' : 'text-content-muted hover:text-content hover:bg-surface-soft' }} transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 13v5a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h5m11-2l-9 9m0 0V4m0 5h5"/></svg>
                <span>تیکت‌های پشتیبانی</span>
                @if($unreadTickets > 0)
                <span class="mr-auto inline-flex items-center justify-center min-w-5 h-5 px-1.5 text-[11px] font-bold rounded-full bg-red-500 text-white">{{ $unreadTickets }}</span>
                @endif
            </a>
            <a href="{{ route('dashboard.profile') }}"
               class="flex items-center gap-3 px-3 py-2 rounded-lg {{ request()->routeIs('dashboard.profile') ? 'bg-indigo-600 text-white' : 'text-content-muted hover:text-content hover:bg-surface-soft' }} transition">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                پروفایل
            </a>
        </nav>

        <div class="p-4 border-t border-line">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="w-full flex items-center gap-3 px-3 py-2 text-sm text-red-400 hover:text-red-300 hover:bg-surface-soft rounded-lg transition">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                    خروج
                </button>
            </form>
        </div>
    </aside>

    <!-- Main content -->
    <div class="flex-1 flex flex-col min-w-0">
        <header class="bg-surface border-b border-line px-4 py-3 lg:px-6 lg:py-4 flex items-center justify-between gap-3">
            <div class="flex items-center gap-3 min-w-0">
                <!-- Hamburger (mobile only) -->
                <button id="panel-menu-btn" type="button"
                        class="lg:hidden -mr-1 p-2 rounded-lg text-content-muted hover:text-content hover:bg-surface-soft focus:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500 transition"
                        aria-label="باز کردن منو" aria-controls="panel-sidebar" aria-expanded="false">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <h1 class="text-base lg:text-lg font-semibold text-content truncate">@yield('title', 'داشبورد')</h1>
            </div>
            {{-- ===== User account dropdown menu ===== --}}
            @php
                $zpUser    = auth()->user();
                $zpName    = $zpUser->name ?: 'کاربر';
                $zpInitial = \Illuminate\Support\Str::upper(mb_substr($zpName, 0, 1));
            @endphp
            <div class="zp-um-wrap shrink-0" id="zp-user-menuwrap">
                <button type="button" id="zp-user-btn" class="zp-um-trigger" aria-haspopup="true" aria-expanded="false" aria-label="منوی حساب کاربری">
                    <svg class="zp-um-chev" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m6 9 6 6 6-6"/></svg>
                    <span class="zp-um-info hidden sm:block">
                        <span class="zp-um-hi block">خوش آمدید</span>
                        <span class="zp-um-nm block">{{ $zpName }}</span>
                    </span>
                    <span class="zp-um-av">{{ $zpInitial }}</span>
                </button>

                <div class="zp-um-menu hidden" id="zp-user-menu" role="menu" aria-labelledby="zp-user-btn">
                    {{-- Identity --}}
                    <div class="zp-um-head">
                        <span class="zp-um-av zp-um-av-lg">{{ $zpInitial }}</span>
                        <div class="min-w-0">
                            <div class="zp-um-nm truncate">{{ $zpName }}</div>
                            <div class="zp-um-sub">#{{ $zpUser->account_id }}</div>
                        </div>
                    </div>

                    {{-- Wallet balance (real) --}}
                    <a href="{{ route('dashboard.wallet') }}" class="zp-um-chip" role="menuitem">
                        <span class="l">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4M3 5v14a2 2 0 0 0 2 2h16v-5M18 12a2 2 0 0 0 0 4h4v-4z"/></svg>
                            موجودی کیف پول
                        </span>
                        <span class="v">{{ number_format((int) $zpUser->wallet_balance_toman) }} <small>ت</small></span>
                    </a>

                    {{-- Items --}}
                    <div class="zp-um-items">
                        <a href="{{ route('dashboard.profile') }}" class="zp-um-item" role="menuitem">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            پروفایل من
                            <svg class="ar" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
                        </a>
                        <a href="{{ route('dashboard.wallet') }}" class="zp-um-item" role="menuitem">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4M3 5v14a2 2 0 0 0 2 2h16v-5M18 12a2 2 0 0 0 0 4h4v-4z"/></svg>
                            کیف پول و شارژ
                            <svg class="ar" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
                        </a>
                        <a href="{{ route('dashboard.services') }}" class="zp-um-item" role="menuitem">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/></svg>
                            سرویس‌های من
                            <svg class="ar" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 18-6-6 6-6"/></svg>
                        </a>

                        <div class="zp-um-sep"></div>

                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="zp-um-item zp-um-danger" role="menuitem">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                                خروج از حساب
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </header>
        <main class="flex-1 p-4 lg:p-6">
            @if(session('success'))
                <div class="mb-6 bg-green-500/10 border border-green-500/30 rounded-lg p-4 text-sm text-green-300">
                    {{ session('success') }}
                </div>
            @endif
            @if(session('error'))
                <div class="mb-6 bg-red-500/10 border border-red-500/30 rounded-lg p-4 text-sm text-red-300">
                    {{ session('error') }}
                </div>
            @endif
            @yield('content')
        </main>
    </div>
</div>

{{-- Lightweight RTL drawer toggle (Alpine isn't loaded on the user panel). --}}
<script>
(function () {
    var btn   = document.getElementById('panel-menu-btn'),
        side  = document.getElementById('panel-sidebar'),
        back  = document.getElementById('panel-backdrop'),
        close = document.getElementById('panel-close-btn'),
        mqDesktop = window.matchMedia('(min-width: 1024px)');

    if (!side) return;

    function openDrawer() {
        side.classList.remove('translate-x-full');
        side.classList.add('translate-x-0');
        back && back.classList.remove('hidden');
        document.body.classList.add('overflow-hidden');
        btn && btn.setAttribute('aria-expanded', 'true');
        close && close.focus();
    }
    function closeDrawer() {
        side.classList.add('translate-x-full');
        side.classList.remove('translate-x-0');
        back && back.classList.add('hidden');
        document.body.classList.remove('overflow-hidden');
        btn && btn.setAttribute('aria-expanded', 'false');
    }

    btn   && btn.addEventListener('click', openDrawer);
    close && close.addEventListener('click', closeDrawer);
    back  && back.addEventListener('click', closeDrawer);

    // Close after navigating from any menu link (mobile only).
    side.querySelectorAll('a[href]').forEach(function (a) {
        a.addEventListener('click', function () { if (!mqDesktop.matches) closeDrawer(); });
    });

    // Esc closes; resizing up to desktop clears the mobile state.
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeDrawer(); });
    mqDesktop.addEventListener('change', function (e) {
        if (e.matches) { back && back.classList.add('hidden'); document.body.classList.remove('overflow-hidden'); }
    });
})();

/* User account dropdown menu — click toggles, outside-click and Esc close it. */
(function () {
    var wrap = document.getElementById('zp-user-menuwrap'),
        btn  = document.getElementById('zp-user-btn'),
        menu = document.getElementById('zp-user-menu');
    if (!wrap || !btn || !menu) return;

    function openMenu()  { menu.classList.remove('hidden'); btn.setAttribute('aria-expanded', 'true'); }
    function closeMenu() { menu.classList.add('hidden');    btn.setAttribute('aria-expanded', 'false'); }

    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        menu.classList.contains('hidden') ? openMenu() : closeMenu();
    });
    document.addEventListener('click', function (e) { if (!wrap.contains(e.target)) closeMenu(); });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMenu(); });
})();
</script>
@stack('scripts')
</body>
</html>
