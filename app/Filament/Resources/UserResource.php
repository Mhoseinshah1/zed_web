<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon  = 'heroicon-o-users';
    protected static ?string $navigationGroup = 'کاربران و سفارش‌ها';
    protected static ?string $navigationLabel = 'کاربران';
    protected static ?string $modelLabel      = 'کاربر';
    protected static ?string $pluralModelLabel = 'کاربران';
    protected static ?int $navigationSort     = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->schema([
                Forms\Components\TextInput::make('username')
                    ->label('نام کاربری (برای ورود)')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(60)
                    ->regex('/^[a-zA-Z0-9_]+$/')
                    ->helperText('فقط حروف انگلیسی، اعداد و خط زیر مجاز است'),

                Forms\Components\TextInput::make('name')
                    ->label('نام نمایشی')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('email')
                    ->label('ایمیل')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),

                Forms\Components\Toggle::make('is_admin')
                    ->label('دسترسی ادمین')
                    ->default(false),

                Forms\Components\DateTimePicker::make('email_verified_at')
                    ->label('تاریخ تایید ایمیل'),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('#')->sortable(),
                Tables\Columns\TextColumn::make('username')->label('نام کاربری')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('name')->label('نام نمایشی')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('email')->label('ایمیل')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('wallet_balance_toman')
                    ->label('موجودی کیف پول (تومان)')
                    ->numeric()
                    ->formatStateUsing(fn ($state) => number_format($state))
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_admin')->label('ادمین')->boolean(),
                Tables\Columns\IconColumn::make('email_verified_at')
                    ->label('تایید ایمیل')
                    ->boolean()
                    ->getStateUsing(fn ($record) => ! is_null($record->email_verified_at)),
                Tables\Columns\TextColumn::make('created_at')->label('تاریخ ثبت‌نام')->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\Filter::make('admins')
                    ->label('فقط ادمین‌ها')
                    ->query(fn ($query) => $query->where('is_admin', true)),
                Tables\Filters\Filter::make('verified')
                    ->label('ایمیل تایید شده')
                    ->query(fn ($query) => $query->whereNotNull('email_verified_at')),
            ])
            ->actions([
                Tables\Actions\Action::make('credit_wallet')
                    ->label('شارژ کیف پول')
                    ->icon('heroicon-o-plus-circle')
                    ->color('success')
                    ->form([
                        Forms\Components\TextInput::make('amount_toman')
                            ->label('مبلغ (تومان)')
                            ->required()
                            ->numeric()
                            ->minValue(1),
                        Forms\Components\Textarea::make('description')
                            ->label('دلیل / توضیح')
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (User $record, array $data) {
                        try {
                            app(WalletService::class)->credit(
                                $record,
                                (int) $data['amount_toman'],
                                WalletTransaction::TYPE_MANUAL_CREDIT,
                                [
                                    'description' => $data['description'],
                                    'admin_id'    => auth()->id(),
                                ]
                            );
                            Notification::make()
                                ->title('کیف پول با موفقیت شارژ شد.')
                                ->success()
                                ->send();
                        } catch (\Exception $e) {
                            Notification::make()
                                ->title('خطا: ' . $e->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->modalHeading('شارژ کیف پول')
                    ->modalSubmitActionLabel('شارژ کن'),

                Tables\Actions\Action::make('debit_wallet')
                    ->label('برداشت از کیف پول')
                    ->icon('heroicon-o-minus-circle')
                    ->color('warning')
                    ->form([
                        Forms\Components\TextInput::make('amount_toman')
                            ->label('مبلغ (تومان)')
                            ->required()
                            ->numeric()
                            ->minValue(1),
                        Forms\Components\Textarea::make('description')
                            ->label('دلیل / توضیح')
                            ->required()
                            ->rows(2),
                    ])
                    ->action(function (User $record, array $data) {
                        try {
                            app(WalletService::class)->debit(
                                $record,
                                (int) $data['amount_toman'],
                                WalletTransaction::TYPE_MANUAL_DEBIT,
                                [
                                    'description' => $data['description'],
                                    'admin_id'    => auth()->id(),
                                ]
                            );
                            Notification::make()
                                ->title('برداشت از کیف پول با موفقیت انجام شد.')
                                ->success()
                                ->send();
                        } catch (\RuntimeException $e) {
                            Notification::make()
                                ->title($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    })
                    ->modalHeading('برداشت از کیف پول')
                    ->modalSubmitActionLabel('برداشت کن'),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit'   => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
