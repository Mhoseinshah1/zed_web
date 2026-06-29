@extends('layouts.panel')

@section('title', 'اعلان‌ها')

@section('content')
<div class="max-w-3xl space-y-6">

    {{-- ── Header ── --}}
    <div class="flex items-center justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold text-white">اعلان‌ها</h1>
            <p class="text-sm text-gray-400 mt-0.5">
                @if($unreadCount > 0)
                    {{ number_format($unreadCount) }} اعلان خوانده‌نشده
                @else
                    همه اعلان‌ها خوانده شده‌اند
                @endif
            </p>
        </div>

        @if($unreadCount > 0)
        <form method="POST" action="{{ route('dashboard.notifications.read-all') }}">
            @csrf
            <button type="submit"
                    class="text-xs bg-gray-800 hover:bg-gray-700 text-gray-200 px-4 py-2 rounded-lg transition">
                علامت‌گذاری همه به‌عنوان خوانده‌شده
            </button>
        </form>
        @endif
    </div>

    {{-- ── List ── --}}
    @forelse($notifications as $notification)
    @php $unread = ! $notification->isRead(); @endphp
    <div class="rounded-xl border p-4 transition
                {{ $unread ? 'bg-indigo-500/5 border-indigo-500/30' : 'bg-gray-900 border-gray-800' }}">
        <div class="flex items-start justify-between gap-3">
            <div class="flex items-start gap-3 min-w-0">
                @if($unread)
                <span class="mt-1.5 w-2 h-2 rounded-full bg-indigo-400 shrink-0"></span>
                @else
                <span class="mt-1.5 w-2 h-2 rounded-full bg-gray-700 shrink-0"></span>
                @endif
                <div class="min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <h3 class="text-sm font-semibold {{ $unread ? 'text-white' : 'text-gray-300' }}">
                            {{ $notification->title }}
                        </h3>
                        <span class="text-[10px] px-2 py-0.5 rounded-full bg-gray-800 text-gray-400">
                            {{ $notification->typeLabel() }}
                        </span>
                    </div>
                    <p class="text-sm text-gray-400 mt-1 leading-6">{{ $notification->message }}</p>
                    <p class="text-[11px] text-gray-600 mt-2">{{ $notification->created_at->format('Y/m/d H:i') }}</p>
                </div>
            </div>

            @if($unread)
            <form method="POST" action="{{ route('dashboard.notifications.read', $notification) }}" class="shrink-0">
                @csrf
                <button type="submit" class="text-xs text-indigo-400 hover:text-indigo-300 transition whitespace-nowrap">
                    خوانده شد
                </button>
            </form>
            @endif
        </div>
    </div>
    @empty
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-10 text-center">
        <div class="text-4xl mb-3">🔔</div>
        <p class="text-sm text-gray-400">اعلانی برای نمایش وجود ندارد.</p>
    </div>
    @endforelse

    @if($notifications->hasPages())
    <div>{{ $notifications->links() }}</div>
    @endif

</div>
@endsection
