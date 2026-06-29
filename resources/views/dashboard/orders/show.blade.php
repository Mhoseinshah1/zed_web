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
                            'completed'                            => 'bg-green-500/10 text-green-400 border-green-500/30',
                            'cancelled','failed','provisioning_failed' => 'bg-red-500/10 text-red-400 border-red-500/30',
                            'paid','processing','provisioning'     => 'bg-blue-500/10 text-blue-400 border-blue-500/30',
                            default                                => 'bg-yellow-500/10 text-yellow-400 border-yellow-500/30',
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
                    <span class="text-gray-400 block mb-1">مبلغ قابل پرداخت</span>
                    <span class="text-white text-lg font-semibold">{{ number_format($order->final_price_toman) }} تومان</span>
                    @if($order->discount_toman > 0)
                    <span class="block text-xs text-gray-500 line-through mt-0.5">{{ number_format($order->price_toman) }} تومان</span>
                    @endif
                </div>
                @if($order->discount_toman > 0)
                <div>
                    <span class="text-gray-400 block mb-1">تخفیف ({{ $order->discount_code }})</span>
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

    {{-- Discount code section --}}
    @if(in_array($order->payment_status, ['unpaid']) && ! in_array($order->status, ['cancelled', 'failed', 'completed']))
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5 mb-6" x-data="{ open: {{ $order->discount_toman > 0 ? 'true' : 'false' }} }">
        <button type="button" @click="open = !open"
                class="flex items-center justify-between w-full text-sm text-gray-300 hover:text-white transition">
            <span class="font-medium">کد تخفیف دارید؟</span>
            <svg class="w-4 h-4 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>

        <div x-show="open" x-cloak class="mt-4">
            @if(session('discount_success'))
            <div class="mb-3 bg-green-500/10 border border-green-500/30 rounded-lg p-3 text-sm text-green-300">
                {{ session('discount_success') }}
            </div>
            @endif

            @error('discount_code')
            <div class="mb-3 bg-red-500/10 border border-red-500/30 rounded-lg p-3 text-sm text-red-300">
                {{ $message }}
            </div>
            @enderror

            @if($order->discount_toman > 0)
            {{-- Active discount summary --}}
            <div class="mb-3 bg-green-500/10 border border-green-500/30 rounded-lg p-4">
                <div class="flex items-center justify-between">
                    <div class="text-sm">
                        <span class="text-green-300 font-semibold font-mono">{{ $order->discount_code }}</span>
                        <span class="text-green-200/70 mr-2">
                            — {{ $order->discount_type === 'percent' ? $order->discount_value . '٪ تخفیف' : number_format($order->discount_value) . ' تومان تخفیف' }}
                        </span>
                        <div class="text-green-400 text-xs mt-1">صرفه‌جویی: {{ number_format($order->discount_toman) }} تومان</div>
                    </div>
                    <form method="POST" action="{{ route('dashboard.orders.discount.remove', $order) }}">
                        @csrf @method('DELETE')
                        <button type="submit" class="text-xs text-red-400 hover:text-red-300 transition">
                            حذف کد
                        </button>
                    </form>
                </div>
            </div>
            @else
            {{-- Discount code input --}}
            <form method="POST" action="{{ route('dashboard.orders.discount.apply', $order) }}"
                  class="flex gap-2">
                @csrf
                <input type="text" name="discount_code"
                       value="{{ old('discount_code') }}"
                       placeholder="کد تخفیف را وارد کنید"
                       class="flex-1 bg-gray-800 border border-gray-700 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 rounded-lg px-4 py-2.5 text-white text-sm outline-none transition font-mono uppercase"
                       autocomplete="off" style="text-transform:uppercase">
                <button type="submit"
                        class="px-4 py-2.5 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium rounded-lg transition whitespace-nowrap">
                    اعمال
                </button>
            </form>
            @endif
        </div>
    </div>
    @endif

    {{-- Payment action --}}
    @if(in_array($order->payment_status, ['unpaid', 'pending']) && ! in_array($order->status, ['cancelled', 'failed', 'completed']))
    @php
        $activeCpTx = $order->paymentTransactions()
            ->where('provider', 'centralpay')
            ->whereIn('status', ['pending', 'waiting'])
            ->whereNotNull('gateway_url')
            ->latest()->first();
    @endphp
    <div class="bg-indigo-500/10 border border-indigo-500/20 rounded-xl p-6 mb-6">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h4 class="text-indigo-300 font-semibold mb-1">پرداخت سفارش</h4>
                @if($activeCpTx)
                <p class="text-indigo-200/70 text-sm">پرداخت ریالی شما در حال انجام است. می‌توانید به درگاه بازگردید.</p>
                @else
                <p class="text-indigo-200/70 text-sm">برای فعال‌سازی سرویس، سفارش خود را پرداخت کنید.</p>
                @endif
            </div>
            @if($activeCpTx)
            <a href="{{ $activeCpTx->gateway_url }}" target="_blank"
               class="shrink-0 bg-green-600 hover:bg-green-500 text-white font-semibold px-6 py-2.5 rounded-xl transition text-sm">
                بازگشت به درگاه ↗
            </a>
            @else
            <a href="{{ route('dashboard.orders.pay', $order) }}"
               class="shrink-0 bg-indigo-600 hover:bg-indigo-500 text-white font-semibold px-6 py-2.5 rounded-xl transition text-sm">
                پرداخت
            </a>
            @endif
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

    {{-- Service / provisioning status --}}
    @if($order->service && $order->service->status === 'active')
    <div class="bg-green-500/10 border border-green-500/20 rounded-xl p-5 mb-6">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h4 class="text-green-300 font-semibold text-sm mb-1">سرویس فعال شد</h4>
                <p class="text-green-200/70 text-sm">سرویس شما فعال شد و آماده استفاده است.</p>
            </div>
            <a href="{{ route('dashboard.services.show', $order->service) }}"
               class="shrink-0 text-xs bg-green-700 hover:bg-green-600 text-white px-4 py-2 rounded-lg transition">
                مشاهده سرویس
            </a>
        </div>
    </div>
    @elseif($order->service)
    <div class="bg-indigo-500/10 border border-indigo-500/20 rounded-xl p-5 mb-6">
        <div class="flex items-center justify-between gap-4">
            <div>
                <h4 class="text-indigo-300 font-semibold text-sm mb-1">سرویس مرتبط</h4>
                <p class="text-indigo-200/70 text-sm">
                    @if(in_array($order->status, ['provisioning', 'paid', 'processing']))
                        پرداخت شما تایید شده و سرویس در حال فعال‌سازی است.
                    @elseif($order->status === 'provisioning_failed')
                        پرداخت شما تایید شده اما فعال‌سازی سرویس با خطا مواجه شده است. پشتیبانی در حال بررسی است.
                    @else
                        {{ $order->service->statusLabel() }}
                    @endif
                </p>
            </div>
            <a href="{{ route('dashboard.services.show', $order->service) }}"
               class="shrink-0 text-xs bg-indigo-600 hover:bg-indigo-500 text-white px-4 py-2 rounded-lg transition">
                مشاهده سرویس
            </a>
        </div>
    </div>
    @elseif(in_array($order->status, ['provisioning', 'paid', 'processing']) && $order->payment_status === 'paid')
    <div class="bg-blue-500/10 border border-blue-500/20 rounded-xl p-5 mb-6">
        <div class="flex gap-3">
            <span class="text-xl">🔄</span>
            <div>
                <h4 class="text-blue-300 font-semibold text-sm mb-1">سرویس در حال فعال‌سازی</h4>
                <p class="text-blue-200/70 text-sm">پرداخت شما تایید شده و سرویس در حال فعال‌سازی است.</p>
            </div>
        </div>
    </div>
    @elseif($order->status === 'provisioning_failed')
    <div class="bg-red-500/10 border border-red-500/20 rounded-xl p-5 mb-6">
        <div class="flex gap-3">
            <span class="text-xl">⚠️</span>
            <div>
                <h4 class="text-red-300 font-semibold text-sm mb-1">خطا در فعال‌سازی سرویس</h4>
                <p class="text-red-200/70 text-sm">پرداخت شما تایید شده اما فعال‌سازی سرویس با خطا مواجه شده است. پشتیبانی در حال بررسی است.</p>
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
