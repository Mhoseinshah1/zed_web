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

    {{-- Payment placeholder --}}
    <div class="bg-yellow-500/10 border border-yellow-500/20 rounded-xl p-6 mb-6">
        <div class="flex gap-3">
            <span class="text-2xl">⏳</span>
            <div>
                <h4 class="text-yellow-300 font-semibold mb-1">پرداخت در دسترس نیست</h4>
                <p class="text-yellow-200/70 text-sm leading-6">
                    پرداخت این سفارش در مرحله بعد فعال می‌شود.<br>
                    فعلاً این سفارش فقط برای آماده‌سازی سیستم خرید ثبت شده است.
                </p>
            </div>
        </div>
    </div>

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
