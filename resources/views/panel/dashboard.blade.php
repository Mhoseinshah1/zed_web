@extends('layouts.panel')

@section('title', 'داشبورد')

@section('content')
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5 mb-8">
    @foreach ([
        ['label' => 'سرویس فعال', 'value' => '۰', 'icon' => '🔌', 'color' => 'indigo'],
        ['label' => 'سفارش‌ها', 'value' => '۰', 'icon' => '📋', 'color' => 'purple'],
        ['label' => 'تیکت باز', 'value' => '۰', 'icon' => '🎫', 'color' => 'yellow'],
        ['label' => 'حجم مصرفی', 'value' => '۰ GB', 'icon' => '📊', 'color' => 'green'],
    ] as $stat)
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
        <div class="flex items-center justify-between">
            <div>
                <p class="text-gray-400 text-sm">{{ $stat['label'] }}</p>
                <p class="text-2xl font-bold text-white mt-1">{{ $stat['value'] }}</p>
            </div>
            <span class="text-3xl">{{ $stat['icon'] }}</span>
        </div>
    </div>
    @endforeach
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-6">
        <h3 class="font-semibold text-white mb-4">سرویس‌های فعال</h3>
        <div class="text-center py-10 text-gray-500">
            <div class="text-4xl mb-3">📭</div>
            <p class="text-sm">سرویس فعالی ندارید</p>
            <a href="{{ route('plans') }}" class="inline-block mt-4 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-5 py-2 rounded-lg transition">
                خرید سرویس
            </a>
        </div>
    </div>

    <div class="bg-gray-900 border border-gray-800 rounded-xl p-6">
        <h3 class="font-semibold text-white mb-4">آخرین سفارش‌ها</h3>
        <div class="text-center py-10 text-gray-500">
            <div class="text-4xl mb-3">🛒</div>
            <p class="text-sm">سفارشی وجود ندارد</p>
        </div>
    </div>
</div>

<div class="mt-6 bg-indigo-500/10 border border-indigo-500/20 rounded-xl p-5 text-sm text-indigo-300">
    <strong>خوش آمدید!</strong> برای شروع استفاده از ZedProxy، یک پلن مناسب خریداری کنید.
    سرویس شما پس از خرید به صورت خودکار فعال می‌شود.
</div>
@endsection
