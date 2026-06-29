@extends('layouts.app')

@section('title', $page->meta_title ?: $page->title)
@section('description', $page->meta_description ?: $page->excerpt)
@if($page->meta_keywords)@section('meta_keywords', $page->meta_keywords)@endif
@if($page->og_title)@section('og_title', $page->og_title)@endif
@if($page->og_description)@section('og_description', $page->og_description)@endif
@if($img = cms_asset_url($page->og_image))@section('og_image', $img)@endif

@section('content')
<section class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-16">
    <nav class="text-xs text-gray-500 mb-6">
        <a href="{{ route('home') }}" class="hover:text-gray-300">خانه</a>
        <span class="mx-1">/</span>
        <span class="text-gray-400">{{ $page->title }}</span>
    </nav>

    <div class="zed-card zed-animate p-6 sm:p-10">
        <h1 class="text-2xl sm:text-3xl font-bold text-white mb-6">{{ $page->title }}</h1>
        @if($page->excerpt)
            <p class="text-gray-400 mb-6">{{ $page->excerpt }}</p>
        @endif
        <div class="prose-content text-gray-300 leading-8 text-sm sm:text-base">
            {!! $page->content !!}
        </div>
    </div>
</section>
@endsection
