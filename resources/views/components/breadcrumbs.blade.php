{{--
    Visible breadcrumb trail. Reads the SAME items the SeoManager uses to build
    the BreadcrumbList JSON-LD, so the visible trail and structured data always
    match. Renders nothing when there is no trail. RTL-friendly.
--}}
@php
    use App\Services\Seo\SeoManager;
    $items = app(SeoManager::class)->getBreadcrumbs();
@endphp
@if(count($items) > 1)
<nav aria-label="مسیر" class="zp-breadcrumbs text-sm text-content-muted py-3">
    <ol class="flex flex-wrap items-center gap-x-2 gap-y-1" dir="rtl">
        @foreach($items as $i => $item)
            <li class="flex items-center gap-x-2">
                @if(!empty($item['url']) && $i < count($items) - 1)
                    <a href="{{ $item['url'] }}" class="hover:text-content transition-colors">{{ $item['name'] }}</a>
                    <span class="text-content-muted/60" aria-hidden="true">/</span>
                @else
                    <span class="text-content" aria-current="page">{{ $item['name'] }}</span>
                @endif
            </li>
        @endforeach
    </ol>
</nav>
@endif
