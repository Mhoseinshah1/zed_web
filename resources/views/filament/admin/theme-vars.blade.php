{{--
    Declarative admin appearance variables for /zed-admin.

    Rendered into the Filament <head> on EVERY admin page (see
    AdminPanelProvider). The resolved, clamped, database-driven values are
    written straight into CSS custom properties — so the saved Theme Studio
    settings apply with no dependency on JavaScript. Injected after the base
    token stylesheet so these declarations win the cascade.

    Scoped to admin only; it is never included on the user dashboard / website.
--}}
@php($zp = \App\Services\Theme\AdminAppearanceResolver::resolve())
<style id="zedproxy-admin-theme-vars">
:root, html, body, .fi-body {
@foreach ($zp['vars'] as $name => $value){{ $name }}: {{ $value }};
@endforeach
}
/* Font scale — scale the document root so every rem-based Filament size follows.
   Applied once on <html>; do not re-scale .fi-body or text would compound. */
html { font-size: calc(100% * var(--zp-admin-font-scale, 1)); }
</style>
