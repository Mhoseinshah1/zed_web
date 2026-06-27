@extends('layouts.panel')

@section('title', 'تیکت‌های پشتیبانی')

@section('content')
<div class="bg-gray-900 border border-gray-800 rounded-xl p-6">
    <div class="flex items-center justify-between mb-6">
        <h2 class="font-semibold text-white">تیکت‌های من</h2>
        <button disabled class="bg-gray-700 text-gray-500 text-sm font-medium px-4 py-2 rounded-lg cursor-not-allowed border border-gray-600">
            تیکت جدید (به زودی)
        </button>
    </div>
    <div class="text-center py-16 text-gray-500">
        <div class="text-5xl mb-4">🎫</div>
        <p>تیکتی وجود ندارد</p>
        <p class="text-xs mt-2">سیستم تیکت در نسخه‌های بعدی فعال می‌شود. تا آن زمان از تلگرام استفاده کنید</p>
        <a href="{{ route('contact') }}" class="inline-block mt-6 text-indigo-400 hover:text-indigo-300 text-sm">
            تماس با پشتیبانی
        </a>
    </div>
</div>
@endsection
