<?php

namespace App\Providers;

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

        // Email-verification endpoints: keyed by BOTH the authenticated user
        // id AND the client IP, so neither a distributed attack on one account
        // nor one machine cycling accounts can bypass the limit.
        $emailVerificationKey = function (Request $request): string {
            $userId = (string) ($request->user()?->getAuthIdentifier() ?? 'guest');

            return $userId.'|'.(string) $request->ip();
        };
        RateLimiter::for('email-verification-verify', fn (Request $request) => Limit::perMinute(10)->by('evv:'.$emailVerificationKey($request)));
        RateLimiter::for('email-verification-resend', fn (Request $request) => Limit::perMinutes(10, 5)->by('evr:'.$emailVerificationKey($request)));
        RateLimiter::for('email-verification-change', fn (Request $request) => Limit::perMinutes(10, 5)->by('evc:'.$emailVerificationKey($request)));
        RateLimiter::for('email-test-send', fn (Request $request) => Limit::perMinutes(10, 3)->by('ets:'.$emailVerificationKey($request)));
    }
}
