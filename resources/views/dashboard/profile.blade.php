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

    {{-- ── Email verification offer (visible when enabled but not verified) ── --}}
    @if(app(\App\Services\Email\EmailVerificationService::class)->isEnabled() && ! $user->hasVerifiedEmailAddress())
        <div class="bg-gray-900 border border-amber-800/60 rounded-xl p-6 text-sm">
            <h2 class="mb-2 font-semibold text-white">تایید آدرس ایمیل</h2>
            <p class="mb-4 leading-7 text-gray-400">آدرس ایمیل شما هنوز تایید نشده است. برای امنیت حساب و دریافت اطلاع‌رسانی‌ها، ایمیل خود را تایید کنید.</p>
            <a href="{{ route('verification.notice') }}"
               class="inline-block rounded-lg bg-indigo-600 px-4 py-2 font-semibold text-white transition hover:bg-indigo-500">
                تایید ایمیل
            </a>
        </div>
    @endif

    {{-- ── Phone verification section ── --}}
    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
        <div class="p-6 border-b border-gray-800">
            <h2 class="font-semibold text-white">تایید شماره موبایل</h2>
        </div>
        <div class="p-6 space-y-4 text-sm">
            @include('dashboard.partials.phone-section', ['user' => $user, 'verificationEnabled' => $verificationEnabled])
        </div>
    </div>

    {{-- ── Appearance / theme section ── --}}
    <x-ui.theme-switcher />

    <p class="text-xs text-gray-600 text-center">برای تغییر سایر اطلاعات حساب با پشتیبانی تماس بگیرید.</p>
</div>
@endsection
