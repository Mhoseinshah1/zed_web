<div class="space-y-4 p-2">
    @if($attempts->isEmpty())
        <p class="text-sm text-gray-500 text-center py-4">هیچ تلاشی برای ساخت سرویس ثبت نشده است.</p>
    @else
        @foreach($attempts as $attempt)
        <div class="border rounded-lg p-4 {{ $attempt->status === 'success' ? 'border-green-600/30 bg-green-900/10' : ($attempt->status === 'failed' ? 'border-red-600/30 bg-red-900/10' : 'border-gray-700 bg-gray-800/30') }}">
            <div class="flex items-center justify-between mb-2">
                <span class="font-semibold text-sm">تلاش {{ $attempt->attempt_number }}</span>
                <span class="text-xs px-2 py-0.5 rounded-full
                    {{ $attempt->status === 'success' ? 'bg-green-600/20 text-green-400' : '' }}
                    {{ $attempt->status === 'failed' ? 'bg-red-600/20 text-red-400' : '' }}
                    {{ $attempt->status === 'processing' ? 'bg-yellow-600/20 text-yellow-400' : '' }}
                    {{ $attempt->status === 'pending' ? 'bg-gray-600/20 text-gray-400' : '' }}
                ">
                    {{ $attempt->statusLabel() }}
                </span>
            </div>
            <div class="grid grid-cols-2 gap-2 text-xs text-gray-400">
                @if($attempt->started_at)
                <div>
                    <span class="block text-gray-500 mb-0.5">شروع</span>
                    {{ $attempt->started_at->format('Y/m/d H:i:s') }}
                </div>
                @endif
                @if($attempt->finished_at)
                <div>
                    <span class="block text-gray-500 mb-0.5">پایان</span>
                    {{ $attempt->finished_at->format('Y/m/d H:i:s') }}
                </div>
                @endif
                @if($attempt->vpnPanel)
                <div class="col-span-2">
                    <span class="block text-gray-500 mb-0.5">پنل</span>
                    {{ $attempt->vpnPanel->name }}
                </div>
                @endif
            </div>
            @if($attempt->error_message)
            <div class="mt-3 p-3 bg-red-900/20 rounded text-xs text-red-300 font-mono break-all">
                {{ $attempt->error_message }}
            </div>
            @endif
        </div>
        @endforeach
    @endif
</div>
