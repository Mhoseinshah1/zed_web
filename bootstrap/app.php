<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trust all reverse proxies (Nginx, Cloudflare, load balancers, etc.) so that
        // Laravel generates correct https:// URLs. Without this, Livewire's update URI
        // resolves to http:// while the page is served over https://, causing the browser
        // to block the mixed-content XHR — the form then falls back to a native POST
        // to /zed-admin/login (GET-only) and the server returns 405.
        $middleware->trustProxies(at: '*');

        // NOWPayments IPN webhook must bypass CSRF — signature verified via HMAC-SHA512
        $middleware->validateCsrfTokens(except: [
            'webhooks/nowpayments',
        ]);

        // Gate sensitive purchase actions behind a completed profile (phone).
        $middleware->alias([
            'profile.complete' => \App\Http\Middleware\EnsureProfileComplete::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
