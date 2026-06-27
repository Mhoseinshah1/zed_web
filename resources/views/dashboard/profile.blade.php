@extends('layouts.panel')

@section('title', 'پروفایل')

@section('content')
<div class="max-w-lg">
    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
        <div class="p-6 border-b border-gray-800">
            <h2 class="font-semibold text-white">اطلاعات حساب کاربری</h2>
        </div>
        <div class="p-6 space-y-5 text-sm">
            <div class="flex justify-between items-center py-3 border-b border-gray-800">
                <span class="text-gray-400">نام</span>
                <span class="text-white">{{ $user->name }}</span>
            </div>
            <div class="flex justify-between items-center py-3 border-b border-gray-800">
                <span class="text-gray-400">نام کاربری</span>
                <span class="text-white font-mono">{{ $user->username }}</span>
            </div>
            @if($user->email)
            <div class="flex justify-between items-center py-3 border-b border-gray-800">
                <span class="text-gray-400">ایمیل</span>
                <span class="text-white">{{ $user->email }}</span>
            </div>
            @endif
            <div class="flex justify-between items-center py-3 border-b border-gray-800">
                <span class="text-gray-400">تاریخ ثبت‌نام</span>
                <span class="text-white">{{ $user->created_at->format('Y/m/d') }}</span>
            </div>
            <div class="flex justify-between items-center py-3">
                <span class="text-gray-400">تعداد سفارش‌ها</span>
                <span class="text-white">{{ $user->orders()->count() }}</span>
            </div>
        </div>
    </div>
    <p class="text-xs text-gray-600 mt-4 text-center">برای تغییر اطلاعات حساب با پشتیبانی تماس بگیرید.</p>
</div>
@endsection
