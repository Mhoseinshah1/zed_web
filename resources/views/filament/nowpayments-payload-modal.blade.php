<div class="space-y-4 p-4">
    @if($gateway_url)
        <div>
            <a href="{{ $gateway_url }}" target="_blank" class="text-primary-600 underline text-sm">
                مشاهده صفحه پرداخت NOWPayments ↗
            </a>
        </div>
    @endif
    <pre class="text-xs bg-gray-100 dark:bg-gray-800 p-3 rounded overflow-auto max-h-96 whitespace-pre-wrap">{{ json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
</div>
