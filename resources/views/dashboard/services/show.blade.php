@extends('layouts.panel')

@section('title', 'جزئیات سرویس')

@section('content')
<div class="max-w-2xl">
    {{-- Header --}}
    <div class="flex items-center gap-4 mb-6">
        <a href="{{ route('dashboard.services') }}" class="text-gray-400 hover:text-white transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
            </svg>
        </a>
        <div>
            <h2 class="text-white font-semibold">جزئیات سرویس</h2>
            <p class="text-xs text-gray-500 mt-0.5 font-mono">{{ $service->service_number }}</p>
        </div>
    </div>

    {{-- Status banner --}}
    @if(in_array($service->status, ['pending_provision', 'failed']))
    <div class="bg-yellow-500/10 border border-yellow-500/20 rounded-xl p-5 mb-6">
        <div class="flex gap-3">
            <span class="text-xl">⏳</span>
            <div>
                <h4 class="text-yellow-300 font-semibold text-sm mb-1">سرویس هنوز آماده نشده است</h4>
                <p class="text-yellow-200/70 text-sm leading-6">
                    سرویس شما هنوز آماده نشده است.<br>
                    در صورت طولانی شدن، با پشتیبانی تماس بگیرید.
                </p>
            </div>
        </div>
    </div>
    @elseif($service->status === 'active')
    <div class="bg-green-500/10 border border-green-500/20 rounded-xl p-5 mb-6">
        <div class="flex gap-3">
            <span class="text-xl">✅</span>
            <div>
                <h4 class="text-green-300 font-semibold text-sm mb-1">سرویس فعال است</h4>
                <p class="text-green-200/70 text-sm">سرویس VPN شما فعال است و می‌توانید از آن استفاده کنید.</p>
            </div>
        </div>
    </div>
    @elseif($service->status === 'disabled')
    <div class="bg-orange-500/10 border border-orange-500/20 rounded-xl p-5 mb-6">
        <div class="flex gap-3">
            <span class="text-xl">⛔</span>
            <div>
                <h4 class="text-orange-300 font-semibold text-sm mb-1">سرویس غیرفعال است</h4>
                <p class="text-orange-200/70 text-sm">این سرویس توسط مدیریت غیرفعال شده است. با پشتیبانی تماس بگیرید.</p>
            </div>
        </div>
    </div>
    @elseif($service->status === 'expired')
    <div class="bg-gray-500/10 border border-gray-500/20 rounded-xl p-5 mb-6">
        <div class="flex gap-3">
            <span class="text-xl">🕐</span>
            <div>
                <h4 class="text-gray-300 font-semibold text-sm mb-1">سرویس منقضی شده</h4>
                <p class="text-gray-400 text-sm">مدت اعتبار این سرویس به پایان رسیده است.</p>
            </div>
        </div>
    </div>
    @endif

    {{-- Service info --}}
    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden mb-6">
        <div class="p-6 border-b border-gray-800">
            <div class="flex items-start justify-between">
                <div>
                    <h3 class="text-white font-semibold text-lg">{{ $service->plan_name ?? 'سرویس VPN' }}</h3>
                </div>
                @php
                    $statusColor = match($service->status) {
                        'active'            => 'bg-green-500/10 text-green-400 border-green-500/30',
                        'pending_provision' => 'bg-yellow-500/10 text-yellow-400 border-yellow-500/30',
                        'disabled'          => 'bg-orange-500/10 text-orange-400 border-orange-500/30',
                        'expired'           => 'bg-gray-500/10 text-gray-400 border-gray-500/30',
                        default             => 'bg-red-500/10 text-red-400 border-red-500/30',
                    };
                @endphp
                <span class="inline-block border text-xs px-3 py-1 rounded-full {{ $statusColor }}">
                    {{ $service->statusLabel() }}
                </span>
            </div>
        </div>

        <div class="p-6">
            <div class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <span class="text-gray-400 block mb-1">شماره سرویس</span>
                    <span class="text-white font-mono text-xs">{{ $service->service_number }}</span>
                </div>
                <div>
                    <span class="text-gray-400 block mb-1">وضعیت ساخت</span>
                    <span class="text-white">{{ $service->provisionStatusLabel() }}</span>
                </div>
                @if($service->traffic_total_gb)
                <div class="col-span-2">
                    {{-- Traffic bar --}}
                    @php
                        $used      = $service->traffic_used_gb ?? 0;
                        $total     = $service->traffic_total_gb;
                        $pct       = $total > 0 ? min(100, round($used / $total * 100)) : 0;
                        $barColor  = $pct >= 90 ? 'bg-red-500' : ($pct >= 70 ? 'bg-yellow-500' : 'bg-green-500');
                    @endphp
                    <span class="text-gray-400 block mb-2">مصرف حجم</span>
                    <div class="w-full bg-gray-800 rounded-full h-2 mb-1">
                        <div class="{{ $barColor }} h-2 rounded-full transition-all" style="width:{{ $pct }}%"></div>
                    </div>
                    <div class="flex justify-between text-xs text-gray-500">
                        <span>{{ $used }} GB مصرف شده</span>
                        <span>{{ $total }} GB کل ({{ $pct }}%)</span>
                    </div>
                </div>
                @else
                <div>
                    <span class="text-gray-400 block mb-1">حجم</span>
                    <span class="text-white">نامحدود</span>
                </div>
                @endif
                @if($service->duration_days)
                <div>
                    <span class="text-gray-400 block mb-1">مدت اعتبار</span>
                    <span class="text-white">{{ $service->duration_days }} روز</span>
                </div>
                @endif
                @if($service->starts_at)
                <div>
                    <span class="text-gray-400 block mb-1">تاریخ شروع</span>
                    <span class="text-white">{{ $service->starts_at->format('Y/m/d') }}</span>
                </div>
                @endif
                @if($service->expires_at)
                <div>
                    <span class="text-gray-400 block mb-1">تاریخ انقضا</span>
                    @php $days = $service->daysRemaining(); @endphp
                    <span class="{{ $days !== null && $days <= 3 ? 'text-red-400' : 'text-white' }}">
                        {{ $service->expires_at->format('Y/m/d') }}
                        @if($days !== null)
                            <span class="text-xs text-gray-500 mr-1">({{ $days }} روز مانده)</span>
                        @endif
                    </span>
                </div>
                @endif
            </div>
        </div>
    </div>

    {{-- Subscription link (Marzban) --}}
    @if($service->subscription_link)
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-6 mb-4">
        <h4 class="text-white font-medium text-sm mb-3">لینک اشتراک (Subscription)</h4>
        <p class="text-gray-500 text-xs mb-3">این لینک را در برنامه‌های V2Ray / Clash / Sing-Box وارد کنید تا کانفیگ‌ها به‌صورت خودکار دریافت شوند.</p>
        <div class="bg-gray-800 rounded-lg p-3 flex items-center gap-3 mb-4">
            <code id="sub-link" class="flex-1 text-xs text-indigo-300 break-all font-mono leading-5">{{ $service->subscription_link }}</code>
            <button onclick="copyText('sub-link', this)"
                    class="shrink-0 text-xs bg-indigo-600 hover:bg-indigo-500 text-white px-3 py-1.5 rounded-lg transition">
                کپی
            </button>
        </div>
        {{-- QR Code --}}
        <div class="flex justify-center">
            <div id="sub-qr" class="bg-white p-3 rounded-xl inline-block"></div>
        </div>
    </div>
    @endif

    {{-- Config link --}}
    @if($service->config_link)
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-6 mb-4">
        <h4 class="text-white font-medium text-sm mb-3">لینک کانفیگ مستقیم</h4>
        <div class="bg-gray-800 rounded-lg p-3 flex items-center gap-3">
            <code id="cfg-link" class="flex-1 text-xs text-gray-300 break-all font-mono leading-5">{{ $service->config_link }}</code>
            <button onclick="copyText('cfg-link', this)"
                    class="shrink-0 text-xs bg-indigo-600 hover:bg-indigo-500 text-white px-3 py-1.5 rounded-lg transition">
                کپی
            </button>
        </div>
    </div>
    @endif

    {{-- No connection info yet --}}
    @if(! $service->subscription_link && ! $service->config_link)
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-6 mb-4">
        <h4 class="text-white font-medium text-sm mb-2">اطلاعات اتصال</h4>
        <p class="text-gray-500 text-sm">لینک کانفیگ هنوز ثبت نشده است.</p>
        @if($service->status === 'pending_provision')
        <p class="text-gray-600 text-xs mt-2">پس از فعال‌سازی سرویس توسط پشتیبانی، لینک‌های اتصال اینجا نمایش داده می‌شوند.</p>
        @endif
    </div>
    @endif

    {{-- Placeholder actions --}}
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-6 mb-6">
        <h4 class="text-white font-medium text-sm mb-3">عملیات</h4>
        <div class="flex flex-wrap gap-3">
            <button disabled class="opacity-40 cursor-not-allowed text-xs bg-gray-800 text-white px-4 py-2 rounded-lg">
                تمدید سرویس (به‌زودی)
            </button>
            <button disabled class="opacity-40 cursor-not-allowed text-xs bg-gray-800 text-white px-4 py-2 rounded-lg">
                خرید حجم اضافه (به‌زودی)
            </button>
        </div>
        <p class="text-xs text-gray-600 mt-3">قابلیت تمدید و خرید حجم اضافه در نسخه بعدی فعال می‌شود.</p>
    </div>

    {{-- Order link --}}
    @if($service->order)
    <div class="mb-6">
        <a href="{{ route('dashboard.orders.show', $service->order) }}"
           class="inline-flex items-center gap-2 text-xs text-indigo-400 hover:text-indigo-300 transition">
            مشاهده سفارش مرتبط ({{ $service->order->order_number }}) ←
        </a>
    </div>
    @endif

    <a href="{{ route('dashboard.services') }}"
       class="inline-flex items-center gap-2 bg-gray-800 hover:bg-gray-700 text-white text-sm font-medium px-5 py-2.5 rounded-lg transition">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
        </svg>
        بازگشت به سرویس‌ها
    </a>
</div>
@endsection

@push('scripts')
<script>
function copyText(elementId, btn) {
    const text = document.getElementById(elementId)?.textContent?.trim();
    if (!text) return;
    navigator.clipboard.writeText(text).then(() => {
        const orig = btn.textContent;
        btn.textContent = '✓';
        setTimeout(() => btn.textContent = orig, 1500);
    }).catch(() => {});
}

@if($service->subscription_link)
(function() {
    const link = @json($service->subscription_link);
    const container = document.getElementById('sub-qr');
    if (!container || !link) return;

    // Draw a simple QR placeholder using a canvas + encoded URL approach
    // We use the free qrcode.js library loaded from CDN
    const script = document.createElement('script');
    script.src = 'https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js';
    script.onload = function() {
        const canvas = document.createElement('canvas');
        container.appendChild(canvas);
        QRCode.toCanvas(canvas, link, { width: 180, margin: 1 }, function(err) {
            if (err) container.innerHTML = '<p class="text-gray-500 text-xs p-4">QR code در دسترس نیست</p>';
        });
    };
    script.onerror = function() {
        container.innerHTML = '';
    };
    document.head.appendChild(script);
})();
@endif
</script>
@endpush
