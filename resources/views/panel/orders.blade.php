@extends('layouts.panel')

@section('title', 'سفارش‌ها')

@section('content')
<div class="bg-gray-900 border border-gray-800 rounded-xl p-6">
    <div class="flex items-center justify-between mb-6">
        <h2 class="font-semibold text-white">سفارش‌های من</h2>
        <a href="{{ route('plans') }}" class="bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-4 py-2 rounded-lg transition">
            خرید جدید
        </a>
    </div>
    <div class="text-center py-16 text-gray-500">
        <div class="text-5xl mb-4">📋</div>
        <p>هنوز سفارشی ثبت نشده است</p>
        <p class="text-xs mt-2">پس از خرید اولین پلن، سفارش‌های شما اینجا نمایش داده می‌شوند</p>
    </div>
</div>
@endsection
