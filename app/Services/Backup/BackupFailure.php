<?php

namespace App\Services\Backup;

/**
 * A categorized backup failure with a strict split between two audiences:
 *
 *  - getMessage() / publicMessage(): a SHORT, bounded, operator-safe Persian
 *    sentence. This is the ONLY text allowed to reach BackupLog.error,
 *    Filament notifications, or Telegram. It never contains raw process
 *    output, absolute paths, work-directory names, usernames, or env values.
 *  - detail(): the raw technical context (process stderr, filesystem error,
 *    offending path). It goes to the SERVER LOG ONLY and is never attached
 *    to any operator-facing channel.
 */
class BackupFailure extends \RuntimeException
{
    public const CATEGORY_CONFIG = 'config';

    public const CATEGORY_PERMISSION = 'permission';

    public const CATEGORY_DUMP = 'dump';

    public const CATEGORY_ARCHIVE = 'archive';

    public const CATEGORY_ENCRYPTION = 'encryption';

    public const CATEGORY_COMMIT = 'commit';

    public const CATEGORY_INTERNAL = 'internal';

    private function __construct(
        private readonly string $category,
        string $publicMessage,
        private readonly string $detail = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($publicMessage, 0, $previous);
    }

    /** Invalid or unusable configuration (storage path, no sources selected…). */
    public static function config(string $publicMessage, string $detail = ''): self
    {
        return new self(self::CATEGORY_CONFIG, $publicMessage, $detail);
    }

    /** Filesystem permission problems on the backup root or work directory. */
    public static function permission(string $publicMessage, string $detail = ''): self
    {
        return new self(self::CATEGORY_PERMISSION, $publicMessage, $detail);
    }

    /** pg_dump did not produce a usable database dump. */
    public static function dump(string $detail = ''): self
    {
        return new self(self::CATEGORY_DUMP, 'تهیه خروجی پایگاه داده ناموفق بود. جزئیات فنی در لاگ سرور ثبت شد.', $detail);
    }

    /** tar did not produce a usable archive. */
    public static function archive(string $detail = ''): self
    {
        return new self(self::CATEGORY_ARCHIVE, 'ساخت فایل آرشیو بکاپ ناموفق بود. جزئیات فنی در لاگ سرور ثبت شد.', $detail);
    }

    /** openssl did not produce a usable encrypted archive. */
    public static function encryption(string $detail = ''): self
    {
        return new self(self::CATEGORY_ENCRYPTION, 'رمزگذاری فایل بکاپ ناموفق بود. جزئیات فنی در لاگ سرور ثبت شد.', $detail);
    }

    /** The verified artifact could not be committed to its final name. */
    public static function commit(string $detail = ''): self
    {
        return new self(self::CATEGORY_COMMIT, 'نهایی‌سازی فایل بکاپ ناموفق بود. جزئیات فنی در لاگ سرور ثبت شد.', $detail);
    }

    /** Any unexpected error — the raw message stays out of operator channels. */
    public static function internal(\Throwable $e): self
    {
        return new self(
            self::CATEGORY_INTERNAL,
            'خطای داخلی در فرایند بکاپ رخ داد. جزئیات فنی در لاگ سرور ثبت شد.',
            $e::class.': '.$e->getMessage(),
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

    /** Raw technical context for the server log only. */
    public function detail(): string
    {
        return $this->detail;
    }
}
