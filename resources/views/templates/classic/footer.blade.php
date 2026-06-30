{{-- Classic site-wide footer (extracted verbatim from the original layouts.app). --}}
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
