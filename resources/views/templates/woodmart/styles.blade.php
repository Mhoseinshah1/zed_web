{{-- ============================================================================
     WoodMart template — SCOPED accent only.
     Chrome (background/text/border) comes from the project theme classes
     (bg-base / bg-surface / text-content / border-line …) which flip with
     html.zed-light, so light & dark both work. The fixed WoodMart orange is the
     ONLY colour defined here, as scoped CSS variables — never hardcoded greys.
     ============================================================================ --}}
<style>
    [data-template="woodmart"] {
        --wm-accent:      #e8552a;
        --wm-accent-2:    #ff7a4d;
        --wm-accent-soft: rgba(232, 85, 42, .10);
        --wm-ok:          #16a34a;

        /* Uniform accent layer — the user panel (.zp-user-panel) reads these so
           its brand/accent turns WoodMart-orange while its structure stays put.
           Templates without a fixed accent omit these and fall back to the theme
           accent. Chrome/light-dark are untouched. */
        --zp-tpl-accent:       var(--wm-accent);
        --zp-tpl-accent-hover: var(--wm-accent-2);
        --zp-tpl-accent-2:     var(--wm-accent-2);
        --zp-tpl-gradient:     linear-gradient(135deg, var(--wm-accent), var(--wm-accent-2));
    }

    /* Accent helpers (orange is intentionally fixed in both light & dark). */
    [data-template="woodmart"] .wm-accent-bg      { background: var(--wm-accent); color:#fff; }
    [data-template="woodmart"] .wm-accent-bg:hover { background: var(--wm-accent-2); }
    [data-template="woodmart"] .wm-ok-bg          { background: var(--wm-ok); color:#fff; }
    [data-template="woodmart"] .wm-accent-text    { color: var(--wm-accent); }
    [data-template="woodmart"] .wm-accent-soft-bg { background: var(--wm-accent-soft); color: var(--wm-accent); }
    [data-template="woodmart"] .wm-accent-border  { border-color: var(--wm-accent) !important; }
    [data-template="woodmart"] .wm-banner         { background: linear-gradient(120deg, var(--wm-accent), var(--wm-accent-2)); }
    [data-template="woodmart"] .wm-logo-badge     { background: linear-gradient(135deg, var(--wm-accent), var(--wm-accent-2)); }

    /* Hover/transition niceties (no colour hardcoding). */
    [data-template="woodmart"] .wm-cat,
    [data-template="woodmart"] .wm-product { transition: transform .2s, box-shadow .2s, border-color .2s; }
    [data-template="woodmart"] .wm-cat:hover     { transform: translateY(-3px); border-color: var(--wm-accent); }
    [data-template="woodmart"] .wm-product:hover { transform: translateY(-4px); box-shadow: 0 16px 44px -16px rgba(20,30,60,.28); }
    [data-template="woodmart"] .wm-navlink:hover,
    [data-template="woodmart"] .wm-navlink.is-on { color: var(--wm-accent); background: var(--wm-accent-soft); }
    [data-template="woodmart"] .wm-globe         { background: radial-gradient(circle at 35% 30%, var(--wm-accent-soft), transparent 60%); border-color: var(--wm-accent); color: var(--wm-accent); }
</style>
