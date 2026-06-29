@php
    /** @var \App\Models\SupportTicket $ticket */
    $ticket = $ticket ?? $getRecord();
    $messages = $ticket->messages()->with('user')->get();
@endphp

<div class="space-y-3">
    @forelse($messages as $message)
        @php
            $isAdmin    = $message->is_admin;
            $isInternal = $message->is_internal_note;
        @endphp
        <div @class([
            'rounded-lg border p-4',
            'bg-amber-50 border-amber-300 dark:bg-amber-900/20 dark:border-amber-700' => $isInternal,
            'bg-primary-50 border-primary-200 dark:bg-primary-900/20 dark:border-primary-700' => $isAdmin && ! $isInternal,
            'bg-gray-50 border-gray-200 dark:bg-gray-800 dark:border-gray-700' => ! $isAdmin,
        ])>
            <div class="mb-1 flex items-center gap-2 text-xs">
                <span class="font-semibold text-gray-700 dark:text-gray-200">
                    @if($isInternal)
                        🔒 یادداشت داخلی — {{ $message->user?->username ?? 'سیستم' }}
                    @elseif($isAdmin)
                        پشتیبانی — {{ $message->user?->username ?? 'سیستم' }}
                    @else
                        کاربر — {{ $message->user?->username ?? '—' }}
                    @endif
                </span>
                <span class="text-gray-400">{{ $message->created_at->format('Y/m/d H:i') }}</span>
            </div>
            <p class="whitespace-pre-line text-sm leading-7 text-gray-800 dark:text-gray-100">{{ $message->body }}</p>
            @if($message->hasAttachment())
                <a href="{{ asset('storage/' . $message->attachment_path) }}" target="_blank"
                   class="mt-2 inline-flex items-center gap-1 text-xs text-primary-600 hover:underline dark:text-primary-400">
                    📎 {{ $message->attachment_name ?? 'پیوست' }}
                </a>
            @endif
        </div>
    @empty
        <p class="text-sm text-gray-500">پیامی ثبت نشده است.</p>
    @endforelse
</div>
