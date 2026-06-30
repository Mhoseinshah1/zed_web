{{-- ============================================================================
     WoodMart template — site-wide 4-column footer. Chrome via theme classes;
     orange link-hover via scoped .wm-navlink helper. Footer pages from real DB.
     ============================================================================ --}}
@php
    $footerPages = \App\Models\Page::where('is_active', true)->where('show_in_footer', true)->orderBy('sort_order')->get();
@endphp
<footer class="bg-base-soft border-t border-line mt-11">
    <div class="max-w-[1180px] mx-auto px-5 pt-9 pb-6">
        <div class="grid grid-cols-2 md:grid-cols-[2fr_1fr_1fr_1fr] gap-7 mb-6">
            {{-- Brand --}}
            <div class="col-span-2 md:col-span-1">
                <div class="flex items-center gap-2.5 font-black text-lg text-content">
                    @if($flogo = cms_image('footer_logo', cms_image('logo')))
                        <img src="{{ $flogo }}" alt="{{ site_setting('site_name', 'ZedProxy') }}" class="h-7 w-auto" style="min-height:1.75rem">
                    @else
                        <span class="wm-logo-badge w-8 h-8 rounded-lg flex items-center justify-center text-white">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 2 4 6v6c0 5 3.4 7.7 8 10 4.6-2.3 8-5 8-10V6l-8-4z"/></svg>
                        </span>
                        <span>{{ site_setting('site_name', 'ZedProxy') }}</span>
                    @endif
                </div>
                <p class="mt-2.5 text-content-muted text-[13px] leading-relaxed max-w-[260px]">
                    {{ site_setting('woodmart_footer_text', 'فروشگاه سرویس‌های امن و پرسرعت اتصال به اینترنت آزاد.') }}
                </p>
            </div>

            {{-- Products --}}
            <div>
                <h4 class="text-sm font-semibold text-content mb-3">{{ site_setting('woodmart_footer_col1', 'محصولات') }}</h4>
                <a href="{{ route('plans') }}" class="wm-navlink block text-content-muted text-[13px] py-1">پلن‌ها</a>
                <a href="{{ route('plans') }}" class="wm-navlink block text-content-muted text-[13px] py-1">لوکیشن‌ها</a>
                <a href="{{ route('status') }}" class="wm-navlink block text-content-muted text-[13px] py-1">وضعیت سرویس‌ها</a>
            </div>

            {{-- Support --}}
            <div>
                <h4 class="text-sm font-semibold text-content mb-3">{{ site_setting('woodmart_footer_col2', 'پشتیبانی') }}</h4>
                <a href="{{ route('contact') }}" class="wm-navlink block text-content-muted text-[13px] py-1">تماس با ما</a>
                <a href="{{ route('faq') }}" class="wm-navlink block text-content-muted text-[13px] py-1">سوالات متداول</a>
                <a href="{{ route('tutorials') }}" class="wm-navlink block text-content-muted text-[13px] py-1">آموزش‌ها</a>
            </div>

            {{-- Account / legal --}}
            <div>
                <h4 class="text-sm font-semibold text-content mb-3">{{ site_setting('woodmart_footer_col3', 'حساب') }}</h4>
                @guest
                    <a href="{{ route('login') }}" class="wm-navlink block text-content-muted text-[13px] py-1">ورود</a>
                @else
                    <a href="{{ route('dashboard.index') }}" class="wm-navlink block text-content-muted text-[13px] py-1">پنل کاربری</a>
                    <a href="{{ route('dashboard.orders') }}" class="wm-navlink block text-content-muted text-[13px] py-1">سفارش‌ها</a>
                @endguest
                <a href="{{ url('/terms') }}" class="wm-navlink block text-content-muted text-[13px] py-1">قوانین و مقررات</a>
                @foreach($footerPages as $fp)
                    <a href="{{ route('pages.show', $fp->slug) }}" class="wm-navlink block text-content-muted text-[13px] py-1">{{ $fp->title }}</a>
                @endforeach
            </div>
        </div>

        <div class="border-t border-line pt-4 text-center text-content-muted text-[12.5px]">
            © {{ date('Y') }} {{ site_setting('copyright_text', 'ZedProxy. تمامی حقوق محفوظ است.') }}
        </div>
    </div>
</footer>
