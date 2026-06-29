@extends('layouts.panel')

@section('title', 'تیکت ' . $ticket->ticket_number)

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

    {{-- Header --}}
    <div class="flex items-start justify-between gap-4">
        <div class="flex items-start gap-4">
            <a href="{{ route('dashboard.tickets') }}" class="text-gray-400 hover:text-white transition mt-1">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            </a>
            <div>
                <h1 class="text-lg font-bold text-white">{{ $ticket->subject }}</h1>
                <div class="flex items-center gap-3 mt-1 text-xs text-gray-500 flex-wrap">
                    <span class="font-mono tracking-wide text-gray-400">شماره تیکت: {{ $ticket->ticket_number }}</span>
                    @if($ticket->category)<span>دسته: {{ $ticket->category->name }}</span>@endif
                    <span>اولویت: {{ $ticket->priorityLabel() }}</span>
                </div>
            </div>
        </div>
        <span class="shrink-0 inline-block border text-xs px-3 py-1 rounded-full {{ $statusColors[$ticket->status] ?? '' }}">
            {{ $ticket->statusLabel() }}
        </span>
    </div>

    @if(session('success'))
    <div class="bg-green-500/10 border border-green-500/30 rounded-xl px-5 py-3 text-sm text-green-300">{{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div class="bg-red-500/10 border border-red-500/30 rounded-xl px-5 py-3 text-sm text-red-300">{{ session('error') }}</div>
    @endif

    @if($ticket->order || $ticket->userService)
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-4 text-xs text-gray-400 flex gap-4 flex-wrap">
        @if($ticket->order)<span>سفارش مرتبط: <span class="text-gray-200 font-mono">{{ $ticket->order->order_number }}</span></span>@endif
        @if($ticket->userService)<span>سرویس مرتبط: <span class="text-gray-200 font-mono">{{ $ticket->userService->service_number }}</span></span>@endif
    </div>
    @endif

    {{-- Conversation (public messages only) --}}
    <div class="space-y-4">
        @foreach($ticket->publicMessages as $message)
        @php $fromAdmin = $message->is_admin; @endphp
        <div class="flex {{ $fromAdmin ? 'justify-start' : 'justify-end' }}">
            <div class="max-w-[85%] rounded-xl p-4 border
                        {{ $fromAdmin ? 'bg-indigo-500/10 border-indigo-500/30' : 'bg-gray-900 border-gray-800' }}">
                <div class="flex items-center gap-2 mb-1.5">
                    <span class="text-xs font-semibold {{ $fromAdmin ? 'text-indigo-300' : 'text-gray-300' }}">
                        {{ $fromAdmin ? 'پشتیبانی' : 'شما' }}
                    </span>
                    <span class="text-[11px] text-gray-600">{{ $message->created_at->format('Y/m/d H:i') }}</span>
                </div>
                <p class="text-sm text-gray-200 leading-7 whitespace-pre-line">{{ $message->body }}</p>
                @if($message->hasAttachment())
                <a href="{{ asset('storage/' . $message->attachment_path) }}" target="_blank"
                   class="inline-flex items-center gap-1 mt-2 text-xs text-indigo-400 hover:text-indigo-300">
                    📎 {{ $message->attachment_name ?? 'پیوست' }}
                </a>
                @endif
            </div>
        </div>
        @endforeach
    </div>

    {{-- Reply / close --}}
    @if($ticket->isClosed())
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5 text-center text-sm text-gray-500">
        این تیکت بسته شده است.
    </div>
    @else
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5 space-y-3">
        <form method="POST" action="{{ route('dashboard.tickets.reply', $ticket) }}" enctype="multipart/form-data" class="space-y-3">
            @csrf
            <textarea name="body" rows="4" required maxlength="5000" placeholder="پاسخ خود را بنویسید..."
                      class="w-full bg-gray-800 border border-gray-700 focus:border-indigo-500 rounded-lg px-4 py-2.5 text-white text-sm outline-none">{{ old('body') }}</textarea>
            <input type="file" name="attachment"
                   class="w-full text-sm text-gray-400 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-gray-800 file:text-gray-200">
            <div class="flex gap-3">
                <button type="submit" class="bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-6 py-2.5 rounded-lg transition">
                    ارسال پاسخ
                </button>
            </div>
        </form>

        <form method="POST" action="{{ route('dashboard.tickets.close', $ticket) }}"
              onsubmit="return confirm('آیا از بستن این تیکت مطمئن هستید؟');">
            @csrf
            <button type="submit" class="text-xs text-red-400 hover:text-red-300 transition">بستن تیکت</button>
        </form>
    </div>
    @endif
</div>
@endsection
