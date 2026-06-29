<?php

namespace App\Services\Referrals;

use App\Models\User;

/**
 * Resolves referral codes into a valid referrer, honoring the referral mode.
 */
class ReferralService
{
    /**
     * Resolve a referral code to an eligible referrer, or null.
     *
     * In representatives_only mode, only approved/active representatives are
     * accepted. Invalid/inactive codes simply return null (registration then
     * proceeds normally without a referrer).
     */
    public function resolveReferrer(?string $code): ?User
    {
        if (blank($code)) {
            return null;
        }

        $referrer = User::where('referral_code', strtoupper(trim($code)))->first();
        if (! $referrer) {
            return null;
        }

        if (ReferralSettings::isRepresentativesOnly() && ! $referrer->isApprovedRepresentative()) {
            return null;
        }

        return $referrer;
    }

    /**
     * Attach a referrer to a freshly created user if eligible.
     *
     * - never overwrites an existing referred_by_user_id
     * - never lets a user refer themselves
     */
    public function attachReferrer(User $newUser, ?string $code): void
    {
        if ($newUser->referred_by_user_id !== null) {
            return;
        }

        $referrer = $this->resolveReferrer($code);
        if (! $referrer || $referrer->id === $newUser->id) {
            return;
        }

        $newUser->update(['referred_by_user_id' => $referrer->id]);
    }
}
