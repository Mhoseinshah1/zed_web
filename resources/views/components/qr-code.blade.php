@props(['value' => null, 'label' => null, 'size' => 200])

@if($value)
<div class="text-center">
    @if($label)
    <p class="text-xs text-gray-400 mb-2">{{ $label }}</p>
    @endif
    <div class="bg-white p-3 rounded-xl inline-block">
        {!! QrCode::format('svg')->size($size)->errorCorrection('M')->generate($value) !!}
    </div>
</div>
@endif
