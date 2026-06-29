<?php

namespace App\Livewire;

use App\Models\SupportTicket;
use App\Services\Support\SupportTicketService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Livewire\Component;

/**
 * Self-contained inline reply composer used inside the admin ticket page.
 * Lets support type text, attach files and optionally mark an internal note,
 * then send — without opening a separate modal/action.
 */
class AdminTicketReplyComposer extends Component implements HasForms
{
    use InteractsWithForms;

    public int $ticketId;

    /** @var array<string,mixed> */
    public array $data = [];

    public function mount(int $ticketId): void
    {
        $this->ticketId = $ticketId;
        $this->form->fill();
    }

    public function getTicketProperty(): SupportTicket
    {
        return SupportTicket::findOrFail($this->ticketId);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Textarea::make('body')
                    ->label('متن پاسخ')
                    ->required()
                    ->rows(4)
                    ->maxLength(5000),

                Forms\Components\FileUpload::make('attachments')
                    ->label('پیوست فایل')
                    ->multiple()
                    ->disk('public')
                    ->directory('support-tickets')
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf', 'text/plain'])
                    ->maxSize(5120)
                    ->maxFiles(5)
                    ->openable()
                    ->downloadable(),

                Forms\Components\Toggle::make('is_internal_note')
                    ->label('یادداشت داخلی')
                    ->helperText('در صورت فعال بودن، این پیام فقط برای پشتیبانی قابل مشاهده است و برای کاربر ارسال نمی‌شود.')
                    ->default(false),
            ])
            ->statePath('data');
    }

    public function send(): void
    {
        $state    = $this->form->getState();
        $internal = (bool) ($state['is_internal_note'] ?? false);

        $service = app(SupportTicketService::class);
        $message = $service->adminReply($this->ticket, auth()->user(), $state['body'], internal: $internal);

        if (! empty($state['attachments'])) {
            $service->attachStoredPaths($message, array_values($state['attachments']));
        }

        $this->form->fill();

        // Ask the parent page to re-render so the new message shows in the thread.
        $this->dispatch('ticket-reply-sent');

        Notification::make()
            ->title($internal ? 'یادداشت داخلی ثبت شد.' : 'پاسخ ارسال شد.')
            ->success()
            ->send();
    }

    public function render()
    {
        return view('livewire.admin-ticket-reply-composer');
    }
}
