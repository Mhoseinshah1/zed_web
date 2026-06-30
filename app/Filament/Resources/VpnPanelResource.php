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
    protected static ?string $navigationGroup  = 'سرویس‌ها و پنل‌های VPN';
    protected static ?string $navigationLabel  = 'پنل‌های VPN';
    protected static ?string $modelLabel       = 'پنل VPN';
    protected static ?string $pluralModelLabel = 'پنل‌های VPN';
    protected static ?int    $navigationSort   = 20;

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

            Forms\Components\Section::make('تنظیمات سنایی / 3X-UI')
                ->description('فقط برای پنل‌های نوع سنایی / 3X-UI. احراز هویت تنها از طریق API رسمی انجام می‌شود؛ توکن API روش توصیه‌شده است.')
                ->visible(fn (Forms\Get $get) => $get('type') === VpnPanel::TYPE_SANAEI_XUI)
                ->schema([
                    Forms\Components\TextInput::make('panel_path')
                        ->label('مسیر پنل')
                        ->placeholder('/M.hosein1384')
                        ->helperText('مسیر اختصاصی پنل که در آدرس ورود استفاده می‌شود.'),

                    Forms\Components\Select::make('auth_method')
                        ->label('روش احراز هویت')
                        ->options(VpnPanel::authMethods())
                        ->default(VpnPanel::AUTH_API_TOKEN)
                        ->live()
                        ->helperText('روش توصیه‌شده: API Token.'),

                    Forms\Components\TextInput::make('api_token')
                        ->label('توکن API')
                        ->password()->revealable()
                        ->visible(fn (Forms\Get $get) => $get('auth_method') !== VpnPanel::AUTH_API_LOGIN)
                        ->required(fn (Forms\Get $get) => $get('type') === VpnPanel::TYPE_SANAEI_XUI && $get('auth_method') !== VpnPanel::AUTH_API_LOGIN)
                        ->helperText('ذخیره به‌صورت رمزگذاری‌شده. در جدول‌ها نمایش داده نمی‌شود.')
                        ->columnSpanFull(),

                    Forms\Components\TextInput::make('username')
                        ->label('نام کاربری پنل')
                        ->visible(fn (Forms\Get $get) => $get('auth_method') === VpnPanel::AUTH_API_LOGIN)
                        ->required(fn (Forms\Get $get) => $get('type') === VpnPanel::TYPE_SANAEI_XUI && $get('auth_method') === VpnPanel::AUTH_API_LOGIN),

                    Forms\Components\TextInput::make('password')
                        ->label('رمز عبور پنل')
                        ->password()->revealable()
                        ->visible(fn (Forms\Get $get) => $get('auth_method') === VpnPanel::AUTH_API_LOGIN)
                        ->required(fn (Forms\Get $get) => $get('type') === VpnPanel::TYPE_SANAEI_XUI && $get('auth_method') === VpnPanel::AUTH_API_LOGIN),

                    Forms\Components\TextInput::make('default_inbound_id')
                        ->label('Inbound پیش‌فرض')
                        ->numeric()
                        ->helperText('با دکمه «دریافت لیست Inboundها» شناسه را پیدا کنید.'),

                    Forms\Components\TextInput::make('subscription_base_url')
                        ->label('آدرس پایه لینک اشتراک')
                        ->placeholder('https://example.com:2096'),

                    Forms\Components\TextInput::make('subscription_path')
                        ->label('مسیر لینک اشتراک')
                        ->placeholder('sub'),

                    Forms\Components\Toggle::make('verify_ssl')
                        ->label('بررسی SSL')
                        ->default(true)
                        ->helperText('برای پنل‌های با گواهی self-signed می‌توانید غیرفعال کنید.'),

                    Forms\Components\TextInput::make('timeout_seconds')
                        ->label('زمان انتظار اتصال (ثانیه)')
                        ->numeric()->default(15)->minValue(1)->maxValue(120),

                    Forms\Components\Textarea::make('notes')
                        ->label('توضیحات ادمین')
                        ->rows(2)->columnSpanFull(),
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

            Forms\Components\Section::make('مدیریت قابلیت‌های کاربر')
                ->description('مشخص کنید کاربران چه اقداماتی روی سرویس‌های این پنل می‌توانند انجام دهند.')
                ->schema([
                    Forms\Components\Toggle::make('allow_user_sync_service')
                        ->label('بروزرسانی وضعیت سرویس')
                        ->helperText('کاربر می‌تواند وضعیت سرویس خود را از Marzban بروزرسانی کند.')
                        ->default(true),

                    Forms\Components\Toggle::make('allow_user_revoke_subscription')
                        ->label('تغییر لینک اشتراک')
                        ->helperText('کاربر می‌تواند لینک اشتراک خود را تغییر دهد (با محدودیت زمانی).')
                        ->default(true),

                    Forms\Components\Toggle::make('allow_user_reset_traffic')
                        ->label('ریست ترافیک')
                        ->helperText('کاربر می‌تواند مصرف ترافیک سرویس خود را ریست کند.')
                        ->default(false),

                    Forms\Components\Toggle::make('allow_user_disable_service')
                        ->label('غیرفعال‌سازی سرویس')
                        ->helperText('کاربر می‌تواند سرویس فعال خود را موقتاً غیرفعال کند.')
                        ->default(false),

                    Forms\Components\Toggle::make('allow_user_enable_service')
                        ->label('فعال‌سازی سرویس')
                        ->helperText('کاربر می‌تواند سرویس غیرفعال خود را دوباره فعال کند.')
                        ->default(false),

                    Forms\Components\Toggle::make('allow_user_view_subscription_qr')
                        ->label('نمایش QR اشتراک')
                        ->helperText('کاربر می‌تواند QR Code لینک اشتراک را ببیند.')
                        ->default(true),

                    Forms\Components\Toggle::make('allow_user_view_config_qr')
                        ->label('نمایش QR کانفیگ')
                        ->helperText('کاربر می‌تواند QR Code لینک کانفیگ را ببیند.')
                        ->default(true),

                    Forms\Components\Toggle::make('allow_user_copy_subscription_link')
                        ->label('کپی لینک اشتراک')
                        ->helperText('کاربر می‌تواند لینک اشتراک را کپی کند.')
                        ->default(true),

                    Forms\Components\Toggle::make('allow_user_copy_config_link')
                        ->label('کپی لینک کانفیگ')
                        ->helperText('کاربر می‌تواند لینک کانفیگ را کپی کند.')
                        ->default(true),

                    Forms\Components\Toggle::make('allow_user_view_all_config_links')
                        ->label('نمایش همه لینک‌های کانفیگ')
                        ->helperText('کاربر می‌تواند تمام لینک‌های کانفیگ موجود را ببیند.')
                        ->default(true),
                ])
                ->columns(2)
                ->collapsible()
                ->collapsed(false),

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

                Tables\Columns\BadgeColumn::make('health_status')
                    ->label('سلامت پنل')
                    ->formatStateUsing(fn ($state) => match ($state) {
                        \App\Models\VpnPanel::HEALTH_ONLINE  => 'آنلاین',
                        \App\Models\VpnPanel::HEALTH_OFFLINE => 'آفلاین',
                        default                              => '—',
                    })
                    ->colors([
                        'success' => [\App\Models\VpnPanel::HEALTH_ONLINE],
                        'danger'  => [\App\Models\VpnPanel::HEALTH_OFFLINE],
                        'gray'    => [null],
                    ]),

                Tables\Columns\TextColumn::make('last_health_checked_at')
                    ->label('آخرین بررسی سلامت')
                    ->dateTime('Y/m/d H:i')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('last_checked_at')
                    ->label('آخرین تست')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('health_error')
                    ->label('خطای سلامت')
                    ->limit(40)
                    ->placeholder('—')
                    ->color('danger'),
            ])
            ->filters([])
            ->actions([
                // ── 3X-UI / Sanaei: test connection via official API ──
                Tables\Actions\Action::make('sanaei_test_connection')
                    ->label('تست اتصال')
                    ->icon('heroicon-o-signal')
                    ->color('info')
                    ->visible(fn (VpnPanel $record) => $record->type === VpnPanel::TYPE_SANAEI_XUI)
                    ->action(function (VpnPanel $record): void {
                        $result = (new \App\Services\VpnPanels\Sanaei3xUiProvider())->testConnection($record);
                        $record->update([
                            'last_checked_at'        => now(),
                            'last_error'             => $result->ok ? null : $result->message,
                            'last_health_checked_at' => now(),
                            'health_status'          => $result->ok ? VpnPanel::HEALTH_ONLINE : VpnPanel::HEALTH_OFFLINE,
                            'health_error'           => $result->ok ? null : $result->message,
                        ]);
                        Notification::make()
                            ->title($result->ok ? 'اتصال موفق' : 'اتصال ناموفق')
                            ->body($result->message)
                            ->{$result->ok ? 'success' : 'danger'}()
                            ->send();
                    }),

                // ── 3X-UI / Sanaei: fetch inbound list ──
                Tables\Actions\Action::make('sanaei_inbounds')
                    ->label('دریافت لیست Inboundها')
                    ->icon('heroicon-o-queue-list')
                    ->color('gray')
                    ->visible(fn (VpnPanel $record) => $record->type === VpnPanel::TYPE_SANAEI_XUI)
                    ->modalSubmitAction(false)
                    ->modalHeading('Inboundهای پنل سنایی')
                    ->modalContent(function (VpnPanel $record) {
                        try {
                            $inbounds = (new \App\Services\VpnPanels\Sanaei\Sanaei3xUiClient($record))->getInbounds();
                            $rows = collect($inbounds)->map(fn ($i) => [
                                'id'       => $i['id'] ?? '—',
                                'remark'   => $i['remark'] ?? '—',
                                'protocol' => $i['protocol'] ?? '—',
                                'port'     => $i['port'] ?? '—',
                            ]);
                            $html = '<table style="width:100%;font-size:13px"><thead><tr style="text-align:right">'
                                . '<th>ID</th><th>نام</th><th>پروتکل</th><th>پورت</th></tr></thead><tbody>';
                            foreach ($rows as $r) {
                                $html .= "<tr><td>{$r['id']}</td><td>" . e($r['remark']) . "</td><td>{$r['protocol']}</td><td>{$r['port']}</td></tr>";
                            }
                            $html .= '</tbody></table>';
                            return new \Illuminate\Support\HtmlString($html);
                        } catch (\Throwable $e) {
                            return new \Illuminate\Support\HtmlString('<p style="color:#f43f5e">دریافت لیست Inboundها ناموفق بود.</p>');
                        }
                    }),

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
                                'last_checked_at'        => now(),
                                'last_error'             => null,
                                'last_health_checked_at' => now(),
                                'health_status'          => VpnPanel::HEALTH_ONLINE,
                                'health_error'           => null,
                                'system_info'            => collect($info)->only([
                                    'version', 'mem_total', 'mem_used', 'cpu_cores', 'cpu_usage', 'total_user', 'users_active',
                                ])->all(),
                            ]);

                            $version = $info['version'] ?? 'unknown';

                            Notification::make()
                                ->title('اتصال موفق')
                                ->body("پنل Marzban متصل شد. نسخه: {$version}")
                                ->success()
                                ->send();

                        } catch (\Throwable $e) {
                            $record->update([
                                'last_checked_at'        => now(),
                                'last_error'             => $e->getMessage(),
                                'last_health_checked_at' => now(),
                                'health_status'          => VpnPanel::HEALTH_OFFLINE,
                                'health_error'           => $e->getMessage(),
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
