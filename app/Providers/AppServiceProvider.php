<?php

namespace App\Providers;

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
        //
    }
}
