<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserServiceResource\Pages;
use App\Jobs\ProvisionMarzbanServiceJob;
use App\Models\UserService;
use App\Models\VpnPanel;
use App\Services\Marzban\MarzbanClient;
use App\Services\ServiceProvisioner;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Bus;

class UserServiceResource extends Resource
{
    protected static ?string $model = UserService::class;

    protected static ?string $navigationIcon   = 'heroicon-o-signal';
    protected static ?string $navigationGroup  = 'کاربران و سفارش‌ها';
    protected static ?string $navigationLabel  = 'سرویس‌ها';
    protected static ?string $modelLabel       = 'سرویس';
    protected static ?string $pluralModelLabel = 'سرویس‌ها';
    protected static ?int    $navigationSort   = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('اطلاعات کاربر و سفارش')->schema([
                Forms\Components\Select::make('user_id')
                    ->label('کاربر')
                    ->relationship('user', 'username')
                    ->searchable()
                    ->required(),

                Forms\Components\Select::make('order_id')
                    ->label('سفارش')
                    ->relationship('order', 'order_number')
                    ->searchable()
                    ->nullable(),

                Forms\Components\Select::make('plan_id')
                    ->label('پلن')
                    ->relationship('plan', 'name')
                    ->searchable()
                    ->nullable(),

                Forms\Components\TextInput::make('service_number')
                    ->label('شماره سرویس')
                    ->disabled()
                    ->placeholder('خودکار تولید می‌شود'),
            ])->columns(2),

            Forms\Components\Section::make('وضعیت سرویس')->schema([
                Forms\Components\TextInput::make('name')
                    ->label('نام سرویس')
                    ->nullable(),

                Forms\Components\Select::make('status')
                    ->label('وضعیت سرویس')
                    ->options(UserService::allStatuses())
                    ->required(),

                Forms\Components\Select::make('provision_status')
                    ->label('وضعیت ساخت')
                    ->options(UserService::allProvisionStatuses())
                    ->required(),

                Forms\Components\TextInput::make('plan_name')
                    ->label('نام پلن (اسنپشات)')
                    ->nullable(),
            ])->columns(2),

            Forms\Components\Section::make('حجم و مدت')->schema([
                Forms\Components\TextInput::make('traffic_total_gb')
                    ->label('حجم کل (GB)')
                    ->numeric()
                    ->nullable(),

                Forms\Components\TextInput::make('traffic_used_gb')
                    ->label('حجم مصرف‌شده (GB)')
                    ->numeric()
                    ->default(0),

                Forms\Components\TextInput::make('duration_days')
                    ->label('مدت (روز)')
                    ->numeric()
                    ->nullable(),
            ])->columns(3),

            Forms\Components\Section::make('تاریخ‌ها')->schema([
                Forms\Components\DateTimePicker::make('starts_at')
                    ->label('تاریخ شروع'),

                Forms\Components\DateTimePicker::make('expires_at')
                    ->label('تاریخ انقضا'),

                Forms\Components\DateTimePicker::make('activated_at')
                    ->label('تاریخ فعال‌سازی'),

                Forms\Components\DateTimePicker::make('disabled_at')
                    ->label('تاریخ غیرفعال‌سازی'),
            ])->columns(2)->collapsible(),

            Forms\Components\Section::make('لینک‌های اتصال')->schema([
                Forms\Components\Textarea::make('config_link')
                    ->label('لینک کانفیگ')
                    ->rows(3)
                    ->nullable()
                    ->columnSpanFull(),

                Forms\Components\Textarea::make('subscription_link')
                    ->label('لینک اشتراک')
                    ->rows(2)
                    ->nullable()
                    ->columnSpanFull(),
            ]),

            Forms\Components\Section::make('پنل VPN')->schema([
                Forms\Components\Select::make('vpn_panel_id')
                    ->label('پنل VPN')
                    ->relationship('vpnPanel', 'name')
                    ->nullable(),

                Forms\Components\Select::make('vpn_inbound_id')
                    ->label('اینباند')
                    ->relationship('vpnInbound', 'name')
                    ->nullable(),

                Forms\Components\TextInput::make('remote_client_id')
                    ->label('Remote Client ID')
                    ->nullable(),

                Forms\Components\TextInput::make('remote_username')
                    ->label('Remote Username')
                    ->nullable(),
            ])->columns(2)->collapsible()->collapsed(),

