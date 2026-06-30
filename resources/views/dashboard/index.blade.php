@extends('layouts.panel')

@section('title', 'داشبورد')

@section('content')
{{-- Stats --}}
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
    <div class="bg-surface border border-line rounded-xl p-5">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-content-muted text-sm">سفارش‌ها</p>
                <p class="text-2xl font-bold text-content mt-1">{{ $user->orders()->count() }}</p>
            </div>
            <span class="text-3xl">📋</span>
        </div>
    </div>
    <div class="bg-surface border border-line rounded-xl p-5">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-content-muted text-sm">سرویس فعال</p>
                <p class="text-2xl font-bold {{ $activeServices > 0 ? 'text-green-400' : 'text-content' }} mt-1">{{ $activeServices }}</p>
                @if($pendingServices > 0)
                <p class="text-xs text-yellow-500 mt-0.5">{{ $pendingServices }} در انتظار ساخت</p>
                @endif
            </div>
            <span class="text-3xl">🔌</span>
        </div>
    </div>
    <a href="{{ route('dashboard.wallet') }}" class="bg-surface border border-line hover:border-indigo-500/50 rounded-xl p-5 transition">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-content-muted text-sm">موجودی کیف پول</p>
                <p class="text-2xl font-bold text-content mt-1">{{ number_format($user->wallet_balance_toman) }} <span class="text-sm font-normal text-content-muted">تومان</span></p>
            </div>
            <span class="text-3xl">💰</span>
        </div>
    </a>
    <div class="bg-surface border border-line rounded-xl p-5">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-content-muted text-sm">پرداخت در انتظار بررسی</p>
                <p class="text-2xl font-bold {{ $pendingPayments > 0 ? 'text-yellow-400' : 'text-content' }} mt-1">{{ $pendingPayments }}</p>
            </div>
            <span class="text-3xl">⏳</span>
        </div>
    </div>
</div>

{{-- Quick links --}}
<div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8">
    <a href="{{ route('plans') }}" class="bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl p-4 text-center transition">
        <div class="text-2xl mb-2">🛒</div>
        <div class="text-sm font-medium">خرید VPN</div>
    </a>
    <a href="{{ route('dashboard.services') }}" class="bg-surface border border-line hover:border-line text-content rounded-xl p-4 text-center transition">
        <div class="text-2xl mb-2">🔌</div>
        <div class="text-sm font-medium">سرویس‌های من</div>
    </a>
    <a href="{{ route('dashboard.orders') }}" class="bg-surface border border-line hover:border-line text-content rounded-xl p-4 text-center transition">
        <div class="text-2xl mb-2">📋</div>
        <div class="text-sm font-medium">سفارش‌های من</div>
    </a>
    <a href="{{ route('dashboard.wallet') }}" class="bg-surface border border-line hover:border-line text-content rounded-xl p-4 text-center transition">
        <div class="text-2xl mb-2">💰</div>
        <div class="text-sm font-medium">کیف پول</div>
    </a>
</div>

{{-- Main content --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    {{-- Active services --}}
    <div class="bg-surface border border-line rounded-xl p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold text-content">سرویس‌های فعال</h3>
            <a href="{{ route('dashboard.services') }}" class="text-xs text-indigo-400 hover:text-indigo-300">مشاهده همه</a>
        </div>
        @if($latestServices->isEmpty())
            <div class="text-center py-8 text-content-muted">
                <div class="text-4xl mb-3">🔌</div>
                <p class="text-sm">هنوز سرویسی ندارید</p>
                <a href="{{ route('plans') }}" class="inline-block mt-4 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-5 py-2 rounded-lg transition">
                    خرید سرویس
                </a>
            </div>
        @else
            <div class="space-y-3">
                @foreach($latestServices as $service)
                @php
                    $statusColor = match($service->status) {
                        'active'            => 'text-green-400',
                        'pending_provision' => 'text-yellow-400',
                        'disabled'          => 'text-orange-400',
                        default             => 'text-content-muted',
                    };
                @endphp
                <a href="{{ route('dashboard.services.show', $service) }}" class="flex items-center justify-between bg-surface-soft/50 hover:bg-surface-soft rounded-lg px-4 py-3 transition">
                    <div>
                        <div class="text-sm font-medium text-content">{{ $service->plan_name ?? 'سرویس VPN' }}</div>
                        <div class="text-xs text-content-muted mt-0.5 font-mono">{{ $service->service_number }}</div>
                    </div>
                    <div class="text-left">
                        <div class="text-xs {{ $statusColor }}">{{ $service->statusLabel() }}</div>
                        @if($service->expires_at)
                        <div class="text-xs text-content-muted mt-0.5">{{ $service->expires_at->format('Y/m/d') }}</div>
                        @endif
                    </div>
                </a>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Latest orders --}}
    <div class="bg-surface border border-line rounded-xl p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold text-content">آخرین سفارش‌ها</h3>
            <a href="{{ route('dashboard.orders') }}" class="text-xs text-indigo-400 hover:text-indigo-300">مشاهده همه</a>
        </div>
        @if($orders->isEmpty())
            <div class="text-center py-8 text-content-muted">
                <div class="text-4xl mb-3">🛒</div>
                <p class="text-sm">هنوز سفارشی ثبت نشده</p>
                <a href="{{ route('plans') }}" class="inline-block mt-4 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-5 py-2 rounded-lg transition">
                    خرید سرویس
                </a>
            </div>
        @else
            <div class="space-y-3">
                @foreach($orders as $order)
                <a href="{{ route('dashboard.orders.show', $order) }}" class="flex items-center justify-between bg-surface-soft/50 hover:bg-surface-soft rounded-lg px-4 py-3 transition">
                    <div>
                        <div class="text-sm font-medium text-content">{{ $order->plan_name }}</div>
                        <div class="text-xs text-content-muted mt-0.5">{{ $order->order_number }}</div>
                    </div>
                    <div class="text-left">
                        <div class="text-sm text-content">{{ number_format($order->final_price_toman) }} تومان</div>
                        <div class="text-xs mt-0.5 {{ $order->status === 'completed' ? 'text-green-400' : ($order->status === 'cancelled' ? 'text-red-400' : 'text-yellow-400') }}">
                            {{ $order->statusLabel() }}
                        </div>
                    </div>
                </a>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endsection
