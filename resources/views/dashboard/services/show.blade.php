@extends('layouts.panel')

@section('title', 'جزئیات سرویس')

@php
    use App\Models\SiteText;

    $canSync    = SiteText::get('services.allow_user_sync_service',           'true')  === 'true';
    $canRevoke  = SiteText::get('services.allow_user_revoke_subscription',    'true')  === 'true';
    $canReset   = SiteText::get('services.allow_user_reset_traffic',          'false') === 'true';
    $canDisable = SiteText::get('services.allow_user_disable_service',        'false') === 'true';
    $canEnable  = SiteText::get('services.allow_user_enable_service',         'false') === 'true';

    $isActive   = $service->status === \App\Models\UserService::STATUS_ACTIVE;
    $isDisabled = $service->status === \App\Models\UserService::STATUS_DISABLED;
    $hasRemote  = filled($service->remote_username);
    $hasSub     = filled($service->subscription_link);
    $hasConfig  = filled($service->config_link);
    $canDoRemoteActions = $hasRemote && $isActive;
@endphp

@section('content')
<div class="max-w-2xl space-y-6">

    {{-- ── Header ── --}}
    <div class="flex items-center gap-4">
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

    {{-- ── Flash messages ── --}}
    @if(session('success'))
    <div class="bg-green-500/10 border border-green-500/30 rounded-xl px-5 py-4 flex items-center gap-3">
        <span class="text-green-400 text-lg">✓</span>
        <p class="text-green-300 text-sm">{{ session('success') }}</p>
    </div>
    @endif
    @if(session('error'))
    <div class="bg-red-500/10 border border-red-500/30 rounded-xl px-5 py-4 flex items-center gap-3">
        <span class="text-red-400 text-lg">✕</span>
        <p class="text-red-300 text-sm">{{ session('error') }}</p>
    </div>
    @endif

    {{-- ── Status banner ── --}}
    @if(in_array($service->status, ['pending_provision', 'failed']))
    <div class="bg-yellow-500/10 border border-yellow-500/20 rounded-xl p-5">
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
    @elseif($isActive)
    <div class="bg-green-500/10 border border-green-500/20 rounded-xl p-5">
        <div class="flex gap-3">
            <span class="text-xl">✅</span>
            <div>
                <h4 class="text-green-300 font-semibold text-sm mb-1">سرویس فعال است</h4>
                <p class="text-green-200/70 text-sm">سرویس VPN شما فعال است و می‌توانید از آن استفاده کنید.</p>
            </div>
        </div>
    </div>
    @elseif($isDisabled)
    <div class="bg-orange-500/10 border border-orange-500/20 rounded-xl p-5">
        <div class="flex gap-3">
            <span class="text-xl">⛔</span>
            <div>
                <h4 class="text-orange-300 font-semibold text-sm mb-1">سرویس موقتاً غیرفعال شده است</h4>
                <p class="text-orange-200/70 text-sm">سرویس شما موقتاً غیرفعال شده است. با پشتیبانی تماس بگیرید.</p>
            </div>
        </div>
    </div>
    @elseif($service->status === 'expired')
    <div class="bg-gray-500/10 border border-gray-500/20 rounded-xl p-5">
        <div class="flex gap-3">
            <span class="text-xl">🕐</span>
            <div>
                <h4 class="text-gray-300 font-semibold text-sm mb-1">سرویس شما منقضی شده است</h4>
                <p class="text-gray-400 text-sm">سرویس شما منقضی شده است. برای تمدید اقدام کنید.</p>
            </div>
        </div>
    </div>
    @endif

    {{-- ── Service info card ── --}}
    <div class="bg-gray-900 border border-gray-800 rounded-xl overflow-hidden">
        <div class="p-6 border-b border-gray-800">
            <div class="flex items-start justify-between">
                <h3 class="text-white font-semibold text-lg">{{ $service->plan_name ?? 'سرویس VPN' }}</h3>
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
                    @php
                        $used     = $service->traffic_used_gb ?? 0;
                        $total    = $service->traffic_total_gb;
                        $pct      = $total > 0 ? min(100, round($used / $total * 100)) : 0;
                        $barColor = $pct >= 90 ? 'bg-red-500' : ($pct >= 70 ? 'bg-yellow-500' : 'bg-green-500');
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
                @if($service->last_synced_at)
                <div class="col-span-2">
                    <span class="text-gray-400 block mb-1">آخرین بروزرسانی</span>
                    <span class="text-gray-300 text-xs">{{ $service->last_synced_at->diffForHumans() }}</span>
                </div>
                @endif
            </div>
        </div>
    </div>

    {{-- ── Subscription link + QR ── --}}
    @if($hasSub)
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-6 space-y-5">
        <div>
            <h4 class="text-white font-medium text-sm mb-1">لینک اشتراک (Subscription)</h4>
            <p class="text-gray-500 text-xs">این لینک را در برنامه‌های V2Ray / Clash / Sing-Box وارد کنید تا کانفیگ‌ها به‌صورت خودکار دریافت شوند.</p>
        </div>

        {{-- Link + copy --}}
        <div class="bg-gray-800 rounded-lg p-3 flex items-center gap-3">
            <code id="sub-link" class="flex-1 text-xs text-indigo-300 break-all font-mono leading-5">{{ $service->subscription_link }}</code>
            <button onclick="copyText('sub-link', this)"
                    class="shrink-0 text-xs bg-indigo-600 hover:bg-indigo-500 text-white px-3 py-1.5 rounded-lg transition">
                کپی لینک اشتراک
            </button>
        </div>

        {{-- QR Code (server-side SVG) --}}
        <div class="flex flex-col items-center gap-2">
            <p class="text-xs text-gray-500">بارکد لینک اشتراک</p>
            <div class="bg-white p-3 rounded-xl" id="sub-qr-wrapper">
                {!! QrCode::format('svg')->size(200)->errorCorrection('M')->generate($service->subscription_link) !!}
            </div>
        </div>
    </div>
    @else
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-6">
        <h4 class="text-white font-medium text-sm mb-2">لینک اشتراک</h4>
        <p class="text-gray-500 text-sm">لینک اشتراک هنوز آماده نشده است.</p>
        @if(in_array($service->status, ['pending_provision', 'failed']))
        <p class="text-gray-600 text-xs mt-2">پس از فعال‌سازی سرویس توسط پشتیبانی، لینک‌های اتصال اینجا نمایش داده می‌شوند.</p>
        @endif
    </div>
    @endif

    {{-- ── Config link + QR ── --}}
    @if($hasConfig)
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-6 space-y-5">
        <h4 class="text-white font-medium text-sm">لینک کانفیگ مستقیم</h4>

        <div class="bg-gray-800 rounded-lg p-3 flex items-center gap-3">
            <code id="cfg-link" class="flex-1 text-xs text-gray-300 break-all font-mono leading-5">{{ $service->config_link }}</code>
            <button onclick="copyText('cfg-link', this)"
                    class="shrink-0 text-xs bg-indigo-600 hover:bg-indigo-500 text-white px-3 py-1.5 rounded-lg transition">
                کپی لینک کانفیگ
            </button>
        </div>

        <div class="flex flex-col items-center gap-2">
            <p class="text-xs text-gray-500">بارکد لینک کانفیگ</p>
            <div class="bg-white p-3 rounded-xl">
                {!! QrCode::format('svg')->size(180)->errorCorrection('M')->generate($service->config_link) !!}
            </div>
        </div>
    </div>
    @endif

    {{-- ── Service management ── --}}
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-6 space-y-4">
        <h4 class="text-white font-medium text-sm">مدیریت سرویس</h4>

        @if(! $canDoRemoteActions && ! ($isDisabled && $hasRemote && $canEnable))
        <p class="text-gray-500 text-sm">این عملیات فقط برای سرویس‌های فعال قابل انجام است.</p>
        @else

        {{-- Sync --}}
        @if($canSync && $canDoRemoteActions)
        <form method="POST" action="{{ route('dashboard.services.sync', $service) }}">
            @csrf
            <button type="submit"
                    class="w-full sm:w-auto inline-flex items-center gap-2 bg-gray-800 hover:bg-gray-700 text-white text-sm px-4 py-2.5 rounded-lg transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                بروزرسانی وضعیت سرویس
            </button>
        </form>
        @endif

        {{-- Revoke subscription --}}
        @if($canRevoke && $canDoRemoteActions && $hasSub)
        <div>
            <button type="button" onclick="document.getElementById('modal-revoke').classList.remove('hidden')"
                    class="w-full sm:w-auto inline-flex items-center gap-2 bg-yellow-600/20 hover:bg-yellow-600/30 border border-yellow-600/40 text-yellow-300 text-sm px-4 py-2.5 rounded-lg transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/>
                </svg>
                تغییر لینک اشتراک
            </button>
        </div>

        {{-- Revoke confirmation modal --}}
        <div id="modal-revoke" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70">
            <div class="bg-gray-900 border border-gray-700 rounded-2xl p-6 max-w-md w-full shadow-2xl">
                <h3 class="text-white font-semibold mb-3">تغییر لینک اشتراک</h3>
                <p class="text-gray-400 text-sm leading-6 mb-5">
                    با انجام این کار لینک اشتراک قبلی شما غیرفعال می‌شود و لینک جدید ساخته می‌شود.<br>
                    برنامه‌های متصل باید دوباره لینک را وارد کنند. ادامه می‌دهید؟
                </p>
                <div class="flex gap-3">
                    <form method="POST" action="{{ route('dashboard.services.revoke-subscription', $service) }}" class="flex-1">
                        @csrf
                        <button type="submit"
                                class="w-full bg-yellow-600 hover:bg-yellow-500 text-white text-sm font-medium px-4 py-2.5 rounded-lg transition">
                            بله، لینک را تغییر بده
                        </button>
                    </form>
                    <button type="button" onclick="document.getElementById('modal-revoke').classList.add('hidden')"
                            class="flex-1 bg-gray-800 hover:bg-gray-700 text-gray-300 text-sm px-4 py-2.5 rounded-lg transition">
                        انصراف
                    </button>
                </div>
            </div>
        </div>
        @endif

        {{-- Reset traffic (optional) --}}
        @if($canReset && $canDoRemoteActions)
        <div>
            <button type="button" onclick="document.getElementById('modal-reset').classList.remove('hidden')"
                    class="w-full sm:w-auto inline-flex items-center gap-2 bg-orange-600/20 hover:bg-orange-600/30 border border-orange-600/40 text-orange-300 text-sm px-4 py-2.5 rounded-lg transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                ریست ترافیک
            </button>
        </div>

        <div id="modal-reset" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70">
            <div class="bg-gray-900 border border-gray-700 rounded-2xl p-6 max-w-md w-full shadow-2xl">
                <h3 class="text-white font-semibold mb-3">ریست مصرف ترافیک</h3>
                <p class="text-gray-400 text-sm leading-6 mb-5">آیا از ریست کردن مصرف ترافیک این سرویس مطمئن هستید؟</p>
                <div class="flex gap-3">
                    <form method="POST" action="{{ route('dashboard.services.reset-traffic', $service) }}" class="flex-1">
                        @csrf
                        <button type="submit"
                                class="w-full bg-orange-600 hover:bg-orange-500 text-white text-sm font-medium px-4 py-2.5 rounded-lg transition">
                            بله، ریست کن
                        </button>
                    </form>
                    <button type="button" onclick="document.getElementById('modal-reset').classList.add('hidden')"
                            class="flex-1 bg-gray-800 hover:bg-gray-700 text-gray-300 text-sm px-4 py-2.5 rounded-lg transition">
                        انصراف
                    </button>
                </div>
            </div>
        </div>
        @endif

        {{-- Disable service (optional) --}}
        @if($canDisable && $canDoRemoteActions)
        <div>
            <button type="button" onclick="document.getElementById('modal-disable').classList.remove('hidden')"
                    class="w-full sm:w-auto inline-flex items-center gap-2 bg-red-600/20 hover:bg-red-600/30 border border-red-600/40 text-red-300 text-sm px-4 py-2.5 rounded-lg transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                غیرفعال‌سازی سرویس
            </button>
        </div>

        <div id="modal-disable" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70">
            <div class="bg-gray-900 border border-gray-700 rounded-2xl p-6 max-w-md w-full shadow-2xl">
                <h3 class="text-white font-semibold mb-3">غیرفعال‌سازی سرویس</h3>
                <p class="text-gray-400 text-sm leading-6 mb-5">
                    سرویس شما موقتاً غیرفعال می‌شود و اتصال از طریق آن ممکن نخواهد بود.<br>
                    برای فعال کردن مجدد با پشتیبانی تماس بگیرید.
                </p>
                <div class="flex gap-3">
                    <form method="POST" action="{{ route('dashboard.services.disable', $service) }}" class="flex-1">
                        @csrf
                        <button type="submit"
                                class="w-full bg-red-600 hover:bg-red-500 text-white text-sm font-medium px-4 py-2.5 rounded-lg transition">
                            بله، غیرفعال کن
                        </button>
                    </form>
                    <button type="button" onclick="document.getElementById('modal-disable').classList.add('hidden')"
                            class="flex-1 bg-gray-800 hover:bg-gray-700 text-gray-300 text-sm px-4 py-2.5 rounded-lg transition">
                        انصراف
                    </button>
                </div>
            </div>
        </div>
        @endif

        {{-- Enable service (optional) --}}
        @if($canEnable && $isDisabled && $hasRemote)
        <div>
            <button type="button" onclick="document.getElementById('modal-enable').classList.remove('hidden')"
                    class="w-full sm:w-auto inline-flex items-center gap-2 bg-green-600/20 hover:bg-green-600/30 border border-green-600/40 text-green-300 text-sm px-4 py-2.5 rounded-lg transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                فعال‌سازی سرویس
            </button>
        </div>

        <div id="modal-enable" class="hidden fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/70">
            <div class="bg-gray-900 border border-gray-700 rounded-2xl p-6 max-w-md w-full shadow-2xl">
                <h3 class="text-white font-semibold mb-3">فعال‌سازی سرویس</h3>
                <p class="text-gray-400 text-sm leading-6 mb-5">سرویس دوباره فعال می‌شود. ادامه می‌دهید؟</p>
                <div class="flex gap-3">
                    <form method="POST" action="{{ route('dashboard.services.enable', $service) }}" class="flex-1">
                        @csrf
                        <button type="submit"
                                class="w-full bg-green-600 hover:bg-green-500 text-white text-sm font-medium px-4 py-2.5 rounded-lg transition">
                            بله، فعال کن
                        </button>
                    </form>
                    <button type="button" onclick="document.getElementById('modal-enable').classList.add('hidden')"
                            class="flex-1 bg-gray-800 hover:bg-gray-700 text-gray-300 text-sm px-4 py-2.5 rounded-lg transition">
                        انصراف
                    </button>
                </div>
            </div>
        </div>
        @endif

        @endif {{-- canDoRemoteActions --}}
    </div>

    {{-- ── Placeholder renewal actions ── --}}
    <div class="bg-gray-900 border border-gray-800 rounded-xl p-6">
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

    {{-- ── Order link ── --}}
    @if($service->order)
    <div>
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
        btn.textContent = '✓ کپی شد';
        setTimeout(() => btn.textContent = orig, 1500);
    }).catch(() => {});
}

// Close modals when clicking overlay
document.addEventListener('click', function (e) {
    if (e.target.classList.contains('fixed')) {
        e.target.classList.add('hidden');
    }
});

// Close modals on Escape
document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.fixed.inset-0').forEach(m => m.classList.add('hidden'));
    }
});
</script>
@endpush
