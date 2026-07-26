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

    /**
     * Sentinel applied-version for the pre-first-apply storage outage: the
     * process could never determine whether a panel override exists, so it
     * fails CLOSED instead of silently trusting the environment transport.
     */
    public const STORAGE_FAIL_CLOSED_VERSION = 'storage-unavailable-fail-closed';

    /** Version of the configuration THIS process last applied (memo). */
    private ?string $appliedVersion = null;

    /** True while the process is in the pre-first-apply fail-closed state. */
    private bool $storageFailClosed = false;

    /**
     * IMMUTABLE environment-backed baseline, captured ONCE PER PROCESS at the
     * first touch of any service instance — strictly before this service
     * performs its first mutation. Static so a freshly constructed service in
     * the same (already mutated) process resolves the exact same fallback
     * values and fingerprint as the long-lived singleton: fallbacks are never
     * derived from config keys the panel application itself rewrote.
     */
    private static ?array $processEnvBaseline = null;

    /** Diagnostics for tests: how many times the cached mailer was purged. */
    private int $purgeCount = 0;

    /**
     * The immutable environment baseline (capturing it on first touch — every
     * caller goes through here BEFORE any config mutation this service makes).
     */
    private function envBaseline(): array
    {
        return self::$processEnvBaseline ??= [
            'default' => config('mail.default'),
            'from_address' => config('mail.from.address'),
            'from_name' => config('mail.from.name'),
            'smtp_local_domain' => config('mail.mailers.smtp.local_domain'),
            // The dedicated name is reserved for the panel — but if a
            // deployment's own config defines it, that ORIGINAL definition
            // must survive an enable→disable cycle exactly.
            'managed_smtp_existed' => config()->has('mail.mailers.'.self::MAILER),
            'managed_smtp_original' => config('mail.mailers.'.self::MAILER),
        ];
    }

    /**
     * TEST-ONLY: forget the captured process baseline so a test can stage its
     * own environment values and have them captured as the baseline (this
     * simulates a process booted under that environment). Production never
     * calls this — the baseline is immutable for the life of the process.
     */
    public static function resetProcessBaselineForTesting(): void
    {
        self::$processEnvBaseline = null;
    }

    /**
     * Resolve the effective source and (for the panel source) the transport
     * values. Secrets stay inside the returned array in memory only — the
     * caller must never persist, log, or serialize it.
     *
     * @return array{source:string, config?:array<string,mixed>, from?:array{address:string,name:string}}
     */
    public function resolve(): array
    {
        // Capture the immutable environment baseline BEFORE any resolution:
        // optional-value fallbacks below must come from the true environment,
        // never from `mail.from.name` etc. after a previous panel apply()
        // rewrote them (that feedback loop made a cleared panel From name
        // keep resolving to the stale panel value).
        $baseline = $this->envBaseline();

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
                'local_domain' => (string) ($row->local_domain ?: $baseline['smtp_local_domain']),
            ],
            'from' => [
                'address' => (string) $row->from_address,
                // Blank optional panel name → the ORIGINAL environment name,
                // immediately and in every process — never the previously
                // applied panel name echoed back out of runtime config.
                'name' => (string) ($row->from_name ?: $baseline['from_name']),
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
        // Baseline capture FIRST — even the failure paths below mutate config
        // and must never poison a later capture.
        $this->envBaseline();

        try {
            $resolved = $this->resolve();
        } catch (Throwable) {
            // Configuration/decryption corruption never reaches here —
            // resolve() classifies it as SOURCE_PANEL_INVALID. This catch is
            // storage-level failure (unreachable database) only.
            if ($this->appliedVersion === self::STORAGE_FAIL_CLOSED_VERSION) {
                return; // already failed closed — no repeated purges
            }

            if ($this->appliedVersion !== null) {
                // Mid-process transient failure AFTER a successful apply:
                // keep the last successfully applied configuration — never
                // guess, never reset.
                return;
            }

            // The process has NEVER resolved a configuration: it cannot know
            // whether a panel override exists, so continuing with the
            // environment transport could silently send through credentials
            // the administrator replaced. Fail CLOSED until storage answers.
            $this->applyFailClosed();
            Mail::purge(self::MAILER);
            $this->purgeCount++;
            $this->appliedVersion = self::STORAGE_FAIL_CLOSED_VERSION;
            $this->storageFailClosed = true;

            return;
        }

        $this->storageFailClosed = false;

        $version = $this->version($resolved);
        if ($version === $this->appliedVersion) {
            return;
        }

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

    /**
     * Override disabled/absent: restore the environment-backed identity from
     * the IMMUTABLE baseline — including the reserved mailer name. When the
     * deployment's own config defined `managed_smtp`, that original
     * definition is restored exactly; otherwise the runtime definition is
     * neutralized so stale panel credentials can never remain reachable (or
     * silently authoritative when the environment default happens to carry
     * the reserved name).
     */
    private function applyEnvBaseline(): void
    {
        $baseline = $this->envBaseline();

        config([
            'mail.default' => $baseline['default'],
            'mail.from.address' => $baseline['from_address'],
            'mail.from.name' => $baseline['from_name'],
            'mail.mailers.'.self::MAILER => $baseline['managed_smtp_existed']
                ? $baseline['managed_smtp_original']
                : null,
        ]);
    }

    /** True while the pre-first-apply storage outage keeps mail fail-closed. */
    public function isStorageFailClosed(): bool
    {
        return $this->storageFailClosed;
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
