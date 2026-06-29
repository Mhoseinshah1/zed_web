{{-- Reusable attachment renderer for ticket messages (user + admin pages).
     Expects: $message (App\Models\SupportTicketMessage) --}}
@php $attachments = $message->displayAttachments(); @endphp

@if($attachments->isNotEmpty())
<div class="mt-3 flex flex-wrap gap-3">
    @foreach($attachments as $att)
        @if(! $att['exists'])
            <span class="inline-flex items-center gap-1 text-xs text-red-400">
                ⚠ فایل پیوست در دسترس نیست.
            </span>
        @elseif($att['is_image'])
            {{-- Image thumbnail — click opens full image in a new tab --}}
            <a href="{{ $att['url'] }}" target="_blank" rel="noopener"
               class="block group" title="{{ $att['name'] }}">
                <img src="{{ $att['url'] }}" alt="{{ $att['name'] }}"
                     class="h-28 w-28 rounded-lg border border-gray-700 object-cover transition group-hover:opacity-90"
                     loading="lazy">
                <span class="mt-1 block max-w-28 truncate text-[11px] text-gray-400">{{ $att['name'] }}</span>
            </a>
        @else
            {{-- Non-image file card (pdf/txt/other) --}}
            <a href="{{ $att['url'] }}" target="_blank" rel="noopener"
               class="inline-flex items-center gap-2 rounded-lg border border-gray-700 bg-gray-800/60 px-3 py-2 text-xs text-gray-200 hover:border-gray-600">
                <span class="text-base">{{ $att['is_pdf'] ? '📄' : '📎' }}</span>
                <span class="flex flex-col">
                    <span class="max-w-40 truncate">{{ $att['name'] }}</span>
                    <span class="text-[10px] uppercase text-gray-500">{{ $att['is_pdf'] ? 'PDF' : ($att['ext'] ?: 'فایل') }} · باز کردن</span>
                </span>
            </a>
        @endif
    @endforeach
</div>
@endif
