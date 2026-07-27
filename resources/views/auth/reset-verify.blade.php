@extends('layouts.app')

@section('title', 'تایید کد بازیابی')

@section('content')
<section class="min-h-[70vh] flex items-center justify-center py-16 px-4">
    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <a href="{{ route('home') }}" class="text-2xl font-bold text-white">
                <span class="text-indigo-400">Zed</span>Proxy
            </a>
            <h1 class="text-xl font-semibold text-white mt-4">تایید کد بازیابی</h1>
            <p class="text-gray-400 text-sm mt-1">کد ۶ رقمی ارسال‌شده را وارد کنید</p>
        </div>

        <div class="bg-gray-900 border border-gray-800 rounded-2xl p-8">
            @if (session('status'))
                <div class="bg-green-500/10 border border-green-500/30 rounded-lg p-4 mb-6 text-sm text-green-300">
                    {{ session('status') }}
                </div>
            @endif
            @if ($errors->any())
                <div class="bg-red-500/10 border border-red-500/30 rounded-lg p-4 mb-6 text-sm text-red-300">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('password.verify.submit') }}" class="space-y-5">
                @csrf
                <div>
                    <label for="code" class="block text-sm font-medium text-gray-300 mb-1.5">کد تایید</label>
                    <input type="text" id="code" name="code" required autofocus inputmode="numeric" autocomplete="one-time-code" maxlength="6" dir="ltr"
                        class="w-full bg-gray-800 border border-gray-700 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 rounded-lg px-4 py-2.5 text-white text-sm outline-none transition text-center tracking-[0.5em]"
                        placeholder="——————">
                </div>
                <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-3 rounded-xl transition">
                    تایید کد
                </button>
            </form>
        </div>

        <p class="text-center text-sm text-gray-500 mt-6">
            <a href="{{ route('password.request') }}" class="text-indigo-400 hover:text-indigo-300 font-medium">درخواست کد جدید</a>
        </p>
    </div>
</section>
@endsection
