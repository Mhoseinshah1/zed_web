@props([
    'padding' => 'p-6',
    'hover'   => false,
    'animate' => false,
])
<div {{ $attributes->class([
    'zed-card',
    $padding,
    'zed-hover-lift' => $hover,
    'zed-animate' => $animate,
]) }}>
    {{ $slot }}
</div>
