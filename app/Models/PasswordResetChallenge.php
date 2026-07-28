<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single password-reset OTP challenge. PURPOSE-SCOPED: rows in this table
 * authorize password resets ONLY — they can never verify an email address or
 * phone number, and contact-verification codes can never appear here.
 *
 * Only the bcrypt hash of the code is stored; the plaintext OTP never
 * touches the database or the logs. The delivery claim token is never
 * serialized either.
 *
 * ACTIVE-CHALLENGE DEFINITION (unchanged by the claim states): a challenge
 * is ACTIVE while `consumed_at IS NULL` — exactly what the
 * `password_reset_one_active` partial unique index enforces. A `sending`
 * (claimed) challenge is still active and still visible to that invariant. No destination address/number is
 * persisted — delivery happens at issuance time and only the safe channel
 * name plus an honest delivery state remain.
 */
class PasswordResetChallenge extends Model
{
    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_SMS = 'sms';

    /** Publication to the queue failed — no transport attempt ever happened. */
    public const SEND_STATUS_PENDING = 'pending';

    public const SEND_STATUS_QUEUED = 'queued';

    /** A worker holds a token-owned claim and is talking to the transport. */
    public const SEND_STATUS_SENDING = 'sending';

    public const SEND_STATUS_SENT = 'sent';

    public const SEND_STATUS_FAILED = 'failed';

    public const SEND_STATUS_DISPATCH_FAILED = 'dispatch_failed';

    protected $fillable = [
        'user_id',
        'channel',
        'token',
        'code_hash',
        'expires_at',
        'attempts',
        'send_status',
        'authorized_at',
        'authorization_expires_at',
        'authorization_proof_hash',
        'password_fingerprint',
        'consumed_at',
        'delivery_claimed_at',
    ];

    /** Secrets/bindings never leave the database via serialization. */
    protected $hidden = ['token', 'code_hash', 'authorization_proof_hash', 'password_fingerprint', 'delivery_claim_token'];

    protected $casts = [
        'expires_at' => 'datetime',
        'authorized_at' => 'datetime',
        'authorization_expires_at' => 'datetime',
        'consumed_at' => 'datetime',
        'delivery_claimed_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
