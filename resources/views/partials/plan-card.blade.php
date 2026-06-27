@php
    $isFeatured = $plan->is_featured;
    $cardClass   = $isFeatured
        ? 'bg-indigo-600 border-2 border-indigo-400 rounded-2xl p-8 flex flex-col relative shadow-2xl shadow-indigo-500/20'
        : 'bg-gray-900 border border-gray-800 rounded-2xl p-8 flex flex-col hover:border-gray-700 transition';
    $textClass   = $isFeatured ? 'text-indigo-200' : 'text-gray-400';
    $priceClass  = 'text-4xl font-extrabold text-white';
    $unitClass   = $isFeatured ? 'text-indigo-200' : 'text-gray-400';
    $periodClass = $isFeatured ? 'text-indigo-300' : 'text-gray-500';
    $liClass     = $isFeatured ? 'text-indigo-100' : 'text-gray-300';
    $checkClass  = $isFeatured ? 'text-yellow-300' : 'text-green-400';
    $btnClass    = $isFeatured
        ? 'mt-8 w-full text-center bg-white hover:bg-gray-100 text-indigo-700 font-bold py-3 rounded-xl transition cursor-pointer'
        : 'mt-8 w-full text-center bg-gray-800 hover:bg-gray-700 text-white font-semibold py-3 rounded-xl transition border border-gray-700 cursor-pointer';
@endphp

<div class="{{ $cardClass }}">
    @if($plan->badge)
    <div class="absolute -top-4 right-1/2 translate-x-1/2">
        <span class="bg-yellow-400 text-gray-900 text-xs font-bold px-4 py-1.5 rounded-full whitespace-nowrap">{{ $plan->badge }}</span>
    </div>
    @endif

    <div class="{{ $textClass }} text-sm font-medium tracking-wide">{{ $plan->name }}</div>

    <div class="mt-4 {{ $priceClass }}">
        {{ number_format($plan->price_toman) }}
        <span class="text-lg font-normal {{ $unitClass }}">تومان</span>
    </div>

    @if($plan->old_price_toman)
    <div class="text-sm {{ $textClass }} line-through mt-1">{{ number_format($plan->old_price_toman) }} تومان</div>
    @endif

    <div class="{{ $periodClass }} text-sm mt-1">
        {{ $plan->durationLabel() }}
    </div>

    <ul class="mt-8 space-y-3 text-sm {{ $liClass }} flex-1">
        <li class="flex items-center gap-2">
            <span class="{{ $checkClass }}">✓</span>
            حجم: {{ $plan->trafficLabel() }}
        </li>
        @foreach($plan->features as $feature)
        <li class="flex items-center gap-2">
            <span class="{{ $checkClass }}">{{ $feature->icon ?? '✓' }}</span>
            {{ $feature->title }}
        </li>
        @endforeach
    </ul>

    @auth
        <form method="POST" action="{{ route('plans.buy', $plan) }}" class="mt-8">
            @csrf
            <button type="submit" class="{{ str_replace('mt-8 ', '', $btnClass) }} w-full py-3">
                خرید این پلن
            </button>
        </form>
    @else
        <a href="{{ route('login') }}"
           class="{{ $btnClass }}">
            ورود برای خرید
        </a>
    @endauth
</div>
