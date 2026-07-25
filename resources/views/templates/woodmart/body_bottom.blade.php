{{-- WoodMart mobile menu toggle — plain JS (Alpine is not loaded on public pages).
     Keeps aria-expanded in sync, closes on link selection and on Escape. --}}
<script>
    (function () {
        var btn  = document.getElementById('wm-menu-btn');
        var menu = document.getElementById('wm-menu');
        if (!btn || !menu) return;

        function setOpen(open) {
            menu.classList.toggle('hidden', !open);
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        }

        btn.addEventListener('click', function () {
            setOpen(menu.classList.contains('hidden'));
        });

        // Close after a navigation link is selected (the <summary> of the
        // collapsible category group is not a link and keeps the menu open).
        menu.addEventListener('click', function (e) {
            if (e.target.closest('a')) setOpen(false);
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !menu.classList.contains('hidden')) {
                setOpen(false);
                btn.focus();
            }
        });
    })();
</script>
