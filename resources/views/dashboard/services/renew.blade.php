@extends('layouts.panel')

@section('title', 'تمدید سرویس')

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
            <h1 class="text-xl font-bold text-white">تمدید سرویس</h1>
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

    {{-- ── Packages ── --}}
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
        <h3 class="text-sm font-medium text-gray-300 mb-4">انتخاب پکیج تمدید</h3>

        {{-- Discount disabled for renewal - Task 10 --}}
        <div class="mb-4 p-3 bg-gray-800/60 border border-gray-700 rounded-lg">
            <p class="text-xs text-gray-500">کد تخفیف برای تمدید سرویس در حال حاضر فعال نیست.</p>
        </div>

        <form action="{{ route('dashboard.services.renew.submit', $service) }}" method="POST" id="renewal-form">
            @csrf
            <div class="space-y-3">
                @foreach($packages as $package)
                <label class="block cursor-pointer">
                    <input type="radio" name="renewal_package_id" value="{{ $package->id }}"
                           class="sr-only peer" required
                           {{ old('renewal_package_id') == $package->id ? 'checked' : '' }}>
                    <div class="border border-gray-700 peer-checked:border-indigo-500 peer-checked:bg-indigo-900/20
                                rounded-xl p-4 transition hover:border-gray-600">
                        <div class="flex items-center justify-between">
                            <div>
                                <p class="text-sm font-medium text-white">{{ $package->name }}</p>
                                @if($package->description)
                                <p class="text-xs text-gray-500 mt-0.5">{{ $package->description }}</p>
                                @endif
                                <p class="text-xs text-indigo-400 mt-1">{{ $package->duration_days }} روز</p>
                            </div>
                            <div class="text-left">
                                <p class="text-base font-bold text-white">{{ number_format($package->price_toman) }}</p>
                                <p class="text-xs text-gray-500">تومان</p>
                            </div>
                        </div>
                        @if(!$service->isExpired())
                        <p class="text-xs text-gray-600 mt-2">
                            انقضای جدید:
                            {{ $service->expires_at->copy()->addDays($package->duration_days)->format('Y/m/d') }}
                        </p>
                        @else
                        <p class="text-xs text-gray-600 mt-2">
                            انقضای جدید:
                            {{ now()->addDays($package->duration_days)->format('Y/m/d') }}
                        </p>
                        @endif
                    </div>
                </label>
                @endforeach
            </div>

            @error('renewal_package_id')
            <p class="text-xs text-red-400 mt-2">{{ $message }}</p>
            @enderror

            <div class="mt-6 flex flex-col sm:flex-row gap-3">
                <button type="submit"
                        class="flex-1 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium
                               px-6 py-3 rounded-lg transition text-center">
                    ادامه و پرداخت
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
