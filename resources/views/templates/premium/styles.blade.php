<style>
html[data-template="premium"] { --zp-tpl-accent: #3f7cff; }
body[data-template="premium"] {
    --zpp-bg:#070d18;
    --zpp-bg-soft:#0b1322;
    --zpp-surface:#0f1a2d;
    --zpp-surface-2:#131f35;
    --zpp-surface-3:#182640;
    --zpp-text:#ecf4ff;
    --zpp-muted:#91a3c2;
    --zpp-line:rgba(166,188,220,.13);
    --zpp-primary:#3f7cff;
    --zpp-primary-2:#14b8ff;
    --zpp-accent:#59e1ff;
    --zpp-success:#34d399;
    --zpp-shadow:0 30px 80px rgba(0,0,0,.30);
    --zpp-card-shadow:0 14px 38px rgba(0,0,0,.14);
    --zpp-gradient:linear-gradient(135deg,var(--zpp-primary),var(--zpp-primary-2) 58%,var(--zpp-accent));
    background:
        radial-gradient(980px 640px at 90% -10%,rgba(63,124,255,.15),transparent 60%),
        radial-gradient(820px 620px at -12% 26%,rgba(89,225,255,.07),transparent 58%),
        linear-gradient(180deg,var(--zpp-bg),var(--zpp-bg-soft));
    color:var(--zpp-text);
}
html.zed-light body[data-template="premium"], html.zp-light body[data-template="premium"] {
    --zpp-bg:#edf4fb;--zpp-bg-soft:#e7eff8;--zpp-surface:#fff;--zpp-surface-2:#f6f9fc;--zpp-surface-3:#eaf1f8;
    --zpp-text:#192336;--zpp-muted:#66778f;--zpp-line:rgba(60,78,104,.12);--zpp-shadow:0 24px 70px rgba(68,84,112,.14);--zpp-card-shadow:0 12px 30px rgba(68,84,112,.10);
}
body[data-template="premium"]:before {
    content:"";position:fixed;inset:0;z-index:-2;pointer-events:none;opacity:.18;
    background-image:linear-gradient(var(--zpp-line) 1px,transparent 1px),linear-gradient(90deg,var(--zpp-line) 1px,transparent 1px);
    background-size:48px 48px;mask-image:linear-gradient(to bottom,#000,transparent 82%);
}
body[data-template="premium"] .zp-premium-container { width:min(calc(100% - 32px),1260px);margin-inline:auto; }
body[data-template="premium"] .zp-premium-header { position:sticky;top:0;z-index:50;background:color-mix(in srgb,var(--zpp-bg) 82%,transparent);backdrop-filter:blur(18px);border-bottom:1px solid var(--zpp-line); }
body[data-template="premium"] .zp-premium-nav { height:80px;display:flex;align-items:center;justify-content:space-between;gap:18px; }
body[data-template="premium"] .zp-premium-brand { display:flex;align-items:center;gap:11px;color:var(--zpp-text);text-decoration:none;font-weight:900; }
body[data-template="premium"] .zp-premium-logo-mark { width:42px;height:42px;border-radius:15px;display:grid;place-items:center;color:#fff;font-size:20px;background:var(--zpp-gradient);box-shadow:0 14px 34px rgba(63,124,255,.28); }
body[data-template="premium"] .zp-premium-logo-img { max-height:42px;width:auto; }
body[data-template="premium"] .zp-premium-brand-copy { display:flex;flex-direction:column;line-height:1.05; }
body[data-template="premium"] .zp-premium-brand-copy strong { font-size:17px;color:var(--zpp-text); }
body[data-template="premium"] .zp-premium-brand-copy small { font-size:9px;color:var(--zpp-muted);letter-spacing:.9px;margin-top:4px; }
body[data-template="premium"] .zp-premium-links { display:flex;align-items:center;gap:5px; }
body[data-template="premium"] .zp-premium-links a { color:var(--zpp-muted);padding:10px 12px;border-radius:12px;font-size:13px;font-weight:700;text-decoration:none;transition:.2s; }
body[data-template="premium"] .zp-premium-links a:hover,body[data-template="premium"] .zp-premium-links a.is-active { color:var(--zpp-text);background:var(--zpp-surface-2); }
body[data-template="premium"] .zp-premium-actions { display:flex;align-items:center;gap:8px; }
body[data-template="premium"] .zp-premium-login-link { color:var(--zpp-muted);font-size:13px;font-weight:700;padding:10px;text-decoration:none; }
body[data-template="premium"] .zp-premium-btn { display:inline-flex;align-items:center;justify-content:center;gap:8px;border-radius:15px;padding:11px 17px;font-size:13px;font-weight:800;text-decoration:none;border:1px solid transparent;transition:.2s;white-space:nowrap; }
body[data-template="premium"] .zp-premium-btn:hover { transform:translateY(-2px); }
body[data-template="premium"] .zp-premium-btn-primary { color:#fff;background:var(--zpp-gradient);box-shadow:0 14px 32px rgba(63,124,255,.20); }
body[data-template="premium"] .zp-premium-btn-soft { color:var(--zpp-text);background:var(--zpp-surface-2);border-color:var(--zpp-line);box-shadow:var(--zpp-card-shadow); }
body[data-template="premium"] .zp-premium-menu-btn { display:none;width:42px;height:42px;border-radius:13px;border:1px solid var(--zpp-line);background:var(--zpp-surface);color:var(--zpp-text); }
body[data-template="premium"] .zp-premium-menu-btn svg { width:20px;height:20px;margin:auto; }
body[data-template="premium"] .zp-premium-mobile-menu { border-top:1px solid var(--zpp-line);background:color-mix(in srgb,var(--zpp-bg) 95%,transparent); }
body[data-template="premium"] .zp-premium-mobile-menu .zp-premium-container { display:grid;gap:6px;padding-block:12px; }
body[data-template="premium"] .zp-premium-mobile-menu a { padding:11px 12px;border-radius:12px;color:var(--zpp-muted);text-decoration:none;font-size:13px;font-weight:700; }
body[data-template="premium"] .zp-premium-mobile-menu a:hover,body[data-template="premium"] .zp-premium-mobile-menu a.is-active { color:var(--zpp-text);background:var(--zpp-surface-2); }

body[data-template="premium"] .zp-premium-hero { padding:72px 0 42px;min-height:720px;display:flex;align-items:center;overflow:hidden; }
body[data-template="premium"] .zp-premium-hero-grid { display:grid;grid-template-columns:1.05fr .95fr;gap:52px;align-items:center; }
body[data-template="premium"] .zp-premium-eyebrow { display:inline-flex;align-items:center;gap:9px;padding:8px 12px;border-radius:999px;color:var(--zpp-accent);border:1px solid rgba(89,225,255,.22);background:rgba(89,225,255,.055);font-size:12px;font-weight:900; }
body[data-template="premium"] .zp-premium-eyebrow span { width:8px;height:8px;border-radius:50%;background:var(--zpp-success);box-shadow:0 0 0 5px rgba(52,211,153,.10); }
body[data-template="premium"] .zp-premium-hero h1 { margin:20px 0 18px;font-size:clamp(44px,5.4vw,74px);line-height:1.07;letter-spacing:-2.2px;font-weight:900;color:var(--zpp-text); }
body[data-template="premium"] .zp-premium-hero h1 span { display:block;background:linear-gradient(120deg,var(--zpp-text),var(--zpp-accent) 48%,var(--zpp-primary));-webkit-background-clip:text;background-clip:text;color:transparent; }
body[data-template="premium"] .zp-premium-hero h1 strong { display:block;color:var(--zpp-text);font-weight:900;margin-top:8px; }
body[data-template="premium"] .zp-premium-hero-copy>p { max-width:690px;color:var(--zpp-muted);font-size:17px;line-height:2;margin:0; }
body[data-template="premium"] .zp-premium-hero-actions { display:flex;gap:10px;flex-wrap:wrap;margin:27px 0 20px; }
body[data-template="premium"] .zp-premium-chips { display:flex;gap:8px;flex-wrap:wrap; }
body[data-template="premium"] .zp-premium-chips span { padding:8px 11px;border:1px solid var(--zpp-line);border-radius:999px;background:var(--zpp-surface);color:var(--zpp-muted);font-size:11px;font-weight:700; }
body[data-template="premium"] .zp-premium-stats { display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:25px; }
body[data-template="premium"] .zp-premium-stats>div { padding:16px;border-radius:19px;background:linear-gradient(180deg,var(--zpp-surface),var(--zpp-surface-2));border:1px solid var(--zpp-line);box-shadow:var(--zpp-card-shadow); }
body[data-template="premium"] .zp-premium-stats strong { display:block;font-size:22px;direction:ltr;text-align:right;color:var(--zpp-text); }
body[data-template="premium"] .zp-premium-stats small { display:block;color:var(--zpp-muted);font-size:10px;margin-top:5px; }

body[data-template="premium"] .zp-premium-console-wrap { position:relative;min-height:590px;display:grid;place-items:center; }
body[data-template="premium"] .zp-premium-orb { position:absolute;border-radius:50%;filter:blur(7px);pointer-events:none; }
body[data-template="premium"] .zp-premium-orb-a { width:210px;height:210px;top:20px;right:0;background:radial-gradient(circle,rgba(63,124,255,.38),transparent 68%); }
body[data-template="premium"] .zp-premium-orb-b { width:170px;height:170px;bottom:15px;left:0;background:radial-gradient(circle,rgba(89,225,255,.24),transparent 68%); }
body[data-template="premium"] .zp-premium-console { width:min(100%,520px);position:relative;border-radius:32px;padding:17px;background:radial-gradient(120% 100% at 100% 0%,rgba(63,124,255,.10),transparent 45%),linear-gradient(180deg,var(--zpp-surface),var(--zpp-surface-2));border:1px solid rgba(112,150,255,.20);box-shadow:var(--zpp-shadow);transition:transform .2s; }
body[data-template="premium"] .zp-premium-console-head { display:flex;align-items:center;justify-content:space-between;padding:4px 5px 14px;color:var(--zpp-text);font-size:13px; }
body[data-template="premium"] .zp-premium-console-head>div { display:flex;gap:5px;direction:ltr; }
body[data-template="premium"] .zp-premium-console-head i { width:8px;height:8px;border-radius:50%;display:block;background:var(--zpp-line); }
body[data-template="premium"] .zp-premium-console-head i:nth-child(1){background:#fb7185}body[data-template="premium"] .zp-premium-console-head i:nth-child(2){background:#fbbf24}body[data-template="premium"] .zp-premium-console-head i:nth-child(3){background:#34d399}
body[data-template="premium"] .zp-premium-connect-card { position:relative;overflow:hidden;padding:22px;border-radius:25px;text-align:center;background:linear-gradient(145deg,rgba(63,124,255,.11),var(--zpp-surface-2));border:1px solid rgba(112,150,255,.18); }
body[data-template="premium"] .zp-premium-connect-card>small { display:block;color:var(--zpp-muted);text-align:right;font-size:11px;font-weight:800; }
body[data-template="premium"] .zp-premium-power { width:112px;height:112px;border-radius:50%;display:grid;place-items:center;margin:14px auto 18px;background:radial-gradient(circle at 50% 40%,rgba(63,124,255,.18),var(--zpp-surface));border:1px solid rgba(89,225,255,.22);box-shadow:inset 0 0 28px rgba(63,124,255,.07),0 0 42px rgba(89,225,255,.08); }
body[data-template="premium"] .zp-premium-power svg { width:45px;height:45px;color:var(--zpp-accent); }
body[data-template="premium"] .zp-premium-connect-card h3 { color:var(--zpp-text);font-size:22px;margin:0;direction:ltr; }
body[data-template="premium"] .zp-premium-connect-card>p { color:var(--zpp-muted);font-size:12px;margin:7px 0 0; }
body[data-template="premium"] .zp-premium-server-row { display:grid;grid-template-columns:1fr auto;align-items:center;gap:10px;text-align:right;margin-top:18px;padding:13px 14px;border-radius:17px;background:var(--zpp-surface);border:1px solid var(--zpp-line); }
body[data-template="premium"] .zp-premium-server-row b { color:var(--zpp-text);font-size:13px; }
body[data-template="premium"] .zp-premium-server-row span { display:block;color:var(--zpp-muted);font-size:10px;margin-top:4px; }
body[data-template="premium"] .zp-premium-server-row>strong { direction:ltr;color:var(--zpp-success);font-size:13px; }
body[data-template="premium"] .zp-premium-metrics { display:grid;grid-template-columns:repeat(3,1fr);gap:9px;margin-top:10px; }
body[data-template="premium"] .zp-premium-metrics>div { padding:12px 8px;border-radius:15px;background:var(--zpp-surface);border:1px solid var(--zpp-line); }
body[data-template="premium"] .zp-premium-metrics b { display:block;color:var(--zpp-text);font-size:14px;direction:ltr; }
body[data-template="premium"] .zp-premium-metrics span { display:block;color:var(--zpp-muted);font-size:9px;margin-top:4px; }
body[data-template="premium"] .zp-premium-info-card { position:absolute;z-index:4;padding:14px;border-radius:19px;background:color-mix(in srgb,var(--zpp-surface) 94%,transparent);border:1px solid var(--zpp-line);box-shadow:var(--zpp-card-shadow);backdrop-filter:blur(12px); }
body[data-template="premium"] .zp-premium-info-card b { color:var(--zpp-text);font-size:12px;direction:ltr;display:block; }
body[data-template="premium"] .zp-premium-info-card p { color:var(--zpp-muted);font-size:10px;line-height:1.85;margin:6px 0 0; }
body[data-template="premium"] .zp-premium-load-card { width:178px;top:82px;left:-24px; }
body[data-template="premium"] .zp-premium-sync-card { width:206px;bottom:18px;right:-8px; }
body[data-template="premium"] .zp-premium-load-bars,body[data-template="premium"] .zp-premium-mini-load { display:flex;gap:4px;direction:ltr;margin-top:9px; }
body[data-template="premium"] .zp-premium-load-bars>* ,body[data-template="premium"] .zp-premium-mini-load>* { height:5px;flex:1;border-radius:999px;background:var(--zpp-surface-3); }
body[data-template="premium"] .zp-premium-load-bars i,body[data-template="premium"] .zp-premium-mini-load i { background:var(--zpp-success); }

body[data-template="premium"] .zp-premium-section { padding:68px 0; }
body[data-template="premium"] .zp-premium-section-shell { padding:28px;border-radius:30px;background:linear-gradient(180deg,var(--zpp-surface),var(--zpp-surface-2));border:1px solid var(--zpp-line);box-shadow:var(--zpp-card-shadow); }
body[data-template="premium"] .zp-premium-section-head { margin-bottom:25px; }
body[data-template="premium"] .zp-premium-section-head-inline { display:flex;justify-content:space-between;align-items:flex-end;gap:18px; }
body[data-template="premium"] .zp-premium-section-head small { display:block;color:var(--zpp-accent);font-size:10px;font-weight:900;letter-spacing:.6px;margin-bottom:7px; }
body[data-template="premium"] .zp-premium-section-head h2 { color:var(--zpp-text);font-size:clamp(28px,3vw,42px);line-height:1.2;letter-spacing:-1px;margin:0; }
body[data-template="premium"] .zp-premium-section-head p { color:var(--zpp-muted);line-height:1.9;margin:8px 0 0;max-width:720px;font-size:13px; }
body[data-template="premium"] .zp-premium-feature-grid { display:grid;grid-template-columns:repeat(3,1fr);gap:14px; }
body[data-template="premium"] .zp-premium-feature-card { padding:22px;border-radius:23px;background:linear-gradient(180deg,var(--zpp-surface),var(--zpp-surface-2));border:1px solid var(--zpp-line);box-shadow:var(--zpp-card-shadow);transition:.22s; }
body[data-template="premium"] .zp-premium-feature-card:hover { transform:translateY(-5px);border-color:rgba(63,124,255,.28);box-shadow:0 0 0 1px rgba(63,124,255,.12),0 18px 45px rgba(0,0,0,.14); }
body[data-template="premium"] .zp-premium-feature-icon { width:48px;height:48px;border-radius:15px;display:grid;place-items:center;margin-bottom:16px;font-size:21px;background:linear-gradient(135deg,rgba(63,124,255,.16),rgba(89,225,255,.06));border:1px solid rgba(63,124,255,.16); }
body[data-template="premium"] .zp-premium-feature-card h3 { color:var(--zpp-text);font-size:16px;margin:0 0 8px; }
body[data-template="premium"] .zp-premium-feature-card p { color:var(--zpp-muted);font-size:12px;line-height:1.9;margin:0; }
body[data-template="premium"] .zp-premium-plan-grid { display:grid;grid-template-columns:repeat(3,1fr);gap:14px; }
body[data-template="premium"] .zp-premium-plan-card { position:relative;padding:22px;border-radius:24px;background:linear-gradient(180deg,var(--zpp-surface),var(--zpp-surface-2));border:1px solid var(--zpp-line);box-shadow:var(--zpp-card-shadow);overflow:hidden; }
body[data-template="premium"] .zp-premium-plan-card:before { content:"";position:absolute;inset:0 0 auto;height:3px;background:linear-gradient(90deg,transparent,var(--zpp-primary),var(--zpp-accent),transparent); }
body[data-template="premium"] .zp-premium-plan-card.is-featured { border-color:rgba(63,124,255,.28);background:radial-gradient(100% 60% at 100% 0%,rgba(63,124,255,.12),transparent 44%),linear-gradient(180deg,var(--zpp-surface),var(--zpp-surface-2)); }
body[data-template="premium"] .zp-premium-plan-badge { position:absolute;top:13px;left:13px;padding:5px 9px;border-radius:999px;background:var(--zpp-gradient);color:#fff;font-size:9px;font-weight:900; }
body[data-template="premium"] .zp-premium-plan-card>small { color:var(--zpp-muted);font-size:10px; }
body[data-template="premium"] .zp-premium-plan-card h3 { color:var(--zpp-text);font-size:18px;margin:6px 0 12px; }
body[data-template="premium"] .zp-premium-plan-price { color:var(--zpp-text);font-size:27px;font-weight:900; }
body[data-template="premium"] .zp-premium-plan-price span { color:var(--zpp-muted);font-size:10px;font-weight:700; }
body[data-template="premium"] .zp-premium-plan-card ul { display:grid;gap:9px;margin:18px 0;padding:0;list-style:none;color:var(--zpp-muted);font-size:11px; }
body[data-template="premium"] .zp-premium-plan-card li:before { content:"✓";color:var(--zpp-success);font-weight:900;margin-left:7px; }
body[data-template="premium"] .zp-premium-plan-card .zp-premium-btn { width:100%; }
body[data-template="premium"] .zp-premium-location-grid { display:grid;grid-template-columns:repeat(3,1fr);gap:12px; }
body[data-template="premium"] .zp-premium-location-card { display:flex;align-items:center;gap:12px;padding:16px;border-radius:19px;background:var(--zpp-surface);border:1px solid var(--zpp-line); }
body[data-template="premium"] .zp-premium-location-flag { width:46px;height:46px;border-radius:14px;display:grid;place-items:center;background:var(--zpp-surface-2);border:1px solid var(--zpp-line);font-size:22px; }
body[data-template="premium"] .zp-premium-location-card b { display:block;color:var(--zpp-text);font-size:13px; }
body[data-template="premium"] .zp-premium-location-card small { display:block;color:var(--zpp-muted);font-size:9px;margin-top:3px; }
body[data-template="premium"] .zp-premium-faq-wrap { max-width:920px; }
body[data-template="premium"] .zp-premium-faq-list { display:grid;gap:9px; }
body[data-template="premium"] .zp-premium-faq-list details { border-radius:18px;border:1px solid var(--zpp-line);background:var(--zpp-surface);box-shadow:var(--zpp-card-shadow);overflow:hidden; }
body[data-template="premium"] .zp-premium-faq-list summary { list-style:none;display:flex;justify-content:space-between;gap:12px;padding:16px 18px;color:var(--zpp-text);font-size:13px;font-weight:800;cursor:pointer; }
body[data-template="premium"] .zp-premium-faq-list summary::-webkit-details-marker { display:none; }
body[data-template="premium"] .zp-premium-faq-list summary span { color:var(--zpp-accent);font-size:18px; }
body[data-template="premium"] .zp-premium-faq-list details[open] summary span { transform:rotate(45deg); }
body[data-template="premium"] .zp-premium-faq-list details p { color:var(--zpp-muted);font-size:12px;line-height:1.9;padding:0 18px 16px;margin:0; }
body[data-template="premium"] .zp-premium-cta { padding:28px;border-radius:29px;display:flex;align-items:center;justify-content:space-between;gap:18px;background:radial-gradient(110% 90% at 100% 0%,rgba(63,124,255,.16),transparent 45%),linear-gradient(135deg,var(--zpp-surface),var(--zpp-surface-2));border:1px solid rgba(63,124,255,.20);box-shadow:var(--zpp-shadow); }
body[data-template="premium"] .zp-premium-cta h2 { color:var(--zpp-text);font-size:24px;margin:0 0 6px; }
body[data-template="premium"] .zp-premium-cta p { color:var(--zpp-muted);font-size:12px;line-height:1.8;margin:0; }
body[data-template="premium"] .zp-premium-banner-wrap { margin-top:24px; }

body[data-template="premium"] .zp-premium-footer { margin-top:45px;border-top:1px solid var(--zpp-line);background:color-mix(in srgb,var(--zpp-bg-soft) 78%,transparent); }
body[data-template="premium"] .zp-premium-footer-grid { display:grid;grid-template-columns:1.35fr repeat(3,1fr);gap:24px;padding:42px 0; }
body[data-template="premium"] .zp-premium-footer-brand p { max-width:370px;color:var(--zpp-muted);font-size:11px;line-height:1.9;margin:13px 0 0; }
body[data-template="premium"] .zp-premium-footer-grid h4 { color:var(--zpp-text);font-size:13px;margin:0 0 13px; }
body[data-template="premium"] .zp-premium-footer-grid>div:not(:first-child) { display:flex;flex-direction:column;align-items:flex-start;gap:8px; }
body[data-template="premium"] .zp-premium-footer-grid>div:not(:first-child) a { color:var(--zpp-muted);font-size:11px;text-decoration:none; }
body[data-template="premium"] .zp-premium-footer-grid>div:not(:first-child) a:hover { color:var(--zpp-text); }
body[data-template="premium"] .zp-premium-footer-bottom { display:flex;justify-content:space-between;gap:14px;padding:16px 0;border-top:1px solid var(--zpp-line);color:var(--zpp-muted);font-size:10px; }

@media(max-width:1100px){
    body[data-template="premium"] .zp-premium-links { display:none; }
    body[data-template="premium"] .zp-premium-menu-btn { display:grid; }
    body[data-template="premium"] .zp-premium-hero-grid { grid-template-columns:1fr; }
    body[data-template="premium"] .zp-premium-hero { min-height:auto;padding-top:50px; }
    body[data-template="premium"] .zp-premium-console-wrap { min-height:auto;padding:20px 0 0; }
    body[data-template="premium"] .zp-premium-info-card { position:static;width:auto;margin-top:10px; }
    body[data-template="premium"] .zp-premium-feature-grid,body[data-template="premium"] .zp-premium-plan-grid,body[data-template="premium"] .zp-premium-location-grid { grid-template-columns:repeat(2,1fr); }
    body[data-template="premium"] .zp-premium-footer-grid { grid-template-columns:1.3fr 1fr 1fr; }
    body[data-template="premium"] .zp-premium-footer-grid>div:last-child { grid-column:2; }
}
@media(max-width:700px){
    body[data-template="premium"] .zp-premium-container { width:min(calc(100% - 20px),1260px); }
    body[data-template="premium"] .zp-premium-nav { height:70px; }
    body[data-template="premium"] .zp-premium-login-link { display:none; }
    body[data-template="premium"] .zp-premium-brand-copy small { display:none; }
    body[data-template="premium"] .zp-premium-logo-mark { width:38px;height:38px;border-radius:13px; }
    body[data-template="premium"] .zp-premium-actions>.zp-premium-btn-primary { padding:10px 13px; }
    body[data-template="premium"] .zp-premium-hero { padding:35px 0 12px; }
    body[data-template="premium"] .zp-premium-hero h1 { font-size:clamp(38px,12vw,54px);letter-spacing:-1.6px; }
    body[data-template="premium"] .zp-premium-hero-copy>p { font-size:14px; }
    body[data-template="premium"] .zp-premium-stats,body[data-template="premium"] .zp-premium-feature-grid,body[data-template="premium"] .zp-premium-plan-grid,body[data-template="premium"] .zp-premium-location-grid { grid-template-columns:1fr; }
    body[data-template="premium"] .zp-premium-metrics { grid-template-columns:1fr; }
    body[data-template="premium"] .zp-premium-section { padding:48px 0; }
    body[data-template="premium"] .zp-premium-section-shell { padding:19px;border-radius:24px; }
    body[data-template="premium"] .zp-premium-section-head-inline,body[data-template="premium"] .zp-premium-cta,body[data-template="premium"] .zp-premium-footer-bottom { flex-direction:column;align-items:flex-start; }
    body[data-template="premium"] .zp-premium-footer-grid { grid-template-columns:1fr 1fr; }
    body[data-template="premium"] .zp-premium-footer-brand { grid-column:1/-1; }
    body[data-template="premium"] .zp-premium-footer-grid>div:last-child { grid-column:auto; }
}
</style>