            Forms\Components\Section::make('یادداشت‌ها')->schema([
                Forms\Components\Textarea::make('admin_notes')
                    ->label('یادداشت ادمین')
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
                Tables\Columns\TextColumn::make('service_number')
                    ->label('شماره سرویس')
                    ->searchable()
                    ->sortable()
                    ->fontFamily('mono')
                    ->copyable(),

                Tables\Columns\TextColumn::make('user.username')
                    ->label('کاربر')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('order.order_number')
                    ->label('سفارش')
                    ->searchable()
                    ->fontFamily('mono')
                    ->default('—'),

                Tables\Columns\TextColumn::make('plan_name')
                    ->label('پلن')
                    ->searchable()
                    ->default('—'),

                Tables\Columns\BadgeColumn::make('status')
                    ->label('وضعیت')
                    ->formatStateUsing(fn ($state) => UserService::allStatuses()[$state] ?? $state)
                    ->colors([
                        'success' => ['active'],
                        'warning' => ['pending_provision'],
                        'info'    => ['disabled'],
                        'gray'    => ['expired'],
                        'danger'  => ['cancelled', 'failed'],
                    ]),

                Tables\Columns\BadgeColumn::make('provision_status')
                    ->label('وضعیت ساخت')
                    ->formatStateUsing(fn ($state) => UserService::allProvisionStatuses()[$state] ?? $state)
                    ->colors([
                        'warning' => ['pending', 'manual_required'],
                        'success' => ['provisioned'],
                        'danger'  => ['failed'],
                        'gray'    => ['skipped'],
                    ]),

                Tables\Columns\TextColumn::make('traffic_total_gb')
                    ->label('حجم کل (GB)')
                    ->default('نامحدود'),

                Tables\Columns\TextColumn::make('traffic_used_gb')
                    ->label('مصرف (GB)')
                    ->default(0),

                Tables\Columns\TextColumn::make('expires_at')
                    ->label('تاریخ انقضا')
                    ->dateTime('Y/m/d')
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('تاریخ ایجاد')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label('وضعیت سرویس')
                    ->options(UserService::allStatuses()),

                Tables\Filters\SelectFilter::make('provision_status')
                    ->label('وضعیت ساخت')
                    ->options(UserService::allProvisionStatuses()),

                Tables\Filters\Filter::make('expired')
                    ->label('منقضی شده')
                    ->query(fn ($query) => $query->where('expires_at', '<', now())->whereNotNull('expires_at')),

                Tables\Filters\Filter::make('active')
                    ->label('فعال')
                    ->query(fn ($query) => $query->where('status', UserService::STATUS_ACTIVE)),
            ])
            ->actions([
                Tables\Actions\Action::make('activate')
                    ->label('فعال‌سازی')
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (UserService $record) => ! in_array($record->status, [
                        UserService::STATUS_ACTIVE,
                        UserService::STATUS_CANCELLED,
                    ]))
                    ->action(function (UserService $record) {
                        try {
                            app(ServiceProvisioner::class)->activateManually($record);
                            Notification::make()->title('سرویس فعال شد.')->success()->send();
                        } catch (\Exception $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    })
                    ->requiresConfirmation()
                    ->modalHeading('فعال‌سازی سرویس')
                    ->modalSubmitActionLabel('فعال کن'),

                Tables\Actions\Action::make('disable')
                    ->label('غیرفعال')
                    ->icon('heroicon-o-pause-circle')
                    ->color('warning')
                    ->visible(fn (UserService $record) => $record->status === UserService::STATUS_ACTIVE)
                    ->action(function (UserService $record) {
                        app(ServiceProvisioner::class)->disableManually($record);
                        Notification::make()->title('سرویس غیرفعال شد.')->warning()->send();
                    })
                    ->requiresConfirmation()
                    ->modalSubmitActionLabel('غیرفعال کن'),

                Tables\Actions\Action::make('cancel')
                    ->label('لغو')
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (UserService $record) => ! in_array($record->status, [
                        UserService::STATUS_CANCELLED,
                        UserService::STATUS_FAILED,
                    ]))
                    ->action(function (UserService $record) {
                        app(ServiceProvisioner::class)->cancelManually($record);
                        Notification::make()->title('سرویس لغو شد.')->warning()->send();
                    })
                    ->requiresConfirmation()
                    ->modalSubmitActionLabel('لغو کن'),

                Tables\Actions\Action::make('retry_provision')
                    ->label('تلاش مجدد Marzban')
                    ->icon('heroicon-o-arrow-path')
                    ->color('info')
                    ->visible(fn (UserService $record) => in_array($record->provision_status, [
                        UserService::PROVISION_MANUAL_REQUIRED,
                        UserService::PROVISION_FAILED,
                        UserService::PROVISION_SKIPPED,
                    ]))
                    ->requiresConfirmation()
                    ->modalHeading('تلاش مجدد ساخت سرویس روی Marzban')
                    ->modalSubmitActionLabel('اجرا کن')
                    ->action(function (UserService $record): void {
                        $panel = $record->vpnPanel
                            ?? VpnPanel::where('type', VpnPanel::TYPE_MARZBAN)
                                ->where('is_active', true)
                                ->where('is_default', true)
                                ->first();

                        if (! $panel) {
                            Notification::make()
                                ->title('هیچ پنل Marzban پیش‌فرض فعالی یافت نشد.')
                                ->danger()
                                ->send();
                            return;
                        }

                        try {
                            Bus::dispatchSync(new ProvisionMarzbanServiceJob($record->id, $panel->id));

                            Notification::make()
                                ->title('سرویس با موفقیت روی Marzban ساخته شد.')
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()
                                ->title('خطا در ساخت سرویس روی Marzban')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('sync_marzban')
                    ->label('همگام‌سازی از Marzban')
                    ->icon('heroicon-o-cloud-arrow-down')
                    ->color('gray')
                    ->visible(fn (UserService $record) => filled($record->remote_username) && $record->vpnPanel?->type === VpnPanel::TYPE_MARZBAN)
                    ->action(function (UserService $record): void {
                        $panel = $record->vpnPanel;
                        if (! $panel) {
                            Notification::make()->title('پنل VPN مشخص نشده.')->danger()->send();
                            return;
                        }

                        try {
                            $client      = new MarzbanClient($panel);
                            $marzbanUser = $client->getUser($record->remote_username);
                            $normalized  = $client->normalizeUserResponse($marzbanUser);
                            $subLink     = $client->extractSubscriptionLink($marzbanUser);

                            $updates = [
                                'traffic_used_gb'   => $normalized['used_traffic_gb'],
                                'subscription_link' => $subLink ?? $record->subscription_link,
                                'config_link'       => $marzbanUser['links'][0] ?? $record->config_link,
                                'last_synced_at'    => now(),
                            ];

                            if (! empty($normalized['expire'])) {
                                $updates['expires_at'] = \Carbon\Carbon::createFromTimestamp($normalized['expire']);
                            }

                            $record->update($updates);

                            \App\Models\VpnServiceProvisionLog::create([
                                'user_service_id'  => $record->id,
                                'vpn_panel_id'     => $panel->id,
                                'action'           => 'marzban_sync_user',
                                'status'           => 'success',
                                'message'          => "Synced from Marzban. Status: {$normalized['status']}. Used: {$normalized['used_traffic_gb']} GB.",
                                'response_payload' => [
                                    'status'       => $normalized['status'],
                                    'used_traffic' => $normalized['used_traffic_gb'],
                                ],
                            ]);

                            Notification::make()
                                ->title('همگام‌سازی موفق')
                                ->body("حجم مصرف‌شده: {$normalized['used_traffic_gb']} GB")
                                ->success()
                                ->send();

                        } catch (\Throwable $e) {
                            \App\Models\VpnServiceProvisionLog::create([
                                'user_service_id' => $record->id,
                                'vpn_panel_id'    => $panel->id,
                                'action'          => 'marzban_sync_user',
                                'status'          => 'failed',
                                'message'         => $e->getMessage(),
                            ]);

                            Notification::make()
                                ->title('خطا در همگام‌سازی')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),

                Tables\Actions\Action::make('reset_traffic_marzban')
                    ->label('ریست ترافیک')
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->visible(fn (UserService $record) => filled($record->remote_username) && $record->vpnPanel?->type === VpnPanel::TYPE_MARZBAN)
                    ->action(function (UserService $record): void {
                        $panel = $record->vpnPanel;
                        if (! $panel) {
                            Notification::make()->title('پنل VPN مشخص نشده.')->danger()->send();
                            return;
                        }

                        try {
                            $client = new MarzbanClient($panel);
                            $client->resetTraffic($record->remote_username);

                            $record->update(['traffic_used_gb' => 0, 'last_synced_at' => now()]);

                            \App\Models\VpnServiceProvisionLog::create([
                                'user_service_id' => $record->id,
                                'vpn_panel_id'    => $panel->id,
                                'action'          => 'marzban_reset_traffic',
                                'status'          => 'success',
                                'message'         => "Traffic reset for '{$record->remote_username}'.",
                            ]);

                            Notification::make()->title('ترافیک با موفقیت ریست شد.')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('خطا در ریست ترافیک')->body($e->getMessage())->danger()->send();
                        }
                    }),

                Tables\Actions\Action::make('revoke_sub_marzban')
                    ->label('لغو اشتراک')
                    ->icon('heroicon-o-key')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (UserService $record) => filled($record->remote_username) && $record->vpnPanel?->type === VpnPanel::TYPE_MARZBAN)
                    ->action(function (UserService $record): void {
                        $panel = $record->vpnPanel;
                        if (! $panel) {
                            Notification::make()->title('پنل VPN مشخص نشده.')->danger()->send();
                            return;
                        }

                        try {
                            $client      = new MarzbanClient($panel);
                            $marzbanUser = $client->revokeSubscription($record->remote_username);
                            $newSubLink  = $client->extractSubscriptionLink($marzbanUser);

                            $record->update(['subscription_link' => $newSubLink, 'last_synced_at' => now()]);

                            \App\Models\VpnServiceProvisionLog::create([
                                'user_service_id' => $record->id,
                                'vpn_panel_id'    => $panel->id,
                                'action'          => 'marzban_revoke_subscription',
                                'status'          => 'success',
                                'message'         => "Subscription revoked for '{$record->remote_username}'.",
                            ]);

                            Notification::make()->title('اشتراک با موفقیت لغو و لینک جدید ذخیره شد.')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('خطا در لغو اشتراک')->body($e->getMessage())->danger()->send();
                        }
                    }),

                Tables\Actions\Action::make('disable_marzban')
                    ->label('غیرفعال کردن')
                    ->icon('heroicon-o-pause-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (UserService $record) => filled($record->remote_username)
                        && $record->vpnPanel?->type === VpnPanel::TYPE_MARZBAN
                        && $record->status === UserService::STATUS_ACTIVE)
                    ->action(function (UserService $record): void {
                        $panel = $record->vpnPanel;
                        if (! $panel) {
                            Notification::make()->title('پنل VPN مشخص نشده.')->danger()->send();
                            return;
                        }

                        try {
                            $client = new MarzbanClient($panel);
                            $client->updateUser($record->remote_username, ['status' => 'disabled']);

                            $record->update(['status' => UserService::STATUS_DISABLED]);

                            \App\Models\VpnServiceProvisionLog::create([
                                'user_service_id' => $record->id,
                                'vpn_panel_id'    => $panel->id,
                                'action'          => 'marzban_disable_user',
                                'status'          => 'success',
                                'message'         => "User '{$record->remote_username}' disabled on Marzban.",
                            ]);

                            Notification::make()->title('سرویس غیرفعال شد.')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('خطا در غیرفعال کردن')->body($e->getMessage())->danger()->send();
                        }
                    }),

                Tables\Actions\Action::make('enable_marzban')
                    ->label('فعال کردن')
                    ->icon('heroicon-o-play-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (UserService $record) => filled($record->remote_username)
                        && $record->vpnPanel?->type === VpnPanel::TYPE_MARZBAN
                        && $record->status === UserService::STATUS_DISABLED)
                    ->action(function (UserService $record): void {
                        $panel = $record->vpnPanel;
                        if (! $panel) {
                            Notification::make()->title('پنل VPN مشخص نشده.')->danger()->send();
                            return;
                        }

                        try {
                            $client = new MarzbanClient($panel);
                            $client->updateUser($record->remote_username, ['status' => 'active']);

                            $record->update(['status' => UserService::STATUS_ACTIVE]);

                            \App\Models\VpnServiceProvisionLog::create([
                                'user_service_id' => $record->id,
                                'vpn_panel_id'    => $panel->id,
                                'action'          => 'marzban_enable_user',
                                'status'          => 'success',
                                'message'         => "User '{$record->remote_username}' enabled on Marzban.",
                            ]);

                            Notification::make()->title('سرویس فعال شد.')->success()->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('خطا در فعال کردن')->body($e->getMessage())->danger()->send();
                        }
                    }),

                Tables\Actions\EditAction::make()->label('ویرایش'),
            ])
            ->bulkActions([])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListUserServices::route('/'),
            'create' => Pages\CreateUserService::route('/create'),
            'edit'   => Pages\EditUserService::route('/{record}/edit'),
        ];
    }
}
