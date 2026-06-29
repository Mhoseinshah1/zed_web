@extends('layouts.panel')

@section('title', 'خرید حجم اضافه')

@php
    $used     = $service->traffic_used_gb ?? 0;
    $total    = $service->traffic_total_gb;
    $remaining = max(0, $total - $used);
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
            <h1 class="text-xl font-bold text-white">خرید حجم اضافه</h1>
            <p class="text-sm text-gray-400 mt-0.5">{{ $service->plan_name ?? $service->service_number }}</p>
        </div>
    </div>

    @if(session('error'))
    <div class="bg-red-500/10 border border-red-500/30 rounded-xl px-5 py-4 text-red-300 text-sm">
        {{ session('error') }}
    </div>
    @endif

    {{-- ── Current traffic ── --}}
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5 space-y-3">
        <h3 class="text-sm font-medium text-gray-300">وضعیت حجم فعلی</h3>
        <div class="grid grid-cols-3 gap-3 text-sm">
            <div>
                <p class="text-xs text-gray-500">حجم کل</p>
                <p class="text-white mt-0.5">{{ $total }} گیگابایت</p>
            </div>
            <div>
                <p class="text-xs text-gray-500">مصرف‌شده</p>
                <p class="text-white mt-0.5">{{ $used }} گیگابایت</p>
            </div>
            <div>
                <p class="text-xs text-gray-500">باقی‌مانده</p>
                <p class="text-green-400 mt-0.5">{{ $remaining }} گیگابایت</p>
            </div>
        </div>
    </div>

    {{-- ── Purchase form ── --}}
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
        <h3 class="text-sm font-medium text-gray-300 mb-4">مقدار حجم اضافه مورد نظر را وارد کنید</h3>

        {{-- Discount can be applied on the next step (order page) --}}
        <div class="mb-4 p-3 bg-gray-800/60 border border-gray-700 rounded-lg">
            <p class="text-xs text-gray-400">اگر کد تخفیف دارید، در مرحله بعد (صفحه سفارش) می‌توانید آن را اعمال کنید.</p>
        </div>

        <form action="{{ route('dashboard.services.extra-traffic.submit', $service) }}" method="POST" id="addon-form">
            @csrf
            <label for="amount_gb" class="block text-sm text-gray-300 mb-2">مقدار حجم اضافه</label>
            <div class="flex items-center gap-2">
                <input type="number" name="amount_gb" id="amount_gb"
                       min="{{ $minGb }}" max="{{ $maxGb }}" step="1" required
                       value="{{ old('amount_gb', $minGb) }}"
                       placeholder="مثلاً 20"
                       data-price="{{ $pricePerGb }}"
                       class="flex-1 bg-gray-800 border border-gray-700 rounded-lg px-4 py-3 text-white text-sm
                              focus:border-indigo-500 focus:outline-none"
                       oninput="updateAddonPrice()">
                <span class="text-sm text-gray-400 shrink-0">گیگابایت</span>
            </div>
            <p class="text-xs text-gray-500 mt-2">
                حداقل {{ $minGb }} و حداکثر {{ $maxGb }} گیگابایت — قیمت هر گیگ:
                {{ number_format($pricePerGb) }} تومان
            </p>

            @error('amount_gb')
            <p class="text-xs text-red-400 mt-2">{{ $message }}</p>
            @enderror

            {{-- Payable amount --}}
            <div class="mt-5 bg-gray-800/60 border border-gray-700 rounded-lg p-4 flex items-center justify-between">
                <span class="text-sm text-gray-400">مبلغ قابل پرداخت</span>
                <span class="text-lg font-bold text-white">
                    <span id="addon-amount">{{ number_format($minGb * $pricePerGb) }}</span> تومان
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
function updateAddonPrice() {
    const input = document.getElementById('amount_gb');
    const price = parseInt(input.dataset.price || '0', 10);
    let gb = parseInt(input.value || '0', 10);
    if (isNaN(gb) || gb < 0) gb = 0;
    const total = gb * price;
    document.getElementById('addon-amount').textContent = total.toLocaleString('en-US');
}
</script>
@endpush
