<?php

namespace App\Services\Addons;

use App\Models\Order;
use App\Models\SiteSetting;
use App\Models\User;
use App\Models\UserService;
use App\Models\VpnServiceProvisionLog;
use App\Services\Marzban\MarzbanClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Handles custom extra-traffic and extra-time purchases for an EXISTING service.
 *
 * This never creates a new UserService and never creates a new Marzban user —
 * it only updates the data_limit / expire of the existing remote user and the
 * matching local UserService record.
 */
class ServiceAddonService
{
    /** Bytes in one gigabyte (GiB), matching ProvisioningService. */
    public const BYTES_PER_GB = 1_073_741_824;

    public function __construct(
        private readonly MarzbanClient $marzban,
    ) {}

    // ── Settings helpers ─────────────────────────────────────────────────────

    public function trafficEnabled(): bool
    {
        return (bool) SiteSetting::get('extra_traffic_enabled', true);
    }

    public function timeEnabled(): bool
    {
        return (bool) SiteSetting::get('extra_time_enabled', true);
    }

    public function pricePerGb(): ?int
    {
        $value = SiteSetting::get('extra_traffic_price_per_gb', null);
        return ($value === null || $value === '') ? null : (int) $value;
    }

    public function pricePerDay(): ?int
    {
        $value = SiteSetting::get('extra_time_price_per_day', null);
        return ($value === null || $value === '') ? null : (int) $value;
    }

    public function minGb(): int
    {
        return (int) SiteSetting::get('extra_traffic_min_gb', 1);
    }

    public function maxGb(): int
    {
        return (int) SiteSetting::get('extra_traffic_max_gb', 100);
    }

    public function minDays(): int
    {
        return (int) SiteSetting::get('extra_time_min_days', 1);
    }

    public function maxDays(): int
    {
        return (int) SiteSetting::get('extra_time_max_days', 30);
    }

    public function applyToExpired(): bool
    {
        return (bool) SiteSetting::get('extra_addon_apply_to_expired_services', true);
    }

    public function calculateExtraTrafficPrice(int $gb): int
    {
        $price = $this->pricePerGb();
        if ($price === null) {
            throw new \InvalidArgumentException('قیمت هر گیگ حجم اضافه تنظیم نشده است.');
        }
        return $gb * $price;
    }

    public function calculateExtraTimePrice(int $days): int
    {
        $price = $this->pricePerDay();
        if ($price === null) {
            throw new \InvalidArgumentException('قیمت هر روز زمان اضافه تنظیم نشده است.');
        }
        return $days * $price;
    }

    // ── Order creation ───────────────────────────────────────────────────────

    public function createExtraTrafficOrder(UserService $service, int $gb, User $user): Order
    {
        if (! $this->trafficEnabled()) {
            throw new \InvalidArgumentException('خرید حجم اضافه در حال حاضر غیرفعال است.');
        }
        if ($service->user_id !== $user->id) {
            throw new \InvalidArgumentException('دسترسی مجاز نیست.');
        }
        if (! $service->traffic_total_gb || $service->traffic_total_gb <= 0) {
            throw new \InvalidArgumentException('این سرویس محدودیت حجم ندارد و نیازی به خرید حجم اضافه نیست.');
        }
        $this->assertServiceEligible($service);

        $pricePerGb = $this->pricePerGb();
        if ($pricePerGb === null) {
            throw new \InvalidArgumentException('قیمت هر گیگ حجم اضافه تنظیم نشده است.');
        }
        if ($gb < $this->minGb()) {
            throw new \InvalidArgumentException('حداقل حجم قابل خرید ' . $this->minGb() . ' گیگابایت است.');
        }
        if ($gb > $this->maxGb()) {
            throw new \InvalidArgumentException('حداکثر حجم قابل خرید ' . $this->maxGb() . ' گیگابایت است.');
        }

        $amount          = $gb * $pricePerGb;
        $originalLimit   = (int) ($service->traffic_total_gb * self::BYTES_PER_GB);
        $newLimit        = $originalLimit + ($gb * self::BYTES_PER_GB);

        return DB::transaction(function () use ($service, $gb, $pricePerGb, $amount, $originalLimit, $newLimit) {
            return Order::create([
                'order_type'          => Order::TYPE_EXTRA_TRAFFIC,
                'user_id'             => $service->user_id,
                'user_service_id'     => $service->id,
                'plan_name'           => $service->plan_name,
                'extra_traffic_gb'    => $gb,
                'unit_price'          => $pricePerGb,
                'original_data_limit' => $originalLimit,
                'new_data_limit'      => $newLimit,
                'price_toman'         => $amount,
                'final_price_toman'   => $amount,
                'discount_toman'      => 0,
                'status'              => Order::STATUS_AWAITING_PAYMENT,
                'payment_status'      => Order::PAYMENT_UNPAID,
                'notes'               => "خرید {$gb} گیگابایت حجم اضافه برای سرویس {$service->service_number}",
            ]);
        });
    }

