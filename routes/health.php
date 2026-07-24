<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Stateless health probes
|--------------------------------------------------------------------------
|
| Registered in bootstrap/app.php via withRouting(then: …) so they are NOT in
| the `web` middleware group — no EncryptCookies / StartSession / CSRF stack.
| The deployer hits /health every few seconds through the loopback vhost, and
| uptime monitors hit it constantly; these probes must never start a session or
| set session / XSRF cookies. Only rate limiting + the non-indexable header are
| applied. Responses are safe booleans only (see HealthController).
|
*/

Route::middleware(['noindex', 'throttle:health'])->group(function (): void {
    Route::get('/health', [HealthController::class, 'check'])->name('health');
    Route::get('/health/live', [HealthController::class, 'live'])->name('health.live');
});
