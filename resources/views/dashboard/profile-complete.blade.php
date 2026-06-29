@extends('layouts.panel')

@section('title', 'تکمیل حساب کاربری')

@section('content')
<div class="max-w-lg space-y-6">

    <div class="bg-amber-500/10 border border-amber-500/30 rounded-xl px-5 py-4 text-sm text-amber-200">
        برای ادامه، لطفاً شماره موبایل حساب کاربری خود را تکمیل کنید.
        @if($verificationRequired)
        <span class="block mt-1 text-amber-300/80">پس از وارد کردن شماره، تایید آن با کد پیامکی الزامی است.</span>
        @endif
    </div>

    @if(session('success'))
    <div class="bg-green-500/10 border border-green-500/30 rounded-xl px-5 py-3 text-sm text-green-300">{{ session('success') }}</div>
    @endif

    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
        <div class="p-6 border-b border-gray-800">
            <h2 class="font-semibold text-white">تایید شماره موبایل</h2>
        </div>
        <div class="p-6 space-y-4 text-sm">
            @include('dashboard.partials.phone-section', ['user' => $user, 'verificationEnabled' => $verificationEnabled])
        </div>
    </div>

    <a href="{{ route('dashboard.index') }}" class="block text-center text-xs text-gray-500 hover:text-gray-300 transition">
        بازگشت به داشبورد
    </a>
</div>
@endsection
