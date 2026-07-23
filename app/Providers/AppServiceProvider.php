<?php

namespace App\Providers;

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
        $this->app->scoped(\App\Services\Seo\SeoManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Lightweight rate limit for the public health probes — enough for
        // orchestrators/monitors, low enough to blunt probing/abuse.
        RateLimiter::for('health', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));
    }
}
