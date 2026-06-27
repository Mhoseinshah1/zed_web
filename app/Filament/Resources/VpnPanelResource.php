<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VpnPanelResource\Pages;
use App\Models\VpnPanel;
use App\Services\Marzban\MarzbanClient;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;

class VpnPanelResource extends Resource
{
    protected static ?string $model = VpnPanel::class;

    protected static ?string $navigationIcon   = 'heroicon-o-server';
    protected static ?string $navigationGroup  = 'مدیریت VPN';
    protected static ?string $navigationLabel  = 'پنل‌های VPN';
    protected static ?string $modelLabel       = 'پنل VPN';
    protected static ?string $pluralModelLabel = 'پنل‌های VPN';
    protected static ?int    $navigationSort   = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('اطلاعات پنل')->schema([
                Forms\Components\TextInput::make('name')
                    ->label('نام پنل')
                    ->required()
                    ->maxLength(255),

                Forms\Components\Select::make('type')
                    ->label('نوع پنل')
                    ->options(VpnPanel::allTypes())
                    ->required()
                    ->default(VpnPanel::TYPE_MARZBAN),

                Forms\Components\TextInput::make('base_url')
                    ->label('آدرس پایه (Base URL)')
                    ->url()
                    ->nullable()
                    ->placeholder('https://panel.example.com:2053')
                    ->columnSpanFull(),

                Forms\Components\TextInput::make('api_docs_url')
                    ->label('آدرس مستندات API')
                    ->url()
                    ->nullable()
                    ->placeholder('https://panel.example.com:2053/docs')
                    ->helperText('مثال: https://panel.staygreen.top/docs')
                    ->columnSpanFull(),

                Forms\Components\TextInput::make('username')
                    ->label('نام کاربری ادمین')
                    ->nullable(),

                Forms\Components\TextInput::make('password')
                    ->label('رمز عبور ادمین')
                    ->password()
                    ->nullable()
                    ->revealable()
                    ->helperText('ذخیره شده به صورت رمزگذاری‌شده'),

                Forms\Components\Toggle::make('is_active')
                    ->label('فعال')
                    ->default(false),

                Forms\Components\Toggle::make('is_default')
                    ->label('پنل پیش‌فرض')
                    ->default(false)
                    ->helperText('سرویس‌های جدید روی این پنل ساخته می‌شوند'),
            ])->columns(2),

            Forms\Components\Section::make('وضعیت اتصال')
                ->schema([
                    Forms\Components\Placeholder::make('last_checked_at')
                        ->label('آخرین تست اتصال')
                        ->content(fn ($record) => $record?->last_checked_at?->diffForHumans() ?? '—'),

                    Forms\Components\Placeholder::make('last_error_display')
                        ->label('آخرین خطا')
                        ->content(fn ($record) => $record?->last_error ?? '—'),
                ])
                ->columns(2)
                ->visibleOn('edit'),

            Forms\Components\Section::make('یادداشت‌ها')->schema([
                Forms\Components\Textarea::make('notes')
                    ->label('یادداشت')
                    ->rows(3)
                    ->nullable()
                    ->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('نام پنل')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\BadgeColumn::make('type')
                    ->label('نوع')
                    ->formatStateUsing(fn ($state) => VpnPanel::allTypes()[$state] ?? $state)
                    ->colors([
                        'info'    => ['marzban'],
                        'warning' => ['xui', 'sanaei_3xui'],
                        'gray'    => ['other'],
                    ]),

                Tables\Columns\TextColumn::make('base_url')
                    ->label('آدرس')
                    ->default('—')
                    ->limit(40),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('فعال')
                    ->boolean(),

                Tables\Columns\IconColumn::make('is_default')
                    ->label('پیش‌فرض')
                    ->boolean(),

                Tables\Columns\TextColumn::make('last_checked_at')
                    ->label('آخرین تست')
                    ->dateTime()
                    ->sortable()
                    ->default('—'),

                Tables\Columns\TextColumn::make('last_error')
                    ->label('آخرین خطا')
                    ->limit(40)
                    ->default('—')
                    ->color('danger'),
            ])
            ->filters([])
            ->actions([
                Tables\Actions\Action::make('test_connection')
                    ->label('تست اتصال')
                    ->icon('heroicon-o-signal')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('تست اتصال')
                    ->modalDescription(fn (VpnPanel $record) => "اتصال به پنل \"{$record->name}\" بررسی می‌شود.")
                    ->visible(fn (VpnPanel $record) => $record->type === VpnPanel::TYPE_MARZBAN)
                    ->action(function (VpnPanel $record): void {
                        try {
                            $client = new MarzbanClient($record);
                            $info   = $client->testConnection();

                            $record->update([
                                'last_checked_at' => now(),
                                'last_error'      => null,
                            ]);

                            $version = $info['version'] ?? 'unknown';

                            Notification::make()
                                ->title('اتصال موفق')
                                ->body("پنل Marzban متصل شد. نسخه: {$version}")
                                ->success()
                                ->send();

                        } catch (\Throwable $e) {
                            $record->update([
                                'last_checked_at' => now(),
                                'last_error'      => $e->getMessage(),
                            ]);

                            Notification::make()
                                ->title('خطا در اتصال')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('refresh_token')
                    ->label('تازه‌سازی توکن')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn (VpnPanel $record) => $record->type === VpnPanel::TYPE_MARZBAN)
                    ->action(function (VpnPanel $record): void {
                        try {
                            Cache::forget("marzban_token_panel_{$record->id}");
                            $client = new MarzbanClient($record);
                            $client->login();

                            Notification::make()
                                ->title('توکن تازه‌سازی شد')
                                ->success()
                                ->send();

                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('خطا در دریافت توکن')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('clear_token')
                    ->label('پاک کردن توکن')
                    ->icon('heroicon-o-x-circle')
                    ->color('gray')
                    ->visible(fn (VpnPanel $record) => $record->type === VpnPanel::TYPE_MARZBAN)
                    ->action(function (VpnPanel $record): void {
                        Cache::forget("marzban_token_panel_{$record->id}");

                        Notification::make()
                            ->title('توکن از کش پاک شد')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('mark_default')
                    ->label('تنظیم پیش‌فرض')
                    ->icon('heroicon-o-star')
                    ->color('success')
                    ->visible(fn (VpnPanel $record) => ! $record->is_default && $record->is_active)
                    ->action(function (VpnPanel $record): void {
                        VpnPanel::where('id', '!=', $record->id)->update(['is_default' => false]);
                        $record->update(['is_default' => true]);

                        Notification::make()
                            ->title('پنل پیش‌فرض تنظیم شد')
                            ->success()
                            ->send();
                    }),

                Tables\Actions\Action::make('open_docs')
                    ->label('مستندات API')
                    ->icon('heroicon-o-document-text')
                    ->color('gray')
                    ->url(fn (VpnPanel $record) => $record->api_docs_url
                        ?: ($record->base_url ? rtrim($record->base_url, '/') . '/docs' : null))
                    ->openUrlInNewTab()
                    ->visible(fn (VpnPanel $record) => filled($record->base_url) || filled($record->api_docs_url)),

                Tables\Actions\EditAction::make()->label('ویرایش'),
                Tables\Actions\DeleteAction::make()->label('حذف'),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListVpnPanels::route('/'),
            'create' => Pages\CreateVpnPanel::route('/create'),
            'edit'   => Pages\EditVpnPanel::route('/{record}/edit'),
        ];
    }
}
