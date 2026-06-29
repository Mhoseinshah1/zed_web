@php $banners = $banners ?? collect(); @endphp
@if($banners instanceof \Illuminate\Support\Collection ? $banners->isNotEmpty() : count($banners))
<div class="space-y-3">
    @foreach($banners as $banner)
        @php
            $style = $banner->theme_style ?? 'primary';
            $bg = match($style) {
                'gradient' => 'zed-gradient-bg',
                'accent'   => 'bg-cyan-500/15 border-cyan-500/30',
                'success'  => 'bg-green-500/10 border-green-500/30',
                'warning'  => 'bg-amber-500/10 border-amber-500/30',
                'danger'   => 'bg-red-500/10 border-red-500/30',
                default    => 'bg-indigo-600/15 border-indigo-500/30',
            };
            $bgImage = cms_asset_url($banner->background_image);
        @endphp
        <div class="zed-card zed-animate overflow-hidden border {{ $bg }} p-5 flex flex-col sm:flex-row sm:items-center gap-4"
             @if($bgImage) style="background-image:linear-gradient(rgba(0,0,0,.55),rgba(0,0,0,.55)),url('{{ $bgImage }}');background-size:cover;background-position:center" @endif>
            @if($img = cms_asset_url($banner->image))
                <img src="{{ $img }}" alt="{{ $banner->title }}" class="h-14 w-14 rounded-xl object-cover shrink-0">
            @endif
            <div class="min-w-0 flex-1">
                @if($banner->title)<p class="font-bold text-white">{{ $banner->title }}</p>@endif
                @if($banner->subtitle)<p class="text-sm text-gray-300 mt-0.5">{{ $banner->subtitle }}</p>@endif
                @if($banner->description)<p class="text-xs text-gray-400 mt-1">{{ $banner->description }}</p>@endif
            </div>
            @if($banner->button_text && $banner->button_url)
                <a href="{{ $banner->button_url }}" class="zed-btn zed-btn-primary px-4 py-2 text-sm font-semibold shrink-0">{{ $banner->button_text }}</a>
            @endif
        </div>
    @endforeach
</div>
@endif
