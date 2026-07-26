<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single email-OTP issuance. Auditable WITHOUT exposing the code: only the
 * Hash is stored; the plaintext never touches the database or the logs.
 */
class EmailVerificationCode extends Model
{
    protected $fillable = [
        'user_id',
        'email',
        'code_hash',
        'expires_at',
        'used_at',
        'attempts',
        'send_status',
        'send_error',
        'delivery_claimed_at',
        'delivery_finalized_at',
        'delivery_config_fingerprint',
        'ip_address',
        'user_agent',
    ];

    /** The delivery claim token never leaves the database via serialization. */
    protected $hidden = [
        'code_hash',
        'delivery_claim_token',
    ];

    public const SEND_STATUS_PENDING = 'pending';

    public const SEND_STATUS_QUEUED = 'queued';

    /** A worker has atomically claimed the record and is talking to the transport. */
    public const SEND_STATUS_SENDING = 'sending';

    public const SEND_STATUS_SENT = 'sent';

    /**
     * The transport ACCEPTED the message but the worker lost its cache-lock
     * ownership before finalization could safely record `sent`. The code was
     * (very likely) delivered and remains usable, but the record is no longer
     * claimable — a retry must never re-send it.
     */
    public const SEND_STATUS_ACCEPTED_PENDING = 'accepted_pending';

    /** Delivery FAILED after real transport attempts (job retries exhausted). */
    public const SEND_STATUS_FAILED = 'failed';

    /**
     * NO transport attempt ever happened: queue publication failed, or lock/
     * row contention exhausted every retry before a delivery claim was made.
     * Excluded from the daily cap and the resend cooldown.
     */
    public const SEND_STATUS_DISPATCH_FAILED = 'dispatch_failed';

    public const SEND_STATUS_SKIPPED = 'skipped';

    /**
     * The POSITIVE list of states in which the user may realistically still
     * receive/use the code. Terminal or dead-end states (failed,
     * dispatch_failed, skipped, pending-legacy) are NEVER actionable — and a
     * future unknown status is not silently treated as actionable either.
     */
    public const ACTIONABLE_STATUSES = [
        self::SEND_STATUS_QUEUED,
        self::SEND_STATUS_SENDING,
        self::SEND_STATUS_SENT,
        self::SEND_STATUS_ACCEPTED_PENDING,
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
        'delivery_claimed_at' => 'datetime',
        'delivery_finalized_at' => 'datetime',
        // Queue-publication / transport-attempt evidence. Deliberately NOT
        // fillable: both are stamped once through explicit trusted updates
        // (requestCode metadata phase; the job's pre-send stamp), are
        // immutable after first set, and must never be mass-assignable.
        'queue_published_at' => 'datetime',
        'transport_attempted_at' => 'datetime',
        'attempts' => 'integer',
    ];

    /**
     * The ONE central definition of an ACTIONABLE code for a user: their
     * current address, unused, in an explicitly-permitted status, and (by
     * default) unexpired. Every "latest active code" lookup — notice-page
     * lifetime, resend cooldown, verification — goes through this scope.
     */
    public function scopeActionableFor($query, User $user, bool $unexpiredOnly = true)
    {
        return $query
            ->where('user_id', $user->id)
            ->where('email', $user->email)
            ->whereNull('used_at')
            ->whereIn('send_status', self::ACTIONABLE_STATUSES)
            ->when($unexpiredOnly, fn ($q) => $q->where('expires_at', '>', now()));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }
}
