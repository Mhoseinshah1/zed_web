<?php

namespace App\Jobs;

use App\Models\BackupLog;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Uploads a file (backup archive) to the management group on the redis queue.
 * A failure only logs — it never affects the backup itself.
 */
class SendTelegramDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int   $tries   = 2;
    public array $backoff = [30, 120];

    public function __construct(
        private string $filePath,
        private ?string $caption = null,
        private ?int $threadId = null,
        private ?int $backupLogId = null,
    ) {}

    public function handle(TelegramClient $client): void
    {
        $client->sendDocument($this->filePath, $this->caption, $this->threadId);

        if ($this->backupLogId && $log = BackupLog::find($this->backupLogId)) {
            $log->update(['sent_to_telegram' => true]);
        }
    }

    public function failed(\Throwable $e): void
    {
        TelegramSettings::safeLog('backup file upload failed', ['error' => class_basename($e)]);
    }
}
