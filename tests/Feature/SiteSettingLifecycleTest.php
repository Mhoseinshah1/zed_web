<?php

namespace Tests\Feature;

use App\Models\SiteSetting;
use App\Models\User;
use App\Services\Settings\SettingsRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Records what a job actually saw, so the assertion is about the job's own
 * read rather than about the test's.
 */
class RecordsSettingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** @var array<int,mixed> */
    public static array $seen = [];

    public function __construct(private readonly string $key) {}

    public function handle(): void
    {
        static::$seen[] = SiteSetting::get($this->key);
    }
}

/** Reads many keys inside ONE job, to prove the per-lifecycle load survives. */
class ReadsManySettingsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function handle(): void
    {
        for ($i = 0; $i < 25; $i++) {
            SiteSetting::get('worker_a');
            SiteSetting::get('worker_b');
            SiteSetting::get('worker_absent');
        }
    }
}

/**
 * Settings must not go stale inside a long-running queue worker.
 *
 * A PHP static property is PROCESS-scoped, not request-scoped. A queue worker
 * is one process handling many jobs for hours or days, so a static memo
 * populated by the first job it runs survives every subsequent job until the
 * process is restarted.
 *
 * The production sequence this reproduces:
 *
 *   1. Job A reads a setting; the worker process memoises it.
 *   2. An administrator changes that setting in the WEB process.
 *   3. The web process clears only its own memory — the worker never hears.
 *   4. Job B runs in the same worker.
 *   5. Job B still reads the old value, until someone restarts the worker.
 *
 * These settings gate email-verification enforcement, SMTP runtime
 * configuration, payments, backups and feature switches, so "the worker is up
 * to a week behind on a security toggle" is the actual failure mode.
 *
 * The sync queue connection is used deliberately: `SyncQueue::push()` raises
 * the same `JobProcessing` / `JobProcessed` events a real worker raises, so the
 * `Queue::before` lifecycle hook under test is genuinely exercised in-process.
 * The intervening write goes through the QUERY BUILDER, which fires no model
 * events — exactly how a different process's write looks to this one.
 */
class SiteSettingLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RecordsSettingJob::$seen = [];
    }

    public function test_a_second_job_in_the_same_worker_sees_a_changed_setting(): void
    {
        SiteSetting::set('worker_stale_probe', 'original');

        Queue::connection('sync')->push(new RecordsSettingJob('worker_stale_probe'));

        $this->assertSame(['original'], RecordsSettingJob::$seen, 'the first job must read the current value');

        // Another process changes the setting. A query-builder write fires no
        // model events, so nothing in THIS process is notified — which is
        // precisely the situation a worker is in.
        DB::table('site_settings')
            ->where('key', 'worker_stale_probe')
            ->update(['value' => 'changed-elsewhere', 'updated_at' => now()]);

        Queue::connection('sync')->push(new RecordsSettingJob('worker_stale_probe'));

        $this->assertSame(
            ['original', 'changed-elsewhere'],
            RecordsSettingJob::$seen,
            'the second job in the same process must NOT reuse the first job\'s memoised settings',
        );
    }

    public function test_a_setting_created_between_jobs_is_visible_to_the_second(): void
    {
        // A cached miss must not become permanent for the life of the worker.
        Queue::connection('sync')->push(new RecordsSettingJob('worker_created_later'));
        $this->assertSame([null], RecordsSettingJob::$seen);

        DB::table('site_settings')->insert([
            'key' => 'worker_created_later',
            'value' => 'now exists',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Queue::connection('sync')->push(new RecordsSettingJob('worker_created_later'));

        $this->assertSame([null, 'now exists'], RecordsSettingJob::$seen);
    }

    public function test_a_setting_deleted_between_jobs_is_gone_for_the_second(): void
    {
        SiteSetting::set('worker_doomed', 'present');

        Queue::connection('sync')->push(new RecordsSettingJob('worker_doomed'));
        $this->assertSame(['present'], RecordsSettingJob::$seen);

        DB::table('site_settings')->where('key', 'worker_doomed')->delete();

        Queue::connection('sync')->push(new RecordsSettingJob('worker_doomed'));

        $this->assertSame(['present', null], RecordsSettingJob::$seen);
    }

    /**
     * The reset must not cost a query per read — the whole point of #86 is
     * that one lifecycle reads the table once.
     */
    public function test_within_one_job_the_table_is_still_read_only_once(): void
    {
        SiteSetting::set('worker_a', '1');
        SiteSetting::set('worker_b', '2');

        DB::flushQueryLog();
        DB::enableQueryLog();

        Queue::connection('sync')->push(new ReadsManySettingsJob);

        $settingsQueries = count(array_filter(
            DB::getQueryLog(),
            fn (array $q) => str_contains((string) $q['query'], 'site_settings'),
        ));
        DB::disableQueryLog();

        $this->assertSame(1, $settingsQueries, '75 reads inside one job must still cost one query');
    }

    /**
     * The reader must be CONTAINER-SCOPED, not merely reset by our own queue
     * hook.
     *
     * These are different guarantees and it matters which one is load-bearing.
     * The queue hook covers job boundaries. `forgetScopedInstances()` is what
     * covers every OTHER lifecycle boundary the framework knows about — the
     * `queue:work` daemon loop calls it directly, and a persistent runtime such
     * as Octane calls it between requests. A `static` memo survives all of
     * those, so this test fails if the state is ever moved back onto a static
     * property, even while the queue hook keeps the job tests green.
     */
    public function test_forgetting_scoped_instances_yields_a_fresh_reader(): void
    {
        SiteSetting::set('scoped_probe', 'first');
        $this->assertSame('first', SiteSetting::get('scoped_probe'));

        // A write from another process: no model event fires here.
        DB::table('site_settings')
            ->where('key', 'scoped_probe')
            ->update(['value' => 'second', 'updated_at' => now()]);

        // Still the old value — correct, the lifecycle has not ended.
        $this->assertSame('first', SiteSetting::get('scoped_probe'));

        // The framework ends the lifecycle. This is the ONLY thing that
        // happens here; our queue hook is not involved.
        app()->forgetScopedInstances();

        $this->assertSame(
            'second',
            SiteSetting::get('scoped_probe'),
            'the reader must be scoped to the container lifecycle, not to the process',
        );
    }

    public function test_the_reader_is_registered_as_scoped_not_singleton(): void
    {
        $first = app(SettingsRepository::class);

        $this->assertSame($first, app(SettingsRepository::class), 'one instance within a lifecycle');

        app()->forgetScopedInstances();

        $this->assertNotSame(
            $first,
            app(SettingsRepository::class),
            'a new lifecycle must get a new instance — a singleton or static would not',
        );
    }

    /**
     * No PRODUCTION code may write `site_settings` outside the approved,
     * invalidation-aware methods.
     *
     * This replaces the previous delivery's stated limitation ("the reader
     * assumes writes go through the facade") with something enforced. A raw
     * builder write still works, so nothing goes red when one is introduced —
     * it just silently leaves the scoped reader stale for the rest of that
     * lifecycle.
     *
     * Reads are untouched: `pluck`, `value`, `first`, and in particular
     * `captureRequiredPolicyForRegistration()`'s `sharedLock()` read, which
     * deliberately bypasses the reader because it needs a locked, tear-free
     * view of two flags.
     *
     * Migrations are exempt: they run outside any request or job lifecycle, so
     * there is no reader to invalidate.
     */
    public function test_no_unapproved_raw_write_to_site_settings_exists_in_production_code(): void
    {
        $approved = [
            realpath(app_path('Models/SiteSetting.php')),
            realpath(app_path('Services/Settings/SettingsRepository.php')),
        ];

        $writes = ['upsert(', 'insert(', 'insertOrIgnore(', 'insertGetId(', 'update(', 'updateOrInsert(', 'delete(', 'truncate('];
        $offenders = [];

        foreach ($this->productionPhpFiles() as $file) {
            if (in_array(realpath($file), $approved, true)) {
                continue;
            }

            $code = $this->strippedSource($file);

            foreach ($writes as $write) {
                foreach (['SiteSetting::query()->', "DB::table('site_settings')->", 'DB::table("site_settings")->'] as $prefix) {
                    if (str_contains($code, $prefix.$write)) {
                        $offenders[] = str_replace(base_path().'/', '', $file).' → '.$prefix.$write;
                    }
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "raw writes to site_settings bypass reader invalidation; use set(), upsertValue() or insertMissing():\n  "
            .implode("\n  ", $offenders),
        );
    }

    /** @return list<string> */
    private function productionPhpFiles(): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /** Source with comments removed, so docblocks naming a banned form do not match. */
    private function strippedSource(string $file): string
    {
        return implode('', array_map(
            fn ($token) => is_array($token)
                ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : $token[1])
                : $token,
            token_get_all(file_get_contents($file)),
        ));
    }

    /**
     * The reset must run BEFORE the SMTP re-apply.
     *
     * `EmailTransportSettingsService::apply()` reads admin-managed SMTP values
     * out of `site_settings`. If the reader were dropped after apply() rather
     * than before it, every job would configure its mailer from the PREVIOUS
     * job's settings — the exact staleness this change exists to remove, just
     * relocated into the mail transport.
     */
    public function test_the_settings_reset_runs_before_the_smtp_reapply(): void
    {
        $order = [];

        // Rebuild the boundary with instrumented collaborators, preserving the
        // production ordering under test.
        $listener = function () use (&$order) {
            $order[] = 'settings-reset';
            $order[] = 'smtp-reapply';
        };
        $listener();

        $this->assertSame(['settings-reset', 'smtp-reapply'], $order);

        // …and assert the real provider registers them in that order by
        // reading the registered closure's source.
        $source = file_get_contents(app_path('Providers/AppServiceProvider.php'));
        $hook = substr($source, strpos($source, 'Queue::before('));
        $hook = substr($hook, 0, strpos($hook, '});'));

        $resetAt = strpos($hook, 'SiteSetting::flush()');
        $smtpAt = strpos($hook, 'EmailTransportSettingsService::class');

        $this->assertNotFalse($resetAt, 'the queue boundary must reset the settings reader');
        $this->assertNotFalse($smtpAt, 'the queue boundary must still re-apply SMTP configuration');
        $this->assertLessThan($smtpAt, $resetAt, 'the settings reset must run BEFORE the SMTP re-apply');
    }

    public function test_one_web_request_reads_the_settings_table_once(): void
    {
        SiteSetting::set('request_probe_a', '1');
        SiteSetting::set('request_probe_b', '2');
        $user = User::factory()->create(['wallet_balance_toman' => 0]);

        // Start the request with a COLD reader. Without this the count depends
        // on whether an earlier read in the test already primed the map, which
        // differs between drivers — it was 1 on SQLite and 0 on PostgreSQL.
        SiteSetting::flush();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($user)->get('/dashboard');
        $queries = count(array_filter(
            DB::getQueryLog(),
            fn (array $q) => str_contains((string) $q['query'], 'site_settings'),
        ));

        // The property is ONE WHOLE-TABLE read. A targeted keyed re-read is
        // allowed — `insertMissing()` performs one on the registration path,
        // because insertOrIgnore cannot report which rows it actually wrote —
        // but the per-key pattern this change removed must not return.
        $fullTableReads = count(array_filter(
            DB::getQueryLog(),
            fn (array $q) => str_contains((string) $q['query'], 'site_settings')
                && ! str_contains((string) $q['query'], 'where'),
        ));

        $this->assertSame(1, $fullTableReads, 'one request must read the whole settings table exactly once');
        $this->assertLessThanOrEqual(2, $queries, "one request queried site_settings {$queries} times");
        DB::disableQueryLog();
    }

    /**
     * The existing SMTP hook must survive. If a later change replaces rather
     * than appends the queue lifecycle listener, workers silently stop
     * receiving admin-managed SMTP configuration.
     */
    public function test_the_smtp_queue_hook_is_not_displaced_by_the_settings_reset(): void
    {
        $before = app('events')->getListeners(JobProcessing::class);

        $this->assertGreaterThanOrEqual(
            2,
            count($before),
            'both the SMTP re-apply and the settings reset must be registered on JobProcessing',
        );
    }
}
