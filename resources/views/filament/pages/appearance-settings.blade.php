<x-filament-panels::page>
    <form wire:submit="save" class="space-y-6">
        {{ $this->form }}

        <div class="flex justify-end">
            <x-filament::button type="submit" icon="heroicon-o-check">
                ذخیره و اعمال
            </x-filament::button>
        </div>
    </form>

    {{-- ── پیش‌نمایش سریع — built from the live admin variables ─────────── --}}
    <x-filament::section icon="heroicon-o-eye">
        <x-slot name="heading">پیش‌نمایش سریع</x-slot>
        <x-slot name="description">نمونه‌ای از دکمه، کارت، آیتم سایدبار، ردیف جدول و ورودی با تنظیمات فعلی.</x-slot>

        @php($style = collect($colors)->merge($admin['vars'])->map(fn ($v, $k) => "$k: $v")->implode(';'))
        <div style="{{ $style }}; display:grid; gap:1rem; grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">
            {{-- sample button --}}
            <div style="background:var(--zp-surface-soft);border:1px solid var(--zp-border);border-radius:var(--zp-admin-card-radius,12px);padding:var(--zp-admin-card-padding,16px)">
                <div style="font-size:.72rem;color:var(--zp-text-muted);margin-bottom:.5rem">دکمه</div>
                <button type="button" style="border:0;cursor:default;color:#fff;background:var(--zp-gradient);border-radius:var(--zp-admin-button-radius,10px);min-height:var(--zp-admin-form-control-height,42px);padding:0 1rem;font-weight:700;font-size:.85rem">دکمه اصلی</button>
            </div>
            {{-- sample sidebar item --}}
            <div style="background:var(--zp-surface);border:1px solid var(--zp-border);border-radius:var(--zp-admin-card-radius,12px);padding:.6rem;width:min(100%,var(--zp-admin-sidebar-width,280px))">
                <div style="font-weight:800;color:var(--zp-text);font-size:var(--zp-admin-sidebar-brand-size,24px);line-height:1.1;margin:.2rem .4rem .5rem">{{ $admin['brand_text'] }}</div>
                <div style="display:flex;align-items:center;gap:.5rem;min-height:var(--zp-admin-sidebar-item-height,40px);padding:0 .5rem;border-radius:var(--zp-admin-button-radius,10px);background:color-mix(in srgb,var(--zp-primary) 16%,transparent)">
                    <svg style="width:var(--zp-admin-sidebar-icon-size,16px);height:var(--zp-admin-sidebar-icon-size,16px)" viewBox="0 0 24 24" fill="none" stroke="var(--zp-primary)" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                    <span style="font-size:var(--zp-admin-sidebar-font-size,14px);color:var(--zp-text);font-weight:600">داشبورد</span>
                </div>
            </div>
            {{-- sample card + table row --}}
            <div style="background:var(--zp-surface);border:1px solid var(--zp-border);border-radius:var(--zp-admin-card-radius,12px);overflow:hidden">
                <div style="padding:var(--zp-admin-card-padding,16px)">
                    <div style="font-weight:700;font-size:.85rem;color:var(--zp-text)">کارت نمونه</div>
                    <div style="font-size:.72rem;color:var(--zp-text-muted)">رنگ اصلی و متن کم‌رنگ</div>
                </div>
                <table style="width:100%;border-collapse:collapse;font-size:.78rem;color:var(--zp-text);border-top:1px solid var(--zp-border)">
                    <tr style="height:var(--zp-admin-table-row-height,48px)">
                        <td style="padding:var(--zp-admin-table-cell-py,10px) var(--zp-admin-table-cell-px,12px)">ردیف جدول</td>
                        <td style="padding:var(--zp-admin-table-cell-py,10px) var(--zp-admin-table-cell-px,12px);color:var(--zp-success)">فعال</td>
                    </tr>
                </table>
            </div>
            {{-- sample input --}}
            <div style="background:var(--zp-surface-soft);border:1px solid var(--zp-border);border-radius:var(--zp-admin-card-radius,12px);padding:var(--zp-admin-card-padding,16px)">
                <div style="font-size:.72rem;color:var(--zp-text-muted);margin-bottom:.5rem">ورودی</div>
                <input placeholder="متن نمونه" style="width:100%;min-height:var(--zp-admin-form-control-height,42px);background:var(--zp-surface);border:1px solid var(--zp-border);border-radius:var(--zp-admin-button-radius,10px);color:var(--zp-text);padding:0 .7rem">
            </div>
        </div>
    </x-filament::section>
</x-filament-panels::page>
