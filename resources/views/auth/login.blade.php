@extends('layouts.app')

@section('title', 'ورود به حساب')

@section('content')
<section class="min-h-[70vh] flex items-center justify-center py-16 px-4">
    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <a href="{{ route('home') }}" class="text-2xl font-bold text-white">
                <span class="text-indigo-400">Zed</span>Proxy
            </a>
            <h1 class="text-xl font-semibold text-white mt-4">ورود به حساب کاربری</h1>
            <p class="text-gray-400 text-sm mt-1">به پنل کاربری خود وارد شوید</p>
        </div>

        <div class="bg-gray-900 border border-gray-800 rounded-2xl p-8">
            @if ($errors->any())
                <div class="bg-red-500/10 border border-red-500/30 rounded-lg p-4 mb-6 text-sm text-red-300">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}" class="space-y-5">
                @csrf
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-300 mb-1.5">ایمیل</label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" required autofocus
                        class="w-full bg-gray-800 border border-gray-700 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 rounded-lg px-4 py-2.5 text-white text-sm outline-none transition"
                        placeholder="email@example.com">
                </div>
                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label for="password" class="block text-sm font-medium text-gray-300">رمز عبور</label>
                        <a href="#" class="text-xs text-indigo-400 hover:text-indigo-300">فراموشی رمز؟</a>
                    </div>
                    <input type="password" id="password" name="password" required
                        class="w-full bg-gray-800 border border-gray-700 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 rounded-lg px-4 py-2.5 text-white text-sm outline-none transition"
                        placeholder="••••••••">
                </div>
                <div class="flex items-center gap-2">
                    <input type="checkbox" id="remember" name="remember" class="rounded border-gray-600 bg-gray-800 text-indigo-600">
                    <label for="remember" class="text-sm text-gray-400">مرا به خاطر بسپار</label>
                </div>
                <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-3 rounded-xl transition">
                    ورود
                </button>
            </form>
        </div>

        <p class="text-center text-sm text-gray-500 mt-6">
            حساب کاربری ندارید؟
            <a href="{{ route('register') }}" class="text-indigo-400 hover:text-indigo-300 font-medium">ثبت‌نام کنید</a>
        </p>
    </div>
</section>
@endsection
