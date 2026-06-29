@extends('layouts.panel')

@section('title', 'پروفایل')

@section('content')
<div class="max-w-lg space-y-6">

    @if(session('success'))
    <div class="bg-green-500/10 border border-green-500/30 rounded-xl px-5 py-3 text-sm text-green-300">{{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div class="bg-red-500/10 border border-red-500/30 rounded-xl px-5 py-3 text-sm text-red-300">{{ session('error') }}</div>
    @endif

    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
        <div class="p-6 border-b border-gray-800">
            <h2 class="font-semibold text-white">اطلاعات حساب کاربری</h2>
        </div>
        <div class="p-6 space-y-5 text-sm">
            <div class="flex justify-between items-center py-3 border-b border-gray-800">
                <span class="text-gray-400">شناسه اکانت</span>
                <span class="text-white font-mono tracking-widest">{{ $user->account_id }}</span>
            </div>
            <div class="flex justify-between items-center py-3 border-b border-gray-800">
                <span class="text-gray-400">نام</span>
                <span class="text-white">{{ $user->name }}</span>
            </div>
            <div class="flex justify-between items-center py-3 border-b border-gray-800">
                <span class="text-gray-400">نام کاربری</span>
                <span class="text-white font-mono">{{ $user->username }}</span>
            </div>
            @if($user->email)
            <div class="flex justify-between items-center py-3 border-b border-gray-800">
                <span class="text-gray-400">ایمیل</span>
                <span class="text-white">{{ $user->email }}</span>
            </div>
            @endif
            <div class="flex justify-between items-center py-3 border-b border-gray-800">
                <span class="text-gray-400">تاریخ ثبت‌نام</span>
                <span class="text-white">{{ $user->created_at->format('Y/m/d') }}</span>
            </div>
            <div class="flex justify-between items-center py-3">
                <span class="text-gray-400">تعداد سفارش‌ها</span>
                <span class="text-white">{{ $user->orders()->count() }}</span>
            </div>
        </div>
    </div>

    {{-- ── Phone verification section ── --}}
    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
        <div class="p-6 border-b border-gray-800">
            <h2 class="font-semibold text-white">تایید شماره موبایل</h2>
        </div>
        <div class="p-6 space-y-4 text-sm">
            @include('dashboard.partials.phone-section', ['user' => $user, 'verificationEnabled' => $verificationEnabled])
        </div>
    </div>

    <p class="text-xs text-gray-600 text-center">برای تغییر سایر اطلاعات حساب با پشتیبانی تماس بگیرید.</p>
</div>
@endsection
