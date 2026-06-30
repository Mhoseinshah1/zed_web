{{--
    Declarative appearance variables for /zed-admin.

    Rendered into the Filament <head> on EVERY admin page (see
    AdminPanelProvider). Emits both the global colour palette (from the active
    preset + brand overrides) and the admin sizing variables (from admin_density
    + admin_sidebar_size). Values are written straight into CSS custom
    properties, so the saved settings apply with no dependency on JavaScript.
    Injected after the base token stylesheet so these declarations win.
--}}
@php($admin = \App\Services\Theme\AdminAppearanceResolver::resolve())
@php($colors = \App\Services\Theme\AppearanceManager::colorVars())
<style id="zedproxy-appearance-vars">
:root, html, body, .fi-body {
@foreach ($colors as $name => $value){{ $name }}: {{ $value }};
@endforeach
@foreach ($admin['vars'] as $name => $value){{ $name }}: {{ $value }};
@endforeach
}
/* Brand display modes (admin_brand_display). */
@if($admin['brand_display'] === 'logo')
.fi-sidebar-header .fi-logo:not(:has(img)){ font-size:0 !important; }
@elseif($admin['brand_display'] === 'text')
.fi-sidebar-header .fi-logo img{ display:none !important; }
@endif
</style>
