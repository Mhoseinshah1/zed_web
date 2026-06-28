@extends('layouts.panel')

@section('title', 'پرداخت کریپتو')

@section('content')
<div class="max-w-2xl">

    @if(session('success'))
    <div class="mb-4 bg-green-500/10 border border-green-500/30 rounded-lg p-4 text-sm text-green-300">
        {{ session('success') }}
    </div>
    @endif

    @if(session('error'))
    <div class="mb-4 bg-red-500/10 border border-red-500/30 rounded-lg p-4 text-sm text-red-300">
        {{ session('error') }}
    </div>
    @endif

    @if(session('info'))
    <div class="mb-4 bg-blue-500/10 border border-blue-500/30 rounded-lg p-4 text-sm text-blue-300">
        {{ session('info') }}
    </div>
    @endif

    @error('status')
    <div class="mb-4 bg-red-500/10 border border-red-500/30 rounded-lg p-4 text-sm text-red-300">
        {{ $message }}
    </div>
    @enderror

    {{-- Order summary --}}
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-6 mb-6">
        <div class="flex items-center justify-between">
            <div>
                <div class="text-white font-medium">{{ $order->plan_name }}</div>
                <div class="text-gray-400 text-sm mt-0.5">{{ $order->durationLabel() }} — {{ $order->trafficLabel() }}</div>
                <div class="text-xs text-gray-500 font-mono mt-1">{{ $order->order_number }}</div>
            </div>
            <div class="text-right">
                <div class="text-2xl font-bold text-white">{{ number_format($order->final_price_toman) }}</div>
                <div class="text-gray-400 text-xs mt-0.5">تومان</div>
            </div>
        </div>
    </div>

    {{-- Payment status --}}
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-6 mb-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold text-white">وضعیت پرداخت</h3>
            @if($tx->gateway_status)
            @php
                $statusColor = match(strtolower($tx->gateway_status)) {
                    'finished'       => 'text-green-400',
                    'waiting'        => 'text-yellow-400',
                    'confirming', 'confirmed', 'sending' => 'text-blue-400',
                    'partially_paid' => 'text-orange-400',
                    'failed', 'expired', 'refunded' => 'text-red-400',
                    default          => 'text-gray-400',
                };
                $statusLabel = match(strtolower($tx->gateway_status)) {
                    'waiting'        => 'در انتظار واریز',
                    'confirming'     => 'در حال تایید',
                    'confirmed'      => 'تایید شده',
                    'sending'        => 'در حال ارسال',
                    'partially_paid' => 'پرداخت ناقص',
                    'finished'       => 'پرداخت شده ✓',
                    'failed'         => 'ناموفق',
                    'refunded'       => 'بازگشت داده شده',
                    'expired'        => 'منقضی شده',
                    default          => $tx->gateway_status,
                };
            @endphp
            <span class="text-sm font-medium {{ $statusColor }}">{{ $statusLabel }}</span>
            @endif
        </div>

        @if($tx->provider_reference)
        <div class="text-xs text-gray-500 mb-2">
            شناسه فاکتور/پرداخت: <span class="font-mono text-gray-400">{{ $tx->provider_reference }}</span>
        </div>
        @endif

        @if($tx->external_id && $tx->external_id !== $tx->provider_reference)
        <div class="text-xs text-gray-500 mb-2">
            شناسه تراکنش: <span class="font-mono text-gray-400">{{ $tx->external_id }}</span>
        </div>
        @endif

        @if($tx->expires_at)
        <div class="text-xs {{ $tx->expires_at->isPast() ? 'text-red-400' : 'text-gray-500' }}">
            انقضا: {{ $tx->expires_at->format('Y/m/d H:i') }}
            @if($tx->expires_at->isPast()) (منقضی شده) @endif
        </div>
        @endif
    </div>

    {{-- Invoice mode: redirect button if invoice_url available and customer hasn't paid yet --}}
    @if($tx->gateway_url && ! $tx->external_id && ! in_array(strtolower($tx->gateway_status ?? ''), ['finished', 'failed', 'refunded', 'expired']))
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-6 mb-6">
        <div class="flex items-start gap-3 mb-4">
            <div class="w-8 h-8 bg-orange-500/20 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5">
                <svg class="w-4 h-4 text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
            </div>
            <div>
                <p class="text-white font-medium text-sm">پرداخت در NOWPayments</p>
                <p class="text-gray-400 text-xs mt-1">
                    فاکتور شما آماده است. روی دکمه زیر کلیک کنید تا به صفحه پرداخت NOWPayments هدایت شوید و ارز دیجیتال مورد نظر خود را انتخاب کنید.
                </p>
            </div>
        </div>

        <div class="bg-yellow-500/10 border border-yellow-500/30 rounded-lg p-3 text-xs text-yellow-300 mb-4">
            <p>• مبلغ دقیق و ارز مورد نظر را انتخاب کنید و واریز کنید.</p>
            <p class="mt-1">• پس از تایید شبکه، سرویس شما به صورت خودکار فعال می‌شود.</p>
            <p class="mt-1">• پس از پرداخت به این صفحه برگردید و وضعیت را بررسی کنید.</p>
        </div>
    </div>
    @endif

    {{-- Direct mode: crypto payment details (pay_address + QR) --}}
    @if($tx->pay_address)
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-6 mb-6">
        <h3 class="font-semibold text-white mb-5">اطلاعات واریز</h3>

        @if($tx->pay_amount && $tx->pay_currency)
        <div class="bg-orange-500/10 border border-orange-500/30 rounded-xl p-4 mb-5 text-center">
            <div class="text-xs text-orange-300 mb-1">مبلغ دقیق برای واریز</div>
            <div class="text-3xl font-bold text-white font-mono">{{ $tx->pay_amount }}</div>
            <div class="text-orange-300 text-sm font-semibold mt-1">{{ strtoupper($tx->pay_currency) }}</div>
            <p class="text-xs text-orange-400/70 mt-2">مبلغ دقیق فوق را واریز کنید — کمتر یا بیشتر واریز نکنید</p>
        </div>
        @endif

        <div class="mb-5">
            <div class="text-xs text-gray-400 mb-2">آدرس کیف پول</div>
            <div class="flex items-center gap-2" x-data="{ copied: false }">
                <div class="flex-1 bg-gray-800 border border-gray-700 rounded-lg px-4 py-3 font-mono text-sm text-white break-all select-all">{{ $tx->pay_address }}</div>
                <button type="button"
                        @click="navigator.clipboard.writeText('{{ $tx->pay_address }}'); copied = true; setTimeout(() => copied = false, 2000)"
                        class="flex-shrink-0 p-2.5 bg-gray-800 hover:bg-gray-700 border border-gray-700 rounded-lg transition">
                    <svg x-show="!copied" class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                    </svg>
                    <svg x-show="copied" class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                </button>
            </div>
        </div>

        <div class="flex justify-center mb-5">
            <div class="bg-white p-3 rounded-xl">
                {!! \SimpleSoftwareIO\QrCode\Facades\QrCode::format('svg')->size(180)->generate($tx->pay_address) !!}
            </div>
        </div>

        <div class="bg-yellow-500/10 border border-yellow-500/30 rounded-lg p-4 text-xs text-yellow-300 space-y-1.5">
            <p class="font-semibold">نکات مهم:</p>
            <p>• فقط <strong class="text-white">{{ strtoupper($tx->pay_currency ?? '') }}</strong> به این آدرس واریز کنید — سایر ارزها از بین می‌روند.</p>
            <p>• مبلغ دقیق <strong class="text-white">{{ $tx->pay_amount }}</strong> را واریز کنید.</p>
            <p>• پس از واریز، تایید شبکه چند دقیقه تا چند ساعت طول می‌کشد.</p>
            <p>• پس از تایید، سرویس شما به صورت خودکار فعال می‌شود.</p>
        </div>
    </div>
    @endif

    {{-- Primary action: go to NOWPayments invoice page --}}
    @if($tx->gateway_url && ! in_array(strtolower($tx->gateway_status ?? ''), ['finished', 'failed', 'refunded', 'expired']))
    <div class="mb-4">
        <a href="{{ $tx->gateway_url }}" target="_blank"
           class="block w-full text-center bg-orange-600 hover:bg-orange-500 text-white font-semibold py-3 rounded-xl transition">
            {{ $tx->external_id ? 'بازگشت به صفحه پرداخت NOWPayments ↗' : 'ادامه پرداخت در NOWPayments ↗' }}
        </a>
    </div>
    @endif

    {{-- Manual status check --}}
    @if($tx->provider_reference && ! in_array(strtolower($tx->gateway_status ?? ''), ['finished', 'failed', 'refunded', 'expired']))
    <form method="POST" action="{{ route('dashboard.orders.nowpayments.check', $order) }}" class="mb-4">
        @csrf
        <button type="submit"
                class="w-full bg-gray-800 hover:bg-gray-700 border border-gray-700 text-white font-medium py-3 rounded-xl transition flex items-center justify-center gap-2">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            بررسی وضعیت پرداخت
        </button>
    </form>
    @endif

    @if(in_array(strtolower($tx->gateway_status ?? ''), ['failed', 'expired']))
    <div class="mb-4 bg-red-500/10 border border-red-500/30 rounded-lg p-4 text-sm text-red-300">
        پرداخت ناموفق یا منقضی شده است. لطفاً دوباره پرداخت را انجام دهید.
    </div>
    <a href="{{ route('dashboard.orders.pay', $order) }}"
       class="block w-full text-center bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-3 rounded-xl transition mb-4">
        پرداخت مجدد
    </a>
    @endif

    <a href="{{ route('dashboard.orders.show', $order) }}"
       class="block text-center text-sm text-gray-500 hover:text-gray-300 transition py-2">
        بازگشت به جزئیات سفارش
    </a>
</div>
@endsection
