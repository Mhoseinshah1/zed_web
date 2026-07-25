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
    /**
     * Request attribute carrying the noindex verdict. Set BEFORE the view
     * renders so SeoManager can force noindex/nofollow regardless of SeoPage
     * database records — routes like /login work even on an unseeded install.
     */
    public const REQUEST_ATTRIBUTE = 'seo.forced_noindex';

    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::REQUEST_ATTRIBUTE, true);

        $response = $next($request);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');

        return $response;
    }
}
