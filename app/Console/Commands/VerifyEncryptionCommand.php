<?php

namespace App\Console\Commands;

use App\Models\PaymentMethod;
use App\Models\VpnPanel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Validate that existing Laravel-encrypted secrets can still be decrypted with
 * the current APP_KEY. Used by install.sh after a safe re-run/upgrade to prove
 * the encryption key was preserved before reporting success.
 *
 * SECURITY: decrypted values are NEVER printed — only counts and a pass/fail
 * status. Returns a non-zero exit code if any existing encrypted value fails to
 * decrypt (invalid MAC / changed key / corrupted ciphertext).
 */
class VerifyEncryptionCommand extends Command
{
    protected $signature = 'zedproxy:verify-encryption';

    protected $description = 'Verify existing encrypted secrets still decrypt with the current APP_KEY (values never shown).';

    /**
     * Encrypted columns per model, keyed by table for existence checks.
     *
     * @var array<class-string, array{table:string, fields:array<int,string>}>
     */
    private array $targets = [
        VpnPanel::class      => ['table' => 'vpn_panels',     'fields' => ['password', 'token', 'api_token']],
        PaymentMethod::class => ['table' => 'payment_methods', 'fields' => ['api_key', 'ipn_secret']],
    ];

    public function handle(): int
    {
        $checked  = 0;
        $failures = 0;

        foreach ($this->targets as $model => $meta) {
            if (! Schema::hasTable($meta['table'])) {
                continue;
            }

            // Only look at columns that actually exist on this schema version.
            $fields = array_values(array_filter(
                $meta['fields'],
                fn (string $f) => Schema::hasColumn($meta['table'], $f),
            ));
            if ($fields === []) {
                continue;
            }

            // Read RAW (still-encrypted) values straight from the table so we
            // only attempt to decrypt rows that genuinely hold ciphertext.
            $rows = DB::table($meta['table'])->select(array_merge(['id'], $fields))->get();

            foreach ($rows as $row) {
                foreach ($fields as $field) {
                    $raw = $row->{$field} ?? null;
                    if ($raw === null || $raw === '') {
                        continue; // nothing encrypted in this cell
                    }

                    $checked++;

                    try {
                        // Loading through the model triggers the 'encrypted'
                        // cast, which decrypts on attribute access and throws
                        // a DecryptException on a bad key/MAC.
                        $instance = $model::find($row->id);
                        if ($instance !== null) {
                            $decrypted = $instance->getAttribute($field);
                            unset($decrypted); // never emit the value
                        }
                    } catch (\Throwable $e) {
                        // Swallow the exception message too — it can contain
                        // ciphertext fragments. Count the failure only.
                        $failures++;
                    }
                }
            }
        }

        if ($checked === 0) {
            $this->info('هیچ داده رمزگذاری‌شده‌ای برای بررسی وجود ندارد.');
            return self::SUCCESS;
        }

        if ($failures > 0) {
            $this->error('خطا در رمزگشایی اطلاعات حساس. APP_KEY یا اطلاعات رمزگذاری‌شده معتبر نیستند.');
            return self::FAILURE;
        }

        $this->info("رمزگشایی {$checked} مقدار حساس با موفقیت تایید شد.");

        return self::SUCCESS;
    }
}
