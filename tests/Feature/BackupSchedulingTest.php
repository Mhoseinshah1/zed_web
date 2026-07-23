<?php

namespace Tests\Feature;

use App\Models\BackupLog;
use App\Models\SiteSetting;
use App\Services\Backup\BackupScheduler;
use App\Services\Backup\BackupService;
use App\Services\Backup\BackupSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BackupSchedulingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Http::fake(); // no real Telegram calls
    }

    private function scheduler(): BackupScheduler
    {
        return app(BackupScheduler::class);
    }

    // ── Master switches ──────────────────────────────────────────────────────

    public function test_manual_command_skips_when_backup_disabled(): void
    {
        SiteSetting::set('backup_enabled', 'false');

        $this->artisan('zedproxy:backup --manual')
            ->expectsOutputToContain('سیستم بکاپ در حال حاضر غیرفعال است.')
            ->assertExitCode(0);

        $this->assertSame(0, BackupLog::count()); // nothing ran
    }

    public function test_scheduled_command_skips_when_auto_disabled(): void
    {
        SiteSetting::set('backup_enabled', 'true');
        SiteSetting::set('backup_auto_enabled', 'false');

        $this->artisan('zedproxy:backup --scheduled')->assertExitCode(0);
        $this->assertSame(0, BackupLog::count());
    }

    // ── fixed_time mode ──────────────────────────────────────────────────────

    public function test_fixed_time_is_due_after_slot_and_only_once_per_day(): void
    {
        SiteSetting::set('backup_enabled', 'true');
        SiteSetting::set('backup_auto_enabled', 'true');
        SiteSetting::set('backup_schedule_mode', BackupSettings::MODE_FIXED_TIME);
        SiteSetting::set('backup_schedule_time', '03:00');

        // Before the slot → not due.
        $this->assertFalse($this->scheduler()->isDue(now()->startOfDay()->addHours(2)));

        // After the slot with no run today → due.
        $at = now()->startOfDay()->addHours(4);
        $this->assertTrue($this->scheduler()->isDue($at));

        // After a scheduled run at/after the slot → no longer due today.
        BackupLog::create(['type' => BackupLog::TYPE_SCHEDULED, 'status' => BackupLog::STATUS_SUCCESS, 'started_at' => now()->startOfDay()->addHours(3)]);
        $this->assertFalse($this->scheduler()->isDue($at));

        // Next run is tomorrow's slot.
        $this->assertSame(
            now()->startOfDay()->addDay()->addHours(3)->format('Y-m-d H:i'),
            $this->scheduler()->nextRunAt($at)->format('Y-m-d H:i'),
        );
    }

    // ── interval mode ────────────────────────────────────────────────────────

    public function test_interval_mode_every_five_minutes(): void
    {
        SiteSetting::set('backup_enabled', 'true');
        SiteSetting::set('backup_auto_enabled', 'true');
        SiteSetting::set('backup_schedule_mode', BackupSettings::MODE_INTERVAL);
        SiteSetting::set('backup_interval_minutes', '5');

        // Never ran → due immediately.
        $this->assertTrue($this->scheduler()->isDue());

        // Ran 2 minutes ago → not due; 6 minutes ago → due.
        $log = BackupLog::create(['type' => BackupLog::TYPE_SCHEDULED, 'status' => BackupLog::STATUS_SUCCESS, 'started_at' => now()->subMinutes(2)]);
        $this->assertFalse($this->scheduler()->isDue());

        $log->update(['started_at' => now()->subMinutes(6)]);
        $this->assertTrue($this->scheduler()->isDue());
    }

    public function test_interval_is_clamped_to_minimum(): void
    {
        SiteSetting::set('backup_interval_minutes', '1'); // below the 5-minute floor
        $this->assertSame(5, app(BackupSettings::class)->intervalMinutes());
    }

    // ── Overlap protection ───────────────────────────────────────────────────

    public function test_backup_does_not_overlap(): void
    {
        SiteSetting::set('backup_enabled', 'true');

        // Simulate a running backup holding the lock.
        $lock = Cache::lock(BackupService::LOCK_NAME, 60);
        $this->assertTrue($lock->get());

        $result = app(BackupService::class)->run(BackupLog::TYPE_MANUAL);

        $this->assertSame(BackupService::STATUS_SKIPPED_LOCKED, $result['status']);
        $this->assertSame('یک عملیات بکاپ دیگر در حال اجرا است.', $result['error']);
        $this->assertSame(0, BackupLog::count()); // no log row for a skipped run

        $lock->release();
    }

    // ── Scheduled end-to-end: due interval run actually creates a backup ─────

    public function test_scheduled_interval_run_executes_when_due(): void
    {
        $tmp = sys_get_temp_dir().'/zpbk_sched_'.uniqid();
        @mkdir($tmp, 0777, true);

        SiteSetting::set('backup_enabled', 'true');
        SiteSetting::set('backup_auto_enabled', 'true');
        SiteSetting::set('backup_schedule_mode', BackupSettings::MODE_INTERVAL);
        SiteSetting::set('backup_interval_minutes', '5');
        SiteSetting::set('backup_storage_path', $tmp);
        SiteSetting::set('backup_include_database', 'false'); // no pg_dump in tests
        SiteSetting::set('backup_include_storage', 'true');

        $this->artisan('zedproxy:backup --scheduled')->assertExitCode(0);

        $this->assertDatabaseHas('backup_logs', ['type' => BackupLog::TYPE_SCHEDULED, 'status' => BackupLog::STATUS_SUCCESS]);

        // Immediately after, a second scheduled run is NOT due.
        $this->artisan('zedproxy:backup --scheduled')->assertExitCode(0);
        $this->assertSame(1, BackupLog::count());

        @exec('rm -rf '.escapeshellarg($tmp));
    }
}
