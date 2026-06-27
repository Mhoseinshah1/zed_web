<?php

namespace App\Filament\Resources;

use App\Filament\Resources\VpnInboundResource\Pages;
use App\Models\VpnInbound;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class VpnInboundResource extends Resource
{
    protected static ?string $model = VpnInbound::class;

    protected static ?string $navigationIcon   = 'heroicon-o-arrows-right-left';
    protected static ?string $navigationGroup  = 'مدیریت VPN';
    protected static ?string $navigationLabel  = 'اینباندها';
    protected static ?string $modelLabel       = 'اینباند';
    protected static ?string $pluralModelLabel = 'اینباندها';
    protected static ?int    $navigationSort   = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('اطلاعات اینباند')->schema([
                Forms\Components\Select::make('vpn_panel_id')
                    ->label('پنل VPN')
                    ->relationship('vpnPanel', 'name')
                    ->required()
                    ->searchable(),

                Forms\Components\TextInput::make('name')
                    ->label('نام اینباند')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('remote_inbound_id')
                    ->label('Remote Inbound ID')
                    ->nullable(),

                Forms\Components\Select::make('protocol')
                    ->label('پروتکل')
                    ->options([
                        'vmess'       => 'VMess',
                        'vless'       => 'VLESS',
                        'trojan'      => 'Trojan',
                        'shadowsocks' => 'Shadowsocks',
                    ])
                    ->nullable(),

                Forms\Components\TextInput::make('port')
                    ->label('پورت')
                    ->numeric()
                    ->nullable()
                    ->minValue(1)
                    ->maxValue(65535),

                Forms\Components\Select::make('network')
                    ->label('شبکه')
                    ->options([
                        'tcp'  => 'TCP',
                        'ws'   => 'WebSocket',
                        'grpc' => 'gRPC',
                        'quic' => 'QUIC',
                    ])
                    ->nullable(),

                Forms\Components\Select::make('security')
                    ->label('امنیت')
                    ->options([
                        'none'    => 'None',
                        'tls'     => 'TLS',
                        'reality' => 'Reality',
                    ])
                    ->nullable(),

                Forms\Components\Toggle::make('is_active')
                    ->label('فعال')
                    ->default(true),

                Forms\Components\Toggle::make('is_default')
                    ->label('اینباند پیش‌فرض')
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
                    ->label('نام')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('vpnPanel.name')
                    ->label('پنل')
                    ->searchable(),

                Tables\Columns\TextColumn::make('protocol')
                    ->label('پروتکل')
                    ->badge()
                    ->default('—'),

                Tables\Columns\TextColumn::make('port')
                    ->label('پورت')
                    ->default('—'),

                Tables\Columns\TextColumn::make('network')
                    ->label('شبکه')
                    ->default('—'),

                Tables\Columns\TextColumn::make('security')
                    ->label('امنیت')
                    ->default('—'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('فعال')
                    ->boolean(),

                Tables\Columns\IconColumn::make('is_default')
                    ->label('پیش‌فرض')
                    ->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('vpn_panel_id')
                    ->label('پنل')
                    ->relationship('vpnPanel', 'name'),

                Tables\Filters\Filter::make('active')
                    ->label('فعال')
                    ->query(fn ($query) => $query->where('is_active', true)),
            ])
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
            'index'  => Pages\ListVpnInbounds::route('/'),
            'create' => Pages\CreateVpnInbound::route('/create'),
            'edit'   => Pages\EditVpnInbound::route('/{record}/edit'),
        ];
    }
}
