@extends('layouts.panel')

@section('title', 'جزئیات سرویس')

@section('content')
<div class="max-w-2xl">
    {{-- Header --}}
    <div class="flex items-center gap-4 mb-6">
        <a href="{{ route('dashboard.services') }}" class="text-gray-400 hover:text-white transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
        </a>
        <div>
            <h2 class="text-white font-semibold">جزئیات سرویس</h2>
            <p class="text-xs text-gray-500 mt-0.5 font-mono">{{ $service->service_number }}</p>
        </div>
    </div>

    {{-- Status banner --}}
    @if($service->status === 'pending_provision')
    <div class="bg-yellow-500/10 border border-yellow-500/20 rounded-xl p-5 mb-6">
        <div class="flex gap-3">
            <span class="text-xl">⏳</span>
            <div>
                <h4 class="text-yellow-300 font-semibold text-sm mb-1">در انتظار آماده‌سازی</h4>
                <p class="text-yellow-200/70 text-sm leading-6">
                    سرویس شما در انتظار آماده‌سازی است. پس از تایید مدیریت، اطلاعات اتصال اینجا نمایش داده می‌شود.
                </p>
            </div>
        </div>
    </div>
    @elseif($service->status === 'active')
    <div class="bg-green-500/10 border border-green-500/20 rounded-xl p-5 mb-6">
        <div class="flex gap-3">
            <span class="text-xl">✅</span>
            <div>
                <h4 class="text-green-300 font-semibold text-sm mb-1">سرویس فعال است</h4>
                <p class="text-green-200/70 text-sm">سرویس VPN شما فعال است و می‌توانید از آن استفاده کنید.</p>
            </div>
        </div>
    </div>
    @elseif($service->status === 'disabled')
    <div class="bg-orange-500/10 border border-orange-500/20 rounded-xl p-5 mb-6">
        <div class="flex gap-3">
            <span class="text-xl">⛔</span>
            <div>
                <h4 class="text-orange-300 font-semibold text-sm mb-1">سرویس غیرفعال است</h4>
                <p class="text-orange-200/70 text-sm">این سرویس توسط مدیریت غیرفعال شده است. با پشتیبانی تماس بگیرید.</p>
            </div>
        </div>
    </div>
    @elseif($service->status === 'expired')
    <div class="bg-gray-500/10 border border-gray-500/20 rounded-xl p-5 mb-6">
        <div class="flex gap-3">
            <span class="text-xl">🕐</span>
            <div>
                <h4 class="text-gray-300 font-semibold text-sm mb-1">سرویس منقضی شده</h4>
                <p class="text-gray-400 text-sm">مدت اعتبار این سرویس به پایان رسیده است.</p>
            </div>
        </div>
    </div>
    @endif

    {{-- Service info --}}
    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden mb-6">
        <div class="p-6 border-b border-gray-800">
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="text-white font-semibold text-lg">{{ $service->plan_name ?? 'سرویس VPN' }}</h3>
                </div>
                @php
                    $statusColor = match($service->status) {
                        'active'            => 'bg-green-500/10 text-green-400 border-green-500/30',
                        'pending_provision' => 'bg-yellow-500/10 text-yellow-400 border-yellow-500/30',
                        'disabled'          => 'bg-orange-500/10 text-orange-400 border-orange-500/30',
                        'expired'           => 'bg-gray-500/10 text-gray-400 border-gray-500/30',
                        default             => 'bg-red-500/10 text-red-400 border-red-500/30',
                    };
                @endphp
                <span class="inline-block border text-xs px-3 py-1 rounded-full {{ $statusColor }}">
                    {{ $service->statusLabel() }}
                </span>
            </div>
        </div>

        <div class="p-6">
            <div class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <span class="text-gray-400 block mb-1">شماره سرویس</span>
                    <span class="text-white font-mono text-xs">{{ $service->service_number }}</span>
                </div>
                <div>
                    <span class="text-gray-400 block mb-1">وضعیت ساخت</span>
                    <span class="text-white">{{ $service->provisionStatusLabel() }}</span>
                </div>
                @if($service->traffic_total_gb)
                <div>
                    <span class="text-gray-400 block mb-1">حجم کل</span>
                    <span class="text-white">{{ $service->traffic_total_gb }} GB</span>
                </div>
                <div>
                    <span class="text-gray-400 block mb-1">مصرف شده</span>
                    <span class="text-white">{{ $service->traffic_used_gb ?? 0 }} GB</span>
                </div>
                <div>
                    <span class="text-gray-400 block mb-1">باقی‌مانده</span>
                    @php $remaining = $service->trafficRemainingGb(); @endphp
                    <span class="{{ $remaining !== null && $remaining < 5 ? 'text-red-400' : 'text-white' }}">
                        {{ $remaining ?? '—' }} GB
                    </span>
                </div>
                @else
                <div>
                    <span class="text-gray-400 block mb-1">حجم</span>
                    <span class="text-white">نامحدود</span>
                </div>
                @endif
                @if($service->duration_days)
                <div>
                    <span class="text-gray-400 block mb-1">مدت اعتبار</span>
                    <span class="text-white">{{ $service->duration_days }} روز</span>
                </div>
                @endif
                @if($service->starts_at)
                <div>
                    <span class="text-gray-400 block mb-1">تاریخ شروع</span>
                    <span class="text-white">{{ $service->starts_at->format('Y/m/d') }}</span>
                </div>
                @endif
                @if($service->expires_at)
                <div>
                    <span class="text-gray-400 block mb-1">تاریخ انقضا</span>
                    @php $days = $service->daysRemaining(); @endphp
                    <span class="{{ $days !== null && $days <= 3 ? 'text-red-400' : 'text-white' }}">
                        {{ $service->expires_at->format('Y/m/d') }}
                        @if($days !== null)
                            <span class="text-xs text-gray-500 mr-1">({{ $days }} روز مانده)</span>
                        @endif
                    </span>
                </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Config link --}}
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-6 mb-4">
        <h4 class="text-white font-medium text-sm mb-3">لینک کانفیگ</h4>
        @if($service->config_link)
        <div class="bg-gray-800 rounded-lg p-3 flex items-center gap-3">
            <code class="flex-1 text-xs text-gray-300 break-all font-mono leading-5">{{ $service->config_link }}</code>
            <button onclick="navigator.clipboard.writeText('{{ $service->config_link }}').then(() => this.textContent='✓').catch(() => {})"
                    class="shrink-0 text-xs bg-indigo-600 hover:bg-indigo-500 text-white px-3 py-1.5 rounded-lg transition">
                کپی
            </button>
        </div>
        @else
        <p class="text-gray-500 text-sm">لینک کانفیگ هنوز ثبت نشده است.</p>
        @endif
    </div>

    {{-- Subscription link --}}
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-6 mb-6">
        <h4 class="text-white font-medium text-sm mb-3">لینک اشتراک</h4>
        @if($service->subscription_link)
        <div class="bg-gray-800 rounded-lg p-3 flex items-center gap-3">
            <code class="flex-1 text-xs text-gray-300 break-all font-mono leading-5">{{ $service->subscription_link }}</code>
            <button onclick="navigator.clipboard.writeText('{{ $service->subscription_link }}').then(() => this.textContent='✓').catch(() => {})"
                    class="shrink-0 text-xs bg-indigo-600 hover:bg-indigo-500 text-white px-3 py-1.5 rounded-lg transition">
                کپی
            </button>
        </div>
        @else
        <p class="text-gray-500 text-sm">لینک اشتراک هنوز ثبت نشده است.</p>
        @endif
    </div>

    {{-- Placeholder actions --}}
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-6 mb-6">
        <h4 class="text-white font-medium text-sm mb-3">عملیات</h4>
        <div class="flex flex-wrap gap-3">
            <button disabled
                    class="opacity-40 cursor-not-allowed text-xs bg-gray-800 text-white px-4 py-2 rounded-lg">
                تمدید سرویس (به‌زودی)
            </button>
            <button disabled
                    class="opacity-40 cursor-not-allowed text-xs bg-gray-800 text-white px-4 py-2 rounded-lg">
                خرید حجم اضافه (به‌زودی)
            </button>
        </div>
        <p class="text-xs text-gray-600 mt-3">قابلیت تمدید و خرید حجم اضافه در نسخه بعدی فعال می‌شود.</p>
    </div>

    {{-- Order link --}}
    @if($service->order)
    <div class="mb-6">
        <a href="{{ route('dashboard.orders.show', $service->order) }}"
           class="inline-flex items-center gap-2 text-xs text-indigo-400 hover:text-indigo-300 transition">
            مشاهده سفارش مرتبط ({{ $service->order->order_number }}) ←
        </a>
    </div>
    @endif

    <a href="{{ route('dashboard.services') }}"
       class="inline-flex items-center gap-2 bg-gray-800 hover:bg-gray-700 text-white text-sm font-medium px-5 py-2.5 rounded-lg transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
        </svg>
        بازگشت به سرویس‌ها
    </a>
</div>
@endsection
