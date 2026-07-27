<?php

namespace App\Services\Queue;

use App\Jobs\SendTelegramAdminMessageJob;
use App\Jobs\SendTelegramDocumentJob;
use App\Services\Telegram\TelegramAdminNotifier;
use App\Support\SecretMasker;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Alerts admins (via the existing Telegram notifier) when a queued job fails
 * TERMINALLY — i.e. Laravel raises JobFailed after retries are exhausted or the
 * job is explicitly marked failed. Intermediate retries never raise that event,
 * so they never alert.
 *
 * GUARANTEES:
 *   • Never throws: any cache/notifier/serialization problem inside this
 *     monitoring path is swallowed (with a positive-listed log line), so
 *     Laravel's own failed-job bookkeeping (failed_jobs row, the job's own
 *     failed() method) is never altered or suppressed.
 *   • No payload data: the alert context is built ONLY from positive-listed
 *     queue metadata (resolved class name, connection, queue, attempts,
 *     exception class). The serialized job command is never read, hashed or
 *     forwarded; raw exception messages are never included.
 *   • No recursion: failures of the Telegram transport jobs themselves are
 *     excluded — otherwise a Telegram outage would fail the delivery job and
 *     enqueue yet another delivery job for the alert about that failure.
 *   • Flood control: an unconditional 600-second dedup window per
 *     (job class, exception class, connection, queue) identity, applied
 *     BEFORE the notifier's own category throttling.
 */
class FailedJobAlerter
{
    public const EVENT_KEY = 'queue_job_failed';

    /** Unconditional dedup window (seconds) per failure identity. */
    public const DEDUP_WINDOW_SECONDS = 600;

    /**
     * Telegram transport jobs whose own terminal failure must never generate
     * another Telegram alert (delivery of that alert would fail the same way).
     *
     * @var list<class-string>
     */
    public const EXCLUDED_JOBS = [
        SendTelegramAdminMessageJob::class,
        SendTelegramDocumentJob::class,
    ];

    public function __construct(private readonly TelegramAdminNotifier $notifier) {}

    /** Handle a terminal job failure. NEVER throws. */
    public function handle(JobFailed $event): void
    {
        $fingerprint = null;

        try {
            $jobClass = (string) $event->job->resolveName();
            if (in_array($jobClass, self::EXCLUDED_JOBS, true)) {
                return;
            }

            $context = $this->context($event, $jobClass);
            $fingerprint = $context['fingerprint'];

            if (! $this->firstInWindow($fingerprint)) {
                return;
            }

            $this->notifier->event(self::EVENT_KEY, $context);
        } catch (\Throwable $e) {
            $this->safeLog('alert', 'listener_error', $e, $fingerprint);
        }
    }

    /**
     * The ONE positive-listed context used for the Telegram message, the
     * notification-log metadata and the dedup/fingerprint identity. Only
     * bounded scalars derived from queue METADATA — never from the serialized
     * payload, never a raw exception message.
     *
     * @return array{job:string,connection:string,queue:string,attempts:int,exception:string,fingerprint:string,failed_at:string}
     */
    private function context(JobFailed $event, string $jobClass): array
    {
        $exceptionClass = get_class($event->exception);
        $connection = (string) $event->connectionName;
        $queue = (string) ($event->job->getQueue() ?? '');

        return [
            'job' => SecretMasker::mask(class_basename($jobClass)),
            'connection' => SecretMasker::mask($connection),
            'queue' => SecretMasker::mask($queue),
            'attempts' => (int) $event->job->attempts(),
            'exception' => SecretMasker::mask(class_basename($exceptionClass)),
            'fingerprint' => $this->fingerprint($jobClass, $exceptionClass, $connection, $queue),
            'failed_at' => now()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * Short non-secret incident fingerprint AND dedup identity: derived only
     * from the positive-listed (job class, exception class, connection, queue)
     * tuple — deliberately NOT from the payload or a per-occurrence UUID, so
     * repeats collapse into one alert per window.
     */
    private function fingerprint(string $jobClass, string $exceptionClass, string $connection, string $queue): string
    {
        return substr(sha1($jobClass.'|'.$exceptionClass.'|'.$connection.'|'.$queue), 0, 12);
    }

    /** True if no identical failure alerted within the dedup window. */
    private function firstInWindow(string $fingerprint): bool
    {
        try {
            // Cache::add returns false when the key already exists → duplicate.
            return Cache::add('tg:qfail:'.$fingerprint, 1, self::DEDUP_WINDOW_SECONDS);
        } catch (\Throwable $e) {
            // Cache outage: fail OPEN (a duplicate alert beats silence); the
            // notifier's own throttling still bounds the flood.
            $this->safeLog('dedup', 'cache_unavailable', $e, $fingerprint);

            return true;
        }
    }

    /** Positive-listed monitoring log: stage, reason code, exception class, fingerprint. */
    private function safeLog(string $stage, string $reason, \Throwable $e, ?string $fingerprint): void
    {
        try {
            Log::warning('[queue-failure-monitor] '.$stage.' skipped', [
                'stage' => $stage,
                'reason' => $reason,
                'exception' => class_basename($e),
                'fingerprint' => $fingerprint,
            ]);
        } catch (\Throwable) {
            // Even the fallback log must never break failed-job handling.
        }
    }
}
