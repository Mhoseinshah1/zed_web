@extends('layouts.app')

@section('title', 'تعیین رمز عبور جدید')

@section('content')
<section class="min-h-[70vh] flex items-center justify-center py-16 px-4">
    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <a href="{{ route('home') }}" class="text-2xl font-bold text-white">
                <span class="text-indigo-400">Zed</span>Proxy
            </a>
            <h1 class="text-xl font-semibold text-white mt-4">تعیین رمز عبور جدید</h1>
            <p class="text-gray-400 text-sm mt-1">رمز عبور جدید حساب خود را وارد کنید</p>
        </div>

        <div class="bg-gray-900 border border-gray-800 rounded-2xl p-8">
            @if ($errors->any())
                <div class="bg-red-500/10 border border-red-500/30 rounded-lg p-4 mb-6 text-sm text-red-300">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('password.update') }}" class="space-y-5">
                @csrf
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-300 mb-1.5">رمز عبور جدید</label>
                    <input type="password" id="password" name="password" required autocomplete="new-password"
                        class="w-full bg-gray-800 border border-gray-700 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 rounded-lg px-4 py-2.5 text-white text-sm outline-none transition"
                        placeholder="حداقل ۸ کاراکتر">
                </div>
                <div>
                    <label for="password_confirmation" class="block text-sm font-medium text-gray-300 mb-1.5">تکرار رمز عبور جدید</label>
                    <input type="password" id="password_confirmation" name="password_confirmation" required autocomplete="new-password"
                        class="w-full bg-gray-800 border border-gray-700 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 rounded-lg px-4 py-2.5 text-white text-sm outline-none transition"
                        placeholder="تکرار رمز عبور">
                </div>
                <button type="submit" class="w-full bg-indigo-600 hover:bg-indigo-500 text-white font-semibold py-3 rounded-xl transition">
                    تغییر رمز عبور
                </button>
            </form>
        </div>
    </div>
</section>
@endsection
