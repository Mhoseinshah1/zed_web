<div class="space-y-3 p-2">
    <div class="mb-3 grid grid-cols-3 gap-3 text-center text-sm">
        <div class="rounded-lg bg-green-900/20 p-3">
            <div class="text-xs text-gray-400 mb-1">استفاده شده</div>
            <div class="text-green-400 font-bold">{{ $redemptions->where('status', 'used')->count() }}</div>
        </div>
        <div class="rounded-lg bg-yellow-900/20 p-3">
            <div class="text-xs text-gray-400 mb-1">رزرو شده</div>
            <div class="text-yellow-400 font-bold">{{ $redemptions->where('status', 'reserved')->count() }}</div>
        </div>
        <div class="rounded-lg bg-gray-800 p-3">
            <div class="text-xs text-gray-400 mb-1">مجموع تخفیف</div>
            <div class="text-white font-bold text-xs">{{ number_format($redemptions->where('status', 'used')->sum('discount_amount')) }} تومان</div>
        </div>
    </div>

    @if($redemptions->isEmpty())
        <p class="text-sm text-gray-500 text-center py-4">هیچ استفاده‌ای ثبت نشده است.</p>
    @else
        @foreach($redemptions as $r)
        <div class="border rounded-lg p-3 text-xs
            {{ $r->status === 'used' ? 'border-green-600/30 bg-green-900/10' : ($r->status === 'reserved' ? 'border-yellow-600/30 bg-yellow-900/10' : 'border-gray-700 bg-gray-800/30') }}">
            <div class="flex items-center justify-between mb-2">
                <div class="font-medium text-sm">{{ $r->user?->username ?? '—' }}</div>
                <span class="px-2 py-0.5 rounded-full
                    {{ $r->status === 'used' ? 'bg-green-600/20 text-green-400' : '' }}
                    {{ $r->status === 'reserved' ? 'bg-yellow-600/20 text-yellow-400' : '' }}
                    {{ $r->status === 'cancelled' ? 'bg-gray-600/20 text-gray-400' : '' }}
                    {{ $r->status === 'expired' ? 'bg-red-600/20 text-red-400' : '' }}
                ">{{ $r->statusLabel() }}</span>
            </div>
            <div class="grid grid-cols-3 gap-2 text-gray-400">
                <div>
                    <span class="block text-gray-500 mb-0.5">مبلغ اصلی</span>
                    {{ number_format($r->original_amount) }} تومان
                </div>
                <div>
                    <span class="block text-gray-500 mb-0.5">تخفیف</span>
                    <span class="text-green-400">{{ number_format($r->discount_amount) }} تومان</span>
                </div>
                <div>
                    <span class="block text-gray-500 mb-0.5">مبلغ نهایی</span>
                    {{ number_format($r->final_amount) }} تومان
                </div>
            </div>
            @if($r->order_id)
            <div class="mt-2 text-gray-500">
                سفارش: <span class="font-mono text-gray-300">{{ $r->order?->order_number ?? $r->order_id }}</span>
                @if($r->used_at) · {{ $r->used_at->format('Y/m/d H:i') }}@endif
            </div>
            @endif
        </div>
        @endforeach
    @endif
</div>
