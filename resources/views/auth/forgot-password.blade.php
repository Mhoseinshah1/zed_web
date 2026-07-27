@extends('layouts.app')

@section('title', 'بازیابی رمز عبور')

@section('content')
<section class="min-h-[70vh] flex items-center justify-center py-16 px-4">
    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <a href="{{ route('home') }}" class="text-2xl font-bold text-white">
                <span class="text-indigo-400">Zed</span>Proxy
            </a>
            <h1 class="text-xl font-semibold text-white mt-4">بازیابی رمز عبور</h1>
            <p class="text-gray-400 text-sm mt-1">ایمیل یا شماره موبایل حساب خود را وارد کنید</p>
        </div>

        <div class="bg-gray-900 border border-gray-800 rounded-2xl p-8">
            @if ($errors->any())
                <div class="bg-red-500/10 border border-red-500/30 rounded-lg p-4 mb-6 text-sm text-red-300">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('password.request.send') }}" class="space-y-5">
                @csrf
                <div>
                    <label for="identifier" class="block text-sm font-medium text-gray-300 mb-1.5">ایمیل یا شماره موبایل</label>
                    <input type="text" id="identifier" name="identifier" required autofocus autocomplete="username"
                        class="w-full bg-gray-800 border border-gray-700 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 rounded-lg px-4 py-2.5 text-white text-sm outline-none transition"
                        placeholder="example@mail.com یا 0912xxxxxxx">
                </div>
                <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-3 rounded-xl transition">
                    ارسال کد تایید
                </button>
            </form>
        </div>

        <p class="text-center text-sm text-gray-500 mt-6">
            <a href="{{ route('login') }}" class="text-indigo-400 hover:text-indigo-300 font-medium">بازگشت به صفحه ورود</a>
        </p>
    </div>
</section>
@endsection
