{{--
    Shared font preloads for the SELF-HOSTED Vazirmatn faces (public app layout
    + user panel layout only — never Filament). Preload ONLY the two weights
    the primary Persian UI paints first (400 body, 700 headings/buttons);
    preloading more would compete with render-critical resources.
--}}
<link rel="preload" href="{{ Vite::asset('resources/fonts/vazirmatn/Vazirmatn-33.0.3-Regular.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="{{ Vite::asset('resources/fonts/vazirmatn/Vazirmatn-33.0.3-Bold.woff2') }}" as="font" type="font/woff2" crossorigin>
