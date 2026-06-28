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

        {{-- Manual method details (non-wallet, non-nowpayments) --}}
        <div x-show="selectedType && selectedType !== 'wallet' && selectedType !== 'nowpayments'" x-cloak>
            @foreach($methods->where(fn($m) => ! in_array($m->type, ['wallet', 'nowpayments'])) as $method)
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

        {{-- CentralPay rial gateway --}}
        @foreach($methods->where('type', 'centralpay') as $method)
        <div x-show="selectedMethod == {{ $method->id }}" x-cloak class="bg-gray-900 border border-gray-800 rounded-xl p-6 mb-6">
            <div class="flex items-start gap-3">
                <div class="w-8 h-8 bg-green-500/20 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5">
                    <svg class="w-4 h-4 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"/>
                    </svg>
                </div>
                <div>
                    <p class="text-white font-medium text-sm">پرداخت ریالی از طریق CentralPay</p>
                    <p class="text-gray-400 text-xs mt-1">
                        پس از کلیک روی «تایید و پرداخت»، به درگاه ریالی CentralPay هدایت می‌شوید.
                        پس از پرداخت موفق، به صورت خودکار بازمی‌گردید و سرویس شما فعال می‌شود.
                    </p>
                    <p class="text-green-400/80 text-xs mt-2">مبلغ قابل پرداخت: <strong class="text-white">{{ number_format($order->final_price_toman) }} تومان</strong></p>
                </div>
            </div>
        </div>
        @endforeach

        {{-- NOWPayments crypto gateway --}}
        @foreach($methods->where('type', 'nowpayments') as $method)
        @php $npMode = $method->getConfig('nowpayments_mode', 'invoice'); @endphp
        <div x-show="selectedMethod == {{ $method->id }}" x-cloak class="bg-gray-900 border border-gray-800 rounded-xl p-6 mb-6">
            <div class="flex items-start gap-3">
                <div class="w-8 h-8 bg-orange-500/20 rounded-full flex items-center justify-center flex-shrink-0 mt-0.5">
                    <svg class="w-4 h-4 text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div class="flex-1">
                    <p class="text-white font-medium text-sm">پرداخت کریپتو از طریق NOWPayments</p>
                    @if($npMode === 'invoice')
                    <p class="text-gray-400 text-xs mt-1">
                        پس از کلیک روی «تایید و پرداخت»، به صفحه NOWPayments هدایت می‌شوید و می‌توانید ارز دیجیتال مورد نظر خود را انتخاب کنید. پرداخت پس از تایید شبکه به صورت خودکار تایید می‌شود.
                    </p>
                    @else
                    @php
                        $defaultCurrency = $method->getConfig('default_pay_currency', '');
                        $allowedCurrencies = array_filter(array_map('trim', explode(',', $method->getConfig('allowed_pay_currencies', '') ?? '')));
                    @endphp
                    <p class="text-gray-400 text-xs mt-1">پرداخت مستقیم کریپتو. پس از پرداخت به آدرس نمایش داده شده، وضعیت به صورت خودکار تایید می‌شود.</p>
                    @if(! empty($allowedCurrencies))
                    <div class="mt-3">
                        <label for="pay_currency_{{ $method->id }}" class="block text-sm text-gray-400 mb-1.5">ارز پرداخت را انتخاب کنید</label>
                        <select id="pay_currency_{{ $method->id }}" name="pay_currency"
                                class="w-full bg-gray-800 border border-gray-700 focus:border-indigo-500 rounded-lg px-4 py-2.5 text-white text-sm outline-none transition">
                            @foreach($allowedCurrencies as $currency)
                            <option value="{{ $currency }}" {{ $currency === $defaultCurrency ? 'selected' : '' }}>{{ strtoupper($currency) }}</option>
                            @endforeach
                        </select>
                    </div>
                    @elseif($defaultCurrency)
                    <input type="hidden" name="pay_currency" value="{{ $defaultCurrency }}">
                    @endif
                    @endif
                </div>
            </div>
        </div>
        @endforeach

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
