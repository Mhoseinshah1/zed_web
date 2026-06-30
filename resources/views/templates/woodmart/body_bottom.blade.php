{{-- WoodMart mobile menu toggle — plain JS (Alpine is not loaded on public pages). --}}
<script>
    document.getElementById('wm-menu-btn')?.addEventListener('click', function () {
        document.getElementById('wm-menu')?.classList.toggle('hidden');
    });
</script>
