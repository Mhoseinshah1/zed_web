<?php

namespace App\Providers;

use App\Services\AdminMfa\AdminMfaSession;
use App\Services\Auth\PasswordResetService;
use App\Services\Email\EmailTransportSettingsService;
use App\Services\Queue\FailedJobAlerter;
use App\Services\Seo\SeoManager;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One SeoManager per request: controllers enrich it and <x-seo-head>
        // renders the resolved SeoData exactly once.
        $this->app->scoped(SeoManager::class);

        // ONE resolver instance per process: it memoizes the last-applied
        // managed-SMTP configuration version, so a long-running queue worker
        // purges/reconfigures only when the configuration actually changed.
        $this->app->singleton(EmailTransportSettingsService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Admin-managed SMTP: apply the EFFECTIVE mail configuration for this
        // process (panel override → dedicated managed_smtp mailer; disabled →
        // untouched .env config; enabled-but-invalid → fail closed). Runs at
        // bootstrap for web/console AND before every queued job, so already-
        // running workers adopt a panel change before their next job without
        // any restart or config cache rebuild. apply() is version-memoized —
        // an unchanged configuration is a no-op with no mailer purge.
        //
        // Deliberately SKIPPED while `config:cache` builds its snapshot: that
        // command boots the app to serialize the config repository to disk,
        // and applying the override there would bake the decrypted SMTP
        // credentials into bootstrap/cache/config.php. Every real boot
        // re-applies at runtime, so the cached file never needs (and never
        // gets) the managed values.
        if (! $this->buildingConfigCache()) {
            app(EmailTransportSettingsService::class)->apply();
        }
        Queue::before(function () {
            app(EmailTransportSettingsService::class)->apply();
        });

        // TERMINAL queue-job failures (retries exhausted / explicitly failed):
        // alert admins on Telegram. Registration stays minimal — classification,
        // sanitization, recursion exclusion and the 600s dedup all live in
        // FailedJobAlerter, which never throws, so Laravel's own failed-job
        // handling (failed_jobs row, the job's failed() method) is untouched.
        // Runs on normal boot, so long-running workers pick it up without any
        // config-cache rebuild.
        Queue::failing(function (JobFailed $event) {
            try {
                app(FailedJobAlerter::class)->handle($event);
            } catch (\Throwable) {
                // Monitoring must never alter the worker's failure handling.
            }
        });

        // Password-hash-bound sessions (AuthenticateSession in the web group):
        // stamp the CURRENT credential hash into the session on every
        // successful login — normal, registration auto-login, and remember-me
        // — so a fresh login is immediately bound, and a login that replaces
        // a previous user's stamp in the same session never trips a false
        // logout. Sessions predating this middleware carry no stamp and are
        // adopted lazily by the middleware itself (documented compatibility:
        // they stay valid until the first credential change).
        Event::listen(Login::class, function ($event): void {
            $request = request();
            if (! $request->hasSession()) {
                return;
            }
            $hash = (string) $event->user->getAuthPassword();
            try {
                $hash = Auth::guard('web')->hashPasswordForCookie($hash);
            } catch (\BadMethodCallException) {
                // Older guard API: store the raw hash (middleware accepts both).
            }
            $request->session()->put('password_hash_web', $hash);
        });

        // Lightweight rate limit for the public health probes — enough for
        // orchestrators/monitors, low enough to blunt probing/abuse.
        RateLimiter::for('health', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));

        // Purchase idempotency: intent (form/token) issuance and order submission
        // are rate-limited SEPARATELY, keyed by the authenticated user (falling
        // back to IP for guests), so a burst of double-clicks/retries is blunted
        // in addition to the server-side idempotency guarantee.
        RateLimiter::for('purchase-intent', fn (Request $request) => Limit::perMinute(30)->by(
            $request->user()?->getAuthIdentifier() ?: $request->ip()
        ));
        RateLimiter::for('purchase-submit', fn (Request $request) => Limit::perMinute(12)->by(
            $request->user()?->getAuthIdentifier() ?: $request->ip()
        ));

        // Email-verification endpoints: TWO independent buckets per limiter —
        // one per authenticated user (followed across IPs, so a distributed
        // attack on one account is still capped) and one per client IP (so one
        // machine cycling accounts is still capped). A concatenated user|IP
        // key would mint a fresh bucket for every pair and enforce neither.
        $emailVerificationLimits = function (Request $request, string $prefix, int $decayMinutes, int $maxAttempts): array {
            $ip = (string) $request->ip();
            $userKey = (string) ($request->user()?->getAuthIdentifier() ?? 'ip:'.$ip);

            return [
                Limit::perMinutes($decayMinutes, $maxAttempts)->by($prefix.':u:'.$userKey),
                Limit::perMinutes($decayMinutes, $maxAttempts)->by($prefix.':ip:'.$ip),
            ];
        };
        RateLimiter::for('email-verification-verify', fn (Request $request) => $emailVerificationLimits($request, 'evv', 1, 10));
        RateLimiter::for('email-verification-resend', fn (Request $request) => $emailVerificationLimits($request, 'evr', 10, 5));
        RateLimiter::for('email-verification-change', fn (Request $request) => $emailVerificationLimits($request, 'evc', 10, 5));
        RateLimiter::for('email-test-send', fn (Request $request) => $emailVerificationLimits($request, 'ets', 10, 3));

        // Administrator MFA challenges: three INDEPENDENT limiters (password
        // attempts use Filament's own per-component bucket) so throttling one
        // stage never masks or opens another. Subject = the pending-login
        // user id from the server session (never client input), falling back
        // to the authenticated admin, then IP — plus a separate per-IP
        // bucket, mirroring the email-verification two-bucket policy.
        $adminMfaLimits = function (Request $request, string $prefix, int $decayMinutes, int $maxAttempts): array {
            $ip = (string) $request->ip();
            $pending = $request->hasSession()
                ? $request->session()->get(AdminMfaSession::PENDING_KEY)
                : session()->get(AdminMfaSession::PENDING_KEY);
            $subject = is_array($pending) && isset($pending['user_id'])
                ? (string) $pending['user_id']
                : (string) ($request->user()?->getAuthIdentifier() ?? 'ip:'.$ip);

            return [
                Limit::perMinutes($decayMinutes, $maxAttempts)->by($prefix.':u:'.$subject),
                Limit::perMinutes($decayMinutes, $maxAttempts * 3)->by($prefix.':ip:'.$ip),
            ];
        };
        // Password reset: independent two-bucket limiters per stage. The
        // SUBJECT is never a raw identifier — the submitted email/phone (for
        // the request stage) or the opaque session challenge token (for the
        // verify/submit stages) is reduced to a non-reversible APP_KEY-keyed
        // HMAC fingerprint before it becomes a cache key.
        $passwordResetLimits = function (Request $request, string $prefix, int $decayMinutes, int $maxAttempts): array {
            $ip = (string) $request->ip();
            $raw = (string) ($request->input('identifier')
                ?? ($request->hasSession() ? $request->session()->get(PasswordResetService::SESSION_TOKEN_KEY) : null)
                ?? 'ip:'.$ip);
            $subject = hash_hmac('sha256', $raw, (string) config('app.key'));

            return [
                Limit::perMinutes($decayMinutes, $maxAttempts)->by($prefix.':s:'.$subject),
                Limit::perMinutes($decayMinutes, $maxAttempts * 3)->by($prefix.':ip:'.$ip),
            ];
        };
        RateLimiter::for('password-reset-request', fn (Request $request) => $passwordResetLimits($request, 'pwrr', 15, 3));
        RateLimiter::for('password-reset-verify', fn (Request $request) => $passwordResetLimits($request, 'pwrv', 10, 10));
        RateLimiter::for('password-reset-submit', fn (Request $request) => $passwordResetLimits($request, 'pwrs', 10, 5));

        RateLimiter::for('admin-totp', fn (Request $request) => $adminMfaLimits($request, 'atotp', 1, 5));
        RateLimiter::for('admin-recovery', fn (Request $request) => $adminMfaLimits($request, 'arec', 10, 5));
        // Consumed MANUALLY inside the sensitive-settings pages (Livewire
        // actions bypass route middleware) — same two-bucket shape.
        RateLimiter::for('admin-step-up', fn (Request $request) => $adminMfaLimits($request, 'astep', 1, 5));
    }

    /**
     * Whether THIS boot is part of `config:cache` building its serialized
     * snapshot (the command bootstraps a console kernel in-process — argv is
     * the only reliable signal, and it also covers the fresh sub-application
     * the command creates, which shares the process argv).
     */
    private function buildingConfigCache(): bool
    {
        return $this->app->runningInConsole()
            && in_array($_SERVER['argv'][1] ?? '', ['config:cache', 'optimize'], true);
    }
}
