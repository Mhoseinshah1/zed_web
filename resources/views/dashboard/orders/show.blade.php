@extends('layouts.panel')

@section('title', 'جزئیات سفارش')

@section('content')
<div class="max-w-2xl">
    {{-- Header --}}
    <div class="flex items-center gap-4 mb-6">
        <a href="{{ route('dashboard.orders') }}" class="text-gray-400 hover:text-white transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
        </a>
        <div>
            <h2 class="text-white font-semibold">جزئیات سفارش</h2>
            <p class="text-xs text-gray-500 mt-0.5 font-mono">{{ $order->order_number }}</p>
        </div>
    </div>

    {{-- Order details card --}}
    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden mb-6">
        <div class="p-6 border-b border-gray-800">
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="text-white font-semibold text-lg">{{ $order->plan_name }}</h3>
                    <p class="text-gray-400 text-sm mt-1">{{ $order->durationLabel() }} — {{ $order->trafficLabel() }}</p>
                </div>
                <div class="text-left">
                    @php
                        $statusColor = match($order->status) {
                            'completed'          => 'bg-green-500/10 text-green-400 border-green-500/30',
                            'cancelled','failed' => 'bg-red-500/10 text-red-400 border-red-500/30',
                            'paid','processing'  => 'bg-blue-500/10 text-blue-400 border-blue-500/30',
                            default              => 'bg-yellow-500/10 text-yellow-400 border-yellow-500/30',
                        };
                    @endphp
                    <span class="inline-block border text-xs px-3 py-1 rounded-full {{ $statusColor }}">
                        {{ $order->statusLabel() }}
                    </span>
                </div>
            </div>
        </div>

        <div class="p-6 space-y-4">
            <div class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <span class="text-gray-400 block mb-1">شماره سفارش</span>
                    <span class="text-white font-mono text-xs">{{ $order->order_number }}</span>
                </div>
                <div>
                    <span class="text-gray-400 block mb-1">تاریخ ثبت</span>
                    <span class="text-white">{{ $order->created_at->format('Y/m/d H:i') }}</span>
                </div>
                <div>
                    <span class="text-gray-400 block mb-1">مبلغ نهایی</span>
                    <span class="text-white text-lg font-semibold">{{ number_format($order->final_price_toman) }} تومان</span>
                </div>
                @if($order->discount_toman > 0)
                <div>
                    <span class="text-gray-400 block mb-1">تخفیف</span>
                    <span class="text-green-400">{{ number_format($order->discount_toman) }} تومان</span>
                </div>
                @endif
                <div>
                    <span class="text-gray-400 block mb-1">وضعیت سفارش</span>
                    <span class="text-white">{{ $order->statusLabel() }}</span>
                </div>
                <div>
                    <span class="text-gray-400 block mb-1">وضعیت پرداخت</span>
                    @php
                        $payColor = match($order->payment_status) {
                            'paid'   => 'text-green-400',
                            'failed' => 'text-red-400',
                            default  => 'text-yellow-400',
                        };
                    @endphp
                    <span class="{{ $payColor }}">{{ $order->paymentStatusLabel() }}</span>
                </div>
                @if($order->traffic_gb)
                <div>
                    <span class="text-gray-400 block mb-1">حجم</span>
                    <span class="text-white">{{ $order->trafficLabel() }}</span>
                </div>
                @endif
                @if($order->duration_days)
                <div>
                    <span class="text-gray-400 block mb-1">مدت اعتبار</span>
                    <span class="text-white">{{ $order->durationLabel() }}</span>
                </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Payment action --}}
    @if(in_array($order->payment_status, ['unpaid', 'pending']) && ! in_array($order->status, ['cancelled', 'failed', 'completed']))
    <div class="bg-indigo-500/10 border border-indigo-500/20 rounded-xl p-6 mb-6">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h4 class="text-indigo-300 font-semibold mb-1">پرداخت سفارش</h4>
                <p class="text-indigo-200/70 text-sm">برای فعال‌سازی سرویس، سفارش خود را پرداخت کنید.</p>
            </div>
            <a href="{{ route('dashboard.orders.pay', $order) }}"
               class="shrink-0 bg-indigo-600 hover:bg-indigo-500 text-white font-semibold px-6 py-2.5 rounded-xl transition text-sm">
                پرداخت
            </a>
        </div>
    </div>
    @elseif($order->payment_status === 'paid')
    <div class="bg-green-500/10 border border-green-500/20 rounded-xl p-6 mb-6">
        <div class="flex gap-3">
            <span class="text-2xl">✅</span>
            <div>
                <h4 class="text-green-300 font-semibold mb-1">پرداخت تایید شده</h4>
                <p class="text-green-200/70 text-sm">پرداخت این سفارش با موفقیت انجام شده است.</p>
            </div>
        </div>
    </div>
    @elseif($order->payment_status === 'pending')
    <div class="bg-yellow-500/10 border border-yellow-500/20 rounded-xl p-6 mb-6">
        <div class="flex gap-3">
            <span class="text-2xl">⏳</span>
            <div>
                <h4 class="text-yellow-300 font-semibold mb-1">در انتظار تایید پرداخت</h4>
                <p class="text-yellow-200/70 text-sm">اطلاعات پرداخت شما ارسال شده و منتظر بررسی توسط پشتیبانی است.</p>
            </div>
        </div>
    </div>
    @endif

    {{-- Service link --}}
    @if($order->service)
    <div class="bg-indigo-500/10 border border-indigo-500/20 rounded-xl p-5 mb-6">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h4 class="text-indigo-300 font-semibold text-sm mb-1">سرویس مرتبط</h4>
                <p class="text-indigo-200/70 text-sm">
                    {{ $order->service->statusLabel() }}
                    @if($order->service->status === 'pending_provision')
                        — سرویس شما در حال آماده‌سازی است.
                    @endif
                </p>
            </div>
            <a href="{{ route('dashboard.services.show', $order->service) }}"
               class="shrink-0 text-xs bg-indigo-600 hover:bg-indigo-500 text-white px-4 py-2 rounded-lg transition">
                مشاهده سرویس
            </a>
        </div>
    </div>
    @elseif($order->payment_status === 'paid')
    <div class="bg-blue-500/10 border border-blue-500/20 rounded-xl p-5 mb-6">
        <div class="flex gap-3">
            <span class="text-xl">🔄</span>
            <div>
                <h4 class="text-blue-300 font-semibold text-sm mb-1">سرویس در حال آماده‌سازی</h4>
                <p class="text-blue-200/70 text-sm">پرداخت تایید شده است. سرویس شما به‌زودی آماده می‌شود.</p>
            </div>
        </div>
    </div>
    @endif

    {{-- Notes --}}
    @if($order->notes)
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5 mb-6">
        <h4 class="text-white font-medium mb-2">یادداشت</h4>
        <p class="text-gray-400 text-sm">{{ $order->notes }}</p>
    </div>
    @endif

    <a href="{{ route('dashboard.orders') }}"
       class="inline-flex items-center gap-2 bg-gray-800 hover:bg-gray-700 text-white text-sm font-medium px-5 py-2.5 rounded-lg transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
        </svg>
        بازگشت به سفارش‌ها
    </a>
</div>
@endsection
