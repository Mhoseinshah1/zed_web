@extends('layouts.app')

@section('title', 'پلن‌های خرید')
@section('description', 'انتخاب پلن VPN و پروکسی مناسب با نیاز شما')

@section('content')
<section class="py-16 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
    <div class="text-center mb-14">
        <h1 class="text-4xl font-extrabold text-white">{{ site_setting('plans.title', 'انتخاب پلن') }}</h1>
        <p class="text-gray-400 mt-3 text-lg">{{ site_setting('plans.subtitle', 'یک پلن مناسب انتخاب کنید و همین الان شروع کنید') }}</p>
    </div>

    @if($plans->isEmpty())
    <div class="text-center text-gray-500 py-20">
        <p class="text-xl">پلنی برای نمایش وجود ندارد. به‌زودی اضافه می‌شود.</p>
    </div>
    @else
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
        @foreach($plans as $plan)
        @include('partials.plan-card', ['plan' => $plan])
        @endforeach
    </div>

    <p class="text-center text-gray-500 text-sm mt-10">
        قیمت‌ها به تومان هستند.
    </p>
    @endif
</section>
@endsection
