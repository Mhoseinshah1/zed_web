{{-- ============================================================================
     Classic template — user-panel accent layer.
     Defines the shared --zp-tpl-accent* variables (read by .zp-user-panel in the
     user panel) with this template's own colour — indigo — scoped to
     [data-template="classic"] so it never leaks into another template. Chrome and
     structure are untouched; on the public site these variables are simply unused.
     ============================================================================ --}}
<style>
    [data-template="classic"] {
        --zp-tpl-accent:       #6366f1;
        --zp-tpl-accent-hover: #4f46e5;
        --zp-tpl-accent-2:     #818cf8;
        --zp-tpl-accent-soft:  rgba(99, 102, 241, .10);
        --zp-tpl-gradient:     linear-gradient(135deg, #6366f1, #818cf8);
    }
</style>
