@extends('layouts.panel')

@section('title', 'تیکت‌های پشتیبانی')

@php
    $statusColors = [
        'open'          => 'bg-blue-500/10 text-blue-300 border-blue-500/30',
        'waiting_user'  => 'bg-amber-500/10 text-amber-300 border-amber-500/30',
        'waiting_admin' => 'bg-indigo-500/10 text-indigo-300 border-indigo-500/30',
        'answered'      => 'bg-green-500/10 text-green-300 border-green-500/30',
        'closed'        => 'bg-gray-500/10 text-gray-400 border-gray-500/30',
    ];
@endphp

@section('content')
<div class="max-w-3xl space-y-6">

    <div class="flex items-center justify-between gap-4">
        <h1 class="text-xl font-bold text-white">تیکت‌های پشتیبانی</h1>
        <a href="{{ route('dashboard.tickets.create') }}"
           class="bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-4 py-2.5 rounded-lg transition">
            ایجاد تیکت جدید
        </a>
    </div>

    @if(session('success'))
    <div class="bg-green-500/10 border border-green-500/30 rounded-xl px-5 py-3 text-sm text-green-300">{{ session('success') }}</div>
    @endif

    @forelse($tickets as $ticket)
    <a href="{{ route('dashboard.tickets.show', $ticket) }}"
       class="block bg-gray-900 border {{ $ticket->user_unread ? 'border-indigo-500/40' : 'border-gray-800' }} rounded-xl p-4 hover:border-gray-700 transition">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    @if($ticket->user_unread)<span class="w-2 h-2 rounded-full bg-indigo-400 shrink-0"></span>@endif
                    <h3 class="text-sm font-semibold text-white truncate">{{ $ticket->subject }}</h3>
                </div>
                <div class="flex items-center gap-3 mt-1.5 text-xs text-gray-500 flex-wrap">
                    <span class="font-mono tracking-wide text-gray-400">{{ $ticket->ticket_number }}</span>
                    @if($ticket->category)<span>دسته: {{ $ticket->category->name }}</span>@endif
                    <span>اولویت: {{ $ticket->priorityLabel() }}</span>
                    @if($ticket->last_reply_at)<span>{{ $ticket->last_reply_at->diffForHumans() }}</span>@endif
                </div>
            </div>
            <span class="shrink-0 inline-block border text-xs px-3 py-1 rounded-full {{ $statusColors[$ticket->status] ?? '' }}">
                {{ $ticket->statusLabel() }}
            </span>
        </div>
    </a>
    @empty
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-10 text-center">
        <div class="text-4xl mb-3">🎫</div>
        <p class="text-sm text-gray-400">هنوز تیکتی ثبت نکرده‌اید.</p>
    </div>
    @endforelse

    @if($tickets->hasPages())
    <div>{{ $tickets->links() }}</div>
    @endif
</div>
@endsection
