@extends('layouts.app')

@section('title', 'سوالات متداول')

@section('content')
<section class="py-16 max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
    <div class="text-center mb-14">
        <h1 class="text-4xl font-extrabold text-white">سوالات متداول</h1>
        <p class="text-gray-400 mt-3">پاسخ سوالات رایج درباره {{ site_setting('site_name', 'ZedProxy') }}</p>
    </div>

    @php
        $items = (isset($faqs) && $faqs->isNotEmpty())
            ? $faqs->map(fn ($f) => ['q' => $f->question, 'a' => $f->answer])
            : collect([
                ['q' => 'چطور سرویس بخرم؟', 'a' => 'از صفحه پلن‌ها، پلن موردنظر را انتخاب کرده و مراحل پرداخت را تکمیل کنید.'],
                ['q' => 'بعد از خرید چطور وصل شوم؟', 'a' => 'پس از خرید، اطلاعات اتصال در داشبورد نمایش داده می‌شود.'],
            ]);
    @endphp

    <div class="space-y-4">
        @foreach ($items as $item)
        <div class="zed-card overflow-hidden" x-data="{ open: false }">
            <button @click="open = !open" class="w-full flex items-center justify-between p-5 text-right hover:bg-gray-800/50 transition">
                <span class="font-medium text-white">{{ $item['q'] }}</span>
                <svg class="w-5 h-5 text-gray-400 transition-transform" :class="{ 'rotate-180': open }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>
            <div x-show="open" x-transition class="px-5 pb-5 text-gray-400 text-sm leading-relaxed border-t border-gray-800">
                <div class="pt-4">{{ $item['a'] }}</div>
            </div>
        </div>
        @endforeach
    </div>

    <div class="mt-12 text-center zed-card p-8">
        <h3 class="text-lg font-semibold text-white mb-2">سوال دیگری دارید؟</h3>
        <p class="text-gray-400 text-sm mb-6">{{ site_setting('support_description', 'تیم پشتیبانی ما آماده کمک است') }}</p>
        <a href="{{ route('contact') }}" class="zed-btn zed-btn-primary inline-block font-semibold px-6 py-2.5">
            تماس با پشتیبانی
        </a>
    </div>
</section>
@endsection

@push('scripts')
<script src="//unpkg.com/alpinejs" defer></script>
@endpush
