<?php

namespace App\Console\Commands;

use Database\Seeders\DefaultPagesSeeder;
use Database\Seeders\SeoPageSeeder;
use Illuminate\Console\Command;
use Throwable;

/**
 * Seed the records the application REQUIRES to behave correctly in production:
 * the default CMS pages (terms/privacy/about — the 301 alias destinations) and
 * the SEO page registry (login/register noindex records). Run by install.sh and
 * the atomic deployer after migrations, before a release goes public.
 *
 * Runs EXACTLY these two seeders and nothing else — never the full
 * DatabaseSeeder (no sample plans, payment methods, or other demo data).
 * Both seeders use firstOrCreate, so administrator-edited records are never
 * overwritten and repeated execution is idempotent.
 */
class SeedRequiredDefaultsCommand extends Command
{
    protected $signature = 'zedproxy:seed-required-defaults';

    protected $description = 'Idempotently seed required default CMS pages and SEO page records (never overwrites admin edits).';

    public function handle(): int
    {
        foreach ([DefaultPagesSeeder::class, SeoPageSeeder::class] as $seeder) {
            try {
                (new $seeder)->run();
            } catch (Throwable $e) {
                $this->error("required-defaults: {$seeder} failed: {$e->getMessage()}");

                return self::FAILURE;
            }
        }

        $this->info('required-defaults: default pages + SEO page records ensured.');

        return self::SUCCESS;
    }
}
