<?php

namespace App\Jobs;

use App\Models\BackupLog;
use App\Services\Backup\BackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs a server backup out-of-band (so /backup and the manual button return
 * immediately). BackupService never throws, so this job can't break anything.
 */
class RunBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 1;
    public int $timeout = 1800;

    public function __construct(private string $type = BackupLog::TYPE_MANUAL) {}

    public function handle(BackupService $service): void
    {
        $service->run($this->type);
    }
}
