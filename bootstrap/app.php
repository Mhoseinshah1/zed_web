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
        // Trust only the reverse proxies configured in config/proxies.php
        // (TRUSTED_PROXIES / TRUST_CLOUDFLARE_PROXIES) instead of every source.
        // Forwarded headers — including X-Forwarded-Proto, which keeps HTTPS URL
        // generation, Livewire, Filament and signed URLs working behind TLS
        // termination — are honoured only when the immediate peer is a trusted
        // proxy, so a directly reachable origin can no longer be tricked into
        // trusting a spoofed X-Forwarded-For and bypassing IP rate limiting.
        $middleware->replace(
            \Illuminate\Http\Middleware\TrustProxies::class,
            \App\Http\Middleware\TrustProxies::class,
        );

        // NOWPayments IPN webhook must bypass CSRF — signature verified via HMAC-SHA512.
        // Telegram admin-bot webhook bypasses CSRF — verified by the secret-token header.
        $middleware->validateCsrfTokens(except: [
            'webhooks/nowpayments',
            'telegram/webhook',
        ]);

        // Gate sensitive purchase actions behind a completed profile (phone).
        $middleware->alias([
            'profile.complete' => \App\Http\Middleware\EnsureProfileComplete::class,
            'noindex'          => \App\Http\Middleware\NoIndexHeaders::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
