<?php

namespace App\Services\Referrals;

use App\Models\Commission;
use App\Models\Notification;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Notifications\NotificationService;
use App\Services\WalletService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Creates and credits representative commissions for paid real purchases.
 *
 * Idempotent: a single commission per order (unique order_id), so duplicate
 * payment callbacks never create or credit a commission twice. Wallet top-ups
 * never generate commission.
 */
class CommissionService
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Record (and credit) commission for a paid order, if eligible. Safe to
     * call repeatedly — only the first call creates the commission.
     */
    public function recordForOrder(Order $order): ?Commission
    {
        if ($order->payment_status !== Order::PAYMENT_PAID) {
            return null;
        }

        if (! in_array($order->order_type, ReferralSettings::commissionableTypes(), true)) {
            return null; // wallet_topup / unknown → never commissionable
        }

        if (! ReferralSettings::commissionEnabledForType($order->order_type)) {
            return null;
        }

        $buyer = $order->user;
        if (! $buyer || ! $buyer->referred_by_user_id) {
            return null;
        }

        $referrer = $buyer->referrer;
        if (! $referrer) {
            return null;
        }

        // In representatives_only mode, the referrer must be an approved/active rep.
        if (ReferralSettings::isRepresentativesOnly() && ! $referrer->isApprovedRepresentative()) {
            return null;
        }

        // Idempotency: one commission per order.
        $existing = Commission::where('order_id', $order->id)->first();
        if ($existing) {
            return $existing;
        }

        [$type, $value]    = $this->resolveRate($referrer);
        $original          = (int) $order->price_toman;
        $final             = (int) $order->final_price_toman;
        $base              = ReferralSettings::commissionAfterDiscount() ? $final : $original;
        $amount            = $this->calculate($type, $value, $base);

        if ($amount <= 0) {
            return null;
        }

        try {
            $commission = Commission::create([
                'representative_user_id' => $referrer->id,
                'referred_user_id'       => $buyer->id,
                'order_id'               => $order->id,
                'order_type'             => $order->order_type,
                'original_amount'        => $original,
                'final_amount'           => $final,
                'commission_type'        => $type,
                'commission_value'       => $value,
                'commission_amount'      => $amount,
                'status'                 => Commission::STATUS_PENDING,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Unique order_id collision under a race → fetch the winner.
            return Commission::where('order_id', $order->id)->first();
        }

        $this->credit($commission);

        return $commission->fresh();
    }

    /**
     * Credit a pending commission to the representative's wallet (idempotent).
     */
    public function credit(Commission $commission): void
    {
        if ($commission->status !== Commission::STATUS_PENDING) {
            return;
        }

        $referrer = $commission->representative;
        if (! $referrer) {
            return;
        }

        // Secondary guard: never double-credit the same commission/order.
        $already = WalletTransaction::where('order_id', $commission->order_id)
            ->where('type', WalletTransaction::TYPE_REPRESENTATIVE_COMMISSION)
            ->exists();

        if ($already) {
            $commission->update(['status' => Commission::STATUS_CREDITED, 'credited_at' => now()]);
            return;
        }

        try {
            DB::transaction(function () use ($commission, $referrer) {
                $this->wallet->credit(
                    $referrer,
                    $commission->commission_amount,
                    WalletTransaction::TYPE_REPRESENTATIVE_COMMISSION,
                    [
                        'order_id'    => $commission->order_id,
                        'description' => 'پورسانت فروش نماینده',
                    ],
                );

                $referrer->increment('total_commission_earned', $commission->commission_amount);
                $referrer->increment('commission_balance', $commission->commission_amount);

                $commission->update([
                    'status'      => Commission::STATUS_CREDITED,
                    'credited_at' => now(),
                ]);
            });
        } catch (\Throwable $e) {
            Log::error('CommissionService: wallet credit failed', [
                'commission_id' => $commission->id,
                'error'         => $e->getMessage(),
            ]);

            $this->notifications->notifyAdmins(
                Notification::TYPE_ADMIN_WARNING,
                ['message' => "واریز پورسانت #{$commission->id} به کیف پول نماینده ناموفق بود. نیاز به تلاش مجدد."],
                'commission_credit_failed:' . $commission->id,
            );
            return;
        }

        // Notify the representative that commission landed in their wallet.
        $this->notifications->notify(
            Notification::TYPE_COMMISSION_CREDITED,
            $referrer,
            [
                'user_name' => $referrer->name ?? $referrer->username,
                'amount'    => number_format($commission->commission_amount),
            ],
            'commission_credited:' . $commission->id,
        );
    }

    public function cancel(Commission $commission, ?string $note = null): void
    {
        $commission->update([
            'status'       => Commission::STATUS_CANCELLED,
            'cancelled_at' => now(),
            'admin_note'   => $note ?? $commission->admin_note,
        ]);
    }

    /**
     * @return array{0:string,1:int} [type, value]
     */
    private function resolveRate(User $referrer): array
    {
        $type = $referrer->commission_type ?: ReferralSettings::defaultCommissionType();

        $value = $type === Commission::TYPE_FIXED
            ? (int) ($referrer->commission_fixed_amount ?? ReferralSettings::defaultCommissionValue())
            : (int) ($referrer->commission_rate ?? ReferralSettings::defaultCommissionValue());

        return [$type, $value];
    }

    private function calculate(string $type, int $value, int $base): int
    {
        return $type === Commission::TYPE_FIXED
            ? $value
            : (int) round($base * $value / 100);
    }
}
