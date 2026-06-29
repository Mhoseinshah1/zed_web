@props([
    'label'   => '',
    'value'   => '',
    'icon'    => null,
    'tone'    => 'primary', // primary | success | warning | danger | info
    'hint'    => null,
])
@php
    $tones = [
        'primary' => 'text-indigo-400 bg-indigo-600/15',
        'success' => 'text-green-400 bg-green-500/10',
        'warning' => 'text-amber-400 bg-amber-500/10',
        'danger'  => 'text-red-400 bg-red-500/10',
        'info'    => 'text-sky-400 bg-sky-500/10',
    ];
    $toneClass = $tones[$tone] ?? $tones['primary'];
@endphp
<div {{ $attributes->class('zed-card zed-hover-lift p-5 flex items-start gap-4') }}>
    @if($icon)
        <div class="w-11 h-11 shrink-0 rounded-xl flex items-center justify-center {{ $toneClass }}">
            {!! $icon !!}
        </div>
    @endif
    <div class="min-w-0">
        <p class="text-xs text-gray-400">{{ $label }}</p>
        <p class="text-xl font-bold text-white mt-1 truncate">{{ $value }}</p>
        @if($hint)
            <p class="text-[11px] text-gray-500 mt-0.5">{{ $hint }}</p>
        @endif
    </div>
</div>
