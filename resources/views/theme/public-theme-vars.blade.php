{{--
    Optional declarative public/user appearance variables.

    The public site and user dashboard already inject these tokens inline via
    ThemeManager::inlineStyle() in their layouts, so this partial is provided as
    a reusable alternative (e.g. for embeds) rather than wired into those
    layouts — keeping the current, working user styling untouched. It only emits
    the shared --zp-* tokens and never the admin-only --zp-admin-* ones.
--}}
<style id="zedproxy-public-theme-vars">
:root { {!! \App\Services\Theme\UserAppearanceResolver::cssDeclarations() !!} }
</style>
