<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RepresentativeResource\Pages;
use App\Models\Commission;
use App\Models\User;
use App\Services\Referrals\RepresentativeService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Manage representatives and pending representative requests (User-backed).
 */
class RepresentativeResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon   = 'heroicon-o-user-group';
    protected static ?string $navigationGroup   = 'بازاریابی';
    protected static ?string $navigationLabel   = 'نمایندگان';
    protected static ?string $modelLabel        = 'نماینده';
    protected static ?string $pluralModelLabel  = 'نمایندگان';
    protected static ?int    $navigationSort    = 2;

    /** Only users who are representatives or have a pending/processed request. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('representative_status', '!=', User::REP_NONE);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = parent::getEloquentQuery()->where('representative_status', User::REP_PENDING)->count();
        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('account_id')->label('شناسه اکانت')->fontFamily('mono')
                    ->searchable(query: fn ($query, $search) => $query->where(fn ($w) => $w
                        ->where('account_id', 'like', "%{$search}%")->orWhere('normalized_phone', 'like', "%{$search}%")
                        ->orWhere('referral_code', 'like', "%{$search}%"))),
                Tables\Columns\TextColumn::make('name')->label('نام')->searchable(),
                Tables\Columns\TextColumn::make('phone')->label('شماره موبایل')->searchable()->placeholder('—'),
                Tables\Columns\TextColumn::make('email')->label('ایمیل')->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('referral_code')->label('کد دعوت')->fontFamily('mono')->copyable()->searchable(),
                Tables\Columns\BadgeColumn::make('representative_status')
                    ->label('وضعیت')
                    ->formatStateUsing(fn ($state) => User::representativeStatuses()[$state] ?? $state)
                    ->colors([
                        'success' => [User::REP_APPROVED],
                        'warning' => [User::REP_PENDING],
                        'danger'  => [User::REP_REJECTED, User::REP_DISABLED],
                    ]),
                Tables\Columns\TextColumn::make('referred_users_count')->label('معرفی‌شده‌ها')->counts('referredUsers'),
                Tables\Columns\TextColumn::make('total_commission_earned')
                    ->label('پورسانت کل')->formatStateUsing(fn ($state) => number_format((int) $state)),
                Tables\Columns\TextColumn::make('created_at')->label('تاریخ')->dateTime('Y/m/d')->sortable(),
            ])
            ->filters([
                Filter::make('pending')->label('در انتظار')->query(fn (Builder $q) => $q->where('representative_status', User::REP_PENDING)),
                Filter::make('approved')->label('تاییدشده')->query(fn (Builder $q) => $q->where('representative_status', User::REP_APPROVED)),
                Filter::make('rejected')->label('ردشده')->query(fn (Builder $q) => $q->where('representative_status', User::REP_REJECTED)),
                Filter::make('disabled')->label('غیرفعال')->query(fn (Builder $q) => $q->where('representative_status', User::REP_DISABLED)),
                Filter::make('active_reps')->label('نماینده‌های فعال')->query(fn (Builder $q) => $q->where('is_representative', true)->where('representative_status', User::REP_APPROVED)),
                Filter::make('with_referrals')->label('دارای زیرمجموعه')->query(fn (Builder $q) => $q->has('referredUsers')),
            ])
            ->actions([
                Tables\Actions\Action::make('approve')
                    ->label('تایید')->icon('heroicon-o-check-badge')->color('success')
                    ->visible(fn (User $r) => $r->representative_status !== User::REP_APPROVED)
                    ->requiresConfirmation()
                    ->action(fn (User $r) => tap(app(RepresentativeService::class)->approve($r),
                        fn () => Notification::make()->title('نماینده تایید شد.')->success()->send())),

                Tables\Actions\Action::make('reject')
                    ->label('رد')->icon('heroicon-o-x-circle')->color('danger')
                    ->visible(fn (User $r) => $r->representative_status === User::REP_PENDING)
                    ->form([Forms\Components\Textarea::make('note')->label('دلیل (اختیاری)')->rows(2)])
                    ->action(fn (User $r, array $data) => tap(app(RepresentativeService::class)->reject($r, $data['note'] ?? null),
                        fn () => Notification::make()->title('درخواست رد شد.')->warning()->send())),

                Tables\Actions\Action::make('disable')
                    ->label('غیرفعال')->icon('heroicon-o-pause-circle')->color('warning')
                    ->visible(fn (User $r) => $r->representative_status === User::REP_APPROVED)
                    ->requiresConfirmation()
                    ->action(fn (User $r) => tap(app(RepresentativeService::class)->disable($r),
                        fn () => Notification::make()->title('نماینده غیرفعال شد.')->warning()->send())),

                Tables\Actions\Action::make('enable')
                    ->label('فعال‌سازی')->icon('heroicon-o-play-circle')->color('success')
                    ->visible(fn (User $r) => in_array($r->representative_status, [User::REP_DISABLED, User::REP_REJECTED], true))
                    ->requiresConfirmation()
                    ->action(fn (User $r) => tap(app(RepresentativeService::class)->enable($r),
                        fn () => Notification::make()->title('نماینده فعال شد.')->success()->send())),

                Tables\Actions\Action::make('set_commission')
                    ->label('تنظیم پورسانت')->icon('heroicon-o-adjustments-horizontal')->color('info')
                    ->form([
                        Forms\Components\Select::make('commission_type')->label('نوع پورسانت')
                            ->options(['percent' => 'درصدی', 'fixed' => 'مبلغ ثابت'])->default(fn (User $r) => $r->commission_type)->live(),
                        Forms\Components\TextInput::make('commission_rate')->label('درصد پورسانت')->numeric()->minValue(0)->maxValue(100)
                            ->default(fn (User $r) => $r->commission_rate)
                            ->visible(fn (Forms\Get $get) => $get('commission_type') === 'percent'),
                        Forms\Components\TextInput::make('commission_fixed_amount')->label('مبلغ ثابت (تومان)')->numeric()->minValue(0)
                            ->default(fn (User $r) => $r->commission_fixed_amount)
                            ->visible(fn (Forms\Get $get) => $get('commission_type') === 'fixed'),
                    ])
                    ->action(function (User $r, array $data) {
                        $r->update([
                            'commission_type'         => $data['commission_type'] ?? null,
                            'commission_rate'         => $data['commission_rate'] ?? null,
                            'commission_fixed_amount' => $data['commission_fixed_amount'] ?? null,
                        ]);
                        Notification::make()->title('پورسانت نماینده تنظیم شد.')->success()->send();
                    }),

                Tables\Actions\Action::make('regenerate_code')
                    ->label('کد دعوت جدید')->icon('heroicon-o-arrow-path')->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('کد دعوت فعلی باطل و کد جدیدی ساخته می‌شود.')
                    ->action(function (User $r) {
                        $r->update(['referral_code' => User::generateReferralCode()]);
                        Notification::make()->title('کد دعوت جدید ساخته شد.')->success()->send();
                    }),

                Tables\Actions\Action::make('note')
                    ->label('یادداشت')->icon('heroicon-o-pencil-square')->color('gray')
                    ->form([Forms\Components\Textarea::make('representative_note')->label('یادداشت ادمین')->rows(3)
                        ->default(fn (User $r) => $r->representative_note)])
                    ->action(function (User $r, array $data) {
                        $r->update(['representative_note' => $data['representative_note'] ?? null]);
                        Notification::make()->title('یادداشت ذخیره شد.')->success()->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRepresentatives::route('/'),
        ];
    }
}
