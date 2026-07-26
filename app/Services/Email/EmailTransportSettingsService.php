<?php

namespace App\Services\Email;

use App\Models\EmailTransportSetting;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * THE single resolver for the effective mail configuration.
 *
 * Precedence:
 *  1. an ENABLED and VALID panel override (email_transport_settings row) —
 *     applied as the dedicated runtime mailer `managed_smtp`, which becomes
 *     the default mailer;
 *  2. the environment-backed configuration when no override is enabled
 *     (missing table, missing row, enabled=false) — nothing is touched;
 *  3. FAIL CLOSED when the override is enabled but cannot be safely resolved
 *     (structurally invalid values, undecryptable secrets): `managed_smtp`
 *     becomes the default with a deliberately unusable empty-host config, so
 *     EmailVerificationService::isMailConfigured() reports unusable, required
 *     verification automatically degrades, and nothing ever silently sends
 *     through the old environment credentials.
 *
 * The environment-backed `smtp` mailer definition is NEVER mutated, no .env
 * file is ever written, and no Artisan/shell command is ever run — the
 * override exists purely as runtime config on a dedicated mailer name.
 *
 * apply() is invoked at application bootstrap (web + console), immediately
 * after a successful settings save, and before EVERY queued job via the
 * Queue::before hook — so long-running workers pick up a change before their
 * next job without any restart, cache clear, or config:cache. The resolved
 * configuration carries a normalized VERSION (a non-reversible hash over the
 * operational values plus an APP_KEY-keyed digest of the secrets); the cached
 * `managed_smtp` mailer instance is purged from the mail manager ONLY when
 * that version changes — an unchanged configuration causes no purge and no
 * config writes on subsequent jobs.
 */
class EmailTransportSettingsService
{
    /** The dedicated runtime mailer name for the panel override. */
    public const MAILER = 'managed_smtp';

    /** Exact Symfony Mailer 7 EsmtpTransportFactory schemes (verified). */
    public const SUPPORTED_SECURITY = ['smtp', 'smtps'];

    /** Operational timeout bounds — mirrors config/mail.php's 1–20s clamp. */
    public const MIN_TIMEOUT = 1;

    public const MAX_TIMEOUT = 20;

    public const SOURCE_PANEL = 'panel';

    public const SOURCE_ENV = 'env';

    public const SOURCE_PANEL_INVALID = 'panel_invalid';

    /** Version of the configuration THIS process last applied (memo). */
    private ?string $appliedVersion = null;

    /** Env-backed baseline captured before the first override application. */
    private ?array $envBaseline = null;

    /** Diagnostics for tests: how many times the cached mailer was purged. */
    private int $purgeCount = 0;

    /**
     * Resolve the effective source and (for the panel source) the transport
     * values. Secrets stay inside the returned array in memory only — the
     * caller must never persist, log, or serialize it.
     *
     * @return array{source:string, config?:array<string,mixed>, from?:array{address:string,name:string}}
     */
    public function resolve(): array
    {
        $row = EmailTransportSetting::instance();

        if ($row === null || ! $row->enabled) {
            return ['source' => self::SOURCE_ENV];
        }

        // Undecryptable secrets (APP_KEY rotation, corrupted ciphertext) make
        // the ENABLED override unusable — fail closed, never fall back to
        // the environment credentials while the admin believes the panel
        // configuration is in charge.
        try {
            $username = $row->username;
            $password = $row->password;
        } catch (Throwable) {
            return ['source' => self::SOURCE_PANEL_INVALID];
        }

        if (! $this->rowLooksStructurallyValid($row)) {
            return ['source' => self::SOURCE_PANEL_INVALID];
        }

        return [
            'source' => self::SOURCE_PANEL,
            'config' => [
                'transport' => 'smtp',
                'scheme' => (string) $row->security,
                'host' => (string) $row->host,
                'port' => (int) $row->port,
                'username' => $username === '' ? null : $username,
                'password' => $password === '' ? null : $password,
                'timeout' => min(self::MAX_TIMEOUT, max(self::MIN_TIMEOUT, (int) $row->timeout)),
                'local_domain' => (string) ($row->local_domain ?: config('mail.mailers.smtp.local_domain')),
            ],
            'from' => [
                'address' => (string) $row->from_address,
                'name' => (string) ($row->from_name ?: config('mail.from.name')),
            ],
        ];
    }

    /**
     * Structural validity required to ACT on an enabled override. The page
     * enforces the same rules before allowing the override to be enabled;
     * this re-check protects against rows mutated outside the page.
     */
    public function rowLooksStructurallyValid(EmailTransportSetting $row): bool
    {
        return (string) $row->host !== ''
            && (int) $row->port >= 1 && (int) $row->port <= 65535
            && in_array((string) $row->security, self::SUPPORTED_SECURITY, true)
            && filter_var((string) $row->from_address, FILTER_VALIDATE_EMAIL) !== false
            && (int) $row->timeout >= self::MIN_TIMEOUT && (int) $row->timeout <= self::MAX_TIMEOUT;
    }

