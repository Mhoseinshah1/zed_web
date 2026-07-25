{{--
    Google Analytics (GA4) — emitted ONLY when ALL of the following hold:
      * a measurement ID is configured and matches the strict GA4 format
        (validated again at render time; the value is escaped on output),
      * the app runs in production (never on local/testing/staging), and
      * the current route does NOT carry the `noindex` middleware
        (admin/dashboard/payment/auth pages are never measured).
--}}
@php
    use App\Http\Middleware\NoIndexHeaders;
    use App\Services\Seo\SeoSettings;

    $gaId = trim((string) SeoSettings::get('seo_google_analytics_id'));

    $routeMiddleware = request()->route()?->gatherMiddleware() ?? [];
    $isNoindexRoute = in_array('noindex', $routeMiddleware, true)
        || in_array(NoIndexHeaders::class, $routeMiddleware, true);

    $emitGa = $gaId !== ''
        && preg_match('/^G-[A-Z0-9]{4,20}$/', $gaId) === 1
        && app()->environment('production')
        && ! $isNoindexRoute;
@endphp
@if($emitGa)
<script async src="https://www.googletagmanager.com/gtag/js?id={{ $gaId }}"></script>
<script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
    gtag('config', '{{ $gaId }}');
</script>
@endif
