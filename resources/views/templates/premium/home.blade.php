<!-- premium-home-template-marker -->
<section class="zp-premium-hero">
    <div class="zp-premium-container zp-premium-hero-grid">
        <div class="zp-premium-hero-copy">
            @if($badge = site_setting('hero_badge_text', 'شبکه ZED آنلاین و پایدار است'))
                <div class="zp-premium-eyebrow"><span></span>{{ $badge }}</div>
            @endif

            <h1>
                <span>{{ site_setting('hero_title', 'اتصال حرفه‌ای، سریع و پایدار') }}</span>
                <strong>{{ site_setting('hero_subtitle', 'برای کاربر حرفه‌ای') }}</strong>
            </h1>

            <p>{{ site_setting('hero_description', 'ZedProxy برای زمانی است که فقط وصل شدن کافی نیست؛ سرعت، پایداری، لوکیشن‌های کاربردی، مدیریت ساده سرویس و پشتیبانی واقعی را در یک تجربه حرفه‌ای کنار هم قرار می‌دهد.') }}</p>

            <div class="zp-premium-hero-actions">
                <a href="{{ site_setting('hero_primary_button_url', route('plans')) }}" class="zp-premium-btn zp-premium-btn-primary">
                    {{ site_setting('hero_primary_button_text', 'مشاهده و خرید پلن‌ها') }}
                </a>
                @auth
                    <a href="{{ route('dashboard.index') }}" class="zp-premium-btn zp-premium-btn-soft">مشاهده پنل کاربری</a>
                @else
                    <a href="{{ route('register') }}" class="zp-premium-btn zp-premium-btn-soft">ساخت حساب ZED</a>
                @endauth
            </div>

            <div class="zp-premium-chips">
                <span>فعال‌سازی سریع</span>
                <span>لینک Subscription</span>
                <span>مناسب کار و ترید</span>
                <span>لوکیشن‌های گیمینگ</span>
                <span>پشتیبانی ۲۴ ساعته</span>
            </div>

            <div class="zp-premium-stats">
                <div><strong>{{ $locations->count() ?: '20+' }}</strong><small>لوکیشن فعال</small></div>
                <div><strong>24/7</strong><small>پشتیبانی</small></div>
                <div><strong>5</strong><small>سطح نمایش بار</small></div>
                <div><strong>Fast</strong><small>تحویل سرویس</small></div>
            </div>
        </div>

        <div class="zp-premium-console-wrap" aria-hidden="true">
            <div class="zp-premium-orb zp-premium-orb-a"></div>
            <div class="zp-premium-orb zp-premium-orb-b"></div>
            <div class="zp-premium-console" id="premium-console-card">
                <div class="zp-premium-console-head">
                    <b>ZED Network Console</b>
                    <div><i></i><i></i><i></i></div>
                </div>
                <div class="zp-premium-connect-card">
                    <small>وضعیت اتصال</small>
                    <div class="zp-premium-power">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M12 2v10"/><path d="M6.3 5.7a8 8 0 1 0 11.4 0"/></svg>
                    </div>
                    <h3>Connected • Stable</h3>
                    <p>مسیر انتخاب‌شده آماده استفاده است</p>

                    @php $heroLocation = $locations->first(); @endphp
                    <div class="zp-premium-server-row">
                        <div>
                            <b>{{ $heroLocation?->flag_emoji }} {{ $heroLocation?->country_name ?: 'Türkiye' }} • Gaming 01</b>
                            <span>مسیر بهینه‌شده برای پینگ پایین و استفاده پایدار</span>
                        </div>
                        <strong>42 ms</strong>
                    </div>

                    <div class="zp-premium-metrics">
                        <div><b>96 Mbps</b><span>دانلود</span></div>
                        <div><b>31 Mbps</b><span>آپلود</span></div>
                        <div><b>▰▰▱▱▱</b><span>شلوغی سرور</span></div>
                    </div>
                </div>

                <div class="zp-premium-info-card zp-premium-load-card">
                    <b>Server Load</b>
                    <p>نمایش شلوغی ۵ مرحله‌ای برای انتخاب دقیق‌تر پیش از اتصال.</p>
                    <div class="zp-premium-load-bars"><i></i><i></i><span></span><span></span><span></span></div>
                </div>
                <div class="zp-premium-info-card zp-premium-sync-card">
                    <b>Subscription Sync</b>
                    <p>با یک لینک اشتراک، سرورها و آپدیت‌ها را داخل اپ دریافت کن.</p>
                </div>
            </div>
        </div>
    </div>
</section>

@if(isset($topBanners) && $topBanners->isNotEmpty())
    <div class="zp-premium-container zp-premium-banner-wrap">@include('partials.banners', ['banners' => $topBanners])</div>
@endif

