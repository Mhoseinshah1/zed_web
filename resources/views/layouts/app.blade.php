@php
    use App\Services\Theme\ThemeManager;
    use App\Services\Theme\TemplateManager;

    $zedTheme      = ThemeManager::resolveTheme(ThemeManager::SURFACE_PUBLIC, auth()->user());
    $zedAppearance = ThemeManager::resolveAppearance(auth()->user());

    // The active homepage template now drives the site-wide shell (header/footer)
    // for every page that extends layouts.app. Classic is the default and is
    // byte-identical to the previous behaviour.
    $tpl = TemplateManager::activeTemplate();
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
    <title>@yield('title', site_setting('site_title', 'ZedProxy')) | سرویس VPN و پروکسی</title>
    <meta name="description" content="@yield('description', site_setting('site_description', 'خرید VPN و پروکسی با کیفیت بالا، سرعت فوق‌العاده و قیمت مناسب'))">
    @hasSection('meta_keywords')<meta name="keywords" content="@yield('meta_keywords')">@endif
    {{-- Open Graph --}}
    <meta property="og:title" content="@yield('og_title', site_setting('site_title', 'ZedProxy'))">
    <meta property="og:description" content="@yield('og_description', site_setting('site_description', ''))">
    <meta property="og:type" content="website">
    @hasSection('og_image')<meta property="og:image" content="@yield('og_image')">@endif
    @if($fav = cms_image('favicon'))<link rel="icon" href="{{ $fav }}">@endif
    <script>{!! ThemeManager::noFoucScript($zedAppearance) !!}</script>

    <!-- Fonts: Vazirmatn (Persian) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Vazirmatn:wght@300;400;500;600;700;800;900&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        body { font-family: 'Vazirmatn', system-ui, sans-serif; }
    </style>
    {{-- Optional per-template head styles (e.g. matrix canvas styling). --}}
    @includeIf("templates.$tpl.styles")
    @stack('styles')
</head>
<body class="bg-gray-950 text-gray-100 antialiased" data-template="{{ $tpl }}">

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
    @stack('scripts')
</body>
</html>
