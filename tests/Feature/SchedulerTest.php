<?php

namespace Tests\Feature;

use App\Filament\Pages\SystemStatus;
use App\Models\SiteSetting;
use App\Models\User;
use App\Scheduling\ScheduleRegistrar;
use App\Support\SchedulerHeartbeat;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The Laravel scheduler is production-critical: a single cron drives every task,
 * a heartbeat proves it is running, and overlap locks must survive a Redis
 * outage. These tests cover the registrar, the heartbeat/status, timezone, and
 * the health/admin surfaces.
 */
class SchedulerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The heartbeat lives in the (real) file cache — clear it so each test
        // starts from a known "never run" state.
        SchedulerHeartbeat::clear();
    }

    protected function tearDown(): void
    {
        SchedulerHeartbeat::clear();
        parent::tearDown();
    }

    /** Build the schedule the way routes/console.php does, in isolation. */
    private function buildSchedule(): Schedule
    {
        $schedule = new Schedule;
        app(ScheduleRegistrar::class)($schedule);

        return $schedule;
    }

    /** @return array<int, string> registered command strings */
    private function scheduledCommands(Schedule $schedule): array
    {
        return array_map(fn ($e) => (string) $e->command, $schedule->events());
    }

    private function hasCommand(Schedule $schedule, string $needle): bool
    {
        foreach ($this->scheduledCommands($schedule) as $command) {
            if (str_contains($command, $needle)) {
                return true;
            }
        }

        return false;
    }

    // ── Heartbeat ─────────────────────────────────────────────────────────────

    public function test_heartbeat_records_and_reads_back_healthy(): void
    {
        $this->assertNull(SchedulerHeartbeat::lastRunAt());
        $this->assertFalse(SchedulerHeartbeat::isHealthy());

        SchedulerHeartbeat::record();

        $this->assertNotNull(SchedulerHeartbeat::lastRunAt());
        $this->assertTrue(SchedulerHeartbeat::isHealthy());
        $this->assertLessThanOrEqual(2, SchedulerHeartbeat::ageSeconds());
    }

    public function test_heartbeat_command_records(): void
    {
        $this->artisan('zedproxy:scheduler-heartbeat')->assertExitCode(0);
        $this->assertTrue(SchedulerHeartbeat::isHealthy());
    }

    // ── Scheduler failure (never run / stale) ─────────────────────────────────

    public function test_status_reports_failure_when_scheduler_never_ran(): void
    {
        $this->artisan('zedproxy:scheduler-status')
            ->expectsOutputToContain('وضعیت زمان‌بندی وظایف')
            ->expectsOutputToContain('زمان‌بندی وظایف به‌درستی اجرا نمی‌شود.')
            ->assertExitCode(1);
    }

    public function test_status_reports_failure_when_heartbeat_is_stale(): void
    {
        Cache::store('file')->forever(SchedulerHeartbeat::KEY, now()->subMinutes(30)->getTimestamp());

        $this->assertFalse(SchedulerHeartbeat::isHealthy());

        $this->artisan('zedproxy:scheduler-status')
            ->expectsOutputToContain('زمان‌بندی وظایف به‌درستی اجرا نمی‌شود.')
            ->assertExitCode(1);
    }

    public function test_status_reports_healthy_after_a_recent_run(): void
    {
        SchedulerHeartbeat::record();

        $this->artisan('zedproxy:scheduler-status')
            ->expectsOutputToContain('آخرین اجرای موفق')
            ->assertExitCode(0);
    }

    // ── Redis outage: heartbeat still works via the file store ────────────────

    public function test_heartbeat_survives_redis_outage(): void
    {
        // Simulate Redis being the default store while it is unavailable: the
        // heartbeat is pinned to the file store, so it must still record/read.
        config(['cache.default' => 'redis']);

        SchedulerHeartbeat::record();

        $this->assertTrue(SchedulerHeartbeat::isHealthy());
        $this->assertNotNull(SchedulerHeartbeat::lastRunAt());
    }

    // ── Application timezone ──────────────────────────────────────────────────

    public function test_status_json_reports_application_timezone(): void
    {
        config(['app.timezone' => 'Asia/Tehran']);
        SchedulerHeartbeat::record();

        $this->artisan('zedproxy:scheduler-status --json')
            ->expectsOutputToContain('Asia/Tehran')
            ->assertExitCode(0);
    }

    public function test_default_application_timezone_is_defined(): void
    {
        // config/app.php reads APP_TIMEZONE (defaults to UTC) — never empty.
        $this->assertNotEmpty(config('app.timezone'));
    }

    // ── Overlap locks + intended cache store ──────────────────────────────────

    public function test_every_scheduled_task_uses_without_overlapping(): void
    {
        SiteSetting::set('marzban_background_sync_enabled', true);
        SiteSetting::set('backup_enabled', true);
        SiteSetting::set('backup_auto_enabled', true);
        SiteSetting::set('telegram_admin_enabled', true);
        SiteSetting::set('daily_report_enabled', true);

        $schedule = $this->buildSchedule();
        $this->assertNotEmpty($schedule->events());

        foreach ($schedule->events() as $event) {
            $this->assertTrue(
                $event->withoutOverlapping,
                "scheduled task without overlap guard: {$event->command}",
            );
        }
    }

    public function test_scheduler_locks_use_the_file_store(): void
    {
        // The framework pins the scheduler mutex store from cache.schedule_store.
        $this->assertSame('file', config('cache.schedule_store'));

        // And the resolved schedule's event mutex reflects it, so overlap locks
        // survive a Redis outage.
        $schedule = app(Schedule::class);
        $ref = new \ReflectionObject($schedule);
        $prop = $ref->getProperty('eventMutex');
        $prop->setAccessible(true);
        $mutex = $prop->getValue($schedule);

        $mutexRef = new \ReflectionObject($mutex);
        if ($mutexRef->hasProperty('store')) {
            $storeProp = $mutexRef->getProperty('store');
            $storeProp->setAccessible(true);
            $this->assertSame('file', $storeProp->getValue($mutex));
        }
    }

    // ── Conditional task registration ─────────────────────────────────────────

    public function test_heartbeat_is_always_scheduled(): void
    {
        $this->assertTrue($this->hasCommand($this->buildSchedule(), 'zedproxy:scheduler-heartbeat'));
    }

    public function test_backup_scheduled_only_when_enabled(): void
    {
        $this->assertFalse($this->hasCommand($this->buildSchedule(), 'zedproxy:backup'));

        SiteSetting::set('backup_enabled', true);
        SiteSetting::set('backup_auto_enabled', true);

        $this->assertTrue($this->hasCommand($this->buildSchedule(), 'zedproxy:backup'));
    }

    public function test_backup_not_scheduled_when_auto_disabled(): void
    {
        SiteSetting::set('backup_enabled', true);
        SiteSetting::set('backup_auto_enabled', false);

        $this->assertFalse($this->hasCommand($this->buildSchedule(), 'zedproxy:backup'));
    }

    public function test_telegram_daily_report_scheduled_only_when_enabled(): void
    {
        $this->assertFalse($this->hasCommand($this->buildSchedule(), 'zedproxy:telegram-daily-report'));

        SiteSetting::set('telegram_admin_enabled', true);
        SiteSetting::set('daily_report_enabled', true);

        $this->assertTrue($this->hasCommand($this->buildSchedule(), 'zedproxy:telegram-daily-report'));
    }

    public function test_marzban_sync_and_panel_health_scheduled_only_when_enabled(): void
    {
        $off = $this->buildSchedule();
        $this->assertFalse($this->hasCommand($off, 'zedproxy:sync-marzban-services'));
        $this->assertFalse($this->hasCommand($off, 'zedproxy:check-marzban-panels'));

        SiteSetting::set('marzban_background_sync_enabled', true);

        $on = $this->buildSchedule();
        $this->assertTrue($this->hasCommand($on, 'zedproxy:sync-marzban-services'));
        $this->assertTrue($this->hasCommand($on, 'zedproxy:check-marzban-panels'));
    }

    // ── Health + admin surfaces ───────────────────────────────────────────────

    public function test_health_command_json_includes_scheduler_status(): void
    {
        SchedulerHeartbeat::record();

        // Exit code reflects infra health (Redis is down in tests → 1); we only
        // assert the scheduler block is present in the diagnostics output.
        $this->artisan('zedproxy:health --json')
            ->expectsOutputToContain('scheduler');
    }

    public function test_admin_system_status_shows_scheduler_panel(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'username' => 'sched_admin']);
        SchedulerHeartbeat::record();

        Livewire::actingAs($admin)->test(SystemStatus::class)
            ->assertSuccessful()
            ->assertSee('وضعیت زمان‌بندی وظایف')
            ->assertSee('آخرین اجرای موفق');
    }

    public function test_admin_system_status_flags_a_down_scheduler(): void
    {
        $admin = User::factory()->create(['is_admin' => true, 'username' => 'sched_admin2']);
        // No heartbeat recorded → down.

        Livewire::actingAs($admin)->test(SystemStatus::class)
            ->assertSuccessful()
            ->assertSee('زمان‌بندی وظایف به‌درستی اجرا نمی‌شود.');
    }
}
