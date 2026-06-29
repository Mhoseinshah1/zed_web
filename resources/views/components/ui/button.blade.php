@props([
    'variant' => 'primary', // primary | secondary | ghost | danger
    'href'    => null,
    'type'    => 'button',
])
@php
    $base = 'zed-btn inline-flex items-center justify-center gap-2 px-4 py-2.5 text-sm font-semibold';
    $variants = [
        'primary'   => 'zed-btn-primary',
        'secondary' => 'bg-gray-800 text-white border border-gray-700',
        'ghost'     => 'bg-transparent text-gray-300 border border-gray-700 hover:text-white',
        'danger'    => 'bg-red-600 text-white',
    ];
    $classes = $base . ' ' . ($variants[$variant] ?? $variants['primary']);
@endphp
@if($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>{{ $slot }}</button>
@endif
