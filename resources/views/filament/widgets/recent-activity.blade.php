{{--
    «فعالیت اخیر» dashboard widget. Wrapped in <x-filament::section> so it picks
    up the admin shell's card chrome (radius / soft shadow / border) automatically.
    Colours come only from the --zp-* theme variables (light/dark aware); the
    scoped .zp-act* classes are admin-only and never leak elsewhere. RTL-safe via
    logical properties.
--}}
<x-filament::section>
    <x-slot name="heading">فعالیت اخیر</x-slot>

    <div class="zp-act">
        @forelse ($items as $it)
            @php($cvar = ['success' => '--zp-success', 'primary' => '--zp-primary', 'info' => '--zp-accent'][$it['color']] ?? '--zp-primary')
            <div class="zp-act-item">
                <span class="zp-act-icon" style="--zp-act-c: var({{ $cvar }})">
                    @svg($it['icon'], 'zp-act-svg')
                </span>
                <div class="zp-act-body">
                    <div class="zp-act-title">{{ $it['title'] }}</div>
                    <div class="zp-act-meta">{{ $it['meta'] }}</div>
                </div>
                <span class="zp-act-time">{{ $it['ago'] }}</span>
            </div>
        @empty
            <div class="zp-act-empty">فعالیتی برای نمایش وجود ندارد.</div>
        @endforelse
    </div>

    <style>
        .zp-act { display: flex; flex-direction: column; }
        .zp-act-item {
            display: flex; align-items: center; gap: .7rem;
            padding: .7rem 0; border-bottom: 1px solid var(--zp-border);
        }
        .zp-act-item:last-child { border-bottom: 0; padding-bottom: 0; }
        .zp-act-item:first-child { padding-top: 0; }
        .zp-act-icon {
            flex-shrink: 0; width: 2rem; height: 2rem; border-radius: .6rem;
            display: flex; align-items: center; justify-content: center;
            color: var(--zp-act-c);
            background: color-mix(in srgb, var(--zp-act-c) 13%, transparent);
        }
        .zp-act-svg { width: 1rem; height: 1rem; }
        .zp-act-body { min-width: 0; }
        .zp-act-title { font-size: .8rem; font-weight: 600; color: var(--zp-text); }
        .zp-act-meta {
            font-size: .74rem; color: var(--zp-text-muted);
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .zp-act-time {
            margin-inline-start: auto; flex-shrink: 0;
            font-size: .7rem; color: var(--zp-text-muted); white-space: nowrap;
        }
        .zp-act-empty { padding: 1rem 0; font-size: .8rem; color: var(--zp-text-muted); }
    </style>
</x-filament::section>
