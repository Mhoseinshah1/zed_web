@extends('layouts.app')

@section('title', site_setting('shop_page_title', 'پلن‌های خرید'))
@section('description', site_setting('shop_page_description', 'انتخاب پلن VPN و پروکسی مناسب با نیاز شما'))

@section('content')
<section class="py-16 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8"
         x-data="{ cat: 'all' }">
    <div class="text-center mb-10">
        <h1 class="text-4xl font-extrabold text-white">{{ site_setting('shop_page_title', site_setting('plans.title', 'انتخاب پلن')) }}</h1>
        <p class="text-gray-400 mt-3 text-lg">{{ site_setting('shop_page_subtitle', site_setting('plans.subtitle', 'یک پلن مناسب انتخاب کنید و همین الان شروع کنید')) }}</p>
        @if($d = site_setting('shop_page_description'))<p class="text-gray-500 mt-2 text-sm max-w-2xl mx-auto">{{ $d }}</p>@endif
    </div>

    {{-- Top banners --}}
    @if(isset($topBanners) && $topBanners->isNotEmpty())
        <div class="mb-10">@include('partials.banners', ['banners' => $topBanners])</div>
    @endif

    {{-- Trust / guarantee strip --}}
    @php $trust = site_setting('trust_text'); $guarantee = site_setting('guarantee_text'); @endphp
    @if($trust || $guarantee)
    <div class="flex flex-wrap justify-center gap-3 mb-10 text-sm">
        @if($trust)<span class="zed-surface-soft rounded-lg px-4 py-2 text-gray-300">✓ {{ $trust }}</span>@endif
        @if($guarantee)<span class="zed-surface-soft rounded-lg px-4 py-2 text-gray-300">✓ {{ $guarantee }}</span>@endif
    </div>
    @endif

    {{-- Category tabs --}}
    @if(isset($categories) && $categories->isNotEmpty())
    <div class="flex flex-wrap justify-center gap-2 mb-10">
        <button @click="cat = 'all'" :class="cat === 'all' ? 'zed-btn-primary text-white' : 'bg-gray-800 text-gray-300'"
                class="zed-btn px-4 py-2 text-sm font-medium">همه پلن‌ها</button>
        @foreach($categories as $category)
            <button @click="cat = '{{ $category->id }}'" :class="cat === '{{ $category->id }}' ? 'zed-btn-primary text-white' : 'bg-gray-800 text-gray-300'"
                    class="zed-btn px-4 py-2 text-sm font-medium">
                @if($category->icon){{ $category->icon }} @endif{{ $category->title }}
            </button>
        @endforeach
    </div>
    @endif

    @if($plans->isEmpty())
    <div class="text-center text-gray-500 py-20">
        <p class="text-xl">پلنی برای نمایش وجود ندارد. به‌زودی اضافه می‌شود.</p>
    </div>
    @else
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
        @foreach($plans as $plan)
        <div x-show="cat === 'all' || cat === '{{ $plan->category_id }}'" x-transition>
            @include('partials.plan-card', ['plan' => $plan])
        </div>
        @endforeach
    </div>

    {{-- Payment / discount help --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mt-12">
        @if($ph = site_setting('payment_help_text'))
        <div class="zed-card p-5 text-sm text-gray-300"><p class="font-semibold text-white mb-1">راهنمای پرداخت</p>{{ $ph }}</div>
        @endif
        @if($dh = site_setting('discount_help_text'))
        <div class="zed-card p-5 text-sm text-gray-300"><p class="font-semibold text-white mb-1">کد تخفیف</p>{{ $dh }}</div>
        @endif
    </div>

    <p class="text-center text-gray-500 text-sm mt-10">قیمت‌ها به تومان هستند.</p>
    @endif
</section>
@endsection
@push('scripts')
<script src="//unpkg.com/alpinejs" defer></script>
@endpush
