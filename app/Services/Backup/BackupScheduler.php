<?php

namespace App\Services\Backup;

use App\Models\BackupLog;
use Illuminate\Support\Carbon;

/**
 * Decides whether an automatic backup is due. The cron entry runs every minute
 * (schedule:run → zedproxy:backup --scheduled) and this class — not the cron
 * expression — is the source of truth, so admins can change the mode/interval
 * without touching the server.
 *
 * Modes:
 *   fixed_time → once per day, at backup_schedule_time (HH:MM).
 *   interval   → every backup_interval_minutes after the last scheduled run.
 */
class BackupScheduler
{
    public function __construct(private readonly BackupSettings $settings) {}

    /** Is an automatic backup due right now? (Does NOT check the overlap lock.) */
    public function isDue(?Carbon $now = null): bool
    {
        $now = $now ?? now();

        if (! $this->settings->enabled() || ! $this->settings->autoEnabled()) {
            return false;
        }

        $last = $this->lastScheduledRunAt();

        if ($this->settings->scheduleMode() === BackupSettings::MODE_INTERVAL) {
            return $last === null
                || $last->lte($now->copy()->subMinutes($this->settings->intervalMinutes()));
        }

        // fixed_time: due once we pass today's slot and no scheduled run has
        // started at/after that slot yet.
        $slot = $this->todaySlot($now);
        return $now->gte($slot) && ($last === null || $last->lt($slot));
    }

    /** When the next automatic backup will run (null when auto backup is off). */
    public function nextRunAt(?Carbon $now = null): ?Carbon
    {
        $now = $now ?? now();

        if (! $this->settings->enabled() || ! $this->settings->autoEnabled()) {
            return null;
        }

        if ($this->settings->scheduleMode() === BackupSettings::MODE_INTERVAL) {
            $last = $this->lastScheduledRunAt();
            $next = $last?->copy()->addMinutes($this->settings->intervalMinutes()) ?? $now;
            return $next->lt($now) ? $now : $next;
        }

        $slot = $this->todaySlot($now);
        $last = $this->lastScheduledRunAt();
        if ($now->lt($slot)) {
            return $slot;
        }
        // Past today's slot: today's run either happened (→ tomorrow) or is due now.
        return ($last !== null && $last->gte($slot)) ? $slot->addDay() : $now;
    }

    /** Start time of the most recent scheduled (automatic) backup, any status. */
    public function lastScheduledRunAt(): ?Carbon
    {
        $last = BackupLog::where('type', BackupLog::TYPE_SCHEDULED)
            ->whereNotNull('started_at')
            ->latest('started_at')
            ->first();

        return $last?->started_at;
    }

    /** Today's fixed-time slot as a Carbon in the app timezone. */
    private function todaySlot(Carbon $now): Carbon
    {
        [$h, $m] = array_map('intval', explode(':', $this->settings->scheduleTime()));
        return $now->copy()->startOfDay()->addHours($h)->addMinutes($m);
    }
}
