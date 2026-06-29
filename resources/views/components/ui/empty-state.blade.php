@props([
    'title'   => 'موردی یافت نشد',
    'message' => null,
    'icon'    => null,
])
<div {{ $attributes->class('zed-card p-10 text-center flex flex-col items-center gap-3') }}>
    <div class="w-14 h-14 rounded-2xl flex items-center justify-center bg-gray-800 text-gray-500">
        @if($icon)
            {!! $icon !!}
        @else
            <svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
        @endif
    </div>
    <p class="text-sm font-semibold text-white">{{ $title }}</p>
    @if($message)
        <p class="text-xs text-gray-400 max-w-sm">{{ $message }}</p>
    @endif
    @if(isset($action))
        <div class="mt-2">{{ $action }}</div>
    @endif
</div>
