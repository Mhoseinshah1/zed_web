@php
    use App\Services\Theme\TemplateManager;
    $activeTemplate = TemplateManager::activeTemplate();
    $templateLayout = match ($activeTemplate) {
        'modern' => 'layouts.modern',
        'shop'   => 'layouts.shop',
        'matrix' => 'layouts.matrix',
        default  => 'layouts.app',
    };
@endphp
{{-- Homepage template dispatcher: chooses the layout + body for the active
     homepage template (classic | modern | shop | matrix). The colour theme is
     handled separately by ThemeManager and applies to all templates. --}}
@extends($templateLayout)

@section('title', site_setting('home_meta_title') ?: 'خانه')
@section('description', site_setting('home_meta_description', site_setting('hero_description', 'خرید VPN و پروکسی با کیفیت بالا، سرعت فوق‌العاده و قیمت مناسب - ZedProxy')))
@if($k = site_setting('home_meta_keywords'))@section('meta_keywords', $k)@endif
@if($ot = site_setting('home_og_title'))@section('og_title', $ot)@endif
@if($od = site_setting('home_og_description'))@section('og_description', $od)@endif
@if($oi = cms_image('home_og_image'))@section('og_image', $oi)@endif

@section('content')
    @include('templates.' . $activeTemplate . '.home')
@endsection
