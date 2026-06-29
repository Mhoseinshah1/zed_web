<x-filament-panels::page>
<div class="zps" dir="rtl" x-data="templateStudio(@js($templates), @js($active))">

    {{-- Hero header --}}
    <div class="zps-hero" style="margin-bottom:1.25rem">
        <div class="zps-hero-inner">
            <div style="min-width:16rem">
                <div style="display:flex;align-items:center;gap:.6rem;flex-wrap:wrap">
                    <h1>قالب‌های صفحه اصلی</h1>
                    <span class="zps-badge">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 13l4 4L19 7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span x-text="templates[active] ? templates[active].title : active"></span>
                    </span>
                </div>
                <p>ساختار و چیدمان صفحه اصلی را انتخاب کنید. رنگ‌بندی به‌صورت جداگانه از «استودیو تم» کنترل می‌شود و روی هر دو قالب اعمال می‌گردد.</p>
            </div>
        </div>
    </div>

    {{-- Gallery --}}
    <div class="zps-grid" style="grid-template-columns:repeat(auto-fit,minmax(280px,1fr))">
        <template x-for="(tpl, key) in templates" :key="key">
            <div class="zps-card" :class="active === key && 'is-active'" style="cursor:default">
                {{-- Mock preview --}}
                <div style="height:9rem;position:relative;overflow:hidden;background:var(--zp-bg-soft);padding:.6rem">
                    <div :style="`background:${tpl.accent}`" style="height:.5rem;border-radius:999px;width:100%;opacity:.9"></div>
                    <div style="display:flex;gap:.35rem;margin-top:.5rem">
                        <div style="height:.45rem;flex:1;background:var(--zp-surface-soft);border-radius:999px"></div>
                        <div style="height:.45rem;width:1.5rem;background:var(--zp-surface-hover);border-radius:999px"></div>
                    </div>
                    <div style="text-align:center;margin-top:.9rem">
                        <div style="height:.6rem;width:60%;margin:0 auto;background:var(--zp-surface-hover);border-radius:999px"></div>
                        <div :style="`background:${tpl.accent}`" style="height:.55rem;width:40%;margin:.4rem auto 0;border-radius:999px"></div>
                    </div>
                    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.35rem;margin-top:.8rem">
                        <div style="height:2rem;background:var(--zp-surface);border:1px solid var(--zp-border);border-radius:.35rem"></div>
                        <div :style="`background:${tpl.accent}`" style="height:2.3rem;margin-top:-.15rem;border-radius:.35rem;opacity:.85"></div>
                        <div style="height:2rem;background:var(--zp-surface);border:1px solid var(--zp-border);border-radius:.35rem"></div>
                    </div>
                    <span class="zps-check"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="M5 13l4 4L19 7" stroke-linecap="round" stroke-linejoin="round"/></svg></span>
                    <span class="zps-pill" x-show="active === key">فعال</span>
                </div>

                <div class="zps-card-body">
                    <p class="zps-card-title" x-text="tpl.title"></p>
                    <p class="zps-card-desc" x-text="tpl.description"></p>
                    <button type="button" class="zps-btn"
                            :class="active === key ? '' : 'zps-btn-primary'"
                            :disabled="active === key || busy"
                            x-on:click="activate(key)"
                            style="width:100%;justify-content:center;margin-top:.5rem"
                            x-text="active === key ? 'قالب فعال' : 'فعال‌سازی'"></button>
                </div>
            </div>
        </template>
    </div>
</div>

<script>
function templateStudio(templates, active) {
    return {
        templates, active, busy: false,
        activate(key) {
            if (this.active === key || this.busy) return;
            this.busy = true;
            this.$wire.persist(key).then(() => {
                this.active = key;
                this.busy = false;
            }).catch(() => { this.busy = false; });
        },
    };
}
</script>
</x-filament-panels::page>
