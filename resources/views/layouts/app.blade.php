@php
    use App\Services\Theme\ThemeManager;
    use App\Services\Theme\TemplateManager;

    // The active homepage template now drives the site-wide shell (header/footer)
    // for every page that extends layouts.app. Classic is the default and is
    // byte-identical to the previous behaviour.
    $tpl = TemplateManager::activeTemplate();

    $zedTheme = ThemeManager::resolveTheme(ThemeManager::SURFACE_PUBLIC, auth()->user());
    // The matrix template is inherently dark (green-on-black); keep it dark
    // regardless of the light/dark toggle. Every other template flips normally.
    $zedAppearance = $tpl === 'matrix'
        ? 'dark'
        : ThemeManager::resolveAppearance(auth()->user());
    $tplHeader = view()->exists("templates.$tpl.header") ? "templates.$tpl.header" : 'templates.classic.header';
    $tplFooter = view()->exists("templates.$tpl.footer") ? "templates.$tpl.footer" : 'templates.classic.footer';
@endphp
<!DOCTYPE html>
<html lang="fa" dir="rtl" class="scroll-smooth {{ ThemeManager::htmlClassFor($zedTheme, $zedAppearance) }}"
      data-theme="{{ $zedTheme }}" data-appearance="{{ $zedAppearance }}"
      style="{{ ThemeManager::inlineStyle() }}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    {{-- Centralized SEO: title, description, keywords, robots, canonical,
         Open Graph, Twitter and JSON-LD are all rendered once here from the
         request-scoped SeoManager (see App\Services\Seo\SeoManager). --}}
    <x-seo-head />

    @if($fav = cms_image('favicon'))<link rel="icon" href="{{ $fav }}">@endif
    <script>{!! ThemeManager::noFoucScript($zedAppearance) !!}</script>

    <!-- Fonts: Vazirmatn — self-hosted woff2 (declared in app.css, served from
         our own origin; no third-party render-blocking request). Preload the
         two critical weights only. -->
    @production
        <link rel="preload" href="{{ Vite::asset('resources/fonts/Vazirmatn-Regular.woff2') }}" as="font" type="font/woff2" crossorigin>
        <link rel="preload" href="{{ Vite::asset('resources/fonts/Vazirmatn-Bold.woff2') }}" as="font" type="font/woff2" crossorigin>
    @endproduction

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    {{-- Optional per-template head styles (e.g. matrix canvas styling). --}}
    @includeIf("templates.$tpl.styles")
    @stack('styles')
</head>
<body class="bg-base text-content antialiased" data-template="{{ $tpl }}">

    {{-- Optional per-template body prefix (e.g. matrix code-rain canvas). --}}
    @includeIf("templates.$tpl.body_top")

    {{-- Site-wide header for the active template. --}}
    @include($tplHeader)

    <!-- Main content -->
    <main class="relative z-10">
        @yield('content')
    </main>

    {{-- Site-wide footer for the active template. --}}
    @include($tplFooter)

    {{-- Optional per-template body suffix (e.g. mobile-menu toggle, matrix JS). --}}
    @includeIf("templates.$tpl.body_bottom")
    @include('partials.double-submit-script')
    @stack('scripts')
</body>
</html>
