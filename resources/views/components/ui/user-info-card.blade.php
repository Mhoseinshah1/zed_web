@props([
    'user' => null,
])
@php $user = $user ?? auth()->user(); @endphp
@if($user)
<div {{ $attributes->class('zed-card p-5 flex items-center gap-4') }}>
    <div class="w-12 h-12 shrink-0 rounded-full zed-gradient-bg flex items-center justify-center text-white font-bold text-lg">
        {{ mb_substr($user->name ?? $user->username ?? 'U', 0, 1) }}
    </div>
    <div class="min-w-0">
        <p class="text-sm font-semibold text-white truncate">{{ $user->name ?? 'کاربر' }}</p>
        <p class="text-xs text-gray-400 truncate">
            @if($user->account_id)
                شناسه: {{ $user->account_id }}
            @endif
        </p>
    </div>
    @if(isset($trailing))
        <div class="mr-auto">{{ $trailing }}</div>
    @endif
</div>
@endif
