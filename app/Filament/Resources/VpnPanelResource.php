<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VpnPanelResource\Pages;
use App\Models\VpnPanel;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

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
            Forms\Components\Section::make()->schema([
                Forms\Components\Placeholder::make('integration_notice')
                    ->label('')
                    ->content('⚠️ اتصال واقعی به پنل VPN در مرحله بعد فعال می‌شود. در حال حاضر فقط اطلاعات ذخیره می‌شود.')
                    ->columnSpanFull(),
            ]),

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
                    ->placeholder('https://panel.example.com:2053'),

                Forms\Components\TextInput::make('username')
                    ->label('نام کاربری')
                    ->nullable(),

                Forms\Components\TextInput::make('password')
                    ->label('رمز عبور')
                    ->password()
                    ->nullable()
                    ->revealable(),

                Forms\Components\TextInput::make('token')
                    ->label('توکن API')
                    ->nullable()
                    ->password()
                    ->revealable(),

                Forms\Components\Toggle::make('is_active')
                    ->label('فعال')
                    ->default(false),

                Forms\Components\Toggle::make('is_default')
                    ->label('پنل پیش‌فرض')
                    ->default(false),
            ])->columns(2),

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

                Tables\Columns\TextColumn::make('updated_at')
                    ->label('آخرین ویرایش')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([])
            ->actions([
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
