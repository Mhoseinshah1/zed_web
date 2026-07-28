<?php

use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\EnsureProfileComplete;
use App\Http\Middleware\EnsureSessionAuthVersion;
use App\Http\Middleware\NoIndexHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Stateless health probes — registered OUTSIDE the `web` group so they
        // never start a session or set session/XSRF cookies (see routes/health.php).
        then: function (): void {
            Route::group([], __DIR__.'/../routes/health.php');
        },
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
            TrustProxies::class,
            App\Http\Middleware\TrustProxies::class,
        );

        // NOWPayments IPN webhook must bypass CSRF — signature verified via HMAC-SHA512.
        // Telegram admin-bot webhook bypasses CSRF — verified by the secret-token header.
        $middleware->validateCsrfTokens(except: [
            'webhooks/nowpayments',
            'telegram/webhook',
        ]);

        // Password-hash-bound sessions for the whole web surface: the session
        // stamps the user's current password hash (lazily for sessions that
        // predate this middleware — they stay valid until the first
        // credential change) and every later request re-verifies it, so a
        // password reset immediately revokes EVERY other authenticated
        // session and remember-me login on every device. The Redis session
        // driver cannot enumerate sessions per user; the password hash acts
        // as the account's monotonic credential version instead. The Filament
        // panel already runs its own AuthenticateSession.
        $middleware->web(append: [
            AuthenticateSession::class,
            // Authoritative monotonic session-revocation check (auth_version):
            // unstamped pre-deployment sessions are adopted only while the
            // account is on the initial version; any advancement (password
            // reset) fails them closed. AuthenticateSession above stays as a
            // second, hash-bound layer for legacy password-change paths.
            EnsureSessionAuthVersion::class,
        ]);

        // Gate sensitive purchase actions behind a completed profile (phone).
        $middleware->alias([
            'profile.complete' => EnsureProfileComplete::class,
            'noindex' => NoIndexHeaders::class,
            'email.verified' => EnsureEmailIsVerified::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