    public function createExtraTimeOrder(UserService $service, int $days, User $user): Order
    {
        if (! $this->timeEnabled()) {
            throw new \InvalidArgumentException('خرید زمان اضافه در حال حاضر غیرفعال است.');
        }
        if ($service->user_id !== $user->id) {
            throw new \InvalidArgumentException('دسترسی مجاز نیست.');
        }
        if ($service->expires_at === null) {
            throw new \InvalidArgumentException('این سرویس تاریخ انقضا ندارد و نیازی به خرید زمان اضافه نیست.');
        }
        $this->assertServiceEligible($service);

        $pricePerDay = $this->pricePerDay();
        if ($pricePerDay === null) {
            throw new \InvalidArgumentException('قیمت هر روز زمان اضافه تنظیم نشده است.');
        }
        if ($days < $this->minDays()) {
            throw new \InvalidArgumentException('حداقل زمان قابل خرید ' . $this->minDays() . ' روز است.');
        }
        if ($days > $this->maxDays()) {
            throw new \InvalidArgumentException('حداکثر زمان قابل خرید ' . $this->maxDays() . ' روز است.');
        }

        $amount    = $days * $pricePerDay;
        $newExpiry = $this->calculateNewExpiry($service, $days);

        return DB::transaction(function () use ($service, $days, $pricePerDay, $amount, $newExpiry) {
            return Order::create([
                'order_type'         => Order::TYPE_EXTRA_TIME,
                'user_id'            => $service->user_id,
                'user_service_id'    => $service->id,
                'plan_name'          => $service->plan_name,
                'extra_time_days'    => $days,
                'unit_price'         => $pricePerDay,
                'original_expire_at' => $service->expires_at,
                'new_expire_at'      => $newExpiry,
                'price_toman'        => $amount,
                'final_price_toman'  => $amount,
                'discount_toman'     => 0,
                'status'             => Order::STATUS_AWAITING_PAYMENT,
                'payment_status'     => Order::PAYMENT_UNPAID,
                'notes'              => "خرید {$days} روز زمان اضافه برای سرویس {$service->service_number}",
            ]);
        });
    }

    /**
     * New expiry: extend from the current expiry if it's still in the future,
     * otherwise extend from now (for expired services, when allowed).
     */
    public function calculateNewExpiry(UserService $service, int $days): Carbon
    {
        $base = ($service->expires_at && $service->expires_at->isFuture())
            ? $service->expires_at->copy()
            : now();

        return $base->addDays($days);
    }

    // ── Apply (post-payment) ─────────────────────────────────────────────────

    /**
     * Apply a paid extra-traffic order to the existing service + Marzban user.
     * Idempotent via addon_applied_at.
     */
    public function applyExtraTraffic(Order $order): UserService
    {
        if ($order->addon_applied_at !== null) {
            return $order->userService;
        }

        $service = $order->userService;
        if (! $service) {
            Log::error('ServiceAddonService: userService missing for extra-traffic order', ['order_id' => $order->id]);
            $order->update([
                'status'                    => Order::STATUS_ADDON_FAILED,
                'addon_apply_failed_reason' => 'سرویس مرتبط یافت نشد.',
            ]);
            throw new \RuntimeException('سرویس مرتبط یافت نشد.');
        }

        $gb            = (int) $order->extra_traffic_gb;
        $currentTotal  = (int) ($service->traffic_total_gb ?? 0);
        $newTotalGb    = $currentTotal + $gb;
        $newLimitBytes = $order->new_data_limit ?? ($newTotalGb * self::BYTES_PER_GB);

        // Update Marzban first — data_limit only, preserving expire / used / links.
        if ($service->remote_username) {
            try {
                $client = $service->vpnPanel ? new MarzbanClient($service->vpnPanel) : $this->marzban;
                $client->updateUser($service->remote_username, [
                    'data_limit' => (int) $newLimitBytes,
                ]);
            } catch (\Throwable $e) {
                return $this->markApplyFailed($order, $service, 'marzban_extra_traffic', $e);
            }
        }

        DB::transaction(function () use ($order, $service, $newTotalGb) {
            $service->update([
                'traffic_total_gb' => $newTotalGb,
                'last_synced_at'   => now(),
                'sync_status'      => UserService::SYNC_SYNCED,
            ]);
            $order->update([
                'status'           => Order::STATUS_COMPLETED,
                'completed_at'     => now(),
                'addon_applied_at' => now(),
            ]);
        });

        VpnServiceProvisionLog::create([
            'user_service_id' => $service->id,
            'vpn_panel_id'    => $service->vpn_panel_id,
            'action'          => 'addon_extra_traffic',
            'status'          => 'success',
            'message'         => "+{$gb} GB extra traffic applied (order {$order->order_number}). New total: {$newTotalGb} GB.",
        ]);

        if ($order->user) {
            app(\App\Services\Notifications\NotificationService::class)->notify(
                \App\Models\Notification::TYPE_EXTRA_TRAFFIC_SUCCESS,
                $order->user,
                [
                    'user_name'    => $order->user->name ?? $order->user->username,
                    'service_name' => $service->plan_name ?? $service->service_number,
                    'order_id'     => $order->order_number,
                    'traffic_gb'   => $gb,
                ],
                'extra_traffic_success:order:' . $order->id,
            );
        }

        return $service->fresh();
    }

