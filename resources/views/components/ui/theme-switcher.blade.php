@props([
    'compact' => false,
])
@php
    use App\Services\Theme\ThemeManager;
    $presets       = ThemeManager::presets();
    $enabled       = ThemeManager::enabledThemes();
    $allowTheme    = ThemeManager::allowUserThemeSwitch();
    $allowAppear   = ThemeManager::allowUserAppearanceSwitch();
    $current       = ThemeManager::resolveTheme(
        auth()->check() ? ThemeManager::SURFACE_USER : ThemeManager::SURFACE_PUBLIC,
        auth()->user()
    );
    $appearance    = ThemeManager::resolveAppearance(auth()->user());
@endphp

<div class="zed-card p-4 {{ $compact ? '' : 'space-y-4' }}" data-theme-switcher>
    <div class="flex items-center justify-between">
        <h3 class="text-sm font-semibold text-white flex items-center gap-2">
            <svg class="w-4 h-4 text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828L9 21"/></svg>
            تنظیمات ظاهر
        </h3>
    </div>

    @if(! $allowTheme && ! $allowAppear)
        <p class="text-xs text-gray-400 bg-gray-800 border border-gray-700 rounded-lg p-3">
            تغییر تم توسط مدیریت غیرفعال شده است.
        </p>
    @endif

    @if($allowAppear)
    <div class="space-y-2">
        <p class="text-xs text-gray-400">حالت نمایش</p>
        <div class="grid grid-cols-3 gap-2">
            @foreach([
                ['key' => 'light',  'label' => 'روشن'],
                ['key' => 'dark',   'label' => 'تاریک'],
                ['key' => 'system', 'label' => 'سیستم'],
            ] as $mode)
                <button type="button" data-appearance-btn="{{ $mode['key'] }}"
                    class="zed-btn px-3 py-2 text-xs font-medium border text-center transition
                        {{ $appearance === $mode['key']
                            ? 'border-indigo-500 bg-indigo-600/15 text-white'
                            : 'border-gray-700 text-gray-400 hover:text-white hover:border-gray-600' }}">
                    {{ $mode['label'] }}
                </button>
            @endforeach
        </div>
    </div>
    @endif

    @if($allowTheme)
    <div class="space-y-2">
        <p class="text-xs text-gray-400">تم رنگی</p>
        <div class="grid grid-cols-2 gap-2 {{ $compact ? 'max-h-56 overflow-y-auto pl-1' : '' }}">
            @foreach($enabled as $key)
                @php $p = $presets[$key] ?? null; @endphp
                @if($p)
                <button type="button" data-theme-btn="{{ $key }}"
                    class="zed-menu-item flex items-center gap-2 px-3 py-2 text-xs font-medium border rounded-lg text-right transition
                        {{ $current === $key
                            ? 'border-indigo-500 bg-indigo-600/15 text-white'
                            : 'border-gray-700 text-gray-300 hover:text-white hover:border-gray-600' }}">
                    <span class="flex items-center -space-x-1 shrink-0">
                        @foreach($p['dots'] as $dot)
                            <span class="w-3.5 h-3.5 rounded-full border border-black/30" style="background: {{ $dot }}"></span>
                        @endforeach
                    </span>
                    <span class="truncate">{{ $p['title'] }}</span>
                </button>
                @endif
            @endforeach
        </div>
        <p class="text-[11px] text-gray-500">انتخاب شما بلافاصله اعمال و ذخیره می‌شود.</p>
    </div>
    @endif
</div>

@once
@push('scripts')
<script>
(function () {
    function post(payload) {
        var token = document.querySelector('meta[name="csrf-token"]');
        return fetch(@json(route('theme.update')), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token ? token.content : '',
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
            body: JSON.stringify(payload),
        });
    }
    document.addEventListener('click', function (e) {
        var themeBtn = e.target.closest('[data-theme-btn]');
        var appearBtn = e.target.closest('[data-appearance-btn]');
        if (themeBtn) {
            var key = themeBtn.getAttribute('data-theme-btn');
            document.documentElement.setAttribute('data-theme', key);
            post({ theme: key }).finally(function () { location.reload(); });
        } else if (appearBtn) {
            var mode = appearBtn.getAttribute('data-appearance-btn');
            post({ appearance: mode }).finally(function () { location.reload(); });
        }
    });
})();
</script>
@endpush
@endonce
