{{-- ============================================================================
     MATRIX / "Hacker" homepage template (uses layouts.matrix). Graphical,
     animation-heavy. Colours come only from the active theme (--zp-*) via the
     project's Tailwind classes — not hardcoded green. Copy via site_setting()
     with a matrix_ prefix. Plans/locations are real data.

     NOTE: the terminal and tunnel diagram are PURELY DECORATIVE demos. They do
     NOT establish, process, or store any real connection or IP address.
     ============================================================================ --}}

<!-- matrix-home-marker -->

{{-- ===== HERO ===== --}}
@if(\App\Models\SiteText::getBool('hero_is_active', true))
<section class="relative pt-14 pb-8 text-center">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
        <span class="zm-mono inline-flex items-center gap-2 bg-green-500/[0.08] border border-green-500/30 text-green-400 text-[13px] px-4 py-1.5 rounded-full mb-6">
            <span class="zm-blink w-2 h-2 rounded-full bg-green-400" style="box-shadow:0 0 10px var(--zp-success)"></span>
            {{ site_setting('matrix_hero_pill', 'connection_status: SECURE') }}
        </span>

        <h1 class="text-4xl sm:text-5xl font-black leading-tight text-white">
            {{ site_setting('matrix_hero_title', 'عبور از سد فیلترینگ') }}<br>
            @php $glitch = site_setting('matrix_hero_glitch', 'سرعت نور'); @endphp
            <span class="zm-glitch zed-gradient-text" data-t="{{ $glitch }}">{{ $glitch }}</span>
        </h1>

        <p class="mt-5 text-lg text-gray-400 max-w-xl mx-auto">
            {{ site_setting('matrix_hero_description', 'ترافیک تو از یک تونل رمزنگاری‌شده عبور می‌کند؛ IP واقعی‌ات پنهان می‌شود و هیچ لاگی ثبت نمی‌شود. آزادی اینترنت، در دستان توست.') }}
        </p>

        <div class="mt-7 flex flex-wrap gap-3 justify-center">
            <a href="{{ site_setting('hero_primary_button_url', route('plans')) }}" class="zed-btn zed-btn-primary px-8 py-3 text-base font-bold">
                {{ site_setting('matrix_hero_primary_btn', 'فعال‌سازی محافظت') }}
            </a>
            <a href="{{ route('plans') }}" class="zed-btn px-8 py-3 text-base font-bold bg-gray-800 text-green-400 border border-gray-700 hover:border-green-400 transition">
                {{ site_setting('matrix_hero_secondary_btn', 'مشاهده پلن‌ها') }}
            </a>
        </div>

        {{-- Live terminal (DEMO ONLY — decorative, no real connection) --}}
        <div class="zed-card max-w-2xl mx-auto mt-10 overflow-hidden text-right" style="box-shadow:0 30px 70px -25px rgba(0,0,0,.85)">
            <div class="flex items-center gap-2 px-4 py-3 border-b border-gray-800 bg-black/25">
                <span class="w-3 h-3 rounded-full bg-red-500"></span>
                <span class="w-3 h-3 rounded-full bg-amber-400"></span>
                <span class="w-3 h-3 rounded-full bg-green-400"></span>
                <span class="zm-mono mr-auto text-xs text-gray-400">root@zedproxy: ~/connect</span>
                <span class="text-[11px] px-2 py-0.5 rounded-full bg-amber-500/15 text-amber-300 border border-amber-500/30">نمایشی</span>
            </div>
            <div id="zm-scr" class="zm-mono p-5 text-left text-[13.5px] min-h-[170px]" style="line-height:2"></div>
        </div>
        <p class="text-[11px] text-gray-500 mt-2">{{ site_setting('matrix_terminal_note', 'این ترمینال صرفاً نمایشی است و هیچ اتصال یا IP واقعی را پردازش یا ذخیره نمی‌کند.') }}</p>
    </div>
</section>
@endif

{{-- Top banners --}}
@if(isset($topBanners) && $topBanners->isNotEmpty())
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-2">
    @include('partials.banners', ['banners' => $topBanners])
</div>
@endif

