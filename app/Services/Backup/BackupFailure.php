<?php

namespace App\Services\Backup;

/**
 * A categorized backup failure with a strict split between two audiences:
 *
 *  - getMessage() / publicMessage(): a SHORT, bounded, operator-safe Persian
 *    sentence. This is the ONLY text allowed to reach BackupLog.error,
 *    Filament notifications, or Telegram. It never contains raw process
 *    output, filesystem paths, work-directory names, usernames, or env
 *    values.
 *  - reason() / exitCode(): positive-listed diagnostics for the SERVER LOG.
 *    `reason` is always a short machine code authored in this codebase
 *    (e.g. `process_failed`, `empty_artifact`, `mkdir_failed`) — never
 *    interpolated stderr, exception text, or paths. The exit code is the
 *    numeric process exit status, safe by construction. Raw technical text
 *    is deliberately NOT collected anywhere on this object, so it cannot be
 *    persisted by accident.
 */
class BackupFailure extends \RuntimeException
{
    public const CATEGORY_CONFIG = 'config';

    public const CATEGORY_PERMISSION = 'permission';

    public const CATEGORY_DUMP = 'dump';

    public const CATEGORY_ARCHIVE = 'archive';

    public const CATEGORY_ENCRYPTION = 'encryption';

    public const CATEGORY_COMMIT = 'commit';

    /** Security boundary: plaintext residue could not be removed on an encrypted run. */
    public const CATEGORY_PLAINTEXT_CLEANUP = 'plaintext_cleanup';

    public const CATEGORY_INTERNAL = 'internal';

    private function __construct(
        private readonly string $category,
        string $publicMessage,
        private readonly string $reason = '',
        private readonly ?int $exitCode = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($publicMessage, 0, $previous);
    }

    /** Invalid or unusable configuration (storage path, no sources selected…). */
    public static function config(string $publicMessage, string $reason = ''): self
    {
        return new self(self::CATEGORY_CONFIG, $publicMessage, $reason);
    }

    /** Filesystem permission problems on the backup root or work directory. */
    public static function permission(string $publicMessage, string $reason = ''): self
    {
        return new self(self::CATEGORY_PERMISSION, $publicMessage, $reason);
    }

    /** pg_dump did not produce a usable database dump. */
    public static function dump(string $reason = '', ?int $exitCode = null): self
    {
        return new self(self::CATEGORY_DUMP, 'تهیه خروجی پایگاه داده ناموفق بود.', $reason, $exitCode);
    }

    /** tar did not produce a usable archive. */
    public static function archive(string $reason = '', ?int $exitCode = null): self
    {
        return new self(self::CATEGORY_ARCHIVE, 'ساخت فایل آرشیو بکاپ ناموفق بود.', $reason, $exitCode);
    }

    /** openssl did not produce a usable encrypted archive. */
    public static function encryption(string $reason = '', ?int $exitCode = null): self
    {
        return new self(self::CATEGORY_ENCRYPTION, 'رمزگذاری فایل بکاپ ناموفق بود.', $reason, $exitCode);
    }

    /** The verified artifact could not be committed to its final name. */
    public static function commit(string $reason = ''): self
    {
        return new self(self::CATEGORY_COMMIT, 'نهایی‌سازی فایل بکاپ ناموفق بود.', $reason);
    }

    /**
     * SECURITY failure: on an encrypted run, plaintext temporary material
     * (database dump / unencrypted archive) could not be verifiably removed.
     * The run must NOT be reported successful in this state.
     */
    public static function plaintextCleanup(string $reason = ''): self
    {
        return new self(
            self::CATEGORY_PLAINTEXT_CLEANUP,
            'حذف امن فایل‌های موقت رمزنگاری‌نشده ممکن نشد؛ برای حفاظت از داده‌ها، بکاپ ناموفق ثبت شد.',
            $reason,
        );
    }

    /** Any unexpected error — its raw message stays off this object entirely. */
    public static function internal(\Throwable $e): self
    {
        return new self(
            self::CATEGORY_INTERNAL,
            'خطای داخلی در فرایند بکاپ رخ داد.',
            'unexpected_exception',
            null,
            $e,
        );
    }

    public function category(): string
    {
        return $this->category;
    }

    /** The bounded operator-safe Persian message (same as getMessage()). */
    public function publicMessage(): string
    {
        return $this->getMessage();
    }

    /** Short authored machine code for the server log — never raw text. */
    public function reason(): string
    {
        return $this->reason;
    }

    /** Numeric process exit status when a process stage failed, else null. */
    public function exitCode(): ?int
    {
        return $this->exitCode;
    }
}
