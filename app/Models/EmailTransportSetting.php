<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
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
     * DEFENSE IN DEPTH ONLY: every Eloquent insert is stamped with the
     * canonical identity so ordinary code never even attempts a noncanonical
     * key. The actual invariant lives in the DATABASE — the unique index
     * plus the CHECK constraint (PostgreSQL) / RAISE triggers (SQLite) that
     * reject any noncanonical key, including raw query-builder inserts that
     * bypass this hook entirely.
     */
    protected static function booted(): void
    {
        static::creating(function (self $row): void {
            $row->singleton_key = self::SINGLETON_KEY;
        });
    }

    /**
     * Production persistence path: save with bounded recovery for the ONE
     * recoverable conflict — losing a concurrent FIRST-creation race on the
     * unique singleton key.
     *
     * Concurrent-write policy (documented): saves serialize on the canonical
     * row and the LAST COMMITTED save wins. A loser reloads the committed
     * winner row and re-applies its own values (including explicitly staged
     * secret ciphertext) as an UPDATE of that row — exactly one retry, no
     * loop. Anything else — noncanonical-key rejections, unrelated database
     * errors, conflicts on an UPDATE — propagates untouched: this method
     * never converts a real failure into silence.
     */
    public function saveSingleton(): self
    {
        $wasInsert = ! $this->exists;

        try {
            // Nested transaction = SAVEPOINT when a caller (the settings
            // page) already opened one: on PostgreSQL a failed INSERT aborts
            // the surrounding transaction, so the conflict must roll back to
            // the savepoint for the recovery UPDATE below to be possible at
            // all.
            DB::transaction(function (): void {
                $this->save();
            });

            return $this;
        } catch (QueryException $e) {
            if (! $wasInsert || ! self::isSingletonInsertConflict($e)) {
                throw $e;
            }

            // Lost the first-creation race: adopt the committed winner and
            // apply OUR values on top (last-committed save wins).
            $winner = self::instance();
            if ($winner === null) {
                throw $e; // conflict without a visible winner — not ours to guess
            }

            foreach (['enabled', 'host', 'port', 'security', 'from_address', 'from_name', 'timeout', 'local_domain'] as $field) {
                $winner->{$field} = $this->{$field};
            }
            // Secrets transfer as already-encrypted attribute payloads, and
            // ONLY when this save explicitly staged them — an absent secret
            // keeps the winner's stored one (blank-keeps-existing semantics).
            foreach (['username', 'password'] as $secret) {
                if (array_key_exists($secret, $this->getAttributes())) {
                    $winner->setRawAttributes(
                        array_merge($winner->getAttributes(), [$secret => $this->getAttributes()[$secret]]),
                        false,
                    );
                }
            }

            $winner->save();

            return $winner;
        }
    }

    /** The one recoverable conflict: a duplicate on the singleton unique key. */
    private static function isSingletonInsertConflict(QueryException $e): bool
    {
        return str_contains($e->getMessage(), 'singleton_key')
            && (str_contains($e->getMessage(), 'duplicate key')      // PostgreSQL 23505
                || str_contains($e->getMessage(), 'UNIQUE constraint failed')); // SQLite
    }
}
