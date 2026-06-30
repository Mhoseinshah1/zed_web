<x-filament-panels::page>
{{--
    پنل تم — modern theme panel. The live preview is FULLY SANDBOXED: every theme
    CSS variable is bound (via Alpine :style) on the `.ztp-stage` container only,
    so CSS-variable inheritance restyles just that subtree. Nothing is written to
    :root/document; the real surfaces only change after «Save» → persist().
--}}
<style>
    .ztp{--ztp-accent:var(--zp-primary,#3b82f6)}
    .ztp *{box-sizing:border-box}
    .ztp-grid{display:grid;grid-template-columns:340px 1fr;gap:1.25rem;align-items:start}
    @media(max-width:900px){.ztp-grid{grid-template-columns:1fr}}
    .ztp-card{background:var(--zp-surface,#141a2b);border:1px solid var(--zp-border,#283047);border-radius:14px;padding:1.25rem}
    .ztp-controls{position:sticky;top:1rem}
    @media(max-width:900px){.ztp-controls{position:static}}
    .ztp-sec{margin-bottom:1.4rem}
    .ztp-sec:last-child{margin-bottom:0}
    .ztp-lbl{font-size:12px;font-weight:700;color:var(--zp-text-muted,#9aa3bd);margin-bottom:.7rem;display:flex;justify-content:space-between;align-items:center}
    .ztp-lbl .val{color:var(--ztp-accent);font-weight:700}

    .ztp-scopes{display:flex;gap:6px}
    .ztp-scopes button{flex:1;background:var(--zp-surface-soft,#1c2438);border:1px solid var(--zp-border,#283047);color:var(--zp-text-muted,#9aa3bd);font-family:inherit;font-size:12px;font-weight:700;padding:8px;border-radius:9px;cursor:pointer;transition:.15s}
    .ztp-scopes button.on{color:var(--zp-text,#e8ebf5);border-color:var(--ztp-accent);background:color-mix(in srgb,var(--ztp-accent) 14%,transparent)}

    .ztp-themes{display:grid;grid-template-columns:repeat(2,1fr);gap:9px;max-height:230px;overflow:auto;padding:2px}
    .ztp-theme{border:1.5px solid var(--zp-border,#283047);border-radius:11px;padding:10px;cursor:pointer;transition:.15s;background:var(--zp-surface-soft,#1c2438)}
    .ztp-theme:hover{border-color:var(--zp-surface-hover,#232c44)}
    .ztp-theme.on{border-color:var(--ztp-accent);box-shadow:0 0 0 3px color-mix(in srgb,var(--ztp-accent) 20%,transparent)}
    .ztp-theme .sw{display:flex;gap:4px;margin-bottom:7px}
    .ztp-theme .sw i{width:17px;height:17px;border-radius:5px;display:block}
    .ztp-theme .nm{font-size:11.5px;font-weight:700;color:var(--zp-text,#e8ebf5)}

    .ztp-accents{display:flex;gap:9px;flex-wrap:wrap}
    .ztp-ac{width:32px;height:32px;border-radius:9px;cursor:pointer;border:2px solid transparent;transition:.15s;position:relative}
    .ztp-ac.on{border-color:var(--zp-text,#fff);box-shadow:0 0 0 2px var(--zp-surface,#141a2b)}
    .ztp-ac.custom{display:flex;align-items:center;justify-content:center;background:var(--zp-surface-soft,#1c2438);border:1.5px dashed var(--zp-border,#283047);overflow:hidden}
    .ztp-ac.custom input{position:absolute;inset:0;opacity:0;cursor:pointer}
    .ztp-ac.custom svg{width:15px;height:15px;color:var(--zp-text-muted,#9aa3bd)}

    .ztp-seg{display:flex;background:var(--zp-surface-soft,#1c2438);border:1px solid var(--zp-border,#283047);border-radius:11px;padding:4px;gap:4px}
    .ztp-seg button{flex:1;border:none;background:none;color:var(--zp-text-muted,#9aa3bd);font-family:inherit;font-weight:700;font-size:12px;padding:8px;border-radius:8px;cursor:pointer;transition:.15s;display:flex;align-items:center;justify-content:center;gap:5px}
    .ztp-seg button svg{width:14px;height:14px}
    .ztp-seg button.on{background:var(--ztp-accent);color:#fff}

    .ztp input[type=range]{width:100%;height:6px;border-radius:3px;background:var(--zp-surface-soft,#1c2438);appearance:none;-webkit-appearance:none;outline:none;cursor:pointer}
    .ztp input[type=range]::-webkit-slider-thumb{-webkit-appearance:none;width:18px;height:18px;border-radius:50%;background:var(--ztp-accent);cursor:pointer;border:3px solid var(--zp-surface,#141a2b)}
    .ztp input[type=range]::-moz-range-thumb{width:16px;height:16px;border:0;border-radius:50%;background:var(--ztp-accent);cursor:pointer}

    .ztp-toggle-row{display:flex;justify-content:space-between;align-items:center;gap:1rem;padding:9px 0}
    .ztp-toggle-row .tt{font-size:13px;color:var(--zp-text,#e8ebf5);font-weight:600}
    .ztp-toggle-row .td{font-size:11px;color:var(--zp-text-muted,#9aa3bd)}
    .ztp-sw{width:40px;height:23px;border-radius:999px;background:var(--zp-surface-soft,#1c2438);border:1px solid var(--zp-border,#283047);position:relative;cursor:pointer;transition:.15s;flex-shrink:0}
    .ztp-sw.on{background:var(--ztp-accent)}
    .ztp-sw::after{content:"";position:absolute;top:2px;right:2px;width:17px;height:17px;border-radius:50%;background:#fff;transition:.15s}
    .ztp-sw.on::after{right:auto;left:2px}

    .ztp-adv-head{display:flex;justify-content:space-between;align-items:center;cursor:pointer;user-select:none}
    .ztp-adv-grid{display:grid;grid-template-columns:1fr 1fr;gap:.7rem;margin-top:.9rem}
    .ztp-field label{display:block;font-size:11px;color:var(--zp-text-muted,#9aa3bd);margin-bottom:.3rem;font-weight:600}
    .ztp-select{width:100%;background:var(--zp-surface-soft,#1c2438);border:1px solid var(--zp-border,#283047);color:var(--zp-text,#e8ebf5);border-radius:8px;padding:7px 9px;font-family:inherit;font-size:12px;appearance:none;-webkit-appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%239aa3bd' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:left .55rem center;background-size:13px;padding-left:1.7rem}

    .ztp-actions{display:flex;gap:10px;margin-top:1.1rem}
    .ztp-btn{border:none;border-radius:10px;padding:11px;font-family:inherit;font-weight:700;font-size:13px;cursor:pointer;transition:.15s}
    .ztp-save{flex:1;background:var(--ztp-accent);color:#fff}
    .ztp-reset{background:var(--zp-surface-soft,#1c2438);color:var(--zp-text-muted,#9aa3bd);border:1px solid var(--zp-border,#283047);padding:11px 16px}

    /* live preview — reads ONLY from .ztp-stage (sandboxed) */
    .ztp-pv-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:4px}
    .ztp-pv-head h3{font-size:14px;font-weight:700;color:var(--zp-text-muted,#9aa3bd)}
    .ztp-pv-badge{font-size:11px;color:var(--ztp-accent);background:color-mix(in srgb,var(--ztp-accent) 12%,transparent);padding:4px 11px;border-radius:999px;font-weight:700}
    .ztp-pv-sub{color:var(--zp-text-muted,#9aa3bd);font-size:12px;margin-bottom:1rem}

    .ztp-stage{background:var(--zp-bg);border:1px solid var(--zp-border);border-radius:calc(var(--zp-card-radius) + 4px);padding:22px;color:var(--zp-text)}
    .ztp-stage .top{display:flex;justify-content:space-between;align-items:center;padding-bottom:16px;border-bottom:1px solid var(--zp-border);margin-bottom:18px}
    .ztp-stage .brand{display:flex;align-items:center;gap:9px;font-weight:800;font-size:16px}
    .ztp-stage .brand .m{width:30px;height:30px;border-radius:9px;background:var(--zp-gradient);display:flex;align-items:center;justify-content:center}
    .ztp-stage .brand .m svg{width:16px;height:16px;color:#fff}
    .ztp-stage .cta{background:var(--zp-primary);color:#fff;border:none;border-radius:var(--zp-button-radius);padding:9px 16px;font-family:inherit;font-weight:700;font-size:13px;cursor:default}
    .ztp-stage .cards{display:grid;grid-template-columns:repeat(3,1fr);gap:12px;margin-bottom:16px}
    .ztp-stage .scard{background:var(--zp-surface);border:1px solid var(--zp-border);border-radius:var(--zp-card-radius);padding:15px}
    .ztp-stage .scard .ic{width:34px;height:34px;border-radius:10px;background:color-mix(in srgb,var(--zp-primary) 15%,transparent);display:flex;align-items:center;justify-content:center;margin-bottom:10px;color:var(--zp-primary)}
    .ztp-stage .scard .ic svg{width:17px;height:17px}
    .ztp-stage .scard .v{font-size:20px;font-weight:800}
    .ztp-stage .scard .l{font-size:12px;color:var(--zp-text-muted);margin-top:2px}
    .ztp-stage .row{display:flex;gap:12px;align-items:stretch}
    @media(max-width:560px){.ztp-stage .cards{grid-template-columns:1fr}.ztp-stage .row{flex-direction:column}}
    .ztp-stage .pane{flex:1;background:var(--zp-surface);border:1px solid var(--zp-border);border-radius:var(--zp-card-radius);padding:16px}
    .ztp-stage .pane h4{font-size:13px;margin-bottom:12px}
    .ztp-stage .in{width:100%;background:var(--zp-bg);border:1px solid var(--zp-border);border-radius:calc(var(--zp-card-radius) - 4px);padding:9px 11px;color:var(--zp-text);font-family:inherit;font-size:13px;margin-bottom:9px;outline:none}
    .ztp-stage .chips{display:flex;gap:7px;flex-wrap:wrap;margin-top:4px}
    .ztp-stage .chip{font-size:12px;padding:5px 12px;border-radius:999px;font-weight:700}
    .ztp-stage .chip-a{background:var(--zp-primary);color:#fff}
    .ztp-stage .chip-s{background:var(--zp-surface-soft);color:var(--zp-text-muted)}
    .ztp-stage .chip-ok{background:color-mix(in srgb,#34d399 16%,transparent);color:#34d399}
    .ztp-stage .prog{height:9px;border-radius:999px;background:var(--zp-surface-soft);overflow:hidden;margin:12px 0 8px}
    .ztp-stage .prog i{display:block;height:100%;width:62%;border-radius:999px;background:var(--zp-gradient)}
    .ztp-stage .link{color:var(--zp-primary);font-size:13px;font-weight:700}
</style>

<div class="ztp" x-data="themePanel(@js($state), @js($presets), @js($accentSwatches))" :style="`--ztp-accent:${accent}`">
    <div class="ztp-grid">

        {{-- ── CONTROLS ─────────────────────────────────────────────── --}}
        <div class="ztp-card ztp-controls">
            {{-- scope --}}
            <div class="ztp-sec">
                <div class="ztp-lbl">اعمال روی</div>
                <div class="ztp-scopes">
                    <button type="button" :class="scope==='public' && 'on'" x-on:click="scope='public'">سایت</button>
                    <button type="button" :class="scope==='user' && 'on'" x-on:click="scope='user'">پنل کاربر</button>
                    <button type="button" :class="scope==='admin' && 'on'" x-on:click="scope='admin'">ادمین</button>
                </div>
            </div>

            {{-- preset gallery --}}
            <div class="ztp-sec">
                <div class="ztp-lbl">تم آماده <span class="val" x-text="presets[activeTheme()]?.title"></span></div>
                <div class="ztp-themes">
                    <template x-for="(p,slug) in presets" :key="slug">
                        <div class="ztp-theme" :class="activeTheme()===slug && 'on'" x-on:click="pickPreset(slug)">
                            <div class="sw">
                                <i :style="`background:${p.a}`"></i><i :style="`background:${p.b}`"></i><i :style="`background:${p.c}`"></i>
                            </div>
                            <div class="nm" x-text="p.title"></div>
                        </div>
                    </template>
                </div>
            </div>

            {{-- accent --}}
            <div class="ztp-sec">
                <div class="ztp-lbl">رنگ شاخص</div>
                <div class="ztp-accents">
                    <template x-for="c in accentSwatches" :key="c">
                        <span class="ztp-ac" :class="accent.toLowerCase()===c.toLowerCase() && 'on'" :style="`background:${c}`" x-on:click="accent=c"></span>
                    </template>
                    <label class="ztp-ac custom">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2C6.5 2 2 6.5 2 12s4.5 10 10 10c.9 0 1.5-.7 1.5-1.5 0-.4-.2-.8-.4-1-.3-.3-.4-.6-.4-1 0-.8.7-1.5 1.5-1.5H16c3.3 0 6-2.7 6-6 0-4.9-4.5-9-10-9z"/></svg>
                        <input type="color" :value="accent" x-on:input="accent=$event.target.value">
                    </label>
                </div>
            </div>

            {{-- appearance --}}
            <div class="ztp-sec">
                <div class="ztp-lbl">حالت نمایش</div>
                <div class="ztp-seg">
                    <button type="button" :class="appearance==='light' && 'on'" x-on:click="appearance='light'">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4 12H2M22 12h-2M5 5 4 4M20 20l-1-1M5 19l-1 1M20 4l-1 1"/></svg> روشن
                    </button>
                    <button type="button" :class="appearance==='dark' && 'on'" x-on:click="appearance='dark'">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></svg> تیره
                    </button>
                    <button type="button" :class="appearance==='system' && 'on'" x-on:click="appearance='system'">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></svg> سیستم
                    </button>
                </div>
            </div>

            {{-- radius --}}
            <div class="ztp-sec">
                <div class="ztp-lbl">گردی گوشه‌ها <span class="val" x-text="fa(radius)+'px'"></span></div>
                <input type="range" min="0" max="28" x-model.number="radius">
            </div>

            {{-- font --}}
            <div class="ztp-sec">
                <div class="ztp-lbl">اندازه فونت <span class="val" x-text="fa(font_scale)+'٪'"></span></div>
                <input type="range" min="85" max="120" x-model.number="font_scale">
            </div>

            {{-- toggles --}}
            <div class="ztp-sec">
                <div class="ztp-lbl">دسترسی کاربران</div>
                <div class="ztp-toggle-row">
                    <div><div class="tt">تغییر تم توسط کاربر</div><div class="td">کاربر می‌تواند تم دلخواهش را انتخاب کند</div></div>
                    <div class="ztp-sw" :class="allow_user_theme_switch && 'on'" x-on:click="allow_user_theme_switch=!allow_user_theme_switch"></div>
                </div>
                <div class="ztp-toggle-row">
                    <div><div class="tt">تغییر حالت روشن/تیره</div><div class="td">اجازهٔ سوییچ بین روشن و تیره</div></div>
                    <div class="ztp-sw" :class="allow_user_appearance_switch && 'on'" x-on:click="allow_user_appearance_switch=!allow_user_appearance_switch"></div>
                </div>
                <div class="ztp-toggle-row">
                    <div><div class="tt">تم سراسری اجباری</div><div class="td">تم را روی همهٔ کاربران تحمیل کن</div></div>
                    <div class="ztp-sw" :class="force_global_theme && 'on'" x-on:click="force_global_theme=!force_global_theme"></div>
                </div>
            </div>

            {{-- advanced accordion --}}
            <div class="ztp-sec">
                <div class="ztp-adv-head" x-on:click="advOpen=!advOpen">
                    <div class="ztp-lbl" style="margin:0">تنظیمات پیشرفته</div>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--zp-text-muted,#9aa3bd)" stroke-width="2" :style="advOpen && 'transform:rotate(180deg)'" style="transition:.15s"><path d="M6 9l6 6 6-6"/></svg>
                </div>
                <div x-show="advOpen" x-cloak class="ztp-adv-grid">
                    <div class="ztp-field"><label>اندازه آیکن‌ها</label>
                        <select class="ztp-select" x-model="icon_size"><option value="1rem">کوچک</option><option value="1.25rem">پیش‌فرض</option><option value="1.5rem">بزرگ</option></select></div>
                    <div class="ztp-field"><label>آیکن منو</label>
                        <select class="ztp-select" x-model="sidebar_icon_size"><option value="1rem">کوچک</option><option value="1.25rem">پیش‌فرض</option><option value="1.5rem">بزرگ</option></select></div>
                    <div class="ztp-field"><label>اندازه لوگو</label>
                        <select class="ztp-select" x-model="logo_size"><option value="1rem">کوچک</option><option value="1.15rem">پیش‌فرض</option><option value="1.4rem">بزرگ</option></select></div>
                    <div class="ztp-field"><label>تصاویر/آواتار</label>
                        <select class="ztp-select" x-model="image_size"><option value="2rem">کوچک</option><option value="2.5rem">پیش‌فرض</option><option value="3rem">بزرگ</option></select></div>
                    <div class="ztp-field"><label>شدت انیمیشن</label>
                        <select class="ztp-select" x-model="animation_intensity"><option value="off">خاموش</option><option value="low">کم</option><option value="medium">متوسط</option><option value="high">زیاد</option></select></div>
                    <div class="ztp-field"><label>تراکم جدول</label>
                        <select class="ztp-select" x-model="table_density"><option value="compact">فشرده</option><option value="normal">عادی</option><option value="comfortable">راحت</option></select></div>
                    <div class="ztp-field"><label>تراکم کارت</label>
                        <select class="ztp-select" x-model="card_density"><option value="compact">فشرده</option><option value="normal">عادی</option><option value="comfortable">راحت</option></select></div>
                </div>
            </div>

            <div class="ztp-actions">
                <button type="button" class="ztp-btn ztp-save" x-on:click="save()">ذخیره تغییرات</button>
                <button type="button" class="ztp-btn ztp-reset" wire:click="resetDefaults" wire:confirm="بازنشانی همهٔ تنظیمات ظاهر به حالت پیش‌فرض؟">بازنشانی</button>
            </div>
        </div>

        {{-- ── LIVE PREVIEW (sandboxed) ─────────────────────────────── --}}
        <div class="ztp-card">
            <div class="ztp-pv-head"><h3>پیش‌نمایش زنده</h3><span class="ztp-pv-badge">به‌روزرسانی لحظه‌ای</span></div>
            <div class="ztp-pv-sub">تغییرات فقط در این کادر دیده می‌شوند و تا زمان ذخیره، روی هیچ بخشی از سایت اعمال نمی‌گردند.</div>

            <div class="ztp-stage" :style="previewStyle()">
                <div class="top">
                    <div class="brand"><span class="m"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M12 2 4 6v6c0 5 3.4 7.7 8 10 4.6-2.3 8-5 8-10V6l-8-4z"/></svg></span> ZedProxy</div>
                    <button class="cta">خرید سرویس</button>
                </div>
                <div class="cards">
                    <div class="scard"><div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2 4 6v6c0 5 3.4 7.7 8 10 4.6-2.3 8-5 8-10V6l-8-4z"/></svg></div><div class="v">۳</div><div class="l">سرویس فعال</div></div>
                    <div class="scard"><div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M13 2 3 14h7l-1 8 10-12h-7l1-8z"/></svg></div><div class="v">۶۲ گیگ</div><div class="l">باقی‌مانده</div></div>
                    <div class="scard"><div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12V7H5a2 2 0 0 1 0-4h14v4"/><path d="M3 5v14a2 2 0 0 0 2 2h16v-5"/><path d="M18 12a2 2 0 0 0 0 4h4v-4z"/></svg></div><div class="v">۴۲۰هزار</div><div class="l">کیف پول</div></div>
                </div>
                <div class="row">
                    <div class="pane">
                        <h4>فرم نمونه</h4>
                        <input class="in" placeholder="نام کاربری">
                        <input class="in" type="password" value="123456">
                        <div class="chips"><span class="chip chip-a">فعال</span><span class="chip chip-ok">پرداخت‌شده</span><span class="chip chip-s">در انتظار</span></div>
                    </div>
                    <div class="pane">
                        <h4>سرویس آلمان</h4>
                        <div style="font-size:12px;color:var(--zp-text-muted)">مصرف: ۳۸ از ۱۰۰ گیگ</div>
                        <div class="prog"><i></i></div>
                        <div style="display:flex;justify-content:space-between;font-size:12px"><span style="color:var(--zp-text-muted)">۶۲٪ باقی‌مانده</span><a class="link">لینک اشتراک ←</a></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function themePanel(state, presets, accentSwatches) {
    return {
        presets, accentSwatches,
        scope: state.scope,
        default_theme_public: state.default_theme_public,
        default_theme_user:   state.default_theme_user,
        default_theme_admin:  state.default_theme_admin,
        enabled_themes:       state.enabled_themes || [],
        accent:  state.accent,
        accent2: state.accent2,
        appearance: state.appearance,
        radius: state.radius,
        font_scale: state.font_scale,
        allow_user_theme_switch: state.allow_user_theme_switch,
        allow_user_appearance_switch: state.allow_user_appearance_switch,
        force_global_theme: state.force_global_theme,
        animation_intensity: state.animation_intensity,
        icon_size: state.icon_size,
        sidebar_icon_size: state.sidebar_icon_size,
        logo_size: state.logo_size,
        image_size: state.image_size,
        table_density: state.table_density,
        card_density: state.card_density,
        advOpen: false,

        dark:  {bg:'#0a0e1a',bgSoft:'#0e1322',surface:'#141a2b',soft:'#1c2438',hover:'#232c44',text:'#e8ebf5',muted:'#9aa3bd',border:'#283047'},
        light: {bg:'#f6f8fc',bgSoft:'#eef2f9',surface:'#ffffff',soft:'#f1f5fb',hover:'#e7edf6',text:'#0f172a',muted:'#64748b',border:'#e2e8f0'},

        fa(n){ return Number(n).toLocaleString('fa-IR'); },

        activeTheme(){ return this['default_theme_' + this.scope]; },

        pickPreset(slug){
            this['default_theme_' + this.scope] = slug;
            if (!this.enabled_themes.includes(slug)) this.enabled_themes = [...this.enabled_themes, slug];
            const p = this.presets[slug];
            if (p){ this.accent = p.a; this.accent2 = p.b; }
        },

        palette(){
            if (this.appearance === 'light') return this.light;
            if (this.appearance === 'system') return (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) ? this.light : this.dark;
            return this.dark;
        },

        previewStyle(){
            const c = this.palette();
            const r = Number(this.radius) || 0;
            return `--zp-bg:${c.bg};--zp-bg-soft:${c.bgSoft};--zp-surface:${c.surface};--zp-surface-soft:${c.soft};`
                 + `--zp-surface-hover:${c.hover};--zp-text:${c.text};--zp-text-muted:${c.muted};--zp-border:${c.border};`
                 + `--zp-primary:${this.accent};--zp-secondary:${this.accent2};--zp-accent:${this.accent2};`
                 + `--zp-gradient:linear-gradient(135deg,${this.accent},${this.accent2});`
                 + `--zp-card-radius:${r}px;--zp-button-radius:${Math.max(0,r-4)}px;`
                 + `font-size:calc(14px * ${Number(this.font_scale)||100} / 100)`;
        },

        save(){
            this.$wire.persist({
                scope: this.scope,
                default_theme_public: this.default_theme_public,
                default_theme_user: this.default_theme_user,
                default_theme_admin: this.default_theme_admin,
                enabled_themes: this.enabled_themes,
                accent: this.accent,
                accent2: this.accent2,
                appearance: this.appearance,
                radius: this.radius,
                font_scale: this.font_scale,
                allow_user_theme_switch: this.allow_user_theme_switch,
                allow_user_appearance_switch: this.allow_user_appearance_switch,
                force_global_theme: this.force_global_theme,
                animation_intensity: this.animation_intensity,
                icon_size: this.icon_size,
                sidebar_icon_size: this.sidebar_icon_size,
                logo_size: this.logo_size,
                image_size: this.image_size,
                table_density: this.table_density,
                card_density: this.card_density,
            });
        },
    };
}
</script>
</x-filament-panels::page>
