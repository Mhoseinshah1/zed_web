{{--
    Shared CMS logo markup for every public template header/footer.

    - Header usage passes eager=true: logos are above the fold and paint first
      (loading="eager" + fetchpriority="high"; NEVER lazy).
    - Footer usage omits eager: below the fold → loading="lazy" decoding="async".
    - Dimensions come from cms_image() and are unknown at render time, so no
      width/height attributes are fabricated — space is reserved by each
      template's existing height/min-height container styles.
--}}
<img src="{{ $src }}"
    alt="{{ site_setting('site_name', 'ZedProxy') }}"
    class="{{ $class ?? 'h-8 w-auto' }}"
    @if(!empty($style)) style="{{ $style }}" @endif
    @if(!empty($eager)) loading="eager" fetchpriority="high" @else loading="lazy" decoding="async" @endif>
