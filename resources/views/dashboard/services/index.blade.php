@extends('layouts.panel')

@section('title', 'سرویس‌های من')

@section('content')
@if($services->isEmpty())
<div class="bg-gray-900 border border-gray-800 rounded-xl p-8">
    <div class="text-center py-12 text-gray-500">
        <div class="text-6xl mb-4">🔌</div>
        <h3 class="text-white font-semibold text-lg mb-2">هنوز سرویسی ندارید</h3>
        <p class="text-sm mb-1">پس از خرید و تایید پرداخت، سرویس VPN شما در این بخش نمایش داده می‌شود.</p>
        <a href="{{ route('plans') }}" class="inline-block mt-6 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-6 py-2.5 rounded-lg transition">
            خرید سرویس VPN
        </a>
    </div>
</div>
@else
<div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
    <div class="p-6 border-b border-gray-800">
        <h2 class="font-semibold text-white">سرویس‌های من</h2>
    </div>

    <div class="divide-y divide-gray-800">
        @foreach($services as $service)
        @php
            $statusColor = match($service->status) {
                'active'            => 'text-green-400 bg-green-500/10 border-green-500/30',
                'pending_provision' => 'text-yellow-400 bg-yellow-500/10 border-yellow-500/30',
                'disabled'          => 'text-orange-400 bg-orange-500/10 border-orange-500/30',
                'expired'           => 'text-gray-400 bg-gray-500/10 border-gray-500/30',
                default             => 'text-red-400 bg-red-500/10 border-red-500/30',
            };
            $daysLeft = $service->daysRemaining();
        @endphp
        <div class="p-5 hover:bg-gray-800/30 transition">
            <div class="flex items-start justify-between gap-4">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-3 mb-2">
                        <span class="font-medium text-white text-sm">{{ $service->plan_name ?? 'سرویس VPN' }}</span>
                        <span class="inline-flex border text-xs px-2 py-0.5 rounded-full {{ $statusColor }}">
                            {{ $service->statusLabel() }}
                        </span>
                    </div>
                    <div class="text-xs text-gray-500 font-mono mb-3">{{ $service->service_number }}</div>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-xs">
                        <div>
                            <span class="text-gray-500 block">حجم کل</span>
                            <span class="text-gray-300">{{ $service->traffic_total_gb ? $service->traffic_total_gb . ' GB' : 'نامحدود' }}</span>
                        </div>
                        <div>
                            <span class="text-gray-500 block">مصرف شده</span>
                            <span class="text-gray-300">{{ $service->traffic_used_gb ?? 0 }} GB</span>
                        </div>
                        <div>
                            <span class="text-gray-500 block">باقی‌مانده</span>
                            @if($service->traffic_total_gb)
                                <span class="{{ ($service->trafficRemainingGb() ?? 0) < 5 ? 'text-red-400' : 'text-green-400' }}">
                                    {{ $service->trafficRemainingGb() }} GB
                                </span>
                            @else
                                <span class="text-gray-300">نامحدود</span>
                            @endif
                        </div>
                        <div>
                            <span class="text-gray-500 block">انقضا</span>
                            @if($service->expires_at)
                                <span class="{{ $daysLeft !== null && $daysLeft <= 3 ? 'text-red-400' : 'text-gray-300' }}">
                                    {{ $service->expires_at->format('Y/m/d') }}
                                    @if($daysLeft !== null)
                                        ({{ $daysLeft }} روز)
                                    @endif
                                </span>
                            @else
                                <span class="text-gray-500">—</span>
                            @endif
                        </div>
                    </div>
                </div>

                <a href="{{ route('dashboard.services.show', $service) }}"
                   class="shrink-0 text-xs bg-gray-800 hover:bg-gray-700 text-white px-3 py-1.5 rounded-lg transition">
                    مشاهده
                </a>
            </div>
        </div>
        @endforeach
    </div>
</div>

@if($services->hasPages())
<div class="mt-4">
    {{ $services->links() }}
</div>
@endif
@endif
@endsection
