<?php

namespace App\Filament\Resources;

use App\Filament\Resources\NotificationResource\Pages;
use App\Models\Notification as NotificationModel;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class NotificationResource extends Resource
{
    protected static ?string $model = NotificationModel::class;

    protected static ?string $navigationIcon   = 'heroicon-o-bell-alert';
    protected static ?string $navigationGroup   = 'سیستم و یکپارچه‌سازی';
    protected static ?string $navigationLabel   = 'اعلان‌های سیستم';
    protected static ?string $modelLabel        = 'اعلان';
    protected static ?string $pluralModelLabel  = 'اعلان‌ها';
    protected static ?int    $navigationSort    = 20;

    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::query()->whereNull('read_at')->count();
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
                Tables\Columns\IconColumn::make('read_at')
                    ->label('وضعیت')
                    ->boolean()
                    ->trueIcon('heroicon-o-envelope-open')
                    ->falseIcon('heroicon-s-envelope')
                    ->trueColor('gray')
                    ->falseColor('danger')
                    ->getStateUsing(fn (NotificationModel $record) => $record->read_at !== null),

                Tables\Columns\BadgeColumn::make('type')
                    ->label('نوع')
                    ->formatStateUsing(fn ($state) => NotificationModel::typeLabels()[$state] ?? $state)
                    ->colors([
                        'danger'  => [
                            NotificationModel::TYPE_MARZBAN_UPDATE_FAILED,
                            NotificationModel::TYPE_PROVISIONING_FAILED,
                            NotificationModel::TYPE_PAYMENT_FAILED,
                            NotificationModel::TYPE_ADMIN_WARNING,
                        ],
                        'success' => [
                            NotificationModel::TYPE_PAYMENT_SUCCESS,
                            NotificationModel::TYPE_NEW_SERVICE_CREATED,
                            NotificationModel::TYPE_RENEWAL_SUCCESS,
                        ],
                    ]),

                Tables\Columns\TextColumn::make('title')
                    ->label('عنوان')
                    ->searchable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('message')
                    ->label('پیام')
                    ->limit(80)
                    ->tooltip(fn (NotificationModel $record) => $record->message)
                    ->wrap(),

                Tables\Columns\TextColumn::make('user.username')
                    ->label('کاربر')
                    ->searchable()
                    ->placeholder('سیستم'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاریخ')
                    ->dateTime('Y/m/d H:i')
                    ->sortable(),
            ])
            ->filters([
                TernaryFilter::make('read_at')
                    ->label('وضعیت خواندن')
                    ->placeholder('همه')
                    ->trueLabel('خوانده‌شده')
                    ->falseLabel('خوانده‌نشده')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('read_at'),
                        false: fn (Builder $q) => $q->whereNull('read_at'),
                        blank: fn (Builder $q) => $q,
                    ),

                SelectFilter::make('type')
                    ->label('نوع')
                    ->options(NotificationModel::typeLabels()),

                SelectFilter::make('scope')
                    ->label('دامنه')
                    ->options(['system' => 'سیستم', 'user' => 'کاربر'])
                    ->query(fn (Builder $query, array $data) => match ($data['value'] ?? null) {
                        'system' => $query->whereNull('user_id'),
                        'user'   => $query->whereNotNull('user_id'),
                        default  => $query,
                    }),

                SelectFilter::make('user_id')
                    ->label('کاربر')
                    ->relationship('user', 'username')
                    ->searchable(),

                Filter::make('date_range')
                    ->form([
                        DatePicker::make('from')->label('از تاریخ'),
                        DatePicker::make('until')->label('تا تاریخ'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('created_at', '<=', $d)))
                    ->label('بازه تاریخ'),
            ])
            ->actions([
                Tables\Actions\Action::make('mark_read')
                    ->label('خوانده شد')
                    ->icon('heroicon-o-check')
                    ->color('gray')
                    ->visible(fn (NotificationModel $record) => $record->read_at === null)
                    ->action(fn (NotificationModel $record) => $record->markRead()),
            ])
            ->bulkActions([
                Tables\Actions\BulkAction::make('mark_read_bulk')
                    ->label('علامت‌گذاری خوانده‌شده')
                    ->icon('heroicon-o-check')
                    ->action(function ($records) {
                        $records->each->markRead();
                        FilamentNotification::make()->title('اعلان‌ها خوانده‌شده شدند.')->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListNotifications::route('/'),
        ];
    }
}
