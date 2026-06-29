<?php

namespace App\Services\Referrals;

use App\Models\Order;
use App\Models\SiteSetting;

/**
 * Typed accessor over the referral/representative/commission site settings.
 * All values live in the database; safe defaults are returned when unset.
 */
class ReferralSettings
{
    const MODE_ALL_USERS         = 'all_users';
    const MODE_REPRESENTATIVES   = 'representatives_only';

    public static function mode(): string
    {
        $mode = (string) SiteSetting::get('referral_mode', self::MODE_ALL_USERS);
        return in_array($mode, [self::MODE_ALL_USERS, self::MODE_REPRESENTATIVES], true)
            ? $mode
            : self::MODE_ALL_USERS;
    }

    public static function isRepresentativesOnly(): bool
    {
        return self::mode() === self::MODE_REPRESENTATIVES;
    }

    public static function representativeSystemEnabled(): bool
    {
        return (bool) SiteSetting::get('representative_system_enabled', false);
    }

    public static function autoApproveRepresentatives(): bool
    {
        return (bool) SiteSetting::get('auto_approve_representatives', false);
    }

    public static function defaultCommissionType(): string
    {
        $type = (string) SiteSetting::get('default_commission_type', 'percent');
        return in_array($type, ['percent', 'fixed'], true) ? $type : 'percent';
    }

    public static function defaultCommissionValue(): int
    {
        return (int) SiteSetting::get('default_commission_value', 0);
    }

    public static function commissionAfterDiscount(): bool
    {
        return (bool) SiteSetting::get('commission_after_discount', true);
    }

    public static function referralCookieDays(): int
    {
        return max(1, (int) SiteSetting::get('referral_cookie_days', 30));
    }

    /** Whether commission is enabled for the given order type. */
    public static function commissionEnabledForType(string $orderType): bool
    {
        return match ($orderType) {
            Order::TYPE_NEW_SERVICE   => (bool) SiteSetting::get('commission_on_new_service', true),
            Order::TYPE_RENEWAL       => (bool) SiteSetting::get('commission_on_renewal', true),
            Order::TYPE_EXTRA_TRAFFIC => (bool) SiteSetting::get('commission_on_extra_traffic', true),
            Order::TYPE_EXTRA_TIME    => (bool) SiteSetting::get('commission_on_extra_time', true),
            default                   => false, // wallet_topup and anything else
        };
    }

    /** Commissionable order types (wallet_topup excluded by design). */
    public static function commissionableTypes(): array
    {
        return [
            Order::TYPE_NEW_SERVICE,
            Order::TYPE_RENEWAL,
            Order::TYPE_EXTRA_TRAFFIC,
            Order::TYPE_EXTRA_TIME,
        ];
    }
}
