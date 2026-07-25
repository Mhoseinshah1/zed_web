{{--
    Shared font preloads for the SELF-HOSTED Vazirmatn faces (public app layout
    + user panel layout only — never Filament). Preload ONLY the Arabic/Persian
    subset of the two weights the primary Persian UI paints first (400 body,
    700 headings/buttons). The Latin subset and other weights are never
    preloaded — they load on demand via unicode-range.
--}}
<link rel="preload" href="{{ Vite::asset('resources/fonts/vazirmatn/Vazirmatn-33.0.3-Regular-arabic.woff2') }}" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="{{ Vite::asset('resources/fonts/vazirmatn/Vazirmatn-33.0.3-Bold-arabic.woff2') }}" as="font" type="font/woff2" crossorigin>
