{{-- ============================================================================
     Map template — user-panel accent layer.
     Defines the shared --zp-tpl-accent* variables (read by .zp-user-panel in the
     user panel) with this template's own colour — cyan — scoped to
     [data-template="map"] so it never leaks into another template. Chrome and
     structure are untouched; on the public site these variables are simply unused.
     ============================================================================ --}}
<style>
    [data-template="map"] {
        --zp-tpl-accent:       #22d3ee;
        --zp-tpl-accent-hover: #06b6d4;
        --zp-tpl-accent-2:     #67e8f9;
        --zp-tpl-accent-soft:  rgba(34, 211, 238, .10);
        --zp-tpl-gradient:     linear-gradient(135deg, #22d3ee, #67e8f9);
    }
</style>