{{-- ===== ENCRYPTION TUNNEL (static conceptual diagram) ===== --}}
<section class="py-14">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-9">
            <div class="zm-mono text-sm font-bold text-green-400">{{ site_setting('matrix_tunnel_tag', 'how_it_works()') }}</div>
            <h2 class="text-3xl font-black text-white mt-2">{{ site_setting('matrix_tunnel_title', 'مسیر امن داده‌ی تو') }}</h2>
            <p class="text-gray-400 mt-1">{{ site_setting('matrix_tunnel_sub', 'ترافیکت قبل از رسیدن به اینترنت، رمزنگاری و ناشناس می‌شود') }}</p>
        </div>

        @php
            $serverLoc = $locations->first();
            $serverSub = $serverLoc ? trim(($serverLoc->flag_emoji ? $serverLoc->flag_emoji . ' ' : '') . $serverLoc->country_name) : 'secure node';
        @endphp
        <div class="zed-card rounded-3xl p-8 sm:p-10">
            <div class="zm-flow flex flex-wrap items-center justify-center lg:justify-between gap-4 max-w-4xl mx-auto">
                {{-- Device (real IP) --}}
                <div class="text-center w-28">
                    <div class="zm-box mx-auto mb-3 text-red-400" style="border-color:rgba(244,63,94,.3)">
                        <svg class="w-8 h-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg>
                    </div>
                    <div class="text-[13px] font-bold text-white">دستگاه تو</div>
                    <div class="zm-mono text-[11px] text-gray-400">IP واقعی</div>
                </div>
                <div class="zm-pipe"><span class="zm-pkt"></span></div>
                {{-- Encrypted tunnel --}}
                <div class="text-center w-28">
                    <div class="zm-box mx-auto mb-3 text-green-400" style="border-color:rgba(52,211,153,.4)">
                        <svg class="w-8 h-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                    </div>
                    <div class="text-[13px] font-bold text-white">تونل رمزنگاری</div>
                    <div class="zm-mono text-[11px] text-gray-400">AES-256</div>
                </div>
                <div class="zm-pipe zm-enc"><span class="zm-pkt"></span><span class="zm-pkt" style="animation-delay:.5s"></span><span class="zm-pkt" style="animation-delay:1s"></span></div>
                {{-- Server --}}
                <div class="text-center w-28">
                    <div class="zm-box mx-auto mb-3 text-green-400" style="border-color:rgba(52,211,153,.4)">
                        <svg class="w-8 h-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="14" width="20" height="8" rx="2"/><path d="M6 18h.01M10 18h.01M2 9l4-4h12l4 4"/></svg>
                    </div>
                    <div class="text-[13px] font-bold text-white">سرور {{ site_setting('site_name', 'ZedProxy') }}</div>
                    <div class="zm-mono text-[11px] text-gray-400">{{ $serverSub }}</div>
                </div>
                <div class="zm-pipe"><span class="zm-pkt"></span></div>
                {{-- Free internet --}}
                <div class="text-center w-28">
                    <div class="zm-box mx-auto mb-3 text-cyan-400">
                        <svg class="w-8 h-8" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20 15 15 0 0 1 0-20z"/></svg>
                    </div>
                    <div class="text-[13px] font-bold text-white">اینترنت آزاد</div>
                    <div class="zm-mono text-[11px] text-gray-400">unrestricted</div>
                </div>
            </div>
            <div class="text-center mt-7 text-[13px] text-gray-400">
                {{ site_setting('matrix_tunnel_note', 'در بخش قرمز، IP واقعی تو دیده می‌شود. از لحظه‌ی ورود به تونل رمزنگاری، هویتت پنهان و ترافیکت غیرقابل‌ردیابی می‌شود.') }}
            </div>
        </div>
    </div>
</section>

