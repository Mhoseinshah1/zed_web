<div>
    <form wire:submit="send" class="space-y-4">
        {{ $this->form }}
        <div>
            <x-filament::button type="submit" icon="heroicon-o-paper-airplane">
                ارسال پاسخ
            </x-filament::button>
        </div>
    </form>

    <x-filament-actions::modals />
</div>