<section class="zp-premium-section">
    <div class="zp-premium-container zp-premium-section-shell">
        <div class="zp-premium-section-head">
            <div>
                <small>چرا ZED</small>
                <h2>{{ site_setting('homepage.features.title', 'سرویس فقط کانفیگ نیست؛ تجربه است.') }}</h2>
                <p>{{ site_setting('homepage.features.subtitle', 'انتخاب بهتر سرور، مدیریت آسان حساب، تجربه خرید روان، آموزش قابل فهم و دسترسی سریع به پشتیبانی.') }}</p>
            </div>
        </div>

        <div class="zp-premium-feature-grid">
            @forelse($features as $feature)
                <article class="zp-premium-feature-card">
                    <div class="zp-premium-feature-icon">{{ $feature->icon ?: '✦' }}</div>
                    <h3>{{ $feature->title }}</h3>
                    <p>{{ $feature->description }}</p>
                </article>
            @empty
                @foreach([
                    ['⚡','سرعت و پایداری','لوکیشن‌های متنوع و انتخاب آگاهانه‌تر بر اساس کیفیت مسیر و شلوغی سرور.'],
                    ['🛡️','اتصال امن','پروتکل‌های مدرن و لینک اشتراک ساده برای استفاده روزمره و حرفه‌ای.'],
                    ['🎮','لوکیشن‌های گیمینگ','مسیرهای منتخب برای کاربرانی که به پینگ و ثبات اتصال حساس‌اند.'],
                    ['📈','مناسب کار و ترید','مدیریت آنلاین سرویس برای استفاده مداوم و نیازهای حساس به ثبات.'],
                    ['◫','پنل کاربری کامل','سرویس‌ها، سفارش‌ها، تمدید، کیف پول، تیکت و پروفایل در یک محیط.'],
                    ['💬','پشتیبانی واقعی','آموزش، تیکت و پشتیبانی برای زمان‌هایی که باید سریع جواب بگیری.'],
                ] as [$icon,$title,$description])
                    <article class="zp-premium-feature-card"><div class="zp-premium-feature-icon">{{ $icon }}</div><h3>{{ $title }}</h3><p>{{ $description }}</p></article>
                @endforeach
            @endforelse
        </div>
    </div>
</section>

@if($plans->isNotEmpty())
<section class="zp-premium-section">
    <div class="zp-premium-container zp-premium-section-shell">
        <div class="zp-premium-section-head zp-premium-section-head-inline">
            <div>
                <small>پلن‌های منتخب</small>
                <h2>{{ site_setting('homepage.plans.title', 'پرفروش‌ها و انتخاب‌های محبوب') }}</h2>
                <p>{{ site_setting('homepage.plans.subtitle', 'پلن مناسب را انتخاب کن؛ بعد از پرداخت سرویس داخل پنل فعال می‌شود.') }}</p>
            </div>
            <a href="{{ route('plans') }}" class="zp-premium-btn zp-premium-btn-soft">همه پلن‌ها</a>
        </div>

        <div class="zp-premium-plan-grid">
            @foreach($plans->take(3) as $plan)
                <div class="zp-premium-plan-card {{ $loop->iteration === 2 ? 'is-featured' : '' }}">
                    @if($loop->iteration === 2)<span class="zp-premium-plan-badge">پیشنهاد ZED</span>@endif
                    <small>{{ $plan->name ?? 'پلن ZED' }}</small>
                    <h3>{{ $plan->name ?? 'سرویس ZED' }}</h3>
                    <div class="zp-premium-plan-price">{{ number_format((int) $plan->price_toman) }} <span>تومان</span></div>
                    <ul>
                        <li>تحویل و فعال‌سازی سریع</li>
                        <li>لینک Subscription</li>
                        <li>مدیریت از پنل کاربری</li>
                        <li>پشتیبانی ۲۴ ساعته</li>
                    </ul>
                    <a href="{{ route('plans') }}" class="zp-premium-btn zp-premium-btn-primary">انتخاب پلن</a>
                </div>
            @endforeach
        </div>
    </div>
</section>
@endif

@if($locations->isNotEmpty())
<section class="zp-premium-section">
    <div class="zp-premium-container zp-premium-section-shell">
        <div class="zp-premium-section-head">
            <div>
                <small>NETWORK</small>
                <h2>لوکیشن‌های منتخب ZED</h2>
                <p>قبل از اتصال، لوکیشن مناسب را بر اساس کاربرد و کیفیت مسیر انتخاب کن.</p>
            </div>
        </div>
        <div class="zp-premium-location-grid">
            @foreach($locations->take(6) as $location)
                <div class="zp-premium-location-card">
                    <div class="zp-premium-location-flag">{{ $location->flag_emoji ?: '🌐' }}</div>
                    <div>
                        <b>{{ $location->country_name }}</b>
                        <small>{{ $location->is_youtube_special ? 'YouTube • Optimized' : 'General • Optimized' }}</small>
                        <div class="zp-premium-mini-load"><i></i><i></i><span></span><span></span><span></span></div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>
@endif

@if(isset($middleBanners) && $middleBanners->isNotEmpty())
    <div class="zp-premium-container zp-premium-banner-wrap">@include('partials.banners', ['banners' => $middleBanners])</div>
@endif

@if(isset($faqs) && $faqs->isNotEmpty())
<section class="zp-premium-section">
    <div class="zp-premium-container zp-premium-faq-wrap">
        <div class="zp-premium-section-head">
            <div>
                <small>FAQ</small>
                <h2>سوال داری؟ احتمالاً جوابش اینجاست.</h2>
                <p>پاسخ کوتاه به سوال‌های رایج درباره خرید، فعال‌سازی، تمدید، پرداخت و اتصال.</p>
            </div>
        </div>
        <div class="zp-premium-faq-list">
            @foreach($faqs->take(6) as $faq)
                <details>
                    <summary>{{ $faq->question }} <span>+</span></summary>
                    <p>{{ $faq->answer }}</p>
                </details>
            @endforeach
        </div>
    </div>
</section>
@endif

<section class="zp-premium-section">
    <div class="zp-premium-container zp-premium-cta">
        <div>
            <h2>{{ site_setting('homepage.cta.title', 'آماده‌ای تجربه ZED را شروع کنی؟') }}</h2>
            <p>{{ site_setting('homepage.cta.subtitle', 'پلن مناسب خودت را انتخاب کن؛ بعد از پرداخت، سرویس از داخل پنل قابل مدیریت است.') }}</p>
        </div>
        <a href="{{ route('plans') }}" class="zp-premium-btn zp-premium-btn-primary">شروع خرید</a>
    </div>
</section>
