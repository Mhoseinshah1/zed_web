@extends('layouts.panel')

@section('title', 'داشبورد')

@section('content')
@php($isPremiumPanel = \App\Services\Theme\TemplateManager::activeTemplate() === 'premium')

@if($isPremiumPanel)
<div class="zpp-dashboard">
    <div class="zpp-dash-head">
        <div>
            <span class="zpp-dash-kicker">ZED ACCOUNT</span>
            <h2>داشبورد من</h2>
            <p>سرویس‌ها، سفارش‌ها و اعتبار حسابت؛ همه در یک نمای سریع.</p>
        </div>
        <a href="{{ route('plans') }}" class="zpp-primary-btn">+ خرید سرویس جدید</a>
    </div>

    <div class="zpp-stat-grid">
        <a href="{{ route('dashboard.services') }}" class="zpp-stat-card">
            <div class="zpp-stat-top">
                <div><small>سرویس فعال</small><strong>{{ $activeServices }}</strong></div>
                <span class="zpp-stat-icon">◉</span>
            </div>
            @if($pendingServices > 0)<small style="color:#fbbf24;margin-top:8px">{{ $pendingServices }} سرویس در انتظار ساخت</small>@endif
        </a>
        <a href="{{ route('dashboard.wallet') }}" class="zpp-stat-card">
            <div class="zpp-stat-top">
                <div><small>موجودی کیف پول</small><strong>{{ number_format($user->wallet_balance_toman) }} <span>تومان</span></strong></div>
                <span class="zpp-stat-icon">◈</span>
            </div>
        </a>
        <a href="{{ route('dashboard.orders') }}" class="zpp-stat-card">
            <div class="zpp-stat-top">
                <div><small>کل سفارش‌ها</small><strong>{{ $user->orders()->count() }}</strong></div>
                <span class="zpp-stat-icon">▤</span>
            </div>
        </a>
        <div class="zpp-stat-card">
            <div class="zpp-stat-top">
                <div><small>پرداخت در انتظار بررسی</small><strong style="{{ $pendingPayments > 0 ? 'color:#fbbf24' : '' }}">{{ $pendingPayments }}</strong></div>
                <span class="zpp-stat-icon">⌛</span>
            </div>
        </div>
    </div>

    <div class="zpp-quick-grid">
        <a href="{{ route('plans') }}" class="zpp-quick-card is-primary"><span class="zpp-quick-icon">⚡</span><span>خرید VPN</span></a>
        <a href="{{ route('dashboard.services') }}" class="zpp-quick-card"><span class="zpp-quick-icon">◉</span><span>سرویس‌های من</span></a>
        <a href="{{ route('dashboard.orders') }}" class="zpp-quick-card"><span class="zpp-quick-icon">▤</span><span>سفارش‌های من</span></a>
        <a href="{{ route('dashboard.wallet') }}" class="zpp-quick-card"><span class="zpp-quick-icon">◈</span><span>کیف پول</span></a>
    </div>

    <div class="zpp-main-grid">
        <section class="zpp-panel-card">
            <div class="zpp-panel-head">
                <h3>سرویس‌های فعال</h3>
                <a href="{{ route('dashboard.services') }}">مشاهده همه ←</a>
            </div>
            <div class="zpp-panel-body">
                @if($latestServices->isEmpty())
                    <div class="zpp-empty">
                        <div>
                            <div class="icon">◉</div>
                            <p>هنوز سرویس فعالی نداری.</p>
                            <a href="{{ route('plans') }}" class="zpp-primary-btn" style="margin-top:12px">خرید سرویس</a>
                        </div>
                    </div>
                @else
                    @foreach($latestServices as $service)
                        @php
                            $totalTraffic = max(0, (int) ($service->traffic_total_gb ?? 0));
                            $usedTraffic = max(0, (int) ($service->traffic_used_gb ?? 0));
                            $trafficPercent = $totalTraffic > 0 ? min(100, (int) round(($usedTraffic / $totalTraffic) * 100)) : 0;
                            $isActive = $service->status === 'active';
                        @endphp
                        <article class="zpp-service-card">
                            <div class="zpp-service-row">
                                <div>
                                    <div class="zpp-service-name">{{ $service->plan_name ?? $service->name ?? 'سرویس VPN' }}</div>
                                    <div class="zpp-service-id">{{ $service->service_number }}</div>
                                </div>
                                <span class="zpp-badge {{ $isActive ? '' : 'warning' }}">● {{ $service->statusLabel() }}</span>
                            </div>

                            @if($totalTraffic > 0)
                                <div class="zpp-progress-meta">
                                    <span>مصرف ترافیک</span>
                                    <span>{{ $usedTraffic }} / {{ $totalTraffic }} GB</span>
                                </div>
                                <div class="zpp-progress"><i style="width:{{ $trafficPercent }}%"></i></div>
                            @endif

                            <div class="zpp-progress-meta" style="margin-top:11px">
                                <span>زمان باقی‌مانده</span>
                                <span>{{ $service->daysRemaining() !== null ? $service->daysRemaining().' روز' : '—' }}</span>
                            </div>

                            <div class="zpp-service-actions">
                                <a href="{{ route('dashboard.services.show', $service) }}" class="zpp-primary-btn" style="min-height:36px;padding:7px 11px">مدیریت سرویس</a>
                                @if($service->subscription_link)
                                    <button type="button" class="zpp-mini-btn" data-zpp-copy="{{ $service->subscription_link }}">کپی Subscription</button>
                                @endif
                            </div>
                        </article>
                    @endforeach
                @endif
            </div>
        </section>

        <section class="zpp-panel-card">
            <div class="zpp-panel-head">
                <h3>آخرین سفارش‌ها</h3>
                <a href="{{ route('dashboard.orders') }}">مشاهده همه ←</a>
            </div>
            <div class="zpp-panel-body">
                @if($orders->isEmpty())
                    <div class="zpp-empty">
                        <div>
                            <div class="icon">▤</div>
                            <p>هنوز سفارشی ثبت نکردی.</p>
                            <a href="{{ route('plans') }}" class="zpp-primary-btn" style="margin-top:12px">مشاهده پلن‌ها</a>
                        </div>
                    </div>
                @else
                    @foreach($orders as $order)
                        <a href="{{ route('dashboard.orders.show', $order) }}" class="zpp-order-row">
                            <div>
                                <b>{{ $order->plan_name }}</b>
                                <small>{{ $order->order_number }}</small>
                            </div>
                            <div class="zpp-order-price">
                                {{ number_format($order->final_price_toman) }} تومان
                                <small style="color:{{ $order->status === 'completed' ? '#34d399' : ($order->status === 'cancelled' ? '#fb7185' : '#fbbf24') }}">{{ $order->statusLabel() }}</small>
                            </div>
                        </a>
                    @endforeach
                @endif
            </div>
        </section>
    </div>
