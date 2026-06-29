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

class ViewSupportTicket extends ViewRecord
{
    protected static string $resource = SupportTicketResource::class;

    public function mount(int | string $record): void
    {
        parent::mount($record);
        // Opening the ticket clears the admin's unread flag.
        app(SupportTicketService::class)->markReadByAdmin($this->record);
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
                ])->columns(3)->collapsible(),

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
        ]);
    }

    protected function getHeaderActions(): array
    {
        $ticket = $this->record;

        return [
            Actions\Action::make('reply')
                ->label('پاسخ به کاربر')
                ->icon('heroicon-o-paper-airplane')
                ->color('primary')
                ->visible(fn () => ! $ticket->isClosed())
                ->form([
                    Forms\Components\Textarea::make('body')->label('متن پاسخ')->required()->rows(4),
                    Forms\Components\FileUpload::make('attachment')
                        ->label('پیوست')
                        ->disk('public')
                        ->directory('support-tickets')
                        ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp', 'application/pdf', 'text/plain'])
                        ->maxSize(5120),
                ])
                ->action(function (array $data) use ($ticket) {
                    app(SupportTicketService::class)->adminReply(
                        $ticket, auth()->user(), $data['body'], internal: false,
                    );
                    // FileUpload stored the file already; attach its path if present.
                    if (! empty($data['attachment'])) {
                        $msg = $ticket->messages()->latest('id')->first();
                        $msg?->update(['attachment_path' => $data['attachment'], 'attachment_name' => basename($data['attachment'])]);
                    }
                    Notification::make()->title('پاسخ ارسال شد.')->success()->send();
                    $this->refreshFormData([]);
                }),

            Actions\Action::make('internal_note')
                ->label('یادداشت داخلی')
                ->icon('heroicon-o-lock-closed')
                ->color('gray')
                ->form([
                    Forms\Components\Textarea::make('body')->label('یادداشت داخلی (برای کاربر نمایش داده نمی‌شود)')->required()->rows(3),
                ])
                ->action(function (array $data) use ($ticket) {
                    app(SupportTicketService::class)->adminReply($ticket, auth()->user(), $data['body'], internal: true);
                    Notification::make()->title('یادداشت داخلی ثبت شد.')->success()->send();
                }),

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
