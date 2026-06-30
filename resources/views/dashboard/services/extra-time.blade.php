@extends('layouts.panel')

@section('title', 'خرید زمان اضافه')

@php
    $daysRemaining = $service->daysRemaining();
    $isExpired     = $service->isExpired();
@endphp

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
            <h1 class="text-xl font-bold text-white">خرید زمان اضافه</h1>
            <p class="text-sm text-gray-400 mt-0.5">{{ $service->plan_name ?? $service->service_number }}</p>
        </div>
    </div>

    @if(session('error'))
    <div class="bg-red-500/10 border border-red-500/30 rounded-xl px-5 py-4 text-red-300 text-sm">
        {{ session('error') }}
    </div>
    @endif

    {{-- ── Current expiry ── --}}
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5 space-y-3">
        <h3 class="text-sm font-medium text-gray-300">وضعیت اعتبار فعلی</h3>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
            <div>
                <p class="text-xs text-gray-500">تاریخ انقضا</p>
                <p class="mt-0.5 {{ $isExpired ? 'text-red-400' : 'text-white' }}">
                    {{ $service->expires_at->format('Y/m/d H:i') }}
                </p>
            </div>
            <div>
                <p class="text-xs text-gray-500">روزهای باقی‌مانده</p>
                <p class="mt-0.5 {{ $isExpired ? 'text-red-400' : 'text-green-400' }}">
                    {{ $isExpired ? 'منقضی شده' : $daysRemaining . ' روز' }}
                </p>
            </div>
        </div>
        @if($isExpired)
        <p class="text-xs text-amber-400">⚠ این سرویس منقضی شده است. با خرید زمان اضافه، از همین لحظه فعال می‌شود.</p>
        @endif
    </div>

    {{-- ── Purchase form ── --}}
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
        <h3 class="text-sm font-medium text-gray-300 mb-4">تعداد روز اضافه مورد نظر را وارد کنید</h3>

        {{-- Discount can be applied on the next step (order page) --}}
        <div class="mb-4 p-3 bg-gray-800/60 border border-gray-700 rounded-lg">
            <p class="text-xs text-gray-400">اگر کد تخفیف دارید، در مرحله بعد (صفحه سفارش) می‌توانید آن را اعمال کنید.</p>
        </div>

        <form action="{{ route('dashboard.services.extra-time.submit', $service) }}" method="POST" id="addon-form">
            @csrf
            <label for="amount_days" class="block text-sm text-gray-300 mb-2">تعداد روز اضافه</label>
            <div class="flex items-center gap-2">
                <input type="number" name="amount_days" id="amount_days"
                       min="{{ $minDays }}" max="{{ $maxDays }}" step="1" required
                       value="{{ old('amount_days', $minDays) }}"
                       placeholder="مثلاً 7"
                       data-price="{{ $pricePerDay }}"
                       data-expired="{{ $isExpired ? '1' : '0' }}"
                       data-expires="{{ $service->expires_at->timestamp }}"
                       class="flex-1 bg-gray-800 border border-gray-700 rounded-lg px-4 py-3 text-white text-sm
                              focus:border-indigo-500 focus:outline-none"
                       oninput="updateAddonTime()">
                <span class="text-sm text-gray-400 shrink-0">روز</span>
            </div>
            <p class="text-xs text-gray-500 mt-2">
                حداقل {{ $minDays }} و حداکثر {{ $maxDays }} روز — قیمت هر روز:
                {{ number_format($pricePerDay) }} تومان
            </p>

            @error('amount_days')
            <p class="text-xs text-red-400 mt-2">{{ $message }}</p>
            @enderror

            {{-- New expiry preview --}}
            <div class="mt-4 bg-gray-800/40 border border-gray-700 rounded-lg p-3 flex items-center justify-between">
                <span class="text-xs text-gray-400">انقضای جدید</span>
                <span class="text-sm text-indigo-300" id="addon-new-expiry">—</span>
            </div>

            {{-- Payable amount --}}
            <div class="mt-3 bg-gray-800/60 border border-gray-700 rounded-lg p-4 flex items-center justify-between">
                <span class="text-sm text-gray-400">مبلغ قابل پرداخت</span>
                <span class="text-lg font-bold text-white">
                    <span id="addon-amount">{{ number_format($minDays * $pricePerDay) }}</span> تومان
                </span>
            </div>

            <div class="mt-6 flex flex-col sm:flex-row gap-3">
                <button type="submit"
                        class="flex-1 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-6 py-3 rounded-lg transition text-center">
                    ادامه و پرداخت
                </button>
                <a href="{{ route('dashboard.services.show', $service) }}"
                   class="flex-1 bg-gray-800 hover:bg-gray-700 text-gray-300 text-sm font-medium px-6 py-3 rounded-lg transition text-center">
                    انصراف
                </a>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
function updateAddonTime() {
    const input = document.getElementById('amount_days');
    const price = parseInt(input.dataset.price || '0', 10);
    let days = parseInt(input.value || '0', 10);
    if (isNaN(days) || days < 0) days = 0;

    document.getElementById('addon-amount').textContent = (days * price).toLocaleString('en-US');

    const expired = input.dataset.expired === '1';
    const base = expired ? new Date() : new Date(parseInt(input.dataset.expires, 10) * 1000);
    base.setDate(base.getDate() + days);
    const y = base.getFullYear();
    const m = String(base.getMonth() + 1).padStart(2, '0');
    const d = String(base.getDate()).padStart(2, '0');
    document.getElementById('addon-new-expiry').textContent = `${y}/${m}/${d}`;
}
document.addEventListener('DOMContentLoaded', updateAddonTime);
</script>
@endpush
