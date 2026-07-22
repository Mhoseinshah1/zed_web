<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds `X-Robots-Tag: noindex, nofollow, noarchive` to responses for private and
 * sensitive routes (dashboard, admin, payments, callbacks, webhooks, health,
 * auth) so they can never be indexed even if a crawler reaches them directly.
 */
class NoIndexHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        return $response;
    }
}
