{{-- Classic site-wide header. Unified nav (order + active state) shared by all
     templates; classic styling preserved. --}}
@php
    $navLinks = [
        ['label' => 'خانه',          'url' => route('home'),      'active' => request()->routeIs('home')],
        ['label' => 'محصولات',       'url' => route('plans'),     'active' => request()->routeIs('plans')],
        ['label' => 'آموزش‌ها',      'url' => route('tutorials'), 'active' => request()->routeIs('tutorials*')],
        ['label' => 'وضعیت سرویس‌ها', 'url' => route('status'),    'active' => request()->routeIs('status')],
        ['label' => 'درباره ما',     'url' => url('/about'),      'active' => request()->is('about', 'pages/about')],
        ['label' => 'قوانین',        'url' => url('/terms'),      'active' => request()->is('terms', 'pages/terms')],
        ['label' => 'پشتیبانی',      'url' => route('contact'),   'active' => request()->routeIs('contact')],
    ];
@endphp
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
            <div class="hidden md:flex items-center gap-1 text-sm font-medium">
                @foreach($navLinks as $link)
                    <a href="{{ $link['url'] }}"
                       class="px-3 py-2 rounded-lg transition {{ $link['active'] ? 'text-white bg-gray-800' : 'text-gray-300 hover:text-white hover:bg-gray-800' }}">
                        {{ $link['label'] }}
                    </a>
                @endforeach
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
        <div id="mobile-menu" class="hidden md:hidden pb-4 space-y-1 text-sm font-medium">
            @foreach($navLinks as $link)
                <a href="{{ $link['url'] }}" class="block py-2 px-2 rounded-lg {{ $link['active'] ? 'text-white bg-gray-800' : 'text-gray-300 hover:text-white hover:bg-gray-800' }}">{{ $link['label'] }}</a>
            @endforeach
        </div>
    </nav>
</header>
