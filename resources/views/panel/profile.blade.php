@extends('layouts.panel')

@section('title', 'پروفایل')

@section('content')
<div class="max-w-2xl">
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-6">
        <h2 class="font-semibold text-white mb-6">اطلاعات حساب</h2>
        <div class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-1.5">نام</label>
                <div class="bg-gray-800 border border-gray-700 rounded-lg px-4 py-2.5 text-gray-300 text-sm">
                    {{ auth()->user()->name ?? '-' }}
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-1.5">ایمیل</label>
                <div class="bg-gray-800 border border-gray-700 rounded-lg px-4 py-2.5 text-gray-300 text-sm">
                    {{ auth()->user()->email ?? '-' }}
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-300 mb-1.5">تاریخ ثبت‌نام</label>
                <div class="bg-gray-800 border border-gray-700 rounded-lg px-4 py-2.5 text-gray-300 text-sm">
                    {{ auth()->user()->created_at?->format('Y/m/d') ?? '-' }}
                </div>
            </div>
        </div>
        <div class="mt-6 pt-6 border-t border-gray-800">
            <p class="text-sm text-gray-500">ویرایش پروفایل در نسخه‌های بعدی اضافه خواهد شد.</p>
        </div>
    </div>
</div>
@endsection
