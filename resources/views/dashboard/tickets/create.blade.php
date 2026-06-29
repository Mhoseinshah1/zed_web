@extends('layouts.panel')

@section('title', 'ایجاد تیکت جدید')

@section('content')
<div class="max-w-2xl space-y-6">

    <div class="flex items-center gap-4">
        <a href="{{ route('dashboard.tickets') }}" class="text-gray-400 hover:text-white transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
        </a>
        <h1 class="text-xl font-bold text-white">ایجاد تیکت جدید</h1>
    </div>

    @if($errors->any())
    <div class="bg-red-500/10 border border-red-500/30 rounded-xl px-5 py-3 text-sm text-red-300">
        <ul class="list-disc pr-4 space-y-1">
            @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
        </ul>
    </div>
    @endif

    <form method="POST" action="{{ route('dashboard.tickets.store') }}" enctype="multipart/form-data"
          class="bg-gray-900 border border-gray-800 rounded-xl p-6 space-y-4">
        @csrf

        <div>
            <label class="block text-sm text-gray-300 mb-1.5">موضوع</label>
            <input type="text" name="subject" value="{{ old('subject') }}" required maxlength="255"
                   class="w-full bg-gray-800 border border-gray-700 focus:border-indigo-500 rounded-lg px-4 py-2.5 text-white text-sm outline-none">
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm text-gray-300 mb-1.5">دسته‌بندی</label>
                <select name="category_id" class="w-full bg-gray-800 border border-gray-700 focus:border-indigo-500 rounded-lg px-4 py-2.5 text-white text-sm outline-none">
                    <option value="">— انتخاب کنید —</option>
                    @foreach($categories as $category)
                    <option value="{{ $category->id }}" @selected(old('category_id') == $category->id)>{{ $category->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-1.5">اولویت</label>
                <select name="priority" class="w-full bg-gray-800 border border-gray-700 focus:border-indigo-500 rounded-lg px-4 py-2.5 text-white text-sm outline-none">
                    @foreach($priorities as $value => $label)
                    <option value="{{ $value }}" @selected(old('priority', 'normal') == $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm text-gray-300 mb-1.5">سفارش مرتبط (اختیاری)</label>
                <select name="order_id" class="w-full bg-gray-800 border border-gray-700 focus:border-indigo-500 rounded-lg px-4 py-2.5 text-white text-sm outline-none">
                    <option value="">— ندارد —</option>
                    @foreach($orders as $order)
                    <option value="{{ $order->id }}" @selected(old('order_id') == $order->id)>{{ $order->order_number }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm text-gray-300 mb-1.5">سرویس مرتبط (اختیاری)</label>
                <select name="user_service_id" class="w-full bg-gray-800 border border-gray-700 focus:border-indigo-500 rounded-lg px-4 py-2.5 text-white text-sm outline-none">
                    <option value="">— ندارد —</option>
                    @foreach($services as $service)
                    <option value="{{ $service->id }}" @selected(old('user_service_id') == $service->id)>{{ $service->service_number }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div>
            <label class="block text-sm text-gray-300 mb-1.5">متن پیام</label>
            <textarea name="body" rows="6" required maxlength="5000"
                      class="w-full bg-gray-800 border border-gray-700 focus:border-indigo-500 rounded-lg px-4 py-2.5 text-white text-sm outline-none">{{ old('body') }}</textarea>
        </div>

        <div>
            <label class="block text-sm text-gray-300 mb-1.5">پیوست (اختیاری)</label>
            <input type="file" name="attachment"
                   class="w-full text-sm text-gray-400 file:mr-3 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-gray-800 file:text-gray-200">
            <p class="text-xs text-gray-600 mt-1">فرمت‌های مجاز: jpg, png, webp, pdf, txt — حداکثر ۵ مگابایت</p>
        </div>

        <div class="flex gap-3 pt-2">
            <button type="submit" class="bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-6 py-2.5 rounded-lg transition">
                ثبت تیکت
            </button>
            <a href="{{ route('dashboard.tickets') }}" class="bg-gray-800 hover:bg-gray-700 text-gray-300 text-sm font-medium px-6 py-2.5 rounded-lg transition">
                انصراف
            </a>
        </div>
    </form>
</div>
@endsection
