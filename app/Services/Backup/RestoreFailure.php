<?php

namespace App\Services\Backup;

/**
 * A categorized restore failure, with the same strict audience split as
 * BackupFailure:
 *
 *  - getMessage(): a SHORT, bounded, operator-safe Persian sentence. It never
 *    contains raw process output, passwords, absolute paths, work-directory
 *    names, usernames, SQL text, or env values.
 *  - reason() / exitCode(): positive-listed diagnostics. `reason` is always a
 *    short machine code authored in this codebase (`not_absolute`,
 *    `entry_symlink`, `psql_failed`, …) — never interpolated stderr, exception
 *    text, or paths. The exit code is a numeric process exit status, safe by
 *    construction.
 *
 * Raw technical text is deliberately NOT collected on this object, so it
 * cannot be logged, echoed, or persisted by accident.
 */
class RestoreFailure extends \RuntimeException
{
    /** The runtime cannot support a restore at all (wrong driver, missing tool). */
    public const CATEGORY_ENVIRONMENT = 'environment';

    /** The requested target database is not an acceptable restore destination. */
    public const CATEGORY_TARGET = 'target';

    /** The supplied archive path / suffix / readability is unusable. */
    public const CATEGORY_ARCHIVE = 'archive';

    /** The archive contents failed the safety inspection. */
    public const CATEGORY_CONTENT = 'content';

    /** Decryption could not produce a usable plaintext archive. */
    public const CATEGORY_DECRYPTION = 'decryption';

    /** Private work directory / staging problems. */
    public const CATEGORY_STAGING = 'staging';

    /** psql refused or aborted the restore. */
    public const CATEGORY_RESTORE = 'restore';

    /** The restored schema failed its post-restore structural checks. */
    public const CATEGORY_VERIFICATION = 'verification';

    /**
     * SECURITY BOUNDARY: plaintext temporaries could not be VERIFIED as
     * removed. Distinct from every other category because the database work
     * may already have committed — see cleanup().
     */
    public const CATEGORY_CLEANUP = 'cleanup';

    /** An unexpected internal error, sanitized at the boundary. */
    public const CATEGORY_INTERNAL = 'internal';

    private function __construct(
        private readonly string $category,
        string $publicMessage,
        private readonly string $reason = '',
        private readonly ?int $exitCode = null,
    ) {
        parent::__construct($publicMessage, 0, null);
    }

    public static function environment(string $publicMessage, string $reason = ''): self
    {
        return new self(self::CATEGORY_ENVIRONMENT, $publicMessage, $reason);
    }

    public static function target(string $publicMessage, string $reason = ''): self
    {
        return new self(self::CATEGORY_TARGET, $publicMessage, $reason);
    }

    public static function archive(string $publicMessage, string $reason = ''): self
    {
        return new self(self::CATEGORY_ARCHIVE, $publicMessage, $reason);
    }

    public static function content(string $publicMessage, string $reason = ''): self
    {
        return new self(self::CATEGORY_CONTENT, $publicMessage, $reason);
    }

    public static function decryption(string $reason = '', ?int $exitCode = null): self
    {
        return new self(
            self::CATEGORY_DECRYPTION,
            'رمزگشایی فایل بکاپ ناموفق بود. رمز عبور یا فایل بکاپ نامعتبر است.',
            $reason,
            $exitCode,
        );
    }

    public static function staging(string $publicMessage, string $reason = ''): self
    {
        return new self(self::CATEGORY_STAGING, $publicMessage, $reason);
    }

    public static function restore(string $reason = '', ?int $exitCode = null): self
    {
        return new self(
            self::CATEGORY_RESTORE,
            'بازیابی پایگاه‌داده ناموفق بود. هیچ تغییری روی پایگاه‌داده مقصد باقی نمانده است.',
            $reason,
            $exitCode,
        );
    }

    public static function verification(string $publicMessage, string $reason = ''): self
    {
        return new self(self::CATEGORY_VERIFICATION, $publicMessage, $reason);
    }

    /**
     * Plaintext residue could not be verified as gone. $restored records
     * whether the database transaction had already committed, because that
     * changes what the operator must be told.
     */
    public static function cleanup(string $reason, bool $restored): self
    {
        return new self(
            self::CATEGORY_CLEANUP,
            $restored
                ? 'بازیابی احتمالاً کامل شده، اما حذف فایل‌های موقت حساس تأیید نشد. پیش از هر اقدام دیگری وضعیت پایگاه‌دادهٔ مقصد و فایل‌های موقت سرور را دستی بررسی کنید و بازیابی را کورکورانه تکرار نکنید.'
                : 'حذف فایل‌های موقت حساس تأیید نشد. فایل‌های موقت سرور را دستی بررسی کنید.',
            $reason,
        );
    }

    /** Any unexpected Throwable, reduced to a safe operator sentence. */
    public static function internal(string $reason = 'unexpected_error'): self
    {
        return new self(
            self::CATEGORY_INTERNAL,
            'بازیابی به دلیل خطای داخلی انجام نشد.',
            $reason,
        );
    }

    public function category(): string
    {
        return $this->category;
    }

    /** Short machine code authored in this codebase — never external text. */
    public function reason(): string
    {
        return $this->reason;
    }

    /** Numeric process exit status, or null when no process was involved. */
    public function exitCode(): ?int
    {
        return $this->exitCode;
    }

    /** The only text allowed to reach an operator surface. */
    public function publicMessage(): string
    {
        return $this->getMessage();
    }
}
