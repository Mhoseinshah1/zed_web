<?php

namespace App\Providers;

use App\Services\AdminMfa\AdminMfaSession;
use App\Services\Seo\SeoManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
        RateLimiter::for('admin-totp', fn (Request $request) => $adminMfaLimits($request, 'atotp', 1, 5));
        RateLimiter::for('admin-recovery', fn (Request $request) => $adminMfaLimits($request, 'arec', 10, 5));
        // Consumed MANUALLY inside the sensitive-settings pages (Livewire
        // actions bypass route middleware) — same two-bucket shape.
        RateLimiter::for('admin-step-up', fn (Request $request) => $adminMfaLimits($request, 'astep', 1, 5));
    }
}
