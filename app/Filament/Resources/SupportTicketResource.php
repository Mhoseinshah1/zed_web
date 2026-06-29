<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SupportTicketResource\Pages;
use App\Filament\Support\UserAccountColumn;
use App\Models\SupportTicket;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SupportTicketResource extends Resource
{
    protected static ?string $model = SupportTicket::class;

    protected static ?string $navigationIcon   = 'heroicon-o-lifebuoy';
    protected static ?string $navigationGroup   = 'پشتیبانی';
    protected static ?string $navigationLabel   = 'تیکت‌ها';
    protected static ?string $modelLabel        = 'تیکت';
    protected static ?string $pluralModelLabel  = 'تیکت‌ها';
    protected static ?int    $navigationSort    = 1;

    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::query()->where('admin_unread', true)->count();
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\IconColumn::make('admin_unread')
                    ->label('جدید')
                    ->boolean()
                    ->trueIcon('heroicon-s-envelope')
                    ->falseIcon('heroicon-o-envelope-open')
                    ->trueColor('danger')
                    ->falseColor('gray'),

                Tables\Columns\TextColumn::make('ticket_number')
                    ->label('شماره تیکت')
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono')
                    ->copyable(),

                UserAccountColumn::make(),

                Tables\Columns\TextColumn::make('user.username')
                    ->label('کاربر')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('subject')
                    ->label('موضوع')
                    ->limit(40)
                    ->searchable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('category.name')
                    ->label('دسته')
                    ->placeholder('—'),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('وضعیت')
                    ->formatStateUsing(fn ($state) => SupportTicket::statuses()[$state] ?? $state)
                    ->colors([
                        'primary' => [SupportTicket::STATUS_OPEN, SupportTicket::STATUS_WAITING_ADMIN],
                        'warning' => [SupportTicket::STATUS_WAITING_USER],
                        'success' => [SupportTicket::STATUS_ANSWERED],
                        'gray'    => [SupportTicket::STATUS_CLOSED],
                    ]),

                Tables\Columns\BadgeColumn::make('priority')
                    ->label('اولویت')
                    ->formatStateUsing(fn ($state) => SupportTicket::priorities()[$state] ?? $state)
                    ->colors([
                        'gray'    => [SupportTicket::PRIORITY_LOW],
                        'primary' => [SupportTicket::PRIORITY_NORMAL],
                        'warning' => [SupportTicket::PRIORITY_HIGH],
                        'danger'  => [SupportTicket::PRIORITY_URGENT],
                    ]),

                Tables\Columns\TextColumn::make('assignedAdmin.username')
                    ->label('کارشناس')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('last_reply_at')
                    ->label('آخرین پاسخ')
                    ->dateTime('Y/m/d H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('وضعیت')->options(SupportTicket::statuses()),
                SelectFilter::make('priority')->label('اولویت')->options(SupportTicket::priorities()),
                SelectFilter::make('category')->label('دسته')->relationship('category', 'name'),
                SelectFilter::make('assigned_admin')
                    ->label('کارشناس')
                    ->relationship('assignedAdmin', 'username'),
            ])
            ->actions([
                Tables\Actions\Action::make('manage')
                    ->label('مدیریت')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->url(fn (SupportTicket $record) => Pages\ViewSupportTicket::getUrl(['record' => $record])),
            ])
            ->defaultSort('last_reply_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSupportTickets::route('/'),
            'view'  => Pages\ViewSupportTicket::route('/{record}'),
        ];
    }
}
