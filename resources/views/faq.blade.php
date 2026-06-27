@extends('layouts.app')

@section('title', 'سوالات متداول')

@section('content')
<section class="py-16 max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
    <div class="text-center mb-14">
        <h1 class="text-4xl font-extrabold text-white">سوالات متداول</h1>
        <p class="text-gray-400 mt-3">پاسخ سوالات رایج درباره ZedProxy</p>
    </div>

    <div class="space-y-4">
        @foreach ([
            ['q' => 'ZedProxy چیست؟', 'a' => 'ZedProxy یک سرویس VPN و پروکسی مبتنی بر پروتکل V2Ray است که دسترسی آزاد و امن به اینترنت را فراهم می‌کند.'],
            ['q' => 'از چه پروتکل‌هایی پشتیبانی می‌شود؟', 'a' => 'در حال حاضر از پروتکل V2Ray (VMess/VLess) پشتیبانی می‌شود. پروتکل‌های بیشتر در نسخه‌های آینده اضافه خواهند شد.'],
            ['q' => 'چطور می‌توانم به سرویس وصل شوم؟', 'a' => 'پس از خرید، یک لینک اشتراک V2Ray دریافت می‌کنید که در اپلیکیشن‌های V2RayNG (اندروید)، V2Box (iOS) و قابل استفاده است.'],
            ['q' => 'آیا لاگ ترافیک من ذخیره می‌شود؟', 'a' => 'خیر. ما هیچ لاگی از ترافیک کاربران نگه نمی‌داریم. حریم خصوصی شما برای ما اولویت دارد.'],
            ['q' => 'در صورت قطعی سرویس چه باید کنم؟', 'a' => 'صفحه وضعیت سرویس را بررسی کنید. در صورت ادامه مشکل، یک تیکت پشتیبانی ثبت کنید.'],
            ['q' => 'آیا تمدید خودکار دارید؟', 'a' => 'در حال حاضر تمدید دستی است. قبل از انقضا ایمیل یادآوری دریافت خواهید کرد.'],
            ['q' => 'امکان استرداد وجه دارد؟', 'a' => 'در صورت عدم رضایت در ۲۴ ساعت اول، امکان استرداد وجه وجود دارد.'],
        ] as $i => $item)
        <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden" x-data="{ open: false }">
            <button @click="open = !open" class="w-full flex items-center justify-between p-5 text-right hover:bg-gray-800/50 transition">
                <span class="font-medium text-white">{{ $item['q'] }}</span>
                <svg class="w-5 h-5 text-gray-400 transition-transform" :class="{ 'rotate-180': open }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>
            <div x-show="open" x-transition class="px-5 pb-5 text-gray-400 text-sm leading-relaxed border-t border-gray-800">
                <div class="pt-4">{{ $item['a'] }}</div>
            </div>
        </div>
        @endforeach
    </div>

    <div class="mt-12 text-center bg-gray-900 border border-gray-800 rounded-2xl p-8">
        <h3 class="text-lg font-semibold text-white mb-2">سوال دیگری دارید؟</h3>
        <p class="text-gray-400 text-sm mb-6">تیم پشتیبانی ما آماده کمک است</p>
        <a href="{{ route('contact') }}" class="bg-indigo-600 hover:bg-indigo-500 text-white font-semibold px-6 py-2.5 rounded-lg transition">
            تماس با پشتیبانی
        </a>
    </div>
</section>
@endsection

@push('scripts')
<script src="//unpkg.com/alpinejs" defer></script>
@endpush
