@extends('layouts.panel')

@section('title', 'شارژ کیف پول')

@section('content')

<div class="mb-6">
    <a href="{{ route('dashboard.wallet') }}" class="text-indigo-400 hover:text-indigo-300 text-sm transition">
        ← بازگشت به کیف پول
    </a>
</div>

<div class="max-w-lg mx-auto">
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-8">
        <div class="mb-6">
            <h2 class="text-xl font-bold text-white">شارژ کیف پول</h2>
            <p class="text-gray-400 text-sm mt-1">موجودی فعلی: <span class="text-white font-medium">{{ number_format($user->wallet_balance_toman) }} تومان</span></p>
        </div>

        @if($errors->any())
        <div class="mb-5 p-4 rounded-xl bg-red-900/40 border border-red-700 text-red-300 text-sm">
            @foreach($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
        @endif

        @if($methods->isEmpty())
        <div class="text-center py-8 text-gray-500">
            <p class="text-sm">در حال حاضر درگاه پرداختی برای شارژ کیف پول در دسترس نیست.</p>
            <a href="{{ route('contact') }}" class="inline-block mt-4 text-indigo-400 hover:text-indigo-300 text-sm transition">
                تماس با پشتیبانی ←
            </a>
        </div>
        @else
        <form method="POST" action="{{ route('dashboard.wallet.topup.submit') }}">
            @csrf

            {{-- Preset amounts --}}
            @if(!empty($presetAmounts))
            <div class="mb-5">
                <label class="block text-gray-300 text-sm font-medium mb-3">مبالغ پیشنهادی</label>
                <div class="grid grid-cols-2 gap-2">
                    @foreach($presetAmounts as $preset)
                    <button type="button"
                        onclick="document.getElementById('amount').value = {{ $preset }}"
                        class="px-4 py-2 rounded-lg border border-gray-700 hover:border-indigo-500 text-gray-300 hover:text-white text-sm transition text-center">
                        {{ number_format($preset) }} تومان
                    </button>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- Custom amount --}}
            <div class="mb-5">
                <label for="amount" class="block text-gray-300 text-sm font-medium mb-2">
                    مبلغ شارژ (تومان)
                </label>
                <input type="number"
                    id="amount"
                    name="amount"
                    value="{{ old('amount') }}"
                    min="{{ $minAmount }}"
                    max="{{ $maxAmount }}"
                    placeholder="{{ number_format($minAmount) }}"
                    class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-3 text-white placeholder-gray-500 focus:outline-none focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500">
                <p class="text-gray-500 text-xs mt-1">
                    حداقل {{ number_format($minAmount) }} — حداکثر {{ number_format($maxAmount) }} تومان
                </p>
            </div>

            {{-- Payment method --}}
            <div class="mb-6">
                <label class="block text-gray-300 text-sm font-medium mb-3">روش پرداخت</label>
                <div class="space-y-2">
                    @foreach($methods as $method)
                    <label class="flex items-center gap-3 p-3 rounded-lg border border-gray-700 hover:border-indigo-500 cursor-pointer transition">
                        <input type="radio" name="payment_method_id" value="{{ $method->id }}"
                            {{ old('payment_method_id') == $method->id || $loop->first ? 'checked' : '' }}
                            class="text-indigo-500">
                        <div>
                            <p class="text-white text-sm font-medium">{{ $method->title }}</p>
                            @if($method->description)
                            <p class="text-gray-400 text-xs mt-0.5">{{ $method->description }}</p>
                            @endif
                        </div>
                    </label>
                    @endforeach
                </div>
            </div>

            <button type="submit"
                class="w-full bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-3 px-6 rounded-lg transition">
                ادامه و رفتن به درگاه پرداخت
            </button>
        </form>
        @endif
    </div>
</div>

@endsection
