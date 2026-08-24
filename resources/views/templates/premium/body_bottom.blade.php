<style id="zpp-user-panel-polish">
html[data-template="premium"] body.zp-user-panel {
    --zp-bg:#070d18;
    --zp-bg-soft:#0b1322;
    --zp-surface:#0f1a2d;
    --zp-surface-soft:#131f35;
    --zp-surface-hover:#182640;
    --zp-text:#ecf4ff;
    --zp-text-muted:#91a3c2;
    --zp-border:rgba(166,188,220,.13);
    --zpp-panel-primary:#3f7cff;
    --zpp-panel-primary-2:#14b8ff;
    --zpp-panel-accent:#59e1ff;
    --zpp-panel-success:#34d399;
    --zpp-panel-warning:#fbbf24;
    --zpp-panel-danger:#fb7185;
    --zpp-panel-gradient:linear-gradient(135deg,#3f7cff,#14b8ff 58%,#59e1ff);
    min-height:100vh;
    color:var(--zp-text);
    background:
        radial-gradient(900px 600px at 92% -8%,rgba(63,124,255,.16),transparent 62%),
        radial-gradient(720px 540px at -8% 24%,rgba(89,225,255,.07),transparent 60%),
        linear-gradient(180deg,#070d18,#0b1322 100%);
}
html[data-template="premium"] body.zp-user-panel:before {
    content:"";position:fixed;inset:0;z-index:-1;pointer-events:none;opacity:.16;
    background-image:linear-gradient(rgba(166,188,220,.10) 1px,transparent 1px),linear-gradient(90deg,rgba(166,188,220,.10) 1px,transparent 1px);
    background-size:48px 48px;mask-image:linear-gradient(to bottom,#000,transparent 86%);
}
html[data-template="premium"] body.zp-user-panel #panel-sidebar {
    width:272px;
    background:linear-gradient(180deg,rgba(15,26,45,.98),rgba(10,18,32,.98)) !important;
    border-color:rgba(166,188,220,.12) !important;
    box-shadow:-18px 0 60px rgba(0,0,0,.18);
}
html[data-template="premium"] body.zp-user-panel #panel-sidebar>div:first-child {
    padding:24px 22px !important;
    border-color:rgba(166,188,220,.12) !important;
}
html[data-template="premium"] body.zp-user-panel #panel-sidebar>div:first-child a {
    font-size:19px;font-weight:900;letter-spacing:-.4px;
}
html[data-template="premium"] body.zp-user-panel #panel-sidebar>div:first-child a span {
    color:#59e1ff !important;
}
html[data-template="premium"] body.zp-user-panel #panel-sidebar nav {
    padding:15px 13px !important;
}
html[data-template="premium"] body.zp-user-panel #panel-sidebar nav a {
    min-height:46px;border-radius:14px !important;padding:11px 13px !important;
    color:#91a3c2 !important;font-weight:700;transition:.2s ease;
}
html[data-template="premium"] body.zp-user-panel #panel-sidebar nav a:hover {
    color:#ecf4ff !important;background:#131f35 !important;transform:translateX(-2px);
}
html[data-template="premium"] body.zp-user-panel #panel-sidebar nav a.bg-indigo-600 {
    color:#fff !important;background:linear-gradient(135deg,#3f7cff,#14b8ff) !important;
    box-shadow:0 12px 30px rgba(63,124,255,.22);
}
html[data-template="premium"] body.zp-user-panel #panel-sidebar nav a svg { opacity:.85; }
html[data-template="premium"] body.zp-user-panel #panel-sidebar form button,
html[data-template="premium"] body.zp-user-panel #panel-sidebar button[type="submit"] {
    border-radius:14px !important;
}
html[data-template="premium"] body.zp-user-panel header {
    min-height:78px;
    background:rgba(7,13,24,.74) !important;
    border-color:rgba(166,188,220,.12) !important;
    backdrop-filter:blur(18px);
    position:sticky;top:0;z-index:30;
}
html[data-template="premium"] body.zp-user-panel header h1,
html[data-template="premium"] body.zp-user-panel header h2,
html[data-template="premium"] body.zp-user-panel header .text-content { color:#ecf4ff !important; }
html[data-template="premium"] body.zp-user-panel main {
    padding:30px !important;
    width:100%;
}
html[data-template="premium"] body.zp-user-panel .zp-um-trigger {
    background:#0f1a2d;border-color:rgba(166,188,220,.13);box-shadow:0 10px 30px rgba(0,0,0,.14);
}
html[data-template="premium"] body.zp-user-panel .zp-um-menu {
    background:#0f1a2d;border-color:rgba(166,188,220,.13);border-radius:20px;
}
html[data-template="premium"] body.zp-user-panel .zp-um-head,
html[data-template="premium"] body.zp-user-panel .zp-um-chip { background:#131f35; }
html[data-template="premium"] body.zp-user-panel .bg-surface {
    background:linear-gradient(180deg,#0f1a2d,#101b2f) !important;
}
html[data-template="premium"] body.zp-user-panel .bg-surface-soft,
html[data-template="premium"] body.zp-user-panel .bg-surface-soft\/50 { background:#131f35 !important; }
html[data-template="premium"] body.zp-user-panel .border-line { border-color:rgba(166,188,220,.13) !important; }
html[data-template="premium"] body.zp-user-panel .text-content { color:#ecf4ff !important; }
html[data-template="premium"] body.zp-user-panel .text-content-muted { color:#91a3c2 !important; }
html[data-template="premium"] body.zp-user-panel .text-indigo-400 { color:#59e1ff !important; }
html[data-template="premium"] body.zp-user-panel .bg-indigo-600 {
    background:linear-gradient(135deg,#3f7cff,#14b8ff) !important;
    box-shadow:0 12px 30px rgba(63,124,255,.19);
}
html[data-template="premium"] body.zp-user-panel .rounded-xl { border-radius:22px !important; }
html[data-template="premium"] body.zp-user-panel input,
html[data-template="premium"] body.zp-user-panel select,
html[data-template="premium"] body.zp-user-panel textarea {
    background:#131f35 !important;color:#ecf4ff !important;border-color:rgba(166,188,220,.15) !important;border-radius:14px !important;
}
html[data-template="premium"] body.zp-user-panel input:focus,
html[data-template="premium"] body.zp-user-panel select:focus,
html[data-template="premium"] body.zp-user-panel textarea:focus {
    border-color:rgba(63,124,255,.65) !important;box-shadow:0 0 0 4px rgba(63,124,255,.10) !important;
}

html[data-template="premium"] body.zp-user-panel .zpp-dashboard { max-width:1320px;margin:0 auto; }
html[data-template="premium"] body.zp-user-panel .zpp-dash-head { display:flex;align-items:flex-end;justify-content:space-between;gap:18px;margin-bottom:22px; }
html[data-template="premium"] body.zp-user-panel .zpp-dash-kicker { color:#59e1ff;font-size:11px;font-weight:900;letter-spacing:.9px;direction:ltr; }
html[data-template="premium"] body.zp-user-panel .zpp-dash-head h2 { margin:5px 0 0;color:#ecf4ff;font-size:31px;font-weight:900;letter-spacing:-1px; }
html[data-template="premium"] body.zp-user-panel .zpp-dash-head p { margin:7px 0 0;color:#91a3c2;font-size:13px; }
html[data-template="premium"] body.zp-user-panel .zpp-primary-btn,
html[data-template="premium"] body.zp-user-panel .zpp-soft-btn {
    display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:43px;padding:10px 16px;border-radius:14px;font-size:12px;font-weight:850;text-decoration:none;transition:.2s ease;
}
html[data-template="premium"] body.zp-user-panel .zpp-primary-btn { color:#fff;background:linear-gradient(135deg,#3f7cff,#14b8ff 68%,#59e1ff);box-shadow:0 13px 30px rgba(63,124,255,.22); }
html[data-template="premium"] body.zp-user-panel .zpp-soft-btn { color:#ecf4ff;background:#131f35;border:1px solid rgba(166,188,220,.13); }
html[data-template="premium"] body.zp-user-panel .zpp-primary-btn:hover,
html[data-template="premium"] body.zp-user-panel .zpp-soft-btn:hover { transform:translateY(-2px); }
html[data-template="premium"] body.zp-user-panel .zpp-stat-grid { display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:16px; }
html[data-template="premium"] body.zp-user-panel .zpp-stat-card {
    position:relative;overflow:hidden;padding:20px 21px;border-radius:23px;border:1px solid rgba(166,188,220,.13);
    background:linear-gradient(180deg,#0f1a2d,#101b2f);box-shadow:0 14px 34px rgba(0,0,0,.13);transition:.22s ease;
}
html[data-template="premium"] body.zp-user-panel .zpp-stat-card:before { content:"";position:absolute;inset:0 0 auto;height:2px;background:linear-gradient(90deg,transparent,#3f7cff,#59e1ff,transparent);opacity:.75; }
html[data-template="premium"] body.zp-user-panel .zpp-stat-card:hover { transform:translateY(-4px);border-color:rgba(63,124,255,.32);box-shadow:0 18px 46px rgba(0,0,0,.18),0 0 0 1px rgba(63,124,255,.08); }
html[data-template="premium"] body.zp-user-panel .zpp-stat-top { display:flex;align-items:center;justify-content:space-between;gap:12px; }
html[data-template="premium"] body.zp-user-panel .zpp-stat-icon { width:44px;height:44px;border-radius:14px;display:grid;place-items:center;font-size:20px;background:#131f35;border:1px solid rgba(166,188,220,.12); }
html[data-template="premium"] body.zp-user-panel .zpp-stat-card small { display:block;color:#91a3c2;font-size:11px;font-weight:700; }
html[data-template="premium"] body.zp-user-panel .zpp-stat-card strong { display:block;color:#ecf4ff;font-size:27px;font-weight:900;margin-top:7px;letter-spacing:-.5px; }
html[data-template="premium"] body.zp-user-panel .zpp-stat-card strong span { font-size:10px;color:#91a3c2;font-weight:600; }
html[data-template="premium"] body.zp-user-panel .zpp-quick-grid { display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin:0 0 16px; }
html[data-template="premium"] body.zp-user-panel .zpp-quick-card {
    display:flex;align-items:center;gap:12px;padding:15px 16px;border-radius:18px;border:1px solid rgba(166,188,220,.13);background:#0f1a2d;color:#ecf4ff;text-decoration:none;transition:.2s ease;
}
html[data-template="premium"] body.zp-user-panel .zpp-quick-card:hover { transform:translateY(-3px);background:#131f35;border-color:rgba(63,124,255,.28); }
html[data-template="premium"] body.zp-user-panel .zpp-quick-card.is-primary { background:linear-gradient(135deg,#3f7cff,#14b8ff);border-color:transparent;box-shadow:0 13px 28px rgba(63,124,255,.20); }
html[data-template="premium"] body.zp-user-panel .zpp-quick-icon { width:40px;height:40px;flex:0 0 40px;border-radius:13px;display:grid;place-items:center;background:rgba(255,255,255,.06);font-size:18px; }
html[data-template="premium"] body.zp-user-panel .zpp-quick-card span:last-child { font-size:12px;font-weight:800; }
html[data-template="premium"] body.zp-user-panel .zpp-main-grid { display:grid;grid-template-columns:1.08fr .92fr;gap:16px; }
html[data-template="premium"] body.zp-user-panel .zpp-panel-card { border-radius:24px;border:1px solid rgba(166,188,220,.13);background:linear-gradient(180deg,#0f1a2d,#101b2f);box-shadow:0 14px 34px rgba(0,0,0,.13);overflow:hidden; }
html[data-template="premium"] body.zp-user-panel .zpp-panel-head { display:flex;align-items:center;justify-content:space-between;gap:12px;padding:19px 20px;border-bottom:1px solid rgba(166,188,220,.10); }
html[data-template="premium"] body.zp-user-panel .zpp-panel-head h3 { margin:0;color:#ecf4ff;font-size:16px;font-weight:900; }
html[data-template="premium"] body.zp-user-panel .zpp-panel-head a { color:#59e1ff;font-size:11px;font-weight:800;text-decoration:none; }
html[data-template="premium"] body.zp-user-panel .zpp-panel-body { padding:16px; }
html[data-template="premium"] body.zp-user-panel .zpp-service-card { padding:17px;border-radius:19px;background:#131f35;border:1px solid rgba(166,188,220,.11); }
html[data-template="premium"] body.zp-user-panel .zpp-service-card+.zpp-service-card { margin-top:10px; }
html[data-template="premium"] body.zp-user-panel .zpp-service-row { display:flex;align-items:flex-start;justify-content:space-between;gap:14px; }
html[data-template="premium"] body.zp-user-panel .zpp-service-name { color:#ecf4ff;font-size:14px;font-weight:900; }
html[data-template="premium"] body.zp-user-panel .zpp-service-id { color:#91a3c2;font-size:10px;font-family:monospace;direction:ltr;text-align:right;margin-top:4px; }
html[data-template="premium"] body.zp-user-panel .zpp-badge { display:inline-flex;align-items:center;gap:5px;padding:5px 9px;border-radius:999px;font-size:9px;font-weight:900;border:1px solid rgba(52,211,153,.20);background:rgba(52,211,153,.08);color:#34d399; }
html[data-template="premium"] body.zp-user-panel .zpp-badge.warning { color:#fbbf24;border-color:rgba(251,191,36,.20);background:rgba(251,191,36,.08); }
html[data-template="premium"] body.zp-user-panel .zpp-progress-meta { display:flex;align-items:center;justify-content:space-between;gap:10px;color:#91a3c2;font-size:10px;margin-top:15px; }
html[data-template="premium"] body.zp-user-panel .zpp-progress { height:8px;border-radius:999px;background:#0c1627;overflow:hidden;margin-top:7px; }
html[data-template="premium"] body.zp-user-panel .zpp-progress i { display:block;height:100%;border-radius:inherit;background:linear-gradient(90deg,#3f7cff,#14b8ff,#59e1ff); }
html[data-template="premium"] body.zp-user-panel .zpp-service-actions { display:flex;gap:7px;flex-wrap:wrap;margin-top:13px; }
html[data-template="premium"] body.zp-user-panel .zpp-mini-btn { display:inline-flex;align-items:center;justify-content:center;padding:8px 11px;border-radius:11px;border:1px solid rgba(166,188,220,.12);background:#0f1a2d;color:#ecf4ff;font-size:10px;font-weight:800;text-decoration:none; }
html[data-template="premium"] body.zp-user-panel .zpp-order-row { display:flex;align-items:center;justify-content:space-between;gap:14px;padding:13px 14px;border-radius:16px;background:#131f35;border:1px solid rgba(166,188,220,.10);text-decoration:none;transition:.18s ease; }
html[data-template="premium"] body.zp-user-panel .zpp-order-row+.zpp-order-row { margin-top:8px; }
html[data-template="premium"] body.zp-user-panel .zpp-order-row:hover { border-color:rgba(63,124,255,.27);transform:translateY(-2px); }
html[data-template="premium"] body.zp-user-panel .zpp-order-row b { color:#ecf4ff;font-size:12px; }
html[data-template="premium"] body.zp-user-panel .zpp-order-row small { display:block;color:#91a3c2;font-size:9px;margin-top:3px; }
html[data-template="premium"] body.zp-user-panel .zpp-order-price { color:#ecf4ff;font-size:11px;font-weight:800;text-align:left;white-space:nowrap; }
html[data-template="premium"] body.zp-user-panel .zpp-empty { min-height:210px;display:grid;place-items:center;text-align:center;color:#91a3c2;padding:24px; }
html[data-template="premium"] body.zp-user-panel .zpp-empty .icon { font-size:34px;margin-bottom:8px; }

@media (max-width:1100px) {
    html[data-template="premium"] body.zp-user-panel .zpp-stat-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
    html[data-template="premium"] body.zp-user-panel .zpp-main-grid { grid-template-columns:1fr; }
}
@media (max-width:1023px) {
    html[data-template="premium"] body.zp-user-panel #panel-sidebar { width:min(86vw,292px); }
    html[data-template="premium"] body.zp-user-panel main { padding:20px !important; }
}
@media (max-width:720px) {
    html[data-template="premium"] body.zp-user-panel main { padding:14px !important; }
    html[data-template="premium"] body.zp-user-panel .zpp-dash-head { align-items:flex-start;flex-direction:column; }
    html[data-template="premium"] body.zp-user-panel .zpp-stat-grid,
    html[data-template="premium"] body.zp-user-panel .zpp-quick-grid { grid-template-columns:1fr 1fr; }
    html[data-template="premium"] body.zp-user-panel .zpp-stat-card { padding:16px; }
    html[data-template="premium"] body.zp-user-panel .zpp-stat-card strong { font-size:22px; }
}
@media (max-width:480px) {
    html[data-template="premium"] body.zp-user-panel .zpp-stat-grid,
    html[data-template="premium"] body.zp-user-panel .zpp-quick-grid { grid-template-columns:1fr; }
}
</style>

<script>
(() => {
    const btn = document.getElementById('premium-menu-btn');
    const menu = document.getElementById('premium-mobile-menu');
    btn?.addEventListener('click', () => {
        const willOpen = menu?.hasAttribute('hidden');
        if (!menu) return;
        if (willOpen) menu.removeAttribute('hidden'); else menu.setAttribute('hidden', '');
        btn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    });

    const card = document.getElementById('premium-console-card');
    if (card && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        card.addEventListener('pointermove', (event) => {
            if (window.innerWidth < 1100) return;
            const rect = card.getBoundingClientRect();
            const x = (event.clientX - rect.left) / rect.width - .5;
            const y = (event.clientY - rect.top) / rect.height - .5;
            card.style.transform = `perspective(1200px) rotateY(${x * 6}deg) rotateX(${-y * 5}deg)`;
        });
        card.addEventListener('pointerleave', () => { card.style.transform = ''; });
    }

    document.querySelectorAll('[data-zpp-copy]').forEach((button) => {
        button.addEventListener('click', async () => {
            const value = button.getAttribute('data-zpp-copy') || '';
            if (!value) return;
            try {
                await navigator.clipboard.writeText(value);
                const original = button.textContent;
                button.textContent = 'کپی شد ✓';
                setTimeout(() => { button.textContent = original; }, 1600);
            } catch (_) {}
        });
    });
})();
</script>
