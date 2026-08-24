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
    if (!card || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    card.addEventListener('pointermove', (event) => {
        if (window.innerWidth < 1100) return;
        const rect = card.getBoundingClientRect();
        const x = (event.clientX - rect.left) / rect.width - .5;
        const y = (event.clientY - rect.top) / rect.height - .5;
        card.style.transform = `perspective(1200px) rotateY(${x * 6}deg) rotateX(${-y * 5}deg)`;
    });
    card.addEventListener('pointerleave', () => { card.style.transform = ''; });
})();
</script>
