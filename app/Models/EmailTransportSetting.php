<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Singleton-style admin-managed SMTP transport configuration.
 *
 * SECRET HANDLING CONTRACT:
 *  - `username` and `password` are encrypted at rest via the authenticated
 *    APP_KEY-backed `encrypted` cast (Crypt::encryptString) — the raw
 *    database values are ciphertext envelopes, never plaintext.
 *  - Both are $hidden: toArray()/toJson()/Livewire serialization can never
 *    leak them.
 *  - Neither is mass-assignable: they are written ONLY through the explicit
 *    setters below, so a stray fill()/update() with request input can never
 *    reach them.
 *  - Reading a corrupt/undecryptable value throws (DecryptException); the
 *    resolver treats that as an UNUSABLE panel configuration (fail closed),
 *    never as permission to fall back to .env while the override is enabled.
 *
 * `instance()` tolerates a missing table (pre-migration bootstrap, fresh
 * clone running `composer install`/`config:cache` before `migrate`): it
 * returns null and the caller uses the environment fallback.
 */
class EmailTransportSetting extends Model
{
    /**
     * The one fixed identity every row must carry — the `singleton_key`
     * unique index makes "exactly one logical row" a DATABASE invariant, not
     * an application convention: concurrent first-time saves race on the
     * same key and exactly one insert can win.
     */
    public const SINGLETON_KEY = 'main';

    /** Secrets are deliberately NOT fillable — explicit setters only. */
    protected $fillable = [
        'enabled',
        'host',
        'port',
        'security',
        'from_address',
        'from_name',
        'timeout',
        'local_domain',
    ];

    /** Never serialized — not into JSON, arrays, or Livewire snapshots. */
    protected $hidden = ['username', 'password'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'port' => 'integer',
            'timeout' => 'integer',
            'username' => 'encrypted',
            'password' => 'encrypted',
        ];
    }

    /** Explicit secret setter (null clears). Never mass-assigned. */
    public function setUsernameSecret(?string $username): void
    {
        $this->username = ($username === null || $username === '') ? null : $username;
    }

    /** Explicit secret setter (null clears). Never mass-assigned. */
    public function setPasswordSecret(?string $password): void
    {
        $this->password = ($password === null || $password === '') ? null : $password;
    }

    /** Non-throwing presence check — never decrypts, never exposes length. */
    public function hasStoredPassword(): bool
    {
        return $this->getRawOriginal('password') !== null;
    }

    /** Non-throwing presence check — never decrypts. */
    public function hasStoredUsername(): bool
    {
        return $this->getRawOriginal('username') !== null;
    }

    /**
     * The singleton row, or null when none exists yet OR the table itself
     * does not exist (pre-migration bootstrap) — both mean "environment
     * fallback". A storage-level FAILURE (unreachable database) deliberately
     * propagates instead: the resolver's apply() catches it and keeps the
     * last successfully applied configuration rather than silently flipping
     * an enabled override back to .env.
     */
    public static function instance(): ?self
    {
        if (! Schema::hasTable('email_transport_settings')) {
            return null;
        }

        return static::query()->where('singleton_key', self::SINGLETON_KEY)->first();
    }

    /** The singleton row, creating a disabled empty one when missing. */
    public static function instanceOrNew(): self
    {
        return static::instance() ?? new self(['enabled' => false]);
    }

    /**
     * Every insert is forced onto the singleton identity — combined with the
     * unique index, a second concurrent first-time save cannot create a
     * second logical row (the losing insert violates the constraint instead
     * of silently coexisting).
     */
    protected static function booted(): void
    {
        static::creating(function (self $row): void {
            $row->singleton_key = self::SINGLETON_KEY;
        });
    }
}
