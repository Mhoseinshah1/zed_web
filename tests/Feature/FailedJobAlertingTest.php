<?php

namespace Tests\Feature;

use App\Jobs\SendTelegramAdminMessageJob;
use App\Jobs\SendTelegramDocumentJob;
use App\Models\SiteSetting;
use App\Models\TelegramAdminNotificationLog;
use App\Models\TelegramAdminTopic;
use App\Models\TelegramTemplate;
use App\Services\Queue\FailedJobAlerter;
use App\Services\Telegram\TelegramAdminNotifier;
use App\Services\Telegram\TelegramSettings;
use App\Services\Telegram\TelegramTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\Job as BaseJob;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Terminal queue-failure alerting: one safe, deduplicated Telegram alert per
 * (job class, exception class, connection, queue) identity per 600 seconds,
 * with NO payload/secret data and NO recursion through the Telegram transport
 * jobs — and never any interference with Laravel's own failed-job handling.
 */
class FailedJobAlertingTest extends TestCase
{
    use RefreshDatabase;

    public const PAYLOAD_CANARY = 'ZP_CANARY_PAYLOAD_5f3759df';

    private const MESSAGE_CANARY = 'ZP_CANARY_EXCEPTION_password=supersecret';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush(); // dedup + notifier throttle both use the cache
        AlwaysFailingTestJob::$attempts = 0;
    }

    private function configureBot(): void
    {
        SiteSetting::set('telegram_admin_enabled', 'true');
        app(TelegramSettings::class)->storeToken('123456:TEST-TOKEN');
        SiteSetting::set('telegram_admin_chat_id', '-1001234567890');
        TelegramAdminTopic::seedDefaults();
    }

    /** Fire a terminal JobFailed event, as the queue worker would. */
    private function failJob(
        string $displayName = 'App\\Jobs\\ProvisionMarzbanServiceJob',
        ?\Throwable $exception = null,
        string $connection = 'redis',
        string $queue = 'default',
        int $attempts = 3,
    ): void {
        $job = new FakeTerminalJob($displayName, $attempts, $connection, $queue);
        event(new JobFailed($connection, $job, $exception ?? new RuntimeException(self::MESSAGE_CANARY)));
    }

    private function alertLogs(): Collection
    {
        return TelegramAdminNotificationLog::where('event_key', FailedJobAlerter::EVENT_KEY)->get();
    }

    // ── Alert creation, routing, content ────────────────────────────────────

    public function test_terminal_failure_creates_one_alert_on_errors_topic_with_expected_fields(): void
    {
        Queue::fake();
        $this->configureBot();
        TelegramAdminTopic::findByKey('errors')->update(['message_thread_id' => 77]);

        $this->failJob();

        $logs = $this->alertLogs();
        $this->assertCount(1, $logs);
        $log = $logs->first();

        $this->assertSame('queue_job_failed', $log->event_key);
        $this->assertSame('errors', $log->topic_key);
        $this->assertSame(77, $log->message_thread_id);
        $this->assertSame(TelegramAdminNotificationLog::STATUS_PENDING, $log->status);

        $expectedFingerprint = substr(sha1(
            'App\\Jobs\\ProvisionMarzbanServiceJob|RuntimeException|redis|default'
        ), 0, 12);

        // Message: job class basename, connection, queue, attempts, exception
        // class basename and the incident fingerprint.
        $this->assertStringContainsString('ProvisionMarzbanServiceJob', $log->message);
        $this->assertStringContainsString('redis', $log->message);
        $this->assertStringContainsString('default', $log->message);
        $this->assertStringContainsString('3', $log->message);
        $this->assertStringContainsString('RuntimeException', $log->message);
        $this->assertStringContainsString($expectedFingerprint, $log->message);

        // Metadata: same positive-listed bounded scalars.
        $this->assertSame('ProvisionMarzbanServiceJob', $log->metadata['job']);
        $this->assertSame('redis', $log->metadata['connection']);
        $this->assertSame('default', $log->metadata['queue']);
        $this->assertSame(3, $log->metadata['attempts']);
        $this->assertSame('RuntimeException', $log->metadata['exception']);
        $this->assertSame($expectedFingerprint, $log->metadata['fingerprint']);

        Queue::assertPushed(SendTelegramAdminMessageJob::class, 1);
    }

    public function test_alert_contains_no_payload_or_secret_data(): void
    {
        Queue::fake();
        $this->configureBot();

        $this->failJob();

        $log = $this->alertLogs()->firstOrFail();
        $haystack = $log->message.' '.$log->title.' '.json_encode($log->metadata);

        // The serialized payload body must never be read or forwarded.
        $this->assertStringNotContainsString(self::PAYLOAD_CANARY, $haystack);
        // Raw exception messages are omitted entirely.
        $this->assertStringNotContainsString('supersecret', $haystack);
        $this->assertStringNotContainsString('ZP_CANARY_EXCEPTION', $haystack);
        $this->assertStringNotContainsString('password', $haystack);
        // No full class paths / namespaces leak either (basenames only).
        $this->assertStringNotContainsString('App\\Jobs\\', $log->message);
    }

    // ── 600-second deduplication ────────────────────────────────────────────

    public function test_identical_failures_inside_600_seconds_alert_once(): void
    {
        Queue::fake();
        $this->configureBot();
        // Array store: TTLs follow Carbon test-time, so travel() is exact
        // (a real Redis TTL runs on wall-clock and can't be time-traveled).
        config(['cache.default' => 'array']);

        $this->failJob();
        $this->travel(599)->seconds();
        $this->failJob();

        $this->assertCount(1, $this->alertLogs());
        Queue::assertPushed(SendTelegramAdminMessageJob::class, 1);
    }

    public function test_same_failure_after_601_seconds_alerts_again(): void
    {
        Queue::fake();
        $this->configureBot();
        config(['cache.default' => 'array']); // see the inside-600s test

        $this->failJob();
        $this->travel(601)->seconds();
        $this->failJob();

        $this->assertCount(2, $this->alertLogs());
        Queue::assertPushed(SendTelegramAdminMessageJob::class, 2);
    }

    public function test_different_job_or_exception_classes_are_not_deduplicated(): void
    {
        Queue::fake();
        $this->configureBot();

        $this->failJob('App\\Jobs\\ProvisionMarzbanServiceJob', new RuntimeException('x'));
        $this->failJob('App\\Jobs\\RunBackupJob', new RuntimeException('x'));
        $this->failJob('App\\Jobs\\ProvisionMarzbanServiceJob', new \LogicException('x'));
        $this->failJob('App\\Jobs\\ProvisionMarzbanServiceJob', new RuntimeException('x'), queue: 'high');

        $this->assertCount(4, $this->alertLogs());
    }

    // ── Recursion prevention ────────────────────────────────────────────────

    public function test_failed_telegram_message_transport_job_creates_no_alert(): void
    {
        Queue::fake();
        $this->configureBot();

        $this->failJob(SendTelegramAdminMessageJob::class);

        $this->assertCount(0, $this->alertLogs());
        Queue::assertNothingPushed();
    }

    public function test_failed_telegram_document_transport_job_creates_no_alert(): void
    {
        Queue::fake();
        $this->configureBot();

        $this->failJob(SendTelegramDocumentJob::class);

        $this->assertCount(0, $this->alertLogs());
        Queue::assertNothingPushed();
    }

    // ── Robustness: unconfigured bot, cache/notifier outages ────────────────

    public function test_unconfigured_bot_follows_notifier_semantics_without_throwing(): void
    {
        Queue::fake();
        // No bot configuration at all; only the topics exist.
        TelegramAdminTopic::seedDefaults();

        $this->failJob();

        $log = $this->alertLogs()->firstOrFail();
        $this->assertSame(TelegramAdminNotificationLog::STATUS_SKIPPED, $log->status);
        Queue::assertNothingPushed();
    }

    public function test_cache_failure_inside_listener_never_throws(): void
    {
        $this->configureBot();
        Queue::fake();
        Cache::shouldReceive('add')->andThrow(new RuntimeException('cache down'));

        $this->failJob(); // must not throw — Laravel's failure handling continues

        $this->addToAssertionCount(1);
    }

    public function test_notifier_failure_inside_listener_never_throws(): void
    {
        $this->configureBot();
        $broken = $this->createMock(TelegramAdminNotifier::class);
        $broken->method('event')->willThrowException(new RuntimeException('notifier down'));
        $this->app->instance(TelegramAdminNotifier::class, $broken);

        $this->failJob(); // must not throw

        $this->addToAssertionCount(1);
    }

    // ── Template behavior on existing installations ─────────────────────────

    public function test_builtin_fallback_works_with_no_template_row(): void
    {
        Queue::fake();
        $this->configureBot();
        $this->assertDatabaseMissing('telegram_templates', ['key' => 'queue_job_failed']);

        $this->failJob();

        $log = $this->alertLogs()->firstOrFail();
        $this->assertStringContainsString('شکست نهایی جاب صف', $log->message);
        $this->assertStringContainsString('ProvisionMarzbanServiceJob', $log->message);
    }

    public function test_seeding_defaults_never_overwrites_admin_edited_template(): void
    {
        TelegramTemplate::create([
            'key' => 'queue_job_failed',
            'title' => 'Custom title',
            'message' => 'ADMIN EDITED {job} {fingerprint}',
            'is_active' => true,
            'available_variables' => '{job}, {fingerprint}',
        ]);

        app(TelegramTemplates::class)->seedDefaults();

        $row = TelegramTemplate::findByKey('queue_job_failed');
        $this->assertSame('ADMIN EDITED {job} {fingerprint}', $row->message);
        $this->assertSame(1, TelegramTemplate::where('key', 'queue_job_failed')->count());

        // And the alert renders through the admin-edited template.
        Queue::fake();
        $this->configureBot();
        $this->failJob();
        $this->assertStringContainsString('ADMIN EDITED ProvisionMarzbanServiceJob', $this->alertLogs()->firstOrFail()->message);
    }

    public function test_seeding_defaults_inserts_missing_queue_failure_template(): void
    {
        app(TelegramTemplates::class)->seedDefaults();

        $row = TelegramTemplate::findByKey('queue_job_failed');
        $this->assertNotNull($row);
        $this->assertStringContainsString('{fingerprint}', $row->message);
    }

    // ── Worker-level integration: terminal exhaustion only ──────────────────

    public function test_worker_alerts_on_terminal_exhaustion_not_on_intermediate_retry(): void
    {
        $this->configureBot();
        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 5]], 200)]);
        config(['queue.default' => 'database']);

        AlwaysFailingTestJob::dispatch();
        $this->assertSame(1, DB::table('jobs')->count());

        // Attempt 1 of 2: released for retry — NO alert, no failed_jobs row.
        app('queue.worker')->runNextJob('database', 'default', new WorkerOptions(sleep: 0));
        $this->assertSame(1, AlwaysFailingTestJob::$attempts);
        $this->assertCount(0, $this->alertLogs());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertSame(1, DB::table('jobs')->count()); // released, still queued

        // Drain through the real queue:work command: attempt 2 is terminal —
        // one alert, Laravel's own failed_jobs persistence still happens, and
        // the alert-delivery job itself runs against the faked Telegram API.
        $this->artisan('queue:work', [
            'connection' => 'database',
            '--stop-when-empty' => true,
            '--sleep' => 0,
        ])->run();

        $this->assertSame(2, AlwaysFailingTestJob::$attempts);
        $logs = $this->alertLogs();
        $this->assertCount(1, $logs);
        $this->assertSame('AlwaysFailingTestJob', $logs->first()->metadata['job']);
        $this->assertSame(2, $logs->first()->metadata['attempts']);
        $this->assertSame(1, DB::table('failed_jobs')->count());

        // The notification stays canary-free even though the exception message
        // and payload carried one.
        $this->assertStringNotContainsString(AlwaysFailingTestJob::ERROR, $logs->first()->message);
        $this->assertStringNotContainsString('abc123', $logs->first()->message.json_encode($logs->first()->metadata));
    }
}

