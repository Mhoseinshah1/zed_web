@extends('layouts.panel')

@section('title', 'سرویس‌های من')

@section('content')
<div class="bg-gray-900 border border-gray-800 rounded-xl p-6">
    <h2 class="font-semibold text-white mb-6">سرویس‌های فعال</h2>
    <div class="text-center py-16 text-gray-500">
        <div class="text-5xl mb-4">🔌</div>
        <p>سرویس فعالی وجود ندارد</p>
        <p class="text-xs mt-2">پس از خرید پلن، لینک اشتراک V2Ray اینجا نمایش داده می‌شود</p>
        <a href="{{ route('plans') }}" class="inline-block mt-6 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-6 py-2.5 rounded-lg transition">
            خرید سرویس
        </a>
    </div>
</div>
@endsection
