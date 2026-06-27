@extends('layouts.app')

@section('title', 'پلن‌های خرید')
@section('description', 'انتخاب پلن VPN و پروکسی مناسب با نیاز شما')

@section('content')
<section class="py-16 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <div class="text-center mb-14">
        <h1 class="text-4xl font-extrabold text-white">انتخاب پلن</h1>
        <p class="text-gray-400 mt-3 text-lg">یک پلن مناسب انتخاب کنید و همین الان شروع کنید</p>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
        {{-- Starter --}}
        <div class="bg-gray-900 border border-gray-800 rounded-2xl p-8 flex flex-col hover:border-gray-700 transition">
            <div class="text-gray-400 text-sm font-medium uppercase tracking-wide">استارتر</div>
            <div class="mt-4 text-4xl font-extrabold text-white">۴۹,۰۰۰ <span class="text-lg font-normal text-gray-400">تومان</span></div>
            <div class="text-gray-500 text-sm mt-1">ماهانه</div>
            <ul class="mt-8 space-y-3 text-sm text-gray-300 flex-1">
                <li class="flex items-center gap-2"><span class="text-green-400">✓</span> ۳۰ گیگابایت حجم</li>
                <li class="flex items-center gap-2"><span class="text-green-400">✓</span> ۱ کاربر</li>
                <li class="flex items-center gap-2"><span class="text-green-400">✓</span> سرورهای اروپا</li>
                <li class="flex items-center gap-2"><span class="text-green-400">✓</span> پروتکل V2Ray</li>
                <li class="flex items-center gap-2"><span class="text-gray-600">✗</span> <span class="text-gray-500">سرورهای آمریکا</span></li>
            </ul>
            <a href="{{ route('register') }}" class="mt-8 block text-center bg-gray-800 hover:bg-gray-700 text-white font-semibold py-3 rounded-xl transition border border-gray-700">
                خرید پلن
            </a>
        </div>

        {{-- Pro (recommended) --}}
        <div class="bg-indigo-600 border-2 border-indigo-400 rounded-2xl p-8 flex flex-col relative shadow-2xl shadow-indigo-500/20">
            <div class="absolute -top-4 right-1/2 translate-x-1/2">
                <span class="bg-yellow-400 text-gray-900 text-xs font-bold px-4 py-1.5 rounded-full">محبوب‌ترین</span>
            </div>
            <div class="text-indigo-200 text-sm font-medium uppercase tracking-wide">حرفه‌ای</div>
            <div class="mt-4 text-4xl font-extrabold text-white">۸۹,۰۰۰ <span class="text-lg font-normal text-indigo-200">تومان</span></div>
            <div class="text-indigo-300 text-sm mt-1">ماهانه</div>
            <ul class="mt-8 space-y-3 text-sm text-indigo-100 flex-1">
                <li class="flex items-center gap-2"><span class="text-yellow-300">✓</span> ۱۰۰ گیگابایت حجم</li>
                <li class="flex items-center gap-2"><span class="text-yellow-300">✓</span> ۳ کاربر</li>
                <li class="flex items-center gap-2"><span class="text-yellow-300">✓</span> سرورهای اروپا و آمریکا</li>
                <li class="flex items-center gap-2"><span class="text-yellow-300">✓</span> پروتکل V2Ray</li>
                <li class="flex items-center gap-2"><span class="text-yellow-300">✓</span> اولویت پشتیبانی</li>
            </ul>
            <a href="{{ route('register') }}" class="mt-8 block text-center bg-white hover:bg-gray-100 text-indigo-700 font-bold py-3 rounded-xl transition">
                خرید پلن
            </a>
        </div>

        {{-- Business --}}
        <div class="bg-gray-900 border border-gray-800 rounded-2xl p-8 flex flex-col hover:border-gray-700 transition">
            <div class="text-gray-400 text-sm font-medium uppercase tracking-wide">بیزینس</div>
            <div class="mt-4 text-4xl font-extrabold text-white">۱۸۹,۰۰۰ <span class="text-lg font-normal text-gray-400">تومان</span></div>
            <div class="text-gray-500 text-sm mt-1">ماهانه</div>
            <ul class="mt-8 space-y-3 text-sm text-gray-300 flex-1">
                <li class="flex items-center gap-2"><span class="text-green-400">✓</span> حجم نامحدود</li>
                <li class="flex items-center gap-2"><span class="text-green-400">✓</span> ۱۰ کاربر</li>
                <li class="flex items-center gap-2"><span class="text-green-400">✓</span> تمام سرورها</li>
                <li class="flex items-center gap-2"><span class="text-green-400">✓</span> چند پروتکل</li>
                <li class="flex items-center gap-2"><span class="text-green-400">✓</span> پشتیبانی اختصاصی</li>
            </ul>
            <a href="{{ route('register') }}" class="mt-8 block text-center bg-gray-800 hover:bg-gray-700 text-white font-semibold py-3 rounded-xl transition border border-gray-700">
                خرید پلن
            </a>
        </div>
    </div>

    <p class="text-center text-gray-500 text-sm mt-10">
        قیمت‌ها به تومان هستند. پرداخت از طریق درگاه‌های معتبر انجام می‌شود.
    </p>
</section>
@endsection
