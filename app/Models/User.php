<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    // NOTE: is_admin and wallet_balance_toman are intentionally NOT fillable — they
    // are privilege/money fields and must never be settable via mass assignment.
    // Legitimate writers set them explicitly via forceFill(): WalletService (balance),
    // CreateAdminCommand and the Filament UserResource pages (is_admin).
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'account_id',
        'phone',
        'normalized_phone',
        'phone_verified_at',
        'profile_completed_at',
        'referral_code',
        'referred_by_user_id',
        'is_representative',
        'representative_status',
        'commission_type',
        'commission_rate',
        'commission_fixed_amount',
        'commission_balance',
        'total_commission_earned',
        'representative_approved_at',
        'representative_note',
        'theme_preference',
        'appearance',
    ];

    // Representative statuses
    const REP_NONE     = 'none';
    const REP_PENDING  = 'pending';
    const REP_APPROVED = 'approved';
    const REP_REJECTED = 'rejected';
    const REP_DISABLED = 'disabled';

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
            'is_representative'         => 'boolean',
            'commission_rate'          => 'integer',
            'commission_fixed_amount'  => 'integer',
            'commission_balance'       => 'integer',
            'total_commission_earned'  => 'integer',
            'representative_approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (User $user) {
            if (empty($user->account_id)) {
                $user->account_id = self::generateAccountId();
            }
            if (empty($user->referral_code)) {
                $user->referral_code = self::generateReferralCode();
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

    /**
     * A readable, unambiguous referral code that does not expose the DB id.
     * Uppercase letters/digits without easily-confused characters (0/O, 1/I).
     */
    public static function makeReferralCode(int $length = 8): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $code;
    }

    public static function generateReferralCode(): string
    {
        do {
            $code = self::makeReferralCode();
        } while (self::where('referral_code', $code)->exists());

        return $code;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_admin === true;
    }

    // ── Referral / representative ────────────────────────────────────────────

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by_user_id');
    }

    public function referredUsers(): HasMany
    {
        return $this->hasMany(User::class, 'referred_by_user_id');
    }

    public function commissionsAsRepresentative(): HasMany
    {
        return $this->hasMany(Commission::class, 'representative_user_id');
    }

    public function representativeRequests(): HasMany
    {
        return $this->hasMany(RepresentativeRequest::class);
    }

    /** Approved AND not disabled — eligible to invite/earn in representatives_only mode. */
    public function isApprovedRepresentative(): bool
    {
        return $this->is_representative
            && $this->representative_status === self::REP_APPROVED;
    }

    public function referralLink(): string
    {
        return route('register', ['ref' => $this->referral_code]);
    }

    public function representativeStatusLabel(): string
    {
        return self::representativeStatuses()[$this->representative_status] ?? $this->representative_status;
    }

    public static function representativeStatuses(): array
    {
        return [
            self::REP_NONE     => 'بدون درخواست',
            self::REP_PENDING  => 'در انتظار بررسی',
            self::REP_APPROVED => 'تاییدشده',
            self::REP_REJECTED => 'ردشده',
            self::REP_DISABLED => 'غیرفعال',
        ];
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

    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }
}