{{-- ===== FEATURES + PROTOCOLS ===== --}}
<section class="py-14">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-9">
            <div class="zm-mono text-sm font-bold text-green-400">features[]</div>
            <h2 class="text-3xl font-black text-white mt-2">{{ site_setting('matrix_features_title', 'چرا ' . site_setting('site_name', 'ZedProxy') . '؟') }}</h2>
        </div>
        @php
            $countryCount = $locations->count() ?: site_setting('matrix_countries', '۱۲');
            $cards = [
                ['t' => site_setting('matrix_feat_1_title', 'رمزنگاری نظامی'), 'd' => site_setting('matrix_feat_1_desc', 'AES-256، استاندارد امنیتی بانک‌ها'), 'svg' => '<path d="M12 2 4 6v6c0 5 3.4 7.7 8 10 4.6-2.3 8-5 8-10V6l-8-4z"/>'],
                ['t' => site_setting('matrix_feat_2_title', 'بدون ثبت لاگ'),   'd' => site_setting('matrix_feat_2_desc', 'هیچ ردی از فعالیت تو نمی‌ماند'), 'svg' => '<path d="M17.94 17.94A10 10 0 0 1 12 20c-7 0-11-8-11-8a18 18 0 0 1 5-5.94M9.9 4.24A9 9 0 0 1 12 4c7 0 11 8 11 8a18 18 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><path d="m1 1 22 22"/>'],
                ['t' => site_setting('matrix_feat_3_title', 'سرعت بالا'),      'd' => site_setting('matrix_feat_3_desc', 'بهینه برای استریم و گیم'), 'svg' => '<path d="M13 2 3 14h7l-1 8 10-12h-7l1-8z"/>'],
                ['t' => $countryCount . ' کشور',                                 'd' => site_setting('matrix_feat_4_desc', 'کمترین پینگ، بیشترین پایداری'), 'svg' => '<circle cx="12" cy="12" r="10"/><path d="M2 12h20M12 2a15 15 0 0 1 0 20 15 15 0 0 1 0-20z"/>'],
            ];
        @endphp
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            @foreach($cards as $card)
            <div class="zed-card zed-hover-lift p-6 text-center">
                <div class="w-14 h-14 mx-auto mb-4 rounded-2xl bg-gray-800 border border-gray-700 flex items-center justify-center text-green-400">
                    <svg class="w-6 h-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">{!! $card['svg'] !!}</svg>
                </div>
                <h3 class="text-[15px] font-bold text-white mb-1.5">{{ $card['t'] }}</h3>
                <p class="text-[13px] text-gray-400">{{ $card['d'] }}</p>
            </div>
            @endforeach
        </div>
        <div class="flex flex-wrap gap-2.5 justify-center mt-8">
            @foreach(['VLESS', 'VMess', 'Trojan', 'Shadowsocks', 'WireGuard', 'Reality'] as $proto)
            <span class="zm-mono text-[13px] text-green-400 border border-gray-700 bg-gray-900 px-4 py-1.5 rounded-lg hover:border-green-400 transition">{{ $proto }}</span>
            @endforeach
        </div>
    </div>
</section>

{{-- ===== PLANS (real data via existing partial) ===== --}}
@if($plans->isNotEmpty())
<section class="py-14">
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="text-center mb-10">
            <div class="zm-mono text-sm font-bold text-green-400">pricing</div>
            <h2 class="text-3xl font-black text-white mt-2">{{ site_setting('matrix_plans_title', 'پلن خودت رو انتخاب کن') }}</h2>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            @foreach($plans->take(3) as $plan)
                @include('partials.plan-card', ['plan' => $plan])
            @endforeach
        </div>
        @if($plans->count() > 3)
        <div class="text-center mt-10">
            <a href="{{ route('plans') }}" class="zed-btn px-8 py-3 font-bold bg-gray-800 text-white border border-gray-700 hover:bg-gray-700 inline-block">مشاهده همه پلن‌ها</a>
        </div>
        @endif
    </div>
</section>
@endif

{{-- Middle banners --}}
@if(isset($middleBanners) && $middleBanners->isNotEmpty())
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    @include('partials.banners', ['banners' => $middleBanners])
</div>
@endif

{{-- ===== FINAL CTA ===== --}}
<section class="py-12">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="relative rounded-3xl px-6 py-14 text-center zed-gradient-bg overflow-hidden">
            <h2 class="relative text-3xl font-black text-white mb-3">{{ site_setting('matrix_cta_title', 'آماده‌ای از سد فیلترینگ عبور کنی؟') }}</h2>
            <p class="relative text-white/90 mb-7">{{ site_setting('matrix_cta_subtitle', 'تحویل آنی · رمزنگاری کامل · ۷ روز ضمانت بازگشت وجه') }}</p>
            <a href="{{ site_setting('hero_primary_button_url', route('plans')) }}" class="relative inline-block bg-white text-emerald-700 font-black px-9 py-3.5 rounded-xl text-base hover:bg-emerald-50 transition">
                {{ site_setting('matrix_cta_button', 'فعال‌سازی محافظت') }}
            </a>
        </div>
    </div>
</section>

