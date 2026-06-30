<x-filament-panels::page>

    @php($last = $this->lastBackup())
    @if($last)
        <div class="rounded-xl border border-line bg-surface p-4 text-sm">
            <span class="font-semibold">آخرین بکاپ:</span>
            @if($last->status === \App\Models\BackupLog::STATUS_SUCCESS)
                <span class="text-green-500">🟢 موفق</span> — {{ $last->sizeMb() }} مگابایت — {{ $last->updated_at->format('Y/m/d H:i') }}
            @elseif($last->status === \App\Models\BackupLog::STATUS_FAILED)
                <span class="text-red-500">🔴 ناموفق</span> — {{ $last->updated_at->format('Y/m/d H:i') }}
                @if($last->error)<div class="text-xs text-content-muted mt-1">{{ \Illuminate\Support\Str::limit($last->error, 120) }}</div>@endif
            @else
                <span class="text-amber-500">⏳ در حال اجرا</span>
            @endif
        </div>
    @endif

    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex flex-wrap gap-3">
            <x-filament::button type="submit" color="primary">ذخیره تنظیمات</x-filament::button>
            {{ $this->runBackupAction }}
        </div>
    </form>

    <x-filament-actions::modals />

</x-filament-panels::page>
