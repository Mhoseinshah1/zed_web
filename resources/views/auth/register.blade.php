@extends('layouts.app')

@section('title', 'ثبت‌نام')

@section('content')
<section class="min-h-[70vh] flex items-center justify-center py-16 px-4">
    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <a href="{{ route('home') }}" class="text-2xl font-bold text-white">
                <span class="text-indigo-400">Zed</span>Proxy
            </a>
            <h1 class="text-xl font-semibold text-white mt-4">ایجاد حساب کاربری</h1>
            <p class="text-gray-400 text-sm mt-1">همین الان ثبت‌نام کنید</p>
        </div>

        <div class="bg-gray-900 border border-gray-800 rounded-2xl p-8">
            @if ($errors->any())
                <div class="bg-red-500/10 border border-red-500/30 rounded-lg p-4 mb-6 text-sm text-red-300">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('register') }}" class="space-y-5">
                @csrf
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-300 mb-1.5">نام</label>
                    <input type="text" id="name" name="name" value="{{ old('name') }}" required autofocus
                        class="w-full bg-gray-800 border border-gray-700 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 rounded-lg px-4 py-2.5 text-white text-sm outline-none transition"
                        placeholder="نام و نام خانوادگی">
                </div>
                <div>
                    <label for="username" class="block text-sm font-medium text-gray-300 mb-1.5">نام کاربری</label>
                    <input type="text" id="username" name="username" value="{{ old('username') }}" required autocomplete="username"
                        class="w-full bg-gray-800 border border-gray-700 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 rounded-lg px-4 py-2.5 text-white text-sm outline-none transition"
                        placeholder="فقط حروف انگلیسی، اعداد و خط زیر">
                </div>
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-300 mb-1.5">ایمیل</label>
                    <input type="email" id="email" name="email" value="{{ old('email') }}" required
                        class="w-full bg-gray-800 border border-gray-700 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 rounded-lg px-4 py-2.5 text-white text-sm outline-none transition"
                        placeholder="email@example.com">
                </div>
                <div>
                    <label for="phone" class="block text-sm font-medium text-gray-300 mb-1.5">شماره موبایل</label>
                    <input type="tel" id="phone" name="phone" value="{{ old('phone') }}" required dir="ltr"
                        class="w-full bg-gray-800 border border-gray-700 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 rounded-lg px-4 py-2.5 text-white text-sm outline-none transition text-left"
                        placeholder="مثلاً 09123456789">
                    @error('phone')<p class="text-xs text-red-400 mt-1">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-300 mb-1.5">رمز عبور</label>
                    <input type="password" id="password" name="password" required
                        class="w-full bg-gray-800 border border-gray-700 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 rounded-lg px-4 py-2.5 text-white text-sm outline-none transition"
                        placeholder="حداقل ۸ کاراکتر">
                </div>
                <div>
                    <label for="password_confirmation" class="block text-sm font-medium text-gray-300 mb-1.5">تکرار رمز عبور</label>
                    <input type="password" id="password_confirmation" name="password_confirmation" required
                        class="w-full bg-gray-800 border border-gray-700 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 rounded-lg px-4 py-2.5 text-white text-sm outline-none transition"
                        placeholder="••••••••">
                </div>
                <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-3 rounded-xl transition">
                    ایجاد حساب
                </button>
            </form>
        </div>

        <p class="text-center text-sm text-gray-500 mt-6">
            حساب کاربری دارید؟
            <a href="{{ route('login') }}" class="text-indigo-400 hover:text-indigo-300 font-medium">وارد شوید</a>
        </p>
    </div>
</section>
@endsection
