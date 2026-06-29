@extends('layouts.panel')

@section('title', 'نمایندگی')

@section('content')
<div class="max-w-3xl space-y-6">

    <h1 class="text-xl font-bold text-white">نمایندگی</h1>

    @if(session('success'))
    <div class="bg-green-500/10 border border-green-500/30 rounded-xl px-5 py-3 text-sm text-green-300">{{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div class="bg-red-500/10 border border-red-500/30 rounded-xl px-5 py-3 text-sm text-red-300">{{ session('error') }}</div>
    @endif

    @if($canInvite)
        {{-- Referral code + link --}}
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-6 space-y-4">
            <div>
                <p class="text-xs text-gray-500 mb-1">کد نمایندگی</p>
                <p class="text-lg font-mono tracking-widest text-white">{{ $user->referral_code }}</p>
            </div>
            <div>
                <p class="text-xs text-gray-500 mb-1">لینک دعوت</p>
                <div class="flex items-center gap-2">
                    <code id="ref-link" class="flex-1 text-xs text-indigo-300 break-all bg-gray-800 rounded-lg px-3 py-2 font-mono">{{ $user->referralLink() }}</code>
                    <button onclick="copyText('ref-link', this)" class="shrink-0 text-xs bg-indigo-600 hover:bg-indigo-500 text-white px-3 py-2 rounded-lg transition">کپی</button>
                </div>
            </div>
        </div>

        {{-- Stats --}}
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
                <p class="text-xs text-gray-500">کاربران معرفی‌شده</p>
                <p class="text-lg font-bold text-white mt-1">{{ number_format($referredCount) }}</p>
            </div>
            <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
                <p class="text-xs text-gray-500">سفارش‌های موفق</p>
                <p class="text-lg font-bold text-white mt-1">{{ number_format($paidOrdersCount) }}</p>
            </div>
            <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
                <p class="text-xs text-gray-500">پورسانت دریافتی</p>
                <p class="text-lg font-bold text-green-400 mt-1">{{ number_format($totalCommission) }}</p>
            </div>
            <div class="bg-gray-900 border border-gray-800 rounded-xl p-4">
                <p class="text-xs text-gray-500">پورسانت در انتظار</p>
                <p class="text-lg font-bold text-amber-400 mt-1">{{ number_format($pendingCommission) }}</p>
            </div>
        </div>

        {{-- Referred users --}}
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <h3 class="text-sm font-medium text-gray-300 mb-3">کاربران معرفی‌شده</h3>
            @forelse($referredUsers as $ru)
            <div class="flex items-center justify-between py-2 border-b border-gray-800 last:border-0 text-sm">
                <span class="text-gray-300 font-mono">{{ $ru->account_id }}</span>
                <span class="text-xs text-gray-600">{{ $ru->created_at->format('Y/m/d') }}</span>
            </div>
            @empty
            <p class="text-xs text-gray-600">هنوز کاربری معرفی نکرده‌اید.</p>
            @endforelse
        </div>

        {{-- Commission history --}}
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-5">
            <h3 class="text-sm font-medium text-gray-300 mb-3">تاریخچه پورسانت</h3>
            @forelse($commissions as $c)
            <div class="flex items-center justify-between py-2 border-b border-gray-800 last:border-0 text-sm">
                <span class="text-gray-300">{{ \App\Models\Order::allOrderTypes()[$c->order_type] ?? $c->order_type }}</span>
                <span class="text-gray-200">{{ number_format($c->commission_amount) }} تومان</span>
                <span class="text-xs {{ $c->status === 'credited' ? 'text-green-400' : 'text-amber-400' }}">{{ $c->statusLabel() }}</span>
            </div>
            @empty
            <p class="text-xs text-gray-600">هنوز پورسانتی ثبت نشده است.</p>
            @endforelse
        </div>
    @else
        {{-- Non-representative in representatives_only mode --}}
        <div class="bg-gray-900 border border-gray-800 rounded-xl p-6">
            <p class="text-sm text-gray-300 leading-7">
                در حال حاضر فقط نماینده‌های تاییدشده می‌توانند کاربر معرفی کنند.
            </p>

            @if($systemEnabled)
                @if($user->representative_status === \App\Models\User::REP_PENDING)
                <p class="mt-3 text-sm text-amber-400">درخواست نمایندگی شما در حال بررسی است.</p>
                @elseif($user->representative_status === \App\Models\User::REP_REJECTED)
                <p class="mt-3 text-sm text-red-400">درخواست نمایندگی شما رد شده است.</p>
                @else
                <form method="POST" action="{{ route('dashboard.representative.request') }}" class="mt-4 space-y-3">
                    @csrf
                    <textarea name="message" rows="3" maxlength="1000" placeholder="توضیحات (اختیاری)"
                              class="w-full bg-gray-800 border border-gray-700 focus:border-indigo-500 rounded-lg px-4 py-2.5 text-white text-sm outline-none"></textarea>
                    <input type="text" name="contact_info" maxlength="255" placeholder="اطلاعات تماس (اختیاری)"
                           class="w-full bg-gray-800 border border-gray-700 focus:border-indigo-500 rounded-lg px-4 py-2.5 text-white text-sm outline-none">
                    <button type="submit" class="bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-medium px-6 py-2.5 rounded-lg transition">
                        درخواست نمایندگی
                    </button>
                </form>
                @endif
            @endif
        </div>
    @endif
</div>
@endsection

@push('scripts')
<script>
function copyText(id, btn) {
    const t = document.getElementById(id)?.textContent?.trim();
    if (!t) return;
    navigator.clipboard.writeText(t).then(() => { const o = btn.textContent; btn.textContent = '✓'; setTimeout(() => btn.textContent = o, 1200); });
}
</script>
@endpush
