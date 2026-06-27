@extends('layouts.panel')

@section('title', 'سرویس‌های من')

@section('content')
<div class="bg-gray-900 border border-gray-800 rounded-xl p-8">
    <div class="text-center py-12 text-gray-500">
        <div class="text-6xl mb-4">🔌</div>
        <h3 class="text-white font-semibold text-lg mb-2">سرویس‌های شما</h3>
        <p class="text-sm mb-1">سرویس‌های شما به‌زودی اینجا نمایش داده می‌شوند</p>
        <p class="text-xs text-gray-600 mb-6">پس از خرید و تایید پرداخت، سرویس VPN شما در این بخش فعال خواهد شد.</p>
        <a href="{{ route('plans') }}" class="inline-block bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-6 py-2.5 rounded-lg transition">
            خرید سرویس VPN
        </a>
    </div>
</div>
@endsection
