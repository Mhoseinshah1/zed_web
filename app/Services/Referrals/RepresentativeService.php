<?php

namespace App\Services\Referrals;

use App\Models\Notification;
use App\Models\RepresentativeRequest;
use App\Models\User;
use App\Services\Notifications\NotificationService;

/**
 * Representative request lifecycle (request / approve / reject / disable).
 */
class RepresentativeService
{
    public function __construct(
        private readonly NotificationService $notifications,
    ) {}

    /**
     * Submit (or auto-approve) a representative request for a user.
     */
    public function request(User $user, ?string $message = null, ?string $contactInfo = null): RepresentativeRequest
    {
        $autoApprove = ReferralSettings::autoApproveRepresentatives();

        $request = RepresentativeRequest::create([
            'user_id'      => $user->id,
            'message'      => $message,
            'contact_info' => $contactInfo,
            'status'       => $autoApprove ? RepresentativeRequest::STATUS_APPROVED : RepresentativeRequest::STATUS_PENDING,
            'reviewed_at'  => $autoApprove ? now() : null,
        ]);

        if ($autoApprove) {
            $this->markUserApproved($user);
        } else {
            $user->update(['representative_status' => User::REP_PENDING]);

            $this->notifications->notifyAdmins(
                Notification::TYPE_REPRESENTATIVE_REQUEST,
                [
                    'user_name' => $user->name ?? $user->username,
                    'account_id'=> $user->account_id,
                ],
                'representative_request:' . $request->id,
            );
        }

        return $request;
    }

    public function approve(User $user, ?string $note = null): void
    {
        $this->markUserApproved($user, $note);
        $this->updateLatestRequest($user, RepresentativeRequest::STATUS_APPROVED, $note);
    }

    public function reject(User $user, ?string $note = null): void
    {
        $user->update([
            'is_representative'      => false,
            'representative_status'  => User::REP_REJECTED,
            'representative_note'    => $note ?? $user->representative_note,
        ]);
        $this->updateLatestRequest($user, RepresentativeRequest::STATUS_REJECTED, $note);
    }

    public function disable(User $user, ?string $note = null): void
    {
        $user->update([
            'is_representative'     => false,
            'representative_status' => User::REP_DISABLED,
            'representative_note'   => $note ?? $user->representative_note,
        ]);
        $this->updateLatestRequest($user, RepresentativeRequest::STATUS_DISABLED, $note);
    }

    public function enable(User $user, ?string $note = null): void
    {
        $this->markUserApproved($user, $note);
        $this->updateLatestRequest($user, RepresentativeRequest::STATUS_APPROVED, $note);
    }

    private function markUserApproved(User $user, ?string $note = null): void
    {
        $user->update([
            'is_representative'          => true,
            'representative_status'      => User::REP_APPROVED,
            'representative_approved_at' => $user->representative_approved_at ?? now(),
            'representative_note'        => $note ?? $user->representative_note,
        ]);
    }

    private function updateLatestRequest(User $user, string $status, ?string $note): void
    {
        $request = $user->representativeRequests()->latest()->first();
        $request?->update([
            'status'      => $status,
            'admin_note'  => $note ?? $request->admin_note,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);
    }
}
