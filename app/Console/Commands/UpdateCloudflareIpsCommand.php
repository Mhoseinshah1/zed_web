<?php

namespace App\Console\Commands;

use App\Support\CloudflareProxies;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

/**
 * Refresh the cached Cloudflare edge IP ranges from Cloudflare's published
 * lists (https://www.cloudflare.com/ips-v4 and /ips-v6) into a local file that
 * App\Support\CloudflareProxies reads at request time.
 *
 * Run this on a schedule (e.g. monthly) so the trusted-proxy list follows
 * Cloudflare's ranges. If the fetch fails or returns nothing usable, the cache
 * is left untouched and the bundled defaults remain in effect — the command
 * never writes an empty or partial range set.
 *
 * See docs/trusted-proxies.md for the recommended cron entry.
 */
class UpdateCloudflareIpsCommand extends Command
{
    protected $signature = 'zedproxy:update-cloudflare-ips';

    protected $description = 'Refresh cached Cloudflare IP ranges used for trusted-proxy resolution.';

    private const SOURCES = [
        'https://www.cloudflare.com/ips-v4',
        'https://www.cloudflare.com/ips-v6',
    ];

    public function handle(): int
    {
        $ranges = [];

        foreach (self::SOURCES as $url) {
            try {
                $response = Http::timeout(15)->get($url);
            } catch (\Throwable $e) {
                $this->error("دریافت محدوده‌های Cloudflare از {$url} ناموفق بود: {$e->getMessage()}");

                return self::FAILURE;
            }

            if (! $response->successful()) {
                $this->error("دریافت {$url} با وضعیت {$response->status()} ناموفق بود.");

                return self::FAILURE;
            }

            foreach (preg_split('/\r\n|\r|\n/', (string) $response->body()) as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $ranges[] = $line;
                }
            }
        }

        $ranges = CloudflareProxies::sanitize($ranges);

        // Refuse to overwrite the cache with a suspiciously small set — a broken
        // upstream response must not shrink the trusted list.
        if (count($ranges) < 5) {
            $this->error('پاسخ دریافتی معتبر نبود؛ محدوده‌های ذخیره‌شده تغییری نکردند.');

            return self::FAILURE;
        }

        Storage::disk('local')->put(
            CloudflareProxies::CACHE_PATH,
            json_encode(array_values($ranges), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        );

        $this->info('محدوده‌های IP کلادفلر به‌روزرسانی شد ('.count($ranges).' مورد).');

        return self::SUCCESS;
    }
}
