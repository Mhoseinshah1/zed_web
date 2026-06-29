@extends('layouts.panel')

@section('title', 'انتخاب پلن برای تمدید')

@section('content')
<div class="max-w-2xl space-y-6">

    {{-- ── Header ── --}}
    <div class="flex items-center gap-4">
        <a href="{{ route('dashboard.services.show', $service) }}" class="text-gray-400 hover:text-white transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
        </a>
        <div>
            <h1 class="text-xl font-bold text-white">انتخاب پلن برای تمدید</h1>
            <p class="text-sm text-gray-400 mt-0.5">{{ $service->service_number }}</p>
        </div>
    </div>

    {{-- ── Service status card ── --}}
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5 space-y-2">
        <h3 class="text-sm font-medium text-gray-300 mb-3">وضعیت فعلی سرویس</h3>
        <div class="grid grid-cols-2 gap-3">
            <div>
                <p class="text-xs text-gray-500">وضعیت</p>
                <p class="text-sm text-white mt-0.5">{{ $service->statusLabel() }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500">تاریخ انقضا</p>
                <p class="text-sm mt-0.5 {{ $service->isExpired() ? 'text-red-400' : 'text-white' }}">
                    {{ $service->expires_at->format('Y/m/d H:i') }}
                </p>
            </div>
            @if(!$service->isExpired())
            <div>
                <p class="text-xs text-gray-500">روزهای باقی‌مانده</p>
                <p class="text-sm text-green-400 mt-0.5">{{ $service->daysRemaining() }} روز</p>
            </div>
            @else
            <div class="col-span-2">
                <p class="text-xs text-amber-400">⚠ این سرویس منقضی شده است. با تمدید، بلافاصله از همین لحظه فعال می‌شود.</p>
            </div>
            @endif
        </div>
    </div>

    {{-- ── Plans ── --}}
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
        <h3 class="text-sm font-medium text-gray-300 mb-4">انتخاب پلن برای تمدید سرویس</h3>

        {{-- Discount can be applied on the next step (order page) --}}
        <div class="mb-4 p-3 bg-gray-800/60 border border-gray-700 rounded-lg">
            <p class="text-xs text-gray-400">اگر کد تخفیف دارید، در مرحله بعد (صفحه سفارش) می‌توانید آن را اعمال کنید.</p>
        </div>

        <form action="{{ route('dashboard.services.renew.submit', $service) }}" method="POST" id="renewal-form">
            @csrf
            <div class="space-y-3">
                @foreach($plans as $plan)
                @php
                    $renewalPrice   = $plan->effectiveRenewalPrice();
                    $renewalDays    = $plan->effectiveRenewalDays();
                    $cashback       = $plan->effectiveCashbackAmount();
                    $finalPrice     = $renewalPrice - ($cashback ?? 0);
                    $newExpiry      = $service->isExpired()
                        ? now()->addDays($renewalDays)
                        : $service->expires_at->copy()->addDays($renewalDays);
                @endphp
                <label class="block cursor-pointer">
                    <input type="radio" name="plan_id" value="{{ $plan->id }}"
                           class="sr-only peer" required
                           {{ old('plan_id') == $plan->id ? 'checked' : '' }}>
                    <div class="border border-gray-700 peer-checked:border-indigo-500 peer-checked:bg-indigo-900/20
                                rounded-xl p-4 transition hover:border-gray-600">
                        <div class="flex items-start justify-between gap-3">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-white">{{ $plan->name }}</p>
                                @if($plan->description)
                                <p class="text-xs text-gray-500 mt-0.5">{{ $plan->description }}</p>
                                @endif
                                <div class="flex flex-wrap items-center gap-3 mt-1.5">
                                    @if($renewalDays)
                                    <span class="text-xs text-indigo-400">⏱ {{ $renewalDays }} روز</span>
                                    @endif
                                    @if($plan->traffic_gb)
                                    <span class="text-xs text-gray-400">📦 {{ $plan->traffic_gb }} گیگابایت</span>
                                    @else
                                    <span class="text-xs text-gray-400">📦 نامحدود</span>
                                    @endif
                                </div>
                            </div>
                            <div class="text-left shrink-0">
                                @if($cashback)
                                <p class="text-xs text-gray-500 line-through text-right">{{ number_format($renewalPrice) }} تومان</p>
                                <p class="text-base font-bold text-white">{{ number_format($renewalPrice) }}</p>
                                <p class="text-xs text-gray-500">تومان</p>
                                @else
                                <p class="text-base font-bold text-white">{{ number_format($renewalPrice) }}</p>
                                <p class="text-xs text-gray-500">تومان</p>
                                @endif
                            </div>
                        </div>

                        @if($cashback)
                        <div class="mt-2 flex items-center gap-1.5 text-xs text-green-400 bg-green-900/20 border border-green-800/40 rounded-lg px-2.5 py-1.5">
                            <span>💸</span>
                            <span>کش‌بک: {{ number_format($cashback) }} تومان
                                @if($plan->renewal_cashback_type === 'percent')
                                    ({{ $plan->renewal_cashback_value }}٪)
                                @endif
                                پس از پرداخت به کیف پول شما واریز می‌شود.
                            </span>
                        </div>
                        @endif

                        @if($renewalDays)
                        <p class="text-xs text-gray-600 mt-2">
                            انقضای جدید: {{ $newExpiry->format('Y/m/d') }}
                        </p>
                        @endif
                    </div>
                </label>
                @endforeach
            </div>

            @error('plan_id')
            <p class="text-xs text-red-400 mt-2">{{ $message }}</p>
            @enderror

            <div class="mt-6 flex flex-col sm:flex-row gap-3">
                <button type="submit"
                        class="flex-1 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium
                               px-6 py-3 rounded-lg transition text-center">
                    تمدید با این پلن
                </button>
                <a href="{{ route('dashboard.services.show', $service) }}"
                   class="flex-1 bg-gray-800 hover:bg-gray-700 text-gray-300 text-sm font-medium
                          px-6 py-3 rounded-lg transition text-center">
                    انصراف
                </a>
            </div>
        </form>
    </div>

</div>
@endsection
