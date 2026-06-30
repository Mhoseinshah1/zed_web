<?php

namespace App\Services\Telegram;

use App\Jobs\SendTelegramAdminMessageJob;
use App\Models\TelegramAdminNotificationLog;
use App\Models\TelegramAdminTopic;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * The admin Telegram notifier — the single entry point used across the app.
 *
 * GUARANTEES:
 *   • Non-blocking: {@see event()} and {@see send()} never throw, so a Telegram
 *     problem can never break a payment/order/service flow.
 *   • Async: the actual API call runs in {@see SendTelegramAdminMessageJob} on
 *     the redis queue.
 *   • No secrets: only safe, escaped summaries are ever passed in.
 *   • Auditable: every attempt records a row in telegram_admin_notification_logs.
 */
class TelegramAdminNotifier
{
    /** event key => [topic key, category]. */
    private const EVENT_MAP = [
        'order_created'          => ['sales', 'sales'],
        'order_paid'             => ['sales', 'sales'],
        'order_failed'           => ['sales', 'sales'],
        'payment_success'        => ['payments', 'payments'],
        'payment_failed'         => ['payments', 'payments'],
        'payment_duplicate'      => ['payments', 'payments'],
        'wallet_topup'           => ['wallet', 'wallet'],
        'wallet_payment'         => ['wallet', 'wallet'],
        'wallet_adjustment'      => ['wallet', 'wallet'],
        'ticket_created'         => ['tickets', 'tickets'],
        'ticket_replied'         => ['tickets', 'tickets'],
        'user_registered'        => ['users', 'users'],
        'user_phone_verified'    => ['users', 'users'],
        'service_provisioned'    => ['services', 'services'],
        'service_renewed'        => ['services', 'services'],
        'service_failed'         => ['errors', 'errors'],
        'service_sync_failed'    => ['errors', 'errors'],
        'panel_down'             => ['panels', 'panels'],
        'panel_recovered'        => ['panels', 'panels'],
        'panel_auth_failed'      => ['panels', 'panels'],
        'representative_request' => ['representatives', 'representatives'],
        'admin_change'           => ['admin', 'admin'],
        'system_alert'           => ['system', 'system'],
    ];

    /** Noisy categories that are throttled harder when rate-limiting is on. */
    private const NOISY = ['panels', 'services', 'errors', 'system'];

    public function __construct(
        private readonly TelegramSettings $settings,
        private readonly TelegramTemplates $templates,
    ) {}

    /**
     * High-level, fully fire-and-forget entry point: resolve the topic/category
     * for an event, render its template and queue the send. NEVER throws.
     */
    public function event(string $eventKey, array $context = [], Model|int|null $related = null): void
    {
        try {
            [$topicKey] = self::EVENT_MAP[$eventKey] ?? [null];
            if ($topicKey === null) {
                return;
            }
            [$title, $message] = $this->templates->render($eventKey, $context);
            $this->send($eventKey, $topicKey, $title, $message, $related, $this->scalarOnly($context));
        } catch (\Throwable $e) {
            TelegramSettings::safeLog('event() failed', ['event' => $eventKey, 'error' => class_basename($e)]);
        }
    }

    /**
     * Lower-level send: run the gate checks, dedupe/throttle, write a log row and
     * queue the actual delivery. Returns the log row (or null on hard skip).
     * NEVER throws.
     */
    public function send(
        string $eventKey,
        string $topicKey,
        string $title,
        string $message,
        Model|int|null $related = null,
        array $metadata = [],
    ): ?TelegramAdminNotificationLog {
        try {
            $category = self::EVENT_MAP[$eventKey][1] ?? $topicKey;
            [$relatedType, $relatedId] = $this->relatedRef($related);

            // ── Gate checks → log skipped/muted and return ──────────────────
            if (! $this->settings->enabled() || ! $this->settings->hasToken() || $this->settings->chatId() === '') {
                return $this->log($eventKey, $topicKey, $title, $message, $relatedType, $relatedId, $metadata, TelegramAdminNotificationLog::STATUS_SKIPPED, 'bot disabled or not configured');
            }
            if (! $this->settings->categoryEnabled($category)) {
                return $this->log($eventKey, $topicKey, $title, $message, $relatedType, $relatedId, $metadata, TelegramAdminNotificationLog::STATUS_MUTED, 'category disabled');
            }

            $topic = TelegramAdminTopic::findByKey($topicKey);
            if ($topic === null || ! $topic->is_active) {
                return $this->log($eventKey, $topicKey, $title, $message, $relatedType, $relatedId, $metadata, TelegramAdminNotificationLog::STATUS_MUTED, 'topic inactive', $topic?->message_thread_id);
            }

            // ── Dedupe / throttle for repeated alerts ───────────────────────
            if ($this->throttled($eventKey, $category, $relatedType, $relatedId, $message)) {
                return $this->log($eventKey, $topicKey, $title, $message, $relatedType, $relatedId, $metadata, TelegramAdminNotificationLog::STATUS_MUTED, 'throttled duplicate', $topic->message_thread_id);
            }

            // ── Enqueue ─────────────────────────────────────────────────────
            $log = $this->log(
                $eventKey, $topicKey, $title, $message, $relatedType, $relatedId, $metadata,
                TelegramAdminNotificationLog::STATUS_PENDING, null,
                $topic->message_thread_id, $topic->chat_id ?: $this->settings->chatId(),
            );

            SendTelegramAdminMessageJob::dispatch($log->id);

            return $log;
        } catch (\Throwable $e) {
            TelegramSettings::safeLog('send() failed', ['event' => $eventKey, 'error' => class_basename($e)]);
            return null;
        }
    }

    /** True if this exact event+related repeats within its throttle window. */
    private function throttled(string $eventKey, string $category, ?string $relatedType, ?int $relatedId, string $message): bool
    {
        // Critical categories always send unless an EXACT duplicate appears in a
        // very short window (guards double IPN/callbacks). Noisy categories are
        // throttled for longer when rate-limiting is enabled.
        $isNoisy = in_array($category, self::NOISY, true);
        $window  = ($isNoisy && $this->settings->rateLimitDuplicates()) ? 600 : 15;

        $ref = $relatedType && $relatedId ? "{$relatedType}:{$relatedId}" : 'm:' . substr(sha1($message), 0, 12);
        $key = "tg:dd:{$eventKey}:{$ref}";

        // Cache::add returns false if the key already exists → duplicate.
        return ! Cache::add($key, 1, $window);
    }

    /** Persist a log row. */
    private function log(
        string $eventKey, string $topicKey, string $title, string $message,
        ?string $relatedType, ?int $relatedId, array $metadata,
        string $status, ?string $error = null,
        ?int $threadId = null, ?string $chatId = null,
    ): TelegramAdminNotificationLog {
        return TelegramAdminNotificationLog::create([
            'event_key'         => $eventKey,
            'topic_key'         => $topicKey,
            'chat_id'           => $chatId,
            'message_thread_id' => $threadId,
            'title'             => $title,
            'message'           => $message,
            'status'            => $status,
            'error'             => $error,
            'related_type'      => $relatedType,
            'related_id'        => $relatedId,
            'metadata'          => $metadata ?: null,
        ]);
    }

    /** @return array{0:?string,1:?int} */
    private function relatedRef(Model|int|null $related): array
    {
        if ($related instanceof Model) {
            return [$related->getMorphClass(), (int) $related->getKey()];
        }
        if (is_int($related)) {
            return [null, $related];
        }
        return [null, null];
    }

    private function scalarOnly(array $context): array
    {
        return array_filter($context, fn ($v) => $v === null || is_scalar($v));
    }
}
