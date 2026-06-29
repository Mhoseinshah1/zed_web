<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserServiceResource\Pages;
use App\Jobs\ProvisionMarzbanServiceJob;
use App\Models\RenewalPackage;
use App\Models\UserService;
use App\Models\VpnPanel;
use App\Models\VpnServiceProvisionLog;
use App\Services\Marzban\MarzbanClient;
use App\Services\Renewals\RenewalService;
use App\Services\ServiceProvisioner;
use Carbon\Carbon;
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

                Tables\Columns\TextColumn::make('remote_username')
                    ->label('نام کاربری Marzban')
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('subscription_link')
                    ->label('لینک اشتراک')
                    ->placeholder('—')
                    ->limit(40)
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('config_link')
                    ->label('لینک کانفیگ')
                    ->placeholder('—')
                    ->limit(40)
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),

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
                    ->modalHeading('ریست مصرف ترافیک')
                    ->modalDescription('آیا از ریست کردن مصرف ترافیک این سرویس مطمئن هستید؟')
                    ->modalSubmitActionLabel('بله، ریست کن')
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
                    ->label('تغییر لینک اشتراک')
                    ->icon('heroicon-o-key')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('تغییر لینک اشتراک')
                    ->modalDescription('با این کار لینک اشتراک قبلی کاربر باطل می‌شود و لینک جدید ساخته می‌شود. ادامه می‌دهید؟')
                    ->modalSubmitActionLabel('بله، لینک را تغییر بده')
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

                Tables\Actions\Action::make('delete_marzban')
                    ->label('حذف از مرزبان')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('حذف کاربر از مرزبان')
                    ->modalDescription('این کار کاربر را از پنل مرزبان حذف می‌کند اما سفارش و سرویس در سایت باقی می‌ماند. ادامه می‌دهید؟')
                    ->modalSubmitActionLabel('بله، از مرزبان حذف کن')
                    ->visible(fn (UserService $record) => filled($record->remote_username) && $record->vpnPanel?->type === VpnPanel::TYPE_MARZBAN)
                    ->action(function (UserService $record): void {
                        $panel = $record->vpnPanel;
                        if (! $panel) {
                            Notification::make()->title('پنل VPN مشخص نشده.')->danger()->send();
                            return;
                        }

                        try {
                            $client = new MarzbanClient($panel);
                            $client->deleteUser($record->remote_username);

                            $record->update([
                                'status'            => UserService::STATUS_CANCELLED,
                                'provision_status'  => UserService::PROVISION_FAILED,
                                'subscription_link' => null,
                                'config_link'       => null,
                                'last_synced_at'    => now(),
                                'admin_notes'       => trim(($record->admin_notes ?? '') . "\nRemote Marzban user deleted on " . now()->toDateTimeString()),
                            ]);

                            VpnServiceProvisionLog::create([
                                'user_service_id' => $record->id,
                                'vpn_panel_id'    => $panel->id,
                                'action'          => 'marzban_delete_user',
                                'status'          => 'success',
                                'message'         => "Remote Marzban user '{$record->remote_username}' deleted from panel '{$panel->name}'.",
                            ]);

                            Notification::make()->title('کاربر از مرزبان حذف شد.')->success()->send();
                        } catch (\Throwable $e) {
                            VpnServiceProvisionLog::create([
                                'user_service_id' => $record->id,
                                'vpn_panel_id'    => $panel->id,
                                'action'          => 'marzban_delete_user',
                                'status'          => 'failed',
                                'message'         => $e->getMessage(),
                            ]);
                            Notification::make()->title('خطا در حذف از مرزبان')->body($e->getMessage())->danger()->send();
                        }
                    }),

                Tables\Actions\Action::make('recreate_marzban')
                    ->label('ساخت دوباره در مرزبان')
                    ->icon('heroicon-o-plus-circle')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('ساخت دوباره کاربر در مرزبان')
                    ->modalDescription('اگر کاربر در مرزبان وجود داشته باشد، همگام‌سازی انجام می‌شود. در غیر این صورت، کاربر جدید ساخته می‌شود.')
                    ->modalSubmitActionLabel('اجرا کن')
                    ->visible(fn (UserService $record) => in_array($record->provision_status, [
                        UserService::PROVISION_FAILED,
                        UserService::PROVISION_MANUAL_REQUIRED,
                        UserService::PROVISION_SKIPPED,
                    ]) || (filled($record->remote_username) && $record->provision_status === UserService::PROVISION_FAILED))
                    ->action(function (UserService $record): void {
                        $panel = $record->vpnPanel
                            ?? VpnPanel::where('type', VpnPanel::TYPE_MARZBAN)
                                ->where('is_active', true)
                                ->where('is_default', true)
                                ->first();

                        if (! $panel) {
                            Notification::make()->title('هیچ پنل Marzban پیش‌فرض فعالی یافت نشد.')->danger()->send();
                            return;
                        }

                        try {
                            Bus::dispatchSync(new ProvisionMarzbanServiceJob($record->id, $panel->id));

                            VpnServiceProvisionLog::create([
                                'user_service_id' => $record->id,
                                'vpn_panel_id'    => $panel->id,
                                'action'          => 'marzban_recreate_user',
                                'status'          => 'success',
                                'message'         => "Service recreated on panel '{$panel->name}'.",
                            ]);

                            Notification::make()->title('سرویس با موفقیت در مرزبان ساخته شد.')->success()->send();
                        } catch (\Throwable $e) {
                            VpnServiceProvisionLog::create([
                                'user_service_id' => $record->id,
                                'vpn_panel_id'    => $panel->id,
                                'action'          => 'marzban_recreate_user',
                                'status'          => 'failed',
                                'message'         => $e->getMessage(),
                            ]);
                            Notification::make()->title('خطا در ساخت سرویس')->body($e->getMessage())->danger()->send();
                        }
                    }),

                Tables\Actions\Action::make('clear_local_links')
                    ->label('پاک کردن لینک‌های محلی')
                    ->icon('heroicon-o-x-mark')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('پاک کردن لینک‌های محلی')
                    ->modalDescription('لینک اشتراک و لینک کانفیگ از پایگاه داده محلی پاک می‌شوند. این عمل روی مرزبان تأثیری ندارد.')
                    ->modalSubmitActionLabel('پاک کن')
                    ->visible(fn (UserService $record) => filled($record->subscription_link) || filled($record->config_link))
                    ->action(function (UserService $record): void {
                        $record->update([
                            'subscription_link' => null,
                            'config_link'       => null,
                        ]);

                        VpnServiceProvisionLog::create([
                            'user_service_id' => $record->id,
                            'vpn_panel_id'    => $record->vpn_panel_id,
                            'action'          => 'clear_local_links',
                            'status'          => 'success',
                            'message'         => 'Local subscription_link and config_link cleared by admin.',
                        ]);

                        Notification::make()->title('لینک‌های محلی پاک شدند.')->success()->send();
                    }),

                Tables\Actions\Action::make('show_subscription_qr')
                    ->label('مشاهده بارکد لینک اشتراک')
                    ->icon('heroicon-o-qr-code')
                    ->color('gray')
                    ->modalHeading('بارکد لینک اشتراک')
                    ->modalContent(fn (UserService $record) => view('filament.user-services.subscription-qr', ['service' => $record]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('بستن')
                    ->visible(fn (UserService $record) => filled($record->subscription_link)),

                Tables\Actions\Action::make('manual_renew')
                    ->label('تمدید دستی')
                    ->icon('heroicon-o-calendar-days')
                    ->color('success')
                    ->visible(fn (UserService $record) => $record->expires_at !== null
                        && in_array($record->status, [UserService::STATUS_ACTIVE, UserService::STATUS_EXPIRED, UserService::STATUS_DISABLED]))
                    ->form([
                        Forms\Components\Select::make('renewal_package_id')
                            ->label('پکیج تمدید')
                            ->options(fn () => RenewalPackage::where('is_active', true)
                                ->orderBy('sort_order')
                                ->orderBy('duration_days')
                                ->pluck('name', 'id'))
                            ->required()
                            ->helperText('انتخاب پکیج برای محاسبه مدت تمدید'),
                    ])
                    ->action(function (UserService $record, array $data): void {
                        $package = RenewalPackage::find($data['renewal_package_id']);
                        if (! $package) {
                            Notification::make()->title('پکیج تمدید یافت نشد.')->danger()->send();
                            return;
                        }

                        try {
                            $renewalService = app(RenewalService::class);
                            $newExpiry = $renewalService->calculateNewExpiry($record, $package->duration_days);

                            if (filled($record->remote_username) && $record->vpnPanel) {
                                $client = new MarzbanClient($record->vpnPanel);
                                $client->updateUser($record->remote_username, ['expire' => $newExpiry->timestamp]);
                            }

                            $record->update([
                                'expires_at' => $newExpiry,
                                'status'     => UserService::STATUS_ACTIVE,
                            ]);

                            VpnServiceProvisionLog::create([
                                'user_service_id' => $record->id,
                                'vpn_panel_id'    => $record->vpn_panel_id,
                                'action'          => 'admin_manual_renewal',
                                'status'          => 'success',
                                'message'         => "Manual renewal: +{$package->duration_days} days. New expiry: {$newExpiry->toDateTimeString()}",
                            ]);

                            Notification::make()
                                ->title('سرویس با موفقیت تمدید شد.')
                                ->body("تاریخ انقضای جدید: {$newExpiry->format('Y/m/d H:i')}")
                                ->success()
                                ->send();
                        } catch (\Throwable $e) {
                            Notification::make()->title('خطا در تمدید دستی')->body($e->getMessage())->danger()->send();
                        }
                    })
                    ->requiresConfirmation()
                    ->modalHeading('تمدید دستی سرویس')
                    ->modalSubmitActionLabel('تمدید کن'),

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
