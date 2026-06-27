@extends('layouts.app')

@section('title', 'تماس با پشتیبانی')

@section('content')
<section class="py-16 max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
    <div class="text-center mb-14">
        <h1 class="text-4xl font-extrabold text-white">تماس با ما</h1>
        <p class="text-gray-400 mt-3">پشتیبانی ۲۴ ساعته آماده کمک به شماست</p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 mb-10">
        <div class="bg-gray-900 border border-gray-800 rounded-2xl p-6 text-center hover:border-indigo-500/50 transition">
            <div class="text-4xl mb-4">💬</div>
            <h3 class="font-semibold text-white mb-2">تلگرام</h3>
            <p class="text-gray-400 text-sm mb-4">سریع‌ترین روش پشتیبانی</p>
            <a href="#" class="inline-block bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-5 py-2 rounded-lg transition">
                پیام در تلگرام
            </a>
        </div>
        <div class="bg-gray-900 border border-gray-800 rounded-2xl p-6 text-center hover:border-indigo-500/50 transition">
            <div class="text-4xl mb-4">🎫</div>
            <h3 class="font-semibold text-white mb-2">تیکت پشتیبانی</h3>
            <p class="text-gray-400 text-sm mb-4">برای مشکلات فنی و اکانت</p>
            <a href="{{ route('login') }}" class="inline-block bg-gray-700 hover:bg-gray-600 text-white text-sm font-medium px-5 py-2 rounded-lg transition border border-gray-600">
                ثبت تیکت
            </a>
        </div>
    </div>

    <div class="bg-gray-900 border border-gray-800 rounded-2xl p-8">
        <h2 class="text-xl font-bold text-white mb-6">ارسال پیام</h2>
        <div class="bg-yellow-500/10 border border-yellow-500/20 rounded-lg p-4 text-sm text-yellow-300 mb-6">
            این فرم در حال حاضر در مرحله ساخت است. لطفاً از تلگرام یا تیکت پشتیبانی استفاده کنید.
        </div>
        <form class="space-y-4" onsubmit="return false;">
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-1.5">نام</label>
                <input type="text" placeholder="نام شما" disabled
                    class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2.5 text-gray-400 text-sm cursor-not-allowed">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-1.5">ایمیل</label>
                <input type="email" placeholder="email@example.com" disabled
                    class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2.5 text-gray-400 text-sm cursor-not-allowed">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-1.5">پیام</label>
                <textarea rows="4" placeholder="پیام خود را بنویسید..." disabled
                    class="w-full bg-gray-800 border border-gray-700 rounded-lg px-4 py-2.5 text-gray-400 text-sm cursor-not-allowed resize-none"></textarea>
            </div>
            <button type="submit" disabled
                class="w-full bg-gray-700 text-gray-500 font-semibold py-3 rounded-xl cursor-not-allowed">
                ارسال پیام (به زودی)
            </button>
        </form>
    </div>
</section>
@endsection
