{{--
    Central SEO head — renders each applicable tag exactly once from the
    request-scoped SeoManager. No duplicate tags, no empty tags, every attribute
    HTML-escaped. JSON-LD is emitted with </script>-injection protection.

    This is the ONLY place these tags are produced; public views must not
    re-declare title/description/og/twitter tags.
--}}
@php
    use App\Services\Seo\SeoManager;
    use App\Services\Seo\SeoSettings;

    /** @var \App\Support\Seo\SeoData $seo */
    $seo = app(SeoManager::class)->resolve();
@endphp
<title>{{ $seo->title }}</title>
@if($seo->description !== '')
<meta name="description" content="{{ $seo->description }}">
@endif
@if($seo->keywords !== '')
<meta name="keywords" content="{{ $seo->keywords }}">
@endif
<meta name="robots" content="{{ $seo->robots() }}">
@if($seo->canonical !== '')
<link rel="canonical" href="{{ $seo->canonical }}">
@endif

{{-- Open Graph --}}
@if($seo->ogTitle !== '')
<meta property="og:title" content="{{ $seo->ogTitle }}">
@endif
@if($seo->ogDescription !== '')
<meta property="og:description" content="{{ $seo->ogDescription }}">
@endif
<meta property="og:type" content="{{ $seo->ogType }}">
@if($seo->ogUrl !== '')
<meta property="og:url" content="{{ $seo->ogUrl }}">
@endif
@if($seo->siteName !== '')
<meta property="og:site_name" content="{{ $seo->siteName }}">
@endif
<meta property="og:locale" content="{{ $seo->locale }}">
@if($seo->ogImage !== '')
<meta property="og:image" content="{{ $seo->ogImage }}">
<meta property="og:image:alt" content="{{ $seo->ogImageAlt }}">
@endif

{{-- Twitter / X --}}
<meta name="twitter:card" content="{{ $seo->twitterCard }}">
@if($seo->twitterSite !== '')
<meta name="twitter:site" content="{{ $seo->twitterSite }}">
@endif
@if($seo->twitterTitle !== '')
<meta name="twitter:title" content="{{ $seo->twitterTitle }}">
@endif
@if($seo->twitterDescription !== '')
<meta name="twitter:description" content="{{ $seo->twitterDescription }}">
@endif
@if($seo->twitterImage !== '')
<meta name="twitter:image" content="{{ $seo->twitterImage }}">
@endif

{{-- Search-engine + verification meta (public tokens only, no secrets) --}}
@if(($gsc = SeoSettings::get('seo_google_verification')) !== '')
<meta name="google-site-verification" content="{{ $gsc }}">
@endif
@if(($bing = SeoSettings::get('seo_bing_verification')) !== '')
<meta name="msvalidate.01" content="{{ $bing }}">
@endif

{{-- JSON-LD structured data — safe encoding; </script> can never break out. --}}
@foreach($seo->schemas as $schema)
<script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>
@endforeach
