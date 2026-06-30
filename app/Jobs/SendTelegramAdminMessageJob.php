<?php

namespace App\Jobs;

use App\Models\TelegramAdminNotificationLog;
use App\Models\TelegramAdminTopic;
use App\Services\Telegram\TelegramClient;
use App\Services\Telegram\TelegramSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers one admin Telegram message on the redis queue. Retries with backoff;
 * an exhausted/failed delivery only marks the log row — it never affects any
 * business flow (this runs out-of-band).
 */
class SendTelegramAdminMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int   $tries   = 3;
    public array $backoff = [10, 30, 90];

    public function __construct(private int $logId) {}

    public function handle(TelegramClient $client, TelegramSettings $settings): void
    {
        $log = TelegramAdminNotificationLog::find($this->logId);
        if (! $log || $log->status !== TelegramAdminNotificationLog::STATUS_PENDING) {
            return; // already processed or gone
        }

        $result = $client->sendMessage(
            text: $log->message,
            messageThreadId: $log->message_thread_id,
            chatId: $log->chat_id ?: null,
            silent: $settings->silent(),
        );

        $log->update([
            'status'              => TelegramAdminNotificationLog::STATUS_SENT,
            'telegram_message_id' => $result['message_id'] ?: null,
            'sent_at'             => now(),
            'error'               => null,
        ]);

        if ($topic = TelegramAdminTopic::findByKey($log->topic_key)) {
            $topic->update(['last_sent_at' => now(), 'last_error' => null]);
        }
    }

    public function failed(\Throwable $e): void
    {
        $log = TelegramAdminNotificationLog::find($this->logId);
        if ($log) {
            $log->update([
                'status'    => TelegramAdminNotificationLog::STATUS_FAILED,
                'failed_at' => now(),
                'error'     => mb_substr($e->getMessage(), 0, 500),
            ]);
            if ($topic = TelegramAdminTopic::findByKey($log->topic_key)) {
                $topic->update(['last_error' => mb_substr($e->getMessage(), 0, 500)]);
            }
        }
    }
}
