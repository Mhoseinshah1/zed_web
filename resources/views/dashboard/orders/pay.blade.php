@extends('layouts.panel')

@section('title', 'پرداخت سفارش')

@section('content')
<div class="max-w-2xl" x-data="{ selectedMethod: null, selectedType: null }">

    {{-- Order summary --}}
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-6 mb-6">
        <h2 class="font-semibold text-white mb-4">خلاصه سفارش</h2>
        <div class="flex items-center justify-between text-sm">
            <div>
                <div class="text-white font-medium">{{ $order->plan_name }}</div>
                <div class="text-gray-400 mt-1">{{ $order->durationLabel() }} — {{ $order->trafficLabel() }}</div>
            </div>
            <div class="text-left">
                <div class="text-2xl font-bold text-white">{{ number_format($order->final_price_toman) }}</div>
                <div class="text-gray-400 text-xs mt-0.5">تومان</div>
            </div>
        </div>
        <div class="mt-3 text-xs text-gray-500 font-mono">{{ $order->order_number }}</div>
    </div>

    {{-- Payment methods --}}
    <form method="POST" action="{{ route('dashboard.orders.pay.submit', $order) }}">
        @csrf

        @error('payment_method_id')
        <div class="mb-4 bg-red-500/10 border border-red-500/30 rounded-lg p-4 text-sm text-red-300">
            {{ $message }}
        </div>
        @enderror

        <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden mb-6">
            <div class="p-6 border-b border-gray-800">
                <h3 class="font-semibold text-white">روش پرداخت</h3>
            </div>

            @if($methods->isEmpty())
                <div class="p-6 text-center text-gray-500 text-sm">
                    روشی پرداخت فعالی در حال حاضر وجود ندارد. با پشتیبانی تماس بگیرید.
                </div>
            @else
            <div class="divide-y divide-gray-800">
                @foreach($methods as $method)
                <label class="flex items-start gap-4 p-5 cursor-pointer hover:bg-gray-800/40 transition"
                       x-on:click="selectedMethod = {{ $method->id }}; selectedType = '{{ $method->type }}'">
                    <input type="radio" name="payment_method_id" value="{{ $method->id }}"
                           class="mt-1 text-indigo-600 border-gray-600 bg-gray-800"
                           x-model="selectedMethod"
                           {{ old('payment_method_id') == $method->id ? 'checked' : '' }}>
                    <div class="flex-1">
                        <div class="text-white font-medium text-sm">{{ $method->title }}</div>
                        @if($method->description)
                        <div class="text-gray-400 text-xs mt-0.5">{{ $method->description }}</div>
                        @endif

                        {{-- Wallet: show balance --}}
                        @if($method->type === 'wallet')
                        <div class="mt-2 text-xs {{ $user->wallet_balance_toman >= $order->final_price_toman ? 'text-green-400' : 'text-red-400' }}">
                            موجودی: {{ number_format($user->wallet_balance_toman) }} تومان
                            @if($user->wallet_balance_toman < $order->final_price_toman)
                                — موجودی کافی نیست
                            @endif
                        </div>
                        @endif
                    </div>
                </label>
                @endforeach
            </div>
            @endif
        </div>

        {{-- Manual method details --}}
        <div x-show="selectedType && selectedType !== 'wallet'" x-cloak>
            @foreach($methods->where(fn($m) => $m->type !== 'wallet') as $method)
            <div x-show="selectedMethod == {{ $method->id }}" class="bg-gray-900 border border-gray-800 rounded-xl p-6 mb-6">
                @if($method->instructions)
                <div class="mb-5">
                    <h4 class="text-white font-medium text-sm mb-2">راهنمای پرداخت</h4>
                    <div class="bg-gray-800/60 rounded-lg p-4 text-sm text-gray-300 leading-6 whitespace-pre-line">{{ $method->instructions }}</div>
                </div>
                @endif

                @if($method->account_label && $method->account_value)
                <div class="mb-5 bg-gray-800 rounded-lg p-4">
                    <div class="text-xs text-gray-400 mb-1">{{ $method->account_label }}</div>
                    <div class="font-mono text-white text-sm break-all select-all">{{ $method->account_value }}</div>
                    @if($method->network)
                    <div class="text-xs text-gray-500 mt-1">شبکه: {{ $method->network }}</div>
                    @endif
                </div>
                @endif
            </div>
            @endforeach

            <div class="bg-gray-900 border border-gray-800 rounded-xl p-6 mb-6">
                <h4 class="text-white font-medium text-sm mb-4">اطلاعات پرداخت</h4>
                <div class="space-y-4">
                    <div>
                        <label for="transaction_reference" class="block text-sm text-gray-400 mb-1.5">
                            کد تراکنش / TXID <span class="text-gray-600">(اختیاری)</span>
                        </label>
                        <input type="text" id="transaction_reference" name="transaction_reference"
                               value="{{ old('transaction_reference') }}"
                               class="w-full bg-gray-800 border border-gray-700 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 rounded-lg px-4 py-2.5 text-white text-sm outline-none transition font-mono"
                               placeholder="شناسه تراکنش را اینجا وارد کنید">
                    </div>
                    <div>
                        <label for="user_note" class="block text-sm text-gray-400 mb-1.5">
                            توضیحات <span class="text-gray-600">(اختیاری)</span>
                        </label>
                        <textarea id="user_note" name="user_note" rows="3"
                                  class="w-full bg-gray-800 border border-gray-700 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 rounded-lg px-4 py-2.5 text-white text-sm outline-none transition resize-none"
                                  placeholder="در صورت نیاز توضیحات اضافه کنید">{{ old('user_note') }}</textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex gap-3">
            <button type="submit"
                    x-bind:disabled="!selectedMethod"
                    class="flex-1 bg-indigo-600 hover:bg-indigo-500 disabled:opacity-50 disabled:cursor-not-allowed text-white font-semibold py-3 rounded-xl transition">
                تایید و پرداخت
            </button>
            <a href="{{ route('dashboard.orders.show', $order) }}"
               class="px-6 py-3 bg-gray-800 hover:bg-gray-700 text-white text-sm font-medium rounded-xl transition flex items-center">
                انصراف
            </a>
        </div>
    </form>
</div>
@endsection
