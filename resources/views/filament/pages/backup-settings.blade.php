<x-filament-panels::page>

    @php($last = $this->lastBackup())
    @php($s = app(\App\Services\Backup\BackupSettings::class))
    @php($next = $this->nextRunAt())

    {{-- وضعیت بکاپ — آخرین اجرا، وضعیت، اجرای بعدی، حجم و مسیر --}}
    <div class="rounded-xl border border-line bg-surface p-4 text-sm space-y-2">
        <div class="flex flex-wrap gap-x-6 gap-y-1">
            <span><span class="font-semibold">سیستم بکاپ:</span>
                @if($s->enabled()) <span class="text-green-500">فعال</span> @else <span class="text-red-500">غیرفعال</span> @endif
            </span>
            <span><span class="font-semibold">بکاپ خودکار:</span>
                @if($s->autoEnabled()) <span class="text-green-500">فعال</span> @else <span class="text-red-500">غیرفعال</span> @endif
            </span>
            <span><span class="font-semibold">اجرای بعدی:</span>
                {{ $next ? $next->format('Y/m/d H:i') : '—' }}
            </span>
        </div>

        @if($last)
            <div class="flex flex-wrap gap-x-6 gap-y-1">
                <span><span class="font-semibold">آخرین بکاپ:</span>
                    @if($last->status === \App\Models\BackupLog::STATUS_SUCCESS)
                        <span class="text-green-500">🟢 موفق</span>
                    @elseif($last->status === \App\Models\BackupLog::STATUS_FAILED)
                        <span class="text-red-500">🔴 ناموفق</span>
                    @else
                        <span class="text-amber-500">⏳ در حال اجرا</span>
                    @endif
                    — {{ $last->updated_at->format('Y/m/d H:i') }}
                </span>
                @if($last->status === \App\Models\BackupLog::STATUS_SUCCESS)
                    <span><span class="font-semibold">حجم:</span> {{ $last->sizeMb() }} مگابایت</span>
                    {{-- فقط نام فایل و وضعیت رمزگذاری — مسیر کامل سرور هرگز نمایش داده نمی‌شود --}}
                    @if($last->file_path)
                        <span dir="ltr" class="text-xs text-content-muted self-center">{{ basename($last->file_path) }}</span>
                        <span class="text-xs text-content-muted self-center">
                            {{ str_ends_with((string) $last->file_path, '.enc') ? '🔐 رمزگذاری‌شده' : '🔓 بدون رمزگذاری' }}
                            — {{ $s->storageLocationLabel() }}
                        </span>
                    @endif
                @endif
            </div>
            @if($last->error)
                <div class="text-xs text-content-muted">{{ \Illuminate\Support\Str::limit($last->error, 120) }}</div>
            @endif
            {{-- بکاپ محلی موفق ولی تحویل تلگرام ثبت/تأیید نشده — بدون جزئیات داخلی --}}
            @if($last->status === \App\Models\BackupLog::STATUS_SUCCESS
                && ((($last->metadata['telegram_report'] ?? null) === 'failed')
                    || (($last->metadata['telegram_document'] ?? null) === 'dispatch_failed')))
                <div class="text-xs text-amber-500">بکاپ محلی با موفقیت انجام شد، اما ارسال گزارش/فایل به تلگرام ثبت نشد. جزئیات در لاگ سرور موجود است.</div>
            @endif
        @else
            <div class="text-content-muted">هنوز بکاپی انجام نشده است.</div>
        @endif
    </div>

    <form wire:submit="save">
        {{ $this->form }}

        <div class="mt-6 flex flex-wrap gap-3">
            <x-filament::button type="submit" color="primary">ذخیره تنظیمات</x-filament::button>
            {{ $this->runBackupAction }}
            {{ $this->cleanupOldAction }}
            {{ $this->sendTestReportAction }}
        </div>
    </form>

    <x-filament-actions::modals />

</x-filament-panels::page>