@push('styles')
<style>
    .zm-glitch { position: relative; display: inline-block; }
    .zm-glitch::before, .zm-glitch::after { content: attr(data-t); position: absolute; inset: 0;
        background: var(--zp-bg); -webkit-text-fill-color: initial; }
    .zm-glitch::before { color: var(--zp-secondary); animation: zm-gl1 3s infinite linear alternate; clip-path: polygon(0 0,100% 0,100% 45%,0 45%); left: 2px; }
    .zm-glitch::after  { color: var(--zp-danger);    animation: zm-gl2 2.7s infinite linear alternate; clip-path: polygon(0 60%,100% 60%,100% 100%,0 100%); left: -2px; }
    @keyframes zm-gl1 { 0%,92%,100%{transform:translate(0);opacity:0} 93%,99%{opacity:.8;transform:translate(-2px,-1px)} }
    @keyframes zm-gl2 { 0%,90%,100%{transform:translate(0);opacity:0} 91%,97%{opacity:.8;transform:translate(2px,1px)} }

    .zm-box { width: 74px; height: 74px; border-radius: 18px; background: var(--zp-surface-soft);
        border: 1px solid var(--zp-border); display: flex; align-items: center; justify-content: center; }
    .zm-pipe { flex: 1; min-width: 30px; height: 3px; position: relative; background: var(--zp-border);
        border-radius: 3px; overflow: hidden; }
    .zm-pipe.zm-enc { background: linear-gradient(90deg, var(--zp-primary), var(--zp-secondary)); }
    .zm-pkt { position: absolute; top: 50%; width: 8px; height: 8px; border-radius: 50%; background: #fff;
        transform: translateY(-50%); box-shadow: 0 0 8px #fff; animation: zm-move 1.6s linear infinite; }
    .zm-pipe.zm-enc .zm-pkt { background: var(--zp-accent); box-shadow: 0 0 10px var(--zp-accent); }
    @keyframes zm-move { from { right: -8px; } to { right: calc(100% + 8px); } }
    .zm-blink { animation: zm-blink 1.4s infinite; }
    @keyframes zm-blink { 0%,100%{opacity:1} 50%{opacity:.3} }
    .zm-cursor { display:inline-block; width:8px; height:16px; background: var(--zp-accent); vertical-align:middle; animation: zm-blink 1s infinite; }

    @media (max-width: 880px) {
        .zm-flow { flex-direction: column; }
        .zm-pipe { width: 3px; min-height: 34px; flex: none; }
        .zm-pipe .zm-pkt { left: 50%; top: auto; transform: translateX(-50%); animation: zm-move-v 1.6s linear infinite; }
        @keyframes zm-move-v { from { top: -8px; } to { top: calc(100% + 8px); } }
    }
    @media (prefers-reduced-motion: reduce) { .zm-pkt, .zm-blink, .zm-cursor { animation: none !important; } }
</style>
@endpush

@php
    // DEMO ONLY — decorative terminal text (editable here), NOT a real log.
    $matrixTerminalLines = [
        [['c-m', '$ '], ['c-w', 'zedproxy connect --secure']],
        [['c-m', '> '], ['c-g', 'establishing encrypted tunnel...']],
        [['c-m', '> '], ['c-c', 'protocol: VLESS + Reality  ✓']],
        [['c-m', '> '], ['c-y', 'real IP  → hidden  ✓']],
        [['c-m', '> '], ['c-c', 'virtual IP assigned  ✓']],
        [['c-m', '> '], ['c-g', 'AES-256-GCM encryption active  ✓']],
        [['c-m', '> '], ['c-w', 'status: '], ['c-g', 'DEMO · CONNECTED (نمایشی)']],
    ];
@endphp
@push('scripts')
<script>
(function () {
    var scr = document.getElementById('zm-scr');
    if (!scr) return;
    var lines = {!! json_encode($matrixTerminalLines, JSON_UNESCAPED_UNICODE) !!};
    var color = { 'c-g': 'text-green-400', 'c-c': 'text-cyan-400', 'c-m': 'text-gray-500', 'c-w': 'text-white', 'c-y': 'text-amber-400' };
    var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    var done = [], i = 0;

    function render(withCursor) {
        scr.innerHTML = done.join('') + (withCursor ? '<span class="zm-cursor"></span>' : '');
    }
    function lineHtml(parts) {
        return parts.map(function (p) { return '<span class="' + (color[p[0]] || '') + '">' + p[1] + '</span>'; }).join('');
    }
    function typeLine() {
        if (i >= lines.length) { render(true); return; }
        done.push('<div>' + lineHtml(lines[i]) + '</div>');
        render(true);
        i++;
        setTimeout(typeLine, 520);
    }
    if (reduce) {
        // No typing animation: render the whole demo log at once.
        lines.forEach(function (l) { done.push('<div>' + lineHtml(l) + '</div>'); });
        render(false);
    } else {
        typeLine();
    }
})();
</script>
@endpush
