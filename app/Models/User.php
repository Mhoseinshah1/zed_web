<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'is_admin',
        'wallet_balance_toman',
        'account_id',
        'phone',
        'normalized_phone',
        'phone_verified_at',
        'profile_completed_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at'    => 'datetime',
            'phone_verified_at'    => 'datetime',
            'profile_completed_at' => 'datetime',
            'password'             => 'hashed',
            'is_admin'             => 'boolean',
            'wallet_balance_toman' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (empty($user->account_id)) {
                $user->account_id = self::generateAccountId();
            }
        });

        // Keep normalized_phone in sync whenever the raw phone changes (admin
        // edits, profile updates, etc.).
        static::saving(function (User $user) {
            if ($user->isDirty('phone')) {
                $user->normalized_phone = $user->phone
                    ? \App\Support\PhoneNumber::normalize($user->phone)
                    : null;
            }
        });
    }

    /**
     * Generate a unique random 6-digit numeric account id (100000–999999).
     * Numeric only, no prefix, not sequential.
     */
    public static function generateAccountId(): string
    {
        do {
            $candidate = (string) random_int(100000, 999999);
        } while (self::where('account_id', $candidate)->exists());

        return $candidate;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_admin === true;
    }

    // ── Phone / profile helpers ──────────────────────────────────────────────

    public function hasPhone(): bool
    {
        return filled($this->phone);
    }

    public function hasVerifiedPhone(): bool
    {
        return $this->phone_verified_at !== null;
    }

    public function phoneVerificationCodes(): HasMany
    {
        return $this->hasMany(PhoneVerificationCode::class);
    }

    public function activeServicesCount(): int
    {
        return $this->services()->where('status', UserService::STATUS_ACTIVE)->count();
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function paymentTransactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    public function walletTransactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(UserService::class);
    }
}
