<!-- premium-template-marker -->
@php
    $navLinks = [
        ['label' => 'خانه',           'url' => route('home'),      'active' => request()->routeIs('home')],
        ['label' => 'تعرفه‌ها',       'url' => route('plans'),     'active' => request()->routeIs('plans')],
        ['label' => 'آموزش اتصال',    'url' => route('tutorials'), 'active' => request()->routeIs('tutorials*')],
        ['label' => 'وضعیت شبکه',     'url' => route('status'),    'active' => request()->routeIs('status')],
        ['label' => 'سوالات متداول',  'url' => route('faq'),       'active' => request()->routeIs('faq')],
        ['label' => 'پشتیبانی',       'url' => route('contact'),   'active' => request()->routeIs('contact')],
    ];
@endphp
<header class="zp-premium-header">
    <div class="zp-premium-container zp-premium-nav">
        <a href="{{ route('home') }}" class="zp-premium-brand" aria-label="{{ site_setting('site_name', 'ZedProxy') }}">
            @if($logo = cms_image('logo'))
                @include('partials.site-logo', ['src' => $logo, 'class' => 'zp-premium-logo-img', 'style' => '', 'eager' => true])
            @else
                <span class="zp-premium-logo-mark">Z</span>
            @endif
            <span class="zp-premium-brand-copy">
                <strong>{{ site_setting('site_name', 'ZedProxy') }}</strong>
                <small>FAST • STABLE • SECURE</small>
            </span>
        </a>

        <nav class="zp-premium-links" aria-label="منوی اصلی">
            @foreach($navLinks as $link)
                <a href="{{ $link['url'] }}" class="{{ $link['active'] ? 'is-active' : '' }}">{{ $link['label'] }}</a>
            @endforeach
        </nav>

        <div class="zp-premium-actions">
            @auth
                <a href="{{ route('dashboard.index') }}" class="zp-premium-btn zp-premium-btn-soft">پنل کاربری</a>
            @else
                <a href="{{ route('login') }}" class="zp-premium-login-link">ورود</a>
            @endauth
            <a href="{{ route('plans') }}" class="zp-premium-btn zp-premium-btn-primary">خرید سرویس</a>
            <button type="button" id="premium-menu-btn" class="zp-premium-menu-btn" aria-label="باز کردن منو" aria-expanded="false">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
            </button>
        </div>
    </div>

    <div id="premium-mobile-menu" class="zp-premium-mobile-menu" hidden>
        <div class="zp-premium-container">
            @foreach($navLinks as $link)
                <a href="{{ $link['url'] }}" class="{{ $link['active'] ? 'is-active' : '' }}">{{ $link['label'] }}</a>
            @endforeach
            @guest
                <a href="{{ route('login') }}">ورود به حساب</a>
                <a href="{{ route('register') }}">ساخت حساب</a>
            @endguest
        </div>
    </div>
</header>
