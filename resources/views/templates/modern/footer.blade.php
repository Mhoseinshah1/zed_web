@php
    $footerPages = \App\Models\Page::where('is_active', true)->where('show_in_footer', true)->orderBy('sort_order')->get();
@endphp
<footer class="bg-gray-900/60 border-t border-gray-800 mt-10">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-8">
            <div class="md:col-span-2">
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
                <p class="mt-3 text-gray-400 text-sm leading-relaxed max-w-sm">
                    {{ site_setting('footer_text', 'سرویس امن و پرسرعت اتصال به اینترنت آزاد با تحویل آنی و پشتیبانی همیشگی.') }}
                </p>
            </div>
            <div>
                <h4 class="text-sm font-semibold text-white mb-4">دسترسی سریع</h4>
                <ul class="space-y-2 text-sm text-gray-400">
                    <li><a href="{{ route('plans') }}" class="hover:text-white transition">محصولات</a></li>
                    <li><a href="{{ route('tutorials') }}" class="hover:text-white transition">آموزش‌ها</a></li>
                    <li><a href="{{ route('status') }}" class="hover:text-white transition">وضعیت سرویس‌ها</a></li>
                    <li><a href="{{ url('/about') }}" class="hover:text-white transition">درباره ما</a></li>
                </ul>
            </div>
            <div>
                <h4 class="text-sm font-semibold text-white mb-4">{{ site_setting('support_title', 'پشتیبانی') }}</h4>
                <ul class="space-y-2 text-sm text-gray-400">
                    <li><a href="{{ route('contact') }}" class="hover:text-white transition">تماس با ما</a></li>
                    <li><a href="{{ route('faq') }}" class="hover:text-white transition">سوالات متداول</a></li>
                    <li><a href="{{ url('/terms') }}" class="hover:text-white transition">قوانین و مقررات</a></li>
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
