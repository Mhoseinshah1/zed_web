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
                <p class="zps-panel-sub">با انتخاب تم از گالری، روی هر سه بخش اعمال می‌شود. در صورت نیاز می‌توانید برای هر بخش تم متفاوتی انتخاب کنید.</p>
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
                        <select class="zps-select" x-model="state.table_density" x-on:change="applyLive(); dirty=true">
                            <option value="compact">فشرده</option><option value="normal">عادی</option><option value="comfortable">راحت</option>
                        </select>
                    </div>
                    <div class="zps-field">
                        <label>تراکم کارت‌ها</label>
                        <select class="zps-select" x-model="state.card_density" x-on:change="applyLive(); dirty=true">
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

            {{-- ── Visual test sandbox ──────────────────────────────────────
                 Uses the same --zp-admin-* tokens the real Filament chrome
                 uses, so every advanced setting visibly changes it live. --}}
            <div class="zps-panel">
                <p class="zps-panel-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
                    نمونهٔ زندهٔ پنل مدیریت
                </p>
                <p class="zps-panel-sub">این نمونه دقیقاً با همان متغیرهای پنل ادمین ساخته شده؛ با تغییر تنظیمات بالا، بلافاصله تغییر می‌کند.</p>
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:.9rem">
                    {{-- sidebar item + icon --}}
                    <div style="border:1px solid var(--zp-border);border-radius:var(--zp-admin-card-radius,14px);padding:var(--zp-admin-card-padding,16px);background:var(--zp-surface-soft)">
                        <div style="font-size:.7rem;color:var(--zp-text-muted);margin-bottom:.5rem">آیتم منو + آیکن</div>
                        <div style="display:flex;align-items:center;gap:.5rem;padding:.4rem .55rem;border-radius:var(--zp-admin-button-radius,10px);background:var(--zp-surface)">
                            <svg style="width:var(--zp-admin-sidebar-icon-size,18px);height:var(--zp-admin-sidebar-icon-size,18px);flex:none" viewBox="0 0 24 24" fill="none" stroke="var(--zp-primary)" stroke-width="2"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                            <span style="font-size:.8rem;color:var(--zp-text)">داشبورد</span>
                        </div>
                    </div>
                    {{-- button --}}
                    <div style="border:1px solid var(--zp-border);border-radius:var(--zp-admin-card-radius,14px);padding:var(--zp-admin-card-padding,16px);background:var(--zp-surface-soft)">
                        <div style="font-size:.7rem;color:var(--zp-text-muted);margin-bottom:.5rem">دکمه</div>
                        <button type="button" style="display:inline-flex;align-items:center;gap:.4rem;border:0;cursor:default;color:#fff;background:var(--zp-gradient);border-radius:var(--zp-admin-button-radius,10px);min-height:var(--zp-admin-form-control-height,42px);padding:0 .9rem;font-size:.8rem;font-weight:700">
                            <svg style="width:var(--zp-admin-action-icon-size,16px);height:var(--zp-admin-action-icon-size,16px)" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M5 12h14"/></svg>
                            افزودن
                        </button>
                    </div>
                    {{-- input + select caret --}}
                    <div style="border:1px solid var(--zp-border);border-radius:var(--zp-admin-card-radius,14px);padding:var(--zp-admin-card-padding,16px);background:var(--zp-surface-soft)">
                        <div style="font-size:.7rem;color:var(--zp-text-muted);margin-bottom:.5rem">ورودی و انتخاب</div>
                        <select class="zps-select" style="min-height:var(--zp-admin-form-control-height,42px);margin-bottom:.5rem"><option>گزینه نمونه</option></select>
                        <input class="zps-input" style="min-height:var(--zp-admin-form-control-height,42px)" placeholder="متن نمونه">
                    </div>
                    {{-- logo --}}
                    <div style="border:1px solid var(--zp-border);border-radius:var(--zp-admin-card-radius,14px);padding:var(--zp-admin-card-padding,16px);background:var(--zp-surface-soft)">
                        <div style="font-size:.7rem;color:var(--zp-text-muted);margin-bottom:.5rem">لوگو</div>
                        <div style="display:flex;align-items:center;gap:.5rem">
                            <div style="width:var(--zp-admin-logo-size,32px);height:var(--zp-admin-logo-size,32px);border-radius:8px;background:var(--zp-gradient);flex:none"></div>
                            <span style="font-weight:800;color:var(--zp-text);font-size:calc(.8rem * var(--zp-admin-font-scale,1))">ZedProxy</span>
                        </div>
                    </div>
                </div>
                {{-- sample table --}}
                <div style="margin-top:.9rem;border:1px solid var(--zp-border);border-radius:var(--zp-admin-card-radius,14px);overflow:hidden">
                    <table style="width:100%;border-collapse:collapse;font-size:.78rem;color:var(--zp-text)">
                        <thead><tr style="background:var(--zp-surface-soft)">
                            <th style="text-align:right;padding:var(--zp-admin-table-cell-py,10px) var(--zp-admin-table-cell-px,12px)">کاربر</th>
                            <th style="text-align:right;padding:var(--zp-admin-table-cell-py,10px) var(--zp-admin-table-cell-px,12px)">وضعیت</th>
                        </tr></thead>
                        <tbody>
                            <tr style="height:var(--zp-admin-table-row-height,48px);border-top:1px solid var(--zp-border)">
                                <td style="padding:var(--zp-admin-table-cell-py,10px) var(--zp-admin-table-cell-px,12px)">نمونه یک</td>
                                <td style="padding:var(--zp-admin-table-cell-py,10px) var(--zp-admin-table-cell-px,12px)">فعال</td>
                            </tr>
                            <tr style="height:var(--zp-admin-table-row-height,48px);border-top:1px solid var(--zp-border)">
                                <td style="padding:var(--zp-admin-table-cell-py,10px) var(--zp-admin-table-cell-px,12px)">نمونه دو</td>
                                <td style="padding:var(--zp-admin-table-cell-py,10px) var(--zp-admin-table-cell-px,12px)">در انتظار</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- ── Diagnostics ──────────────────────────────────────────────
                 Saved DB value vs resolved CSS value vs the value actually
                 applied in the browser right now. Collapsible, admin-only. --}}
            <div class="zps-panel">
                <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;cursor:pointer" x-on:click="diagOpen=!diagOpen">
                    <p class="zps-panel-title" style="margin:0">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.3-4.3"/></svg>
                        عیب‌یابی تنظیمات ظاهر
                    </p>
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" :style="diagOpen ? 'transform:rotate(180deg)' : ''"><path d="M6 9l6 6 6-6"/></svg>
                </div>
                <div x-show="diagOpen" x-cloak style="margin-top:.85rem">
                    <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:.8rem">
                        <span class="zps-badge" style="background:color-mix(in srgb,var(--zp-primary) 16%,transparent);color:var(--zp-primary);border-color:transparent">تم ادمین: {{ $adminResolved['theme'] }}</span>
                        <span class="zps-badge" style="background:color-mix(in srgb,var(--zp-info) 16%,transparent);color:var(--zp-info);border-color:transparent">حالت: {{ $adminResolved['appearance'] }}</span>
                        <span class="zps-badge" style="background:color-mix(in srgb,var(--zp-warning) 16%,transparent);color:var(--zp-warning);border-color:transparent">انیمیشن: {{ $adminResolved['animation_intensity'] }}</span>
                        <span class="zps-badge" id="zp-diag-styletag">style tag: زنده</span>
                    </div>
                    <div style="overflow-x:auto">
                        <table style="width:100%;border-collapse:collapse;font-size:.74rem">
                            <thead><tr style="color:var(--zp-text-muted);text-align:right">
                                <th style="padding:.4rem .5rem">متغیر</th>
                                <th style="padding:.4rem .5rem">مقدار ذخیره‌شده (DB)</th>
                                <th style="padding:.4rem .5rem">مقدار محاسبه‌شده (CSS)</th>
                                <th style="padding:.4rem .5rem">اعمال‌شده در مرورگر</th>
                            </tr></thead>
                            <tbody style="color:var(--zp-text)">
                                @php($diagRows = [
                                    ['اندازه آیکن', $adminResolved['raw']['icon_size'], '--zp-admin-icon-size'],
                                    ['آیکن منو', $adminResolved['raw']['sidebar_icon_size'], '--zp-admin-sidebar-icon-size'],
                                    ['اندازه لوگو', $adminResolved['raw']['logo_size'], '--zp-admin-logo-size'],
                                    ['اندازه فونت', $adminResolved['raw']['font_scale'].'%', '--zp-admin-font-scale'],
                                    ['تراکم جدول', $adminResolved['raw']['table_density'], '--zp-admin-table-row-height'],
                                    ['تراکم کارت', $adminResolved['raw']['card_density'], '--zp-admin-card-padding'],
                                    ['گردی کارت', $adminResolved['raw']['card_radius'], '--zp-admin-card-radius'],
                                    ['گردی دکمه', $adminResolved['raw']['button_radius'], '--zp-admin-button-radius'],
                                    ['انیمیشن', $adminResolved['raw']['animation_intensity'], '--zp-admin-animation-speed'],
                                ])
                                @foreach($diagRows as [$label, $dbVal, $varName])
                                    <tr style="border-top:1px solid var(--zp-border)">
                                        <td style="padding:.45rem .5rem">{{ $label }}</td>
                                        <td style="padding:.45rem .5rem;font-family:monospace;direction:ltr">{{ $dbVal }}</td>
                                        <td style="padding:.45rem .5rem;font-family:monospace;direction:ltr">{{ $adminResolved['vars'][$varName] ?? '—' }}</td>
                                        <td style="padding:.45rem .5rem;font-family:monospace;direction:ltr" x-text="(diagTick, liveVar('{{ $varName }}'))"></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    <div style="display:flex;gap:.5rem;margin-top:.85rem;flex-wrap:wrap">
                        <button type="button" class="zps-btn" x-on:click="refreshDiag()">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6M1 20v-6h6"/><path d="M3.5 9a9 9 0 0114.85-3.36L23 10M1 14l4.65 4.36A9 9 0 0020.5 15"/></svg>
                            تازه‌سازی مقادیر زنده
                        </button>
                        <button type="button" class="zps-btn zps-btn-primary" wire:click="recheckAppearance">
                            بررسی اعمال تنظیمات
                        </button>
                    </div>
                    <p style="font-size:.7rem;color:var(--zp-text-muted);margin-top:.6rem">اگر ستون «اعمال‌شده» با «محاسبه‌شده» برابر بود، تنظیمات با موفقیت روی پنل اعمال شده‌اند.</p>
                </div>
            </div>

            {{-- Save bar --}}
            <div class="zps-panel zps-savebar">
                <div>
                    <span x-show="dirty" class="zps-dirty">تغییرات ذخیره نشده‌اند.</span>
                    <span x-show="saved" class="zps-saved">تغییرات ظاهر با موفقیت ذخیره و اعمال شد.</span>
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
        diagOpen: false, diagTick: 0,

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
            // Mirror the compact, independent admin tokens — IDENTICAL maths to
            // AdminAppearanceResolver so the live preview equals what /zed-admin
            // renders after save (and the diagnostics rows line up).
            const px = (v) => { const n = parseFloat(v) || 1; return /rem|em/.test(v) ? n * 16 : (/px/.test(v) ? n : (n <= 4 ? n * 16 : n)); };
            const clamp = (n, a, b) => Math.round(Math.max(a, Math.min(b, n)) * 10) / 10;
            const trimNum = (n) => String(Math.round(n * 1000) / 1000);
            const iconPx = clamp(px(this.state.icon_size) * 0.85, 12, 24);
            const sidePx = clamp(px(this.state.sidebar_icon_size) * 0.9, 14, 26);
            const logoPx = clamp(px(this.state.logo_size) / 18.4 * 32, 24, 56);
            const cardR  = clamp(px(this.state.card_radius), 8, 28);
            const btnR   = clamp(px(this.state.button_radius), 6, 24);
            const fScale = clamp((parseInt(this.state.font_scale) || 100) / 100, 0.9, 1.15);
            const S = (k, v) => el.style.setProperty(k, v);
            S('--zp-admin-icon-size', iconPx + 'px');
            S('--zp-admin-action-icon-size', clamp(iconPx, 12, 22) + 'px');
            S('--zp-admin-form-icon-size', clamp(iconPx, 12, 22) + 'px');
            S('--zp-admin-sidebar-icon-size', sidePx + 'px');
            S('--zp-admin-select-caret-size', clamp(iconPx - 2, 10, 18) + 'px');
            S('--zp-admin-logo-size', logoPx + 'px');
            S('--zp-admin-card-radius', cardR + 'px');
            S('--zp-admin-button-radius', btnR + 'px');
            S('--zp-admin-font-scale', trimNum(fScale));
            S('--zp-admin-animation-speed', this.speed(this.state.animation_intensity));
            const card = ({ compact: [12, 38, 10], comfortable: [20, 46, 18] })[this.state.card_density] || [16, 42, 14];
            S('--zp-admin-card-padding', card[0] + 'px');
            S('--zp-admin-form-control-height', card[1] + 'px');
            S('--zp-admin-density-gap', card[2] + 'px');
            const tbl = ({ compact: [40, 8, 10], comfortable: [56, 14, 16] })[this.state.table_density] || [48, 10, 12];
            S('--zp-admin-table-row-height', tbl[0] + 'px');
            S('--zp-admin-table-cell-py', tbl[1] + 'px');
            S('--zp-admin-table-cell-px', tbl[2] + 'px');
        },
        bump() { this.fade = false; requestAnimationFrame(() => requestAnimationFrame(() => this.fade = true)); },

        selectTheme(key) {
            // Picking a theme in the gallery applies it everywhere by default
            // (public + user dashboard + admin). Per-surface overrides below can
            // still diverge afterwards.
            this.state.activeTheme = key;
            this.state.default_theme_admin = key;
            this.state.default_theme_public = key;
            this.state.default_theme_user = key;
            if (!this.enabledOn(key)) { this.state.enabled_themes = [...(this.state.enabled_themes || []), key]; }
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
            this.$wire.persist(this.state).then(() => {
                this.dirty = false; this.saved = true;
                // Re-apply the resolved tokens to the live document so the whole
                // admin chrome reflects the saved values immediately (persisted
                // values are re-injected declaratively on the next page load).
                this.applyLive();
                this.refreshDiag();
                setTimeout(() => this.saved = false, 3200);
            });
        },

        /** Read the variables actually applied to :root right now (diagnostics). */
        liveVar(name) {
            try { return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || '—'; }
            catch (e) { return '—'; }
        },
        refreshDiag() { this.diagTick++; },
    };
}
</script>
</x-filament-panels::page>
