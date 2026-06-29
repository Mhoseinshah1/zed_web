@extends('layouts.app')

@section('title', $tutorial->meta_title ?: $tutorial->title)
@section('description', $tutorial->meta_description ?: $tutorial->short_description)
@if($img = cms_asset_url($tutorial->og_image))@section('og_image', $img)@endif

@section('content')
<section class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
    <nav class="text-xs text-gray-500 mb-6">
        <a href="{{ route('home') }}" class="hover:text-gray-300">خانه</a>
        <span class="mx-1">/</span>
        <a href="{{ route('tutorials') }}" class="hover:text-gray-300">آموزش‌ها</a>
        <span class="mx-1">/</span>
        <span class="text-gray-400">{{ $tutorial->title }}</span>
    </nav>

    <div class="zed-card zed-animate p-6 sm:p-10">
        <span class="inline-block text-[11px] px-2.5 py-0.5 rounded-full bg-indigo-600/15 text-indigo-300 mb-4">
            {{ $tutorial->platformLabel() }}
        </span>
        <h1 class="text-2xl sm:text-3xl font-bold text-white mb-4">{{ $tutorial->title }}</h1>
        @if($tutorial->short_description)
            <p class="text-gray-400 mb-6">{{ $tutorial->short_description }}</p>
        @endif

        @if($tutorial->video_url)
            <div class="mb-6">
                <a href="{{ $tutorial->video_url }}" target="_blank" rel="noopener"
                   class="zed-btn zed-btn-primary inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold">
                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                    مشاهده ویدیو آموزشی
                </a>
            </div>
        @endif

        @if($img = cms_asset_url($tutorial->image))
            <img src="{{ $img }}" alt="{{ $tutorial->title }}" class="rounded-xl w-full mb-6">
        @endif

        <div class="prose-content text-gray-300 leading-8 text-sm sm:text-base">
            {!! $tutorial->content !!}
        </div>
    </div>

    @if($related->isNotEmpty())
        <div class="mt-10">
            <h2 class="text-lg font-bold text-white mb-4">آموزش‌های مرتبط</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                @foreach($related as $r)
                    <a href="{{ route('tutorials.show', $r->slug) }}" class="zed-card zed-hover-lift p-4 block">
                        <p class="font-semibold text-white">{{ $r->title }}</p>
                        @if($r->short_description)<p class="text-xs text-gray-400 mt-1">{{ $r->short_description }}</p>@endif
                    </a>
                @endforeach
            </div>
        </div>
    @endif
</section>
@endsection
