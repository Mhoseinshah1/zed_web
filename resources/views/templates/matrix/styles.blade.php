    <style>
        /* User-panel accent layer — green fits the inherently dark matrix look.
           Read by .zp-user-panel; scoped so it can't leak into another template. */
        [data-template="matrix"] {
            --zp-tpl-accent:       #34d399;
            --zp-tpl-accent-hover: #10b981;
            --zp-tpl-accent-2:     #6ee7b7;
            --zp-tpl-accent-soft:  rgba(52, 211, 153, .10);
            --zp-tpl-gradient:     linear-gradient(135deg, #10b981, #34d399);
        }
        body { font-family: 'Vazirmatn', system-ui, sans-serif; }
        .zm-mono { font-family: 'Courier New', monospace; direction: ltr; unicode-bidi: embed; }
        #zm-matrix { position: fixed; inset: 0; z-index: 0; opacity: .16; pointer-events: none; }
        .zm-bg-glow { position: fixed; inset: 0; z-index: 0; pointer-events: none;
            background:
                radial-gradient(ellipse 50% 40% at 70% 5%, color-mix(in srgb, var(--zp-primary) 16%, transparent), transparent),
                radial-gradient(ellipse 45% 40% at 20% 30%, color-mix(in srgb, var(--zp-accent) 12%, transparent), transparent); }
        .zm-scanline { position: fixed; inset: 0; z-index: 1; pointer-events: none;
            background: repeating-linear-gradient(0deg, transparent 0 2px, rgba(0,0,0,.12) 2px 4px); opacity: .4; }
        @media (prefers-reduced-motion: reduce) {
            #zm-matrix { display: none; }
            .zm-blink, .zm-pkt, .zm-glitch::before, .zm-glitch::after { animation: none !important; }
        }
    </style>