</div>
@else
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
    <div class="bg-surface border border-line rounded-xl p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold text-content">سرویس‌های فعال</h3>
            <a href="{{ route('dashboard.services') }}" class="text-xs text-indigo-400 hover:text-indigo-300">مشاهده همه</a>
        </div>
        @if($latestServices->isEmpty())
            <div class="text-center py-8 text-content-muted">
                <div class="text-4xl mb-3">🔌</div>
                <p class="text-sm">هنوز سرویسی ندارید</p>
                <a href="{{ route('plans') }}" class="inline-block mt-4 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-5 py-2 rounded-lg transition">خرید سرویس</a>
            </div>
        @else
            <div class="space-y-3">
                @foreach($latestServices as $service)
                @php
                    $statusColor = match($service->status) {
                        'active' => 'text-green-400',
                        'pending_provision' => 'text-yellow-400',
                        'disabled' => 'text-orange-400',
                        default => 'text-content-muted',
                    };
                @endphp
                <a href="{{ route('dashboard.services.show', $service) }}" class="flex items-center justify-between bg-surface-soft/50 hover:bg-surface-soft rounded-lg px-4 py-3 transition">
                    <div>
                        <div class="text-sm font-medium text-content">{{ $service->plan_name ?? 'سرویس VPN' }}</div>
                        <div class="text-xs text-content-muted mt-0.5 font-mono">{{ $service->service_number }}</div>
                    </div>
                    <div class="text-left">
                        <div class="text-xs {{ $statusColor }}">{{ $service->statusLabel() }}</div>
                        @if($service->expires_at)<div class="text-xs text-content-muted mt-0.5">{{ $service->expires_at->format('Y/m/d') }}</div>@endif
                    </div>
                </a>
                @endforeach
            </div>
        @endif
    </div>

    <div class="bg-surface border border-line rounded-xl p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold text-content">آخرین سفارش‌ها</h3>
            <a href="{{ route('dashboard.orders') }}" class="text-xs text-indigo-400 hover:text-indigo-300">مشاهده همه</a>
        </div>
        @if($orders->isEmpty())
            <div class="text-center py-8 text-content-muted">
                <div class="text-4xl mb-3">🛒</div>
                <p class="text-sm">هنوز سفارشی ثبت نشده</p>
                <a href="{{ route('plans') }}" class="inline-block mt-4 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-5 py-2 rounded-lg transition">خرید سرویس</a>
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
                        <div class="text-xs mt-0.5 {{ $order->status === 'completed' ? 'text-green-400' : ($order->status === 'cancelled' ? 'text-red-400' : 'text-yellow-400') }}">{{ $order->statusLabel() }}</div>
                    </div>
                </a>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endif
@endsection
