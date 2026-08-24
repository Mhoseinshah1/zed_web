@php
    $footerPages = \App\Models\Page::where('is_active', true)->where('show_in_footer', true)->orderBy('sort_order')->get();
@endphp
<footer class="zp-premium-footer">
    <div class="zp-premium-container zp-premium-footer-grid">
        <div class="zp-premium-footer-brand">
            <a href="{{ route('home') }}" class="zp-premium-brand">
                @if($flogo = cms_image('footer_logo', cms_image('logo')))
                    @include('partials.site-logo', ['src' => $flogo, 'class' => 'zp-premium-logo-img', 'style' => ''])
                @else
                    <span class="zp-premium-logo-mark">Z</span>
                @endif
                <span class="zp-premium-brand-copy">
                    <strong>{{ site_setting('site_name', 'ZedProxy') }}</strong>
                    <small>FAST • STABLE • SECURE</small>
                </span>
            </a>
            <p>{{ site_setting('footer_text', 'ارائه‌دهنده خدمات VPN و پروکسی با تمرکز روی سرعت، پایداری، مدیریت ساده سرویس و پشتیبانی ۲۴ ساعته.') }}</p>
        </div>

        <div>
            <h4>سرویس</h4>
            <a href="{{ route('plans') }}">تعرفه‌ها</a>
            <a href="{{ route('tutorials') }}">آموزش اتصال</a>
            <a href="{{ route('status') }}">وضعیت شبکه</a>
            <a href="{{ route('faq') }}">سوالات متداول</a>
        </div>

        <div>
            <h4>حساب</h4>
            @auth
                <a href="{{ route('dashboard.index') }}">پنل کاربری</a>
                <a href="{{ route('dashboard.services') }}">سرویس‌های من</a>
                <a href="{{ route('dashboard.orders') }}">سفارش‌ها</a>
                <a href="{{ route('dashboard.wallet') }}">کیف پول</a>
            @else
                <a href="{{ route('login') }}">ورود</a>
                <a href="{{ route('register') }}">ثبت‌نام</a>
            @endauth
            <a href="{{ route('contact') }}">پشتیبانی</a>
        </div>

        <div>
            <h4>ZED</h4>
            <a href="{{ url('/about') }}">درباره ما</a>
            <a href="{{ url('/terms') }}">قوانین</a>
            <a href="{{ url('/privacy') }}">حریم خصوصی</a>
            @foreach($footerPages as $fp)
                <a href="{{ route('pages.show', $fp->slug) }}">{{ $fp->title }}</a>
            @endforeach
        </div>
    </div>
    <div class="zp-premium-container zp-premium-footer-bottom">
        <span>© {{ date('Y') }} {{ site_setting('copyright_text', 'ZedProxy. تمامی حقوق محفوظ است.') }}</span>
        <span>Fast • Stable • Secure</span>
    </div>
</footer>