    /**
     * Apply a paid extra-time order to the existing service + Marzban user.
     * Idempotent via addon_applied_at.
     */
    public function applyExtraTime(Order $order): UserService
    {
        if ($order->addon_applied_at !== null) {
            return $order->userService;
        }

        $service = $order->userService;
        if (! $service) {
            Log::error('ServiceAddonService: userService missing for extra-time order', ['order_id' => $order->id]);
            $order->update([
                'status'                    => Order::STATUS_ADDON_FAILED,
                'addon_apply_failed_reason' => 'سرویس مرتبط یافت نشد.',
            ]);
            throw new \RuntimeException('سرویس مرتبط یافت نشد.');
        }

        $days      = (int) $order->extra_time_days;
        $newExpiry = $this->calculateNewExpiry($service, $days);

        if ($service->remote_username) {
            try {
                $client = $service->vpnPanel ? new MarzbanClient($service->vpnPanel) : $this->marzban;
                $client->updateUser($service->remote_username, [
                    'expire' => $newExpiry->timestamp,
                ]);
            } catch (\Throwable $e) {
                return $this->markApplyFailed($order, $service, 'marzban_extra_time', $e);
            }
        }

        DB::transaction(function () use ($order, $service, $newExpiry) {
            $service->update([
                'expires_at'     => $newExpiry,
                'status'         => UserService::STATUS_ACTIVE,
                'last_synced_at' => now(),
                'sync_status'    => UserService::SYNC_SYNCED,
            ]);
            $order->update([
                'status'           => Order::STATUS_COMPLETED,
                'completed_at'     => now(),
                'new_expire_at'    => $newExpiry,
                'addon_applied_at' => now(),
            ]);
        });

        VpnServiceProvisionLog::create([
            'user_service_id' => $service->id,
            'vpn_panel_id'    => $service->vpn_panel_id,
            'action'          => 'addon_extra_time',
            'status'          => 'success',
            'message'         => "+{$days} days extra time applied (order {$order->order_number}). New expiry: {$newExpiry->toDateTimeString()}.",
        ]);

        if ($order->user) {
            app(\App\Services\Notifications\NotificationService::class)->notify(
                \App\Models\Notification::TYPE_EXTRA_TIME_SUCCESS,
                $order->user,
                [
                    'user_name'    => $order->user->name ?? $order->user->username,
                    'service_name' => $service->plan_name ?? $service->service_number,
                    'order_id'     => $order->order_number,
                    'days'         => $days,
                    'expiry_date'  => $newExpiry->format('Y/m/d'),
                ],
                'extra_time_success:order:' . $order->id,
            );
        }

        return $service->fresh();
    }

    // ── Internal ─────────────────────────────────────────────────────────────

    private function assertServiceEligible(UserService $service): void
    {
        $isExpired = $service->isExpired();

        if ($isExpired && ! $this->applyToExpired()) {
            throw new \InvalidArgumentException('برای سرویس منقضی‌شده امکان خرید وجود ندارد.');
        }

        $allowed = [UserService::STATUS_ACTIVE, UserService::STATUS_EXPIRED, UserService::STATUS_DISABLED];
        if (! in_array($service->status, $allowed, true) && ! $isExpired) {
            throw new \InvalidArgumentException('این سرویس در وضعیت مناسبی برای خرید نیست.');
        }
    }

    /**
     * Marzban update failed: the payment stays paid, the order is marked failed
     * so an admin can retry. Used traffic, expire and links are untouched.
     */
    private function markApplyFailed(Order $order, UserService $service, string $action, \Throwable $e): UserService
    {
        Log::error('ServiceAddonService: Marzban update failed', [
            'order_id'        => $order->id,
            'service_id'      => $service->id,
            'remote_username' => $service->remote_username,
            'error'           => $e->getMessage(),
        ]);

        $order->update([
            'status'                    => Order::STATUS_ADDON_FAILED,
            'addon_apply_failed_reason' => $e->getMessage(),
        ]);

        VpnServiceProvisionLog::create([
            'user_service_id' => $service->id,
            'vpn_panel_id'    => $service->vpn_panel_id,
            'action'          => $action,
            'status'          => 'failed',
            'message'         => $e->getMessage(),
        ]);

        // System/admin warning — payment is confirmed but the Marzban add-on
        // update failed; admin can retry. Idempotent per order.
        app(\App\Services\Notifications\NotificationService::class)->notifyAdmins(
            \App\Models\Notification::TYPE_MARZBAN_UPDATE_FAILED,
            [
                'user_name'  => $order->user?->name ?? $order->user?->username ?? '—',
                'order_id'   => $order->order_number,
                'service_id' => $service->id,
                'error'      => $e->getMessage(),
            ],
            'marzban_update_failed:' . $action . ':' . $order->id,
        );

        return $service->fresh();
    }
}
