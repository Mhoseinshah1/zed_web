<x-filament-panels::page>
@php
    $allKeys = array_keys($presets);
@endphp

<div class="zps" dir="rtl"
     x-data="themeStudio(@js($state), @js($presets), @js($groups), @js($groupLabels))"
     x-init="init()">

    {{-- ── Hero header ─────────────────────────────────────────────────── --}}
    <div class="zps-hero zps-animate" style="margin-bottom:1.25rem">
        <div class="zps-hero-inner">
            <div style="min-width:16rem">
                <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
                    <h1>استودیو تم ZedProxy</h1>
                    <span class="zps-badge">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 13l4 4L19 7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span x-text="dirty ? 'اعمال شده' : 'فعال'"></span>
                    </span>
                </div>
                <p>ظاهر سایت، داشبورد کاربر و پنل مدیریت را از یک‌جا کنترل کنید.</p>

                <div style="display:flex;align-items:center;gap:.9rem;flex-wrap:wrap;margin-top:1rem">
                    <div>
                        <div style="font-size:.7rem;color:var(--zp-text-muted)">تم فعال</div>
                        <div style="font-weight:800;font-size:.95rem" x-text="presets[state.activeTheme].title"></div>
                    </div>
                    <span class="zps-dots">
                        <template x-for="(c, i) in presets[state.activeTheme].dots" :key="i">
                            <span class="zps-dot" :style="`background:${c}`"></span>
                        </template>
                    </span>
                    <div>
                        <div style="font-size:.7rem;color:var(--zp-text-muted)">حالت نمایش</div>
                        <div style="font-weight:800;font-size:.95rem" x-text="appearanceLabel()"></div>
                    </div>
                </div>
            </div>

            <div class="zps-actions">
                <button type="button" class="zps-btn zps-btn-primary" x-on:click="save()">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M5 13l4 4L19 7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                    ذخیره تغییرات
                </button>
                <button type="button" class="zps-btn" x-on:click="quickPreview()">
                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    پیش‌نمایش سریع
                </button>
                <button type="button" class="zps-btn zps-btn-ghost" wire:click="resetDefaults"
                        wire:confirm="بازنشانی همه تنظیمات ظاهر به حالت پیش‌فرض؟">
                    بازنشانی پیش‌فرض‌ها
                </button>
            </div>
        </div>
    </div>

    <div class="zps-layout">
        {{-- ── LEFT: appearance + gallery ──────────────────────────────── --}}
        <div class="zps-stack">

            {{-- Appearance mode --}}
            <div class="zps-panel">
                <p class="zps-panel-title">حالت نمایش</p>
                <p class="zps-panel-sub">روشن، تاریک یا هماهنگ با مرورگر.</p>
                <div class="zps-seg">
                    <button type="button" :class="state.appearance==='light' && 'is-active'" x-on:click="setAppearance('light')">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M19 5l-1.5 1.5M6.5 17.5L5 19" stroke-linecap="round"/></svg>
                        روشن
                    </button>
                    <button type="button" :class="state.appearance==='dark' && 'is-active'" x-on:click="setAppearance('dark')">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12.8A9 9 0 1111.2 3a7 7 0 009.8 9.8z" stroke-linejoin="round"/></svg>
                        تاریک
                    </button>
                    <button type="button" :class="state.appearance==='system' && 'is-active'" x-on:click="setAppearance('system')">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4" stroke-linecap="round"/></svg>
                        سیستم
                    </button>
                </div>
            </div>

            {{-- Theme gallery --}}
            <div class="zps-panel">
                <p class="zps-panel-title">گالری تم‌ها</p>
                <p class="zps-panel-sub">یک تم را انتخاب کنید تا بلافاصله پیش‌نمایش و اعمال شود.</p>

                <template x-for="grp in ['dark','light','special']" :key="grp">
                    <div>
                        <div class="zps-group-label" x-text="groupLabels[grp]"></div>
                        <div class="zps-grid">
                            <template x-for="key in groups[grp]" :key="key">
                                <div class="zps-card" :class="state.activeTheme===key && 'is-active'"
                                     role="button" tabindex="0"
                                     x-on:click="selectTheme(key)"
                                     x-on:keydown.enter="selectTheme(key)">
                                    <div class="zps-card-preview" :style="`background:${presets[key].colors.gradient}`">
                                        <span class="zps-check">
                                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 13l4 4L19 7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        </span>
                                        <span class="zps-pill" x-show="key===state.default_theme_admin" x-text="state.activeTheme===key ? 'فعال' : 'پیش‌فرض'"></span>
                                    </div>
                                    <div class="zps-card-body">
                                        <p class="zps-card-title" x-text="presets[key].title"></p>
                                        <p class="zps-card-desc" x-text="presets[key].description"></p>
                                        <span class="zps-dots">
                                            <template x-for="(c,i) in presets[key].dots" :key="i">
                                                <span class="zps-dot" style="width:.8rem;height:.8rem" :style="`background:${c}`"></span>
                                            </template>
                                        </span>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>

            {{-- Scope controls --}}
            <div class="zps-panel">
                <p class="zps-panel-title">دامنه اعمال تم</p>
                <p class="zps-panel-sub">تم پیش‌فرض هر بخش را تعیین کنید. با فعال‌بودن «اجبار تم سراسری»، تم فعال روی همه‌جا اعمال می‌شود.</p>
                <div class="zps-controls-grid">
                    <div class="zps-field">
                        <label>تم پیش‌فرض سایت عمومی</label>
                        <select class="zps-select" x-model="state.default_theme_public" x-on:change="dirty=true">
                            <template x-for="key in allKeys" :key="key"><option :value="key" x-text="presets[key].title"></option></template>
                        </select>
                    </div>
                    <div class="zps-field">
                        <label>تم پیش‌فرض داشبورد کاربر</label>
                        <select class="zps-select" x-model="state.default_theme_user" x-on:change="dirty=true">
                            <template x-for="key in allKeys" :key="key"><option :value="key" x-text="presets[key].title"></option></template>
                        </select>
                    </div>
                    <div class="zps-field">
                        <label>تم پیش‌فرض پنل مدیریت</label>
                        <select class="zps-select" x-model="state.default_theme_admin" x-on:change="state.activeTheme=state.default_theme_admin; applyLive(); dirty=true">
                            <template x-for="key in allKeys" :key="key"><option :value="key" x-text="presets[key].title"></option></template>
                        </select>
                    </div>
                </div>

                <div style="margin-top:1rem">
                    <div class="zps-switch-row">
                        <div><div style="font-weight:700;font-size:.85rem">اجبار تم سراسری</div><div style="font-size:.72rem;color:var(--zp-text-muted)">انتخاب کاربر نادیده گرفته می‌شود.</div></div>
                        <div class="zps-switch" :class="state.force_global_theme && 'is-on'" x-on:click="state.force_global_theme=!state.force_global_theme; dirty=true"></div>
                    </div>
                    <div class="zps-switch-row">
                        <div><div style="font-weight:700;font-size:.85rem">اجازه تغییر تم توسط کاربر</div></div>
                        <div class="zps-switch" :class="state.allow_user_theme_switch && 'is-on'" x-on:click="state.allow_user_theme_switch=!state.allow_user_theme_switch; dirty=true"></div>
                    </div>
                    <div class="zps-switch-row">
                        <div><div style="font-weight:700;font-size:.85rem">اجازه تغییر حالت روشن/تاریک/سیستم توسط کاربر</div></div>
                        <div class="zps-switch" :class="state.allow_user_appearance_switch && 'is-on'" x-on:click="state.allow_user_appearance_switch=!state.allow_user_appearance_switch; dirty=true"></div>
                    </div>
                </div>
            </div>

            {{-- Enabled themes manager --}}
            <div class="zps-panel">
                <p class="zps-panel-title">مدیریت تم‌های قابل نمایش برای کاربران</p>
                <p class="zps-panel-sub">تنها تم‌های فعال‌شده برای کاربران قابل انتخاب خواهند بود.</p>
                <div style="display:flex;gap:.5rem;flex-wrap:wrap;margin-bottom:.85rem">
                    <button type="button" class="zps-btn" x-on:click="enableAll()">فعال‌سازی همه</button>
                    <button type="button" class="zps-btn zps-btn-ghost" x-on:click="disableExcept()">غیرفعال‌سازی انتخابی</button>
                </div>
                <div class="zps-chips">
                    <template x-for="key in allKeys" :key="key">
                        <button type="button" class="zps-chip" :class="enabledOn(key) && 'is-on'" x-on:click="toggleEnabled(key)">
                            <span class="zps-dot" style="width:.7rem;height:.7rem" :style="`background:${presets[key].colors.primary}`"></span>
                            <span x-text="presets[key].title"></span>
                            <span style="font-size:.62rem;opacity:.8" x-text="enabledOn(key) ? 'فعال' : 'غیرفعال'"></span>
                        </button>
                    </template>
                </div>
            </div>

            {{-- Advanced customization --}}
            <div class="zps-panel">
                <p class="zps-panel-title">تنظیمات پیشرفته ظاهر</p>
                <p class="zps-panel-sub">این تنظیمات از طریق متغیرهای CSS روی کل پلتفرم اعمال می‌شوند.</p>
                <div class="zps-controls-grid">
                    <div class="zps-field">
                        <label>شدت انیمیشن‌ها</label>
                        <select class="zps-select" x-model="state.animation_intensity" x-on:change="applyLive(); dirty=true">
                            <option value="off">خاموش</option><option value="low">کم</option>
                            <option value="medium">متوسط</option><option value="high">زیاد</option>
                        </select>
                    </div>
                    <div class="zps-field">
                        <label>گردی کارت‌ها</label>
                        <select class="zps-select" x-model="state.card_radius" x-on:change="applyLive(); dirty=true">
                            <option value="0.35rem">کم</option><option value="0.6rem">متوسط</option>
                            <option value="0.9rem">پیش‌فرض</option><option value="1.2rem">زیاد</option><option value="1.6rem">خیلی زیاد</option>
                        </select>
                    </div>
                    <div class="zps-field">
                        <label>گردی دکمه‌ها</label>
                        <select class="zps-select" x-model="state.button_radius" x-on:change="applyLive(); dirty=true">
                            <option value="0.3rem">کم</option><option value="0.5rem">متوسط</option>
                            <option value="0.6rem">پیش‌فرض</option><option value="0.9rem">زیاد</option><option value="9999px">کاملاً گرد</option>
                        </select>
                    </div>
                    <div class="zps-field">
                        <label>اندازه آیکن‌ها</label>
                        <select class="zps-select" x-model="state.icon_size" x-on:change="applyLive(); dirty=true">
                            <option value="1rem">کوچک</option><option value="1.25rem">پیش‌فرض</option><option value="1.5rem">بزرگ</option>
                        </select>
                    </div>
                    <div class="zps-field">
                        <label>اندازه آیکن‌های منو</label>
                        <select class="zps-select" x-model="state.sidebar_icon_size" x-on:change="applyLive(); dirty=true">
                            <option value="1rem">کوچک</option><option value="1.25rem">پیش‌فرض</option><option value="1.5rem">بزرگ</option>
                        </select>
                    </div>
                    <div class="zps-field">
                        <label>اندازه لوگو</label>
                        <select class="zps-select" x-model="state.logo_size" x-on:change="applyLive(); dirty=true">
                            <option value="1rem">کوچک</option><option value="1.15rem">پیش‌فرض</option><option value="1.4rem">بزرگ</option>
                        </select>
                    </div>
                    <div class="zps-field">
                        <label>اندازه فونت</label>
                        <select class="zps-select" x-model.number="state.font_scale" x-on:change="applyLive(); dirty=true">
                            <option :value="90">کوچک</option><option :value="100">پیش‌فرض</option>
                            <option :value="110">بزرگ</option><option :value="120">خیلی بزرگ</option>
                        </select>
                    </div>
                    <div class="zps-field">
                        <label>تراکم جدول‌ها</label>
                        <select class="zps-select" x-model="state.table_density" x-on:change="dirty=true">
                            <option value="compact">فشرده</option><option value="normal">عادی</option><option value="comfortable">راحت</option>
                        </select>
                    </div>
                    <div class="zps-field">
                        <label>تراکم کارت‌ها</label>
                        <select class="zps-select" x-model="state.card_density" x-on:change="dirty=true">
                            <option value="compact">فشرده</option><option value="normal">عادی</option><option value="comfortable">راحت</option>
                        </select>
                    </div>
                    <div class="zps-field">
                        <label>اندازه تصاویر و آواتارها</label>
                        <select class="zps-select" x-model="state.image_size" x-on:change="applyLive(); dirty=true">
                            <option value="2rem">کوچک</option><option value="2.5rem">پیش‌فرض</option><option value="3rem">بزرگ</option>
                        </select>
                    </div>
                </div>
            </div>

            {{-- Save bar --}}
            <div class="zps-panel zps-savebar">
                <div>
                    <span x-show="dirty" class="zps-dirty">تغییرات ذخیره نشده‌اند.</span>
                    <span x-show="saved" class="zps-saved">تم با موفقیت ذخیره شد.</span>
                    <span x-show="!dirty && !saved" style="font-size:.78rem;color:var(--zp-text-muted)">همه تغییرات ذخیره شده‌اند.</span>
                </div>
                <button type="button" class="zps-btn zps-btn-primary" x-on:click="save()">ذخیره تغییرات</button>
            </div>
        </div>

        {{-- ── RIGHT: live preview ─────────────────────────────────────── --}}
        <div>
            <div class="zps-panel zps-sticky" :style="previewVars()">
                <p class="zps-panel-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    پیش‌نمایش زنده
                </p>
                <p class="zps-panel-sub">انتخاب شما به‌صورت زنده پیش‌نمایش داده می‌شود و پس از ذخیره، روی بخش‌های انتخاب‌شده اعمال خواهد شد.</p>

                <div class="zps-preview-fade" :class="fade ? '' : 'is-out'"
                     :style="`opacity:${fade?1:0};transform:scale(${fade?1:.98})`">
                    <div style="border-radius:var(--zp-card-radius);overflow:hidden;border:1px solid var(--zpv-border)">
                        <div :style="`background:${presets[state.activeTheme].colors.gradient};padding:.9rem 1rem;color:#fff`">
                            <div style="font-weight:800;font-size:.95rem">ZedProxy</div>
                            <div style="font-size:.72rem;opacity:.9">متن برند</div>
                        </div>
                        <div :style="`background:var(--zpv-bg);padding:1rem;display:flex;flex-direction:column;gap:.8rem`">
                            {{-- buttons --}}
                            <div style="display:flex;gap:.5rem;flex-wrap:wrap">
                                <button class="zps-prev-btn" :style="`background:${pc('primary')};color:#fff`">دکمه اصلی</button>
                                <button class="zps-prev-btn" :style="`background:transparent;border:1px solid ${pc('border')};color:var(--zpv-text)`">دکمه دوم</button>
                                <span class="zps-prev-btn" :style="`background:${pc('accent')}22;color:${pc('accent')};font-size:.7rem`">برچسب</span>
                            </div>
                            {{-- card sample --}}
                            <div class="zps-prev-card">
                                <div style="display:flex;align-items:center;gap:.6rem">
                                    <div :style="`width:2.2rem;height:2.2rem;border-radius:9999px;background:${presets[state.activeTheme].colors.gradient}`"></div>
                                    <div>
                                        <div style="font-weight:700;font-size:.82rem;color:var(--zpv-text)">کارت نمونه</div>
                                        <div style="font-size:.7rem;color:var(--zpv-muted)">رنگ اصلی و متن کم‌رنگ</div>
                                    </div>
                                    <span style="margin-right:auto;font-size:.66rem;font-weight:700;padding:.15rem .55rem;border-radius:9999px"
                                          :style="`background:${pc('primary')}22;color:${pc('primary')}`">وضعیت فعال</span>
                                </div>
                                <input class="zps-input" style="margin-top:.7rem" :style="`background:var(--zpv-soft);border-color:var(--zpv-border);color:var(--zpv-text)`" placeholder="ورودی نمونه">
                            </div>
                            {{-- mini dashboard stat --}}
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.6rem">
                                <div class="zps-prev-card" style="padding:.7rem">
                                    <div style="font-size:.66rem;color:var(--zpv-muted)">کیف پول</div>
                                    <div style="font-weight:800;font-size:1rem;color:var(--zpv-text)">۱٬۲۵۰٬۰۰۰</div>
                                </div>
                                <div class="zps-prev-card" style="padding:.7rem">
                                    <div style="font-size:.66rem;color:var(--zpv-muted)">سرویس فعال</div>
                                    <div style="font-weight:800;font-size:1rem" :style="`color:${pc('accent')}`">۳</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function themeStudio(state, presets, groups, groupLabels) {
    return {
        state, presets, groups, groupLabels,
        allKeys: Object.keys(presets),
        dirty: false, saved: false, fade: true,

        init() { this.applyLive(); },

        isLight(theme) {
            if (this.state.appearance === 'light') return true;
            if (this.state.appearance === 'dark') return false;
            return (this.presets[theme]?.appearance) === 'light';
        },
        appearanceLabel() {
            return { light: 'روشن', dark: 'تاریک', system: 'سیستم' }[this.state.appearance] || 'تاریک';
        },
        speed(intensity) {
            return ({ off: '0ms', low: '160ms', high: '320ms' })[intensity] || '220ms';
        },
        pc(name) { return this.presets[this.state.activeTheme].colors[name]; },

        applyLive() {
            const el = document.documentElement;
            el.setAttribute('data-theme', this.state.activeTheme);
            const light = this.isLight(this.state.activeTheme);
            el.classList.toggle('zed-light', light);
            el.classList.toggle('dark', !light);
            el.classList.toggle('zed-anim-none', this.state.animation_intensity === 'off');
            el.style.setProperty('--zp-card-radius', this.state.card_radius);
            el.style.setProperty('--zp-button-radius', this.state.button_radius);
            el.style.setProperty('--zp-animation-speed', this.speed(this.state.animation_intensity));
            el.style.setProperty('--zp-icon-size', this.state.icon_size);
            el.style.setProperty('--zp-sidebar-icon-size', this.state.sidebar_icon_size);
        },
        bump() { this.fade = false; requestAnimationFrame(() => requestAnimationFrame(() => this.fade = true)); },

        selectTheme(key) {
            this.state.activeTheme = key;
            this.state.default_theme_admin = key;
            this.bump(); this.applyLive(); this.dirty = true; this.saved = false;
        },
        setAppearance(mode) { this.state.appearance = mode; this.applyLive(); this.dirty = true; this.saved = false; },

        enabledOn(key) { return (this.state.enabled_themes || []).includes(key); },
        toggleEnabled(key) {
            const arr = this.state.enabled_themes || [];
            const i = arr.indexOf(key);
            if (i === -1) { arr.push(key); }
            else if (key !== this.state.default_theme_admin) { arr.splice(i, 1); }
            this.state.enabled_themes = [...arr]; this.dirty = true;
        },
        enableAll() { this.state.enabled_themes = [...this.allKeys]; this.dirty = true; },
        disableExcept() { this.state.enabled_themes = [this.state.default_theme_admin]; this.dirty = true; },

        quickPreview() { this.bump(); window.scrollTo({ top: 0, behavior: 'smooth' }); },

        previewVars() {
            const c = this.presets[this.state.activeTheme].colors;
            return `--zpv-bg:${c.bg};--zpv-surface:${c.surface};--zpv-soft:${c.surface_soft};`
                 + `--zpv-text:${c.text};--zpv-muted:${c.muted};--zpv-border:${c.border};`;
        },

        save() {
            this.$wire.persist(this.state).then(() => { this.dirty = false; this.saved = true;
                setTimeout(() => this.saved = false, 2600); });
        },
    };
}
</script>
</x-filament-panels::page>