    /** The effective source WITHOUT mutating anything (for UI status). */
    public function effectiveSource(): string
    {
        return $this->resolve()['source'];
    }

    /**
     * Apply the resolved configuration to the RUNNING process. Idempotent
     * and cheap when nothing changed: the normalized version is compared to
     * the last-applied one and an unchanged configuration returns without
     * touching config or purging any cached mailer.
     */
    public function apply(): void
    {
        try {
            $resolved = $this->resolve();
        } catch (Throwable) {
            // Storage unavailable mid-process (transient DB failure inside a
            // worker): keep the last successfully applied configuration —
            // never guess, never reset.
            return;
        }

        $version = $this->version($resolved);
        if ($version === $this->appliedVersion) {
            return;
        }

        // Snapshot the untouched environment-backed values ONCE, before the
        // first mutation this process makes, so a later disable restores the
        // true .env identity inside the same long-lived worker.
        $this->envBaseline ??= [
            'default' => config('mail.default'),
            'from.address' => config('mail.from.address'),
            'from.name' => config('mail.from.name'),
        ];

        match ($resolved['source']) {
            self::SOURCE_PANEL => $this->applyPanel($resolved),
            self::SOURCE_PANEL_INVALID => $this->applyFailClosed(),
            default => $this->applyEnvBaseline(),
        };

        // Drop only the dedicated cached mailer instance — the mail manager
        // memoizes constructed mailers per name, and a stale instance would
        // keep the previous transport alive for the rest of the process.
        Mail::purge(self::MAILER);
        $this->purgeCount++;

        $this->appliedVersion = $version;
    }

    private function applyPanel(array $resolved): void
    {
        config([
            'mail.mailers.'.self::MAILER => $resolved['config'],
            'mail.default' => self::MAILER,
            'mail.from.address' => $resolved['from']['address'],
            'mail.from.name' => $resolved['from']['name'],
        ]);
    }

    /**
     * Enabled-but-unusable override: the default mailer becomes a
     * deliberately unresolvable managed_smtp definition (empty host, port 0).
     * isMailConfigured() reports it unusable, required verification
     * automatically becomes unenforceable, and any direct send attempt fails
     * at connect — .env credentials are never silently used.
     */
    private function applyFailClosed(): void
    {
        config([
            'mail.mailers.'.self::MAILER => [
                'transport' => 'smtp',
                'host' => '',
                'port' => 0,
                'timeout' => self::MIN_TIMEOUT,
            ],
            'mail.default' => self::MAILER,
        ]);
    }

    /** Override disabled/absent: restore the environment-backed identity. */
    private function applyEnvBaseline(): void
    {
        config([
            'mail.default' => $this->envBaseline['default'],
            'mail.from.address' => $this->envBaseline['from.address'],
            'mail.from.name' => $this->envBaseline['from.name'],
        ]);
    }

    /**
     * Normalized non-reversible version of a resolved configuration. Changes
     * whenever host, port, security, username, password, From identity,
     * timeout, EHLO domain, or the override SOURCE changes. Secrets enter
     * only as an APP_KEY-derived-key HMAC — nothing reversible ever leaves
     * this method.
     */
    public function version(?array $resolved = null): string
    {
        $resolved ??= $this->resolve();

        $config = $resolved['config'] ?? [];

        $derivedKey = hash_hmac('sha256', 'zedproxy.managed-smtp-config-version.v1', (string) config('app.key'));
        $secretDigest = hash_hmac('sha256', (string) json_encode([
            'username' => (string) ($config['username'] ?? ''),
            'password' => (string) ($config['password'] ?? ''),
        ]), $derivedKey);

        return hash('sha256', (string) json_encode([
            'source' => $resolved['source'],
            'host' => (string) ($config['host'] ?? ''),
            'port' => (int) ($config['port'] ?? 0),
            'scheme' => (string) ($config['scheme'] ?? ''),
            'timeout' => (int) ($config['timeout'] ?? 0),
            'local_domain' => (string) ($config['local_domain'] ?? ''),
            'from_address' => (string) ($resolved['from']['address'] ?? ''),
            'from_name' => (string) ($resolved['from']['name'] ?? ''),
            'secret_digest' => $secretDigest,
        ]));
    }

    /** Test/diagnostic accessor — number of cache purges this process made. */
    public function purgeCount(): int
    {
        return $this->purgeCount;
    }
}