/**
 * A minimal terminal-failure Job double: exposes exactly the queue METADATA the
 * listener is allowed to touch, while the raw body carries a canary that must
 * never surface in any alert or log row.
 */
class FakeTerminalJob extends BaseJob implements JobContract
{
    public function __construct(
        private string $displayName,
        private int $attemptCount,
        string $connectionName,
        string $queue,
    ) {
        $this->container = app();
        $this->connectionName = $connectionName;
        $this->queue = $queue;
    }

    public function getJobId(): string
    {
        return (string) Str::uuid();
    }

    public function getRawBody(): string
    {
        return json_encode([
            'uuid' => (string) Str::uuid(),
            'displayName' => $this->displayName,
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'maxTries' => $this->attemptCount,
            'attempts' => $this->attemptCount,
            'data' => [
                'commandName' => $this->displayName,
                'command' => FailedJobAlertingTest::PAYLOAD_CANARY,
            ],
        ]);
    }

    public function attempts(): int
    {
        return $this->attemptCount;
    }
}

/** Always throws; two tries with no backoff so the worker retries once. */
class AlwaysFailingTestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public const ERROR = 'ZP_CANARY_WORKER_secret=abc123';

    public static int $attempts = 0;

    public int $tries = 2;

    public function handle(): void
    {
        self::$attempts++;

        throw new RuntimeException(self::ERROR);
    }
}
