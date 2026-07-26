<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TOTP credential for mandatory administrator MFA.
 *
 * NOTHING here is mass-assignable — every write happens through
 * AdminTotpService with explicit forceFill/attribute assignment, always inside
 * a transaction holding the row lock. The secret uses the authenticated
 * `encrypted` cast (APP_KEY) and recovery codes are stored ONLY as bcrypt
 * hashes inside an encrypted JSON array; both are hidden from serialization
 * so no API response, log dump, or Livewire snapshot can ever include them.
 */
class AdminTwoFactorCredential extends Model
{
    protected $table = 'admin_two_factor_credentials';

    /** Explicit trusted writes only — nothing is fillable. */
    protected $guarded = ['*'];

    protected $hidden = [
        'secret',
        'pending_secret',
        'recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'pending_secret' => 'encrypted',
            'recovery_codes' => 'encrypted:array',
            'confirmed_at' => 'datetime',
            'pending_secret_generated_at' => 'datetime',
            'recovery_codes_generated_at' => 'datetime',
            'last_verified_timestep' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Enrollment finished: the admin proved possession with a live code. */
    public function isConfirmed(): bool
    {
        return $this->confirmed_at !== null;
    }

    /**
     * Version token binding sessions/grants to THIS factor. Derived from the
     * stored CIPHERTEXT of the active secret: every confirmation/replacement
     * writes a fresh encryption payload (random IV), so the version rotates
     * even when two replacements land within the same second — and a hash of
     * ciphertext can never leak the secret itself.
     */
    public function version(): string
    {
        $raw = (string) $this->getRawOriginal('secret');

        return $raw === '' ? '' : hash('sha256', $raw);
    }

    /**
     * Non-secret digest of the PENDING secret's ciphertext — binds a
     * replacement/enrollment hand-off to one specific provisioned secret
     * without ever copying secret material into the session.
     */
    public function pendingVersion(): string
    {
        $raw = (string) $this->getRawOriginal('pending_secret');

        return $raw === '' ? '' : hash('sha256', $raw);
    }
}
