<?php

namespace App\Filament\Resources\SupportTicketResource\Pages;

use App\Filament\Resources\SupportTicketResource;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Support\SupportTicketService;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists\Components;
use Filament\Infolists\Infolist;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Livewire\Attributes\On;

/**
 * Admin ticket page: user info, ticket meta, the conversation thread, and an
 * inline reply composer rendered directly under the conversation (no separate
 * reply modal). Status/assignment/close actions remain in the header.
 */
class ViewSupportTicket extends ViewRecord
{
    protected static string $resource = SupportTicketResource::class;

    public function mount(int | string $record): void
    {
        parent::mount($record);
        app(SupportTicketService::class)->markReadByAdmin($this->record);
    }

    /** Re-render the page (and the conversation) after the composer sends a reply. */
    #[On('ticket-reply-sent')]
    public function onReplySent(): void
    {
        $this->record->refresh();
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Components\Section::make('اطلاعات کاربر')
                ->schema([
                    Components\TextEntry::make('user.account_id')->label('شناسه اکانت'),
                    Components\TextEntry::make('user.name')->label('نام کاربر'),
                    Components\TextEntry::make('user.phone')->label('شماره موبایل')->placeholder('—'),
                    Components\TextEntry::make('user.phone_verified_at')
                        ->label('وضعیت تایید شماره')
                        ->formatStateUsing(fn ($state) => $state ? 'تایید شده' : 'تایید نشده'),
                    Components\TextEntry::make('user.email')->label('ایمیل'),
                    Components\TextEntry::make('user.wallet_balance_toman')
                        ->label('موجودی کیف پول')
                        ->formatStateUsing(fn ($state) => number_format((int) $state) . ' تومان'),
                    Components\TextEntry::make('active_services_count')
                        ->label('سرویس‌های فعال')
                        ->state(fn (SupportTicket $record) => (string) ($record->user?->activeServicesCount() ?? 0)),
                ])->columns(3)->collapsible()->collapsed(),

            Components\Section::make('مشخصات تیکت')
                ->schema([
                    Components\TextEntry::make('ticket_number')->label('شماره تیکت')->copyable(),
                    Components\TextEntry::make('subject')->label('موضوع'),
                    Components\TextEntry::make('category.name')->label('دسته')->placeholder('—'),
                    Components\TextEntry::make('status')->label('وضعیت')->badge()
                        ->formatStateUsing(fn ($state) => SupportTicket::statuses()[$state] ?? $state),
                    Components\TextEntry::make('priority')->label('اولویت')->badge()
                        ->formatStateUsing(fn ($state) => SupportTicket::priorities()[$state] ?? $state),
                    Components\TextEntry::make('assignedAdmin.username')->label('کارشناس')->placeholder('—'),
                    Components\TextEntry::make('order.order_number')->label('سفارش مرتبط')->placeholder('—'),
                    Components\TextEntry::make('userService.service_number')->label('سرویس مرتبط')->placeholder('—'),
                ])->columns(3),

            Components\Section::make('گفتگو')
                ->schema([
                    Components\ViewEntry::make('messages')
                        ->view('filament.support.conversation')
                        ->viewData(['ticket' => $this->record]),
                ]),

            // Inline reply composer (Livewire child) — directly under the conversation.
            Components\Section::make('پاسخ پشتیبانی')
                ->visible(fn (SupportTicket $record) => ! $record->isClosed())
                ->schema([
                    Components\ViewEntry::make('reply_composer')
                        ->view('filament.support.composer')
                        ->viewData(['ticketId' => $this->record->id]),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $ticket = $this->record;

        return [
            Actions\Action::make('set_status')
                ->label('تغییر وضعیت')
                ->icon('heroicon-o-flag')
                ->color('warning')
                ->form([
                    Forms\Components\Select::make('status')
                        ->label('وضعیت')
                        ->options(SupportTicket::statuses())
                        ->default($ticket->status)
                        ->required(),
                ])
                ->action(function (array $data) use ($ticket) {
                    $ticket->update([
                        'status'    => $data['status'],
                        'closed_at' => $data['status'] === SupportTicket::STATUS_CLOSED ? now() : null,
                    ]);
                    Notification::make()->title('وضعیت تیکت به‌روزرسانی شد.')->success()->send();
                }),

            Actions\Action::make('assign')
                ->label('تخصیص به کارشناس')
                ->icon('heroicon-o-user-plus')
                ->color('info')
                ->form([
                    Forms\Components\Select::make('assigned_admin_id')
                        ->label('کارشناس')
                        ->options(fn () => User::where('is_admin', true)->pluck('username', 'id'))
                        ->default($ticket->assigned_admin_id)
                        ->searchable(),
                ])
                ->action(function (array $data) use ($ticket) {
                    $ticket->update(['assigned_admin_id' => $data['assigned_admin_id'] ?? null]);
                    Notification::make()->title('تیکت تخصیص داده شد.')->success()->send();
                }),

            Actions\Action::make('close')
                ->label('بستن تیکت')
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => ! $ticket->isClosed())
                ->requiresConfirmation()
                ->action(function () use ($ticket) {
                    app(SupportTicketService::class)->close($ticket);
                    Notification::make()->title('تیکت بسته شد.')->success()->send();
                }),

            Actions\Action::make('reopen')
                ->label('بازگشایی تیکت')
                ->icon('heroicon-o-arrow-path')
                ->color('success')
                ->visible(fn () => $ticket->isClosed())
                ->action(function () use ($ticket) {
                    app(SupportTicketService::class)->reopen($ticket);
                    Notification::make()->title('تیکت بازگشایی شد.')->success()->send();
                }),
        ];
    }
}
