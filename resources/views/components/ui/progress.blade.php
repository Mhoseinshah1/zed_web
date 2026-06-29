@props([
    'value' => 0,   // 0..100
    'tone'  => 'primary', // primary | success | warning | danger
])
@php
    $pct = max(0, min(100, (float) $value));
    $tones = [
        'primary' => 'var(--zed-gradient)',
        'success' => 'var(--zed-success)',
        'warning' => 'var(--zed-warning)',
        'danger'  => 'var(--zed-danger)',
    ];
    $fill = $tones[$tone] ?? $tones['primary'];
@endphp
<div {{ $attributes->class('h-2 w-full rounded-full overflow-hidden bg-gray-800') }}>
    <div class="h-full rounded-full" style="width: {{ $pct }}%; background: {{ $fill }}; transition: width var(--zed-anim) ease;"></div>
</div>
