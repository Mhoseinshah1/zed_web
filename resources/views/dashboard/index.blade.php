@extends('layouts.panel')

@section('title', 'داشبورد')

@section('content')
{{-- Stats --}}
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-400 text-sm">سفارش‌ها</p>
                <p class="text-2xl font-bold text-white mt-1">{{ $user->orders()->count() }}</p>
            </div>
            <span class="text-3xl">📋</span>
        </div>
    </div>
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-400 text-sm">سرویس فعال</p>
                <p class="text-2xl font-bold text-white mt-1">۰</p>
            </div>
            <span class="text-3xl">🔌</span>
        </div>
    </div>
    <a href="{{ route('dashboard.wallet') }}" class="bg-gray-900 border border-gray-800 hover:border-indigo-500/50 rounded-xl p-5 transition">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-400 text-sm">موجودی کیف پول</p>
                <p class="text-2xl font-bold text-white mt-1">{{ number_format($user->wallet_balance_toman) }} <span class="text-sm font-normal text-gray-400">تومان</span></p>
            </div>
            <span class="text-3xl">💰</span>
        </div>
    </a>
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-400 text-sm">پرداخت در انتظار بررسی</p>
                <p class="text-2xl font-bold {{ $pendingPayments > 0 ? 'text-yellow-400' : 'text-white' }} mt-1">{{ $pendingPayments }}</p>
            </div>
            <span class="text-3xl">⏳</span>
        </div>
    </div>
</div>

{{-- Quick links --}}
<div class="grid grid-cols-2 sm:grid-cols-4 gap-4 mb-8">
    <a href="{{ route('plans') }}" class="bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl p-4 text-center transition">
        <div class="text-2xl mb-2">🛒</div>
        <div class="text-sm font-medium">خرید VPN</div>
    </a>
    <a href="{{ route('dashboard.orders') }}" class="bg-gray-900 border border-gray-800 hover:border-gray-700 text-white rounded-xl p-4 text-center transition">
        <div class="text-2xl mb-2">📋</div>
        <div class="text-sm font-medium">سفارش‌های من</div>
    </a>
    <a href="{{ route('dashboard.services') }}" class="bg-gray-900 border border-gray-800 hover:border-gray-700 text-white rounded-xl p-4 text-center transition">
        <div class="text-2xl mb-2">🔌</div>
        <div class="text-sm font-medium">سرویس‌های من</div>
    </a>
    <a href="{{ route('dashboard.profile') }}" class="bg-gray-900 border border-gray-800 hover:border-gray-700 text-white rounded-xl p-4 text-center transition">
        <div class="text-2xl mb-2">👤</div>
        <div class="text-sm font-medium">پروفایل</div>
    </a>
    <a href="{{ route('dashboard.wallet') }}" class="bg-gray-900 border border-gray-800 hover:border-gray-700 text-white rounded-xl p-4 text-center transition">
        <div class="text-2xl mb-2">💰</div>
        <div class="text-sm font-medium">کیف پول</div>
    </a>
</div>

{{-- Main content --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    {{-- Latest orders --}}
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-6">
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-semibold text-white">آخرین سفارش‌ها</h3>
            <a href="{{ route('dashboard.orders') }}" class="text-xs text-indigo-400 hover:text-indigo-300">مشاهده همه</a>
        </div>
        @if($orders->isEmpty())
            <div class="text-center py-8 text-gray-500">
                <div class="text-4xl mb-3">🛒</div>
                <p class="text-sm">هنوز سفارشی ثبت نشده</p>
                <a href="{{ route('plans') }}" class="inline-block mt-4 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-5 py-2 rounded-lg transition">
                    خرید سرویس
                </a>
            </div>
        @else
            <div class="space-y-3">
                @foreach($orders as $order)
                <a href="{{ route('dashboard.orders.show', $order) }}" class="flex items-center justify-between bg-gray-800/50 hover:bg-gray-800 rounded-lg px-4 py-3 transition">
                    <div>
                        <div class="text-sm font-medium text-white">{{ $order->plan_name }}</div>
                        <div class="text-xs text-gray-500 mt-0.5">{{ $order->order_number }}</div>
                    </div>
                    <div class="text-left">
                        <div class="text-sm text-white">{{ number_format($order->final_price_toman) }} تومان</div>
                        <div class="text-xs mt-0.5 {{ $order->status === 'completed' ? 'text-green-400' : ($order->status === 'cancelled' ? 'text-red-400' : 'text-yellow-400') }}">
                            {{ $order->statusLabel() }}
                        </div>
                    </div>
                </a>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Services placeholder --}}
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-6">
        <h3 class="font-semibold text-white mb-4">سرویس‌های فعال</h3>
        <div class="text-center py-8 text-gray-500">
            <div class="text-4xl mb-3">📭</div>
            <p class="text-sm">سرویس‌های شما به‌زودی اینجا نمایش داده می‌شوند</p>
        </div>
    </div>
</div>

{{-- Account info --}}
<div class="mt-6 bg-gray-900 border border-gray-800 rounded-xl p-5">
    <h3 class="font-semibold text-white mb-3">اطلاعات حساب</h3>
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
        <div>
            <span class="text-gray-400">نام کاربری:</span>
            <span class="text-white mr-2">{{ $user->username }}</span>
        </div>
        @if($user->email)
        <div>
            <span class="text-gray-400">ایمیل:</span>
            <span class="text-white mr-2">{{ $user->email }}</span>
        </div>
        @endif
        <div>
            <span class="text-gray-400">تاریخ ثبت‌نام:</span>
            <span class="text-white mr-2">{{ $user->created_at->format('Y/m/d') }}</span>
        </div>
    </div>
</div>
@endsection
