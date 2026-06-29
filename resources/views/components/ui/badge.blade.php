@props([
    'variant' => 'neutral', // neutral | primary | success | warning | danger | info
])
@php
    $variants = [
        'neutral' => 'bg-gray-800 text-gray-300 border-gray-700',
        'primary' => 'bg-indigo-600/15 text-indigo-300 border-indigo-500/30',
        'success' => 'bg-green-500/10 text-green-300 border-green-500/30',
        'warning' => 'bg-amber-500/10 text-amber-300 border-amber-500/30',
        'danger'  => 'bg-red-500/10 text-red-300 border-red-500/30',
        'info'    => 'bg-sky-500/10 text-sky-300 border-sky-500/30',
    ];
    $classes = 'inline-flex items-center gap-1 px-2.5 py-0.5 text-xs font-medium rounded-full border '
        . ($variants[$variant] ?? $variants['neutral']);
@endphp
<span {{ $attributes->class($classes) }}>{{ $slot }}</span>
