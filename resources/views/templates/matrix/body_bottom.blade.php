<script>
    document.getElementById('matrix-menu-btn')?.addEventListener('click', function () {
        document.getElementById('matrix-menu')?.classList.toggle('hidden');
    });

    // ===== Matrix code-rain — theme-coloured, fps-capped, battery-friendly =====
    (function () {
        var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var canvas = document.getElementById('zm-matrix');
        if (!canvas || reduce || innerWidth < 640) { if (canvas) canvas.style.display = 'none'; return; }

        var ctx = canvas.getContext('2d');
        var css = getComputedStyle(document.documentElement);
        var rain = (css.getPropertyValue('--zp-accent') || '#10b981').trim();
        var bg   = (css.getPropertyValue('--zp-bg') || '#060a08').trim();
        var chars = '01ﾊﾐﾋｰｳｼﾅﾓﾆｻﾜｲｸﾘ';
        var fs = 14, cols = 0, drops = [];

        function resize() {
            canvas.width = innerWidth; canvas.height = innerHeight;
            cols = Math.floor(canvas.width / fs);
            drops = new Array(cols).fill(1);
        }
        resize();
        addEventListener('resize', resize);

        // Cap at ~20fps so the animation never saturates the CPU.
        var last = 0, interval = 1000 / 20, running = true, raf;
        function frame(now) {
            if (!running) return;
            raf = requestAnimationFrame(frame);
            if (now - last < interval) return;
            last = now;
            ctx.fillStyle = bg.length ? hexToRgba(bg, 0.10) : 'rgba(6,10,8,.10)';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.fillStyle = rain; ctx.font = fs + 'px monospace';
            for (var i = 0; i < drops.length; i++) {
                ctx.fillText(chars[Math.floor(Math.random() * chars.length)], i * fs, drops[i] * fs);
                if (drops[i] * fs > canvas.height && Math.random() > 0.975) drops[i] = 0;
                drops[i]++;
            }
        }
        function hexToRgba(h, a) {
            h = h.replace('#', '');
            if (h.length === 3) h = h.split('').map(function (c) { return c + c; }).join('');
            var n = parseInt(h, 16);
            return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + a + ')';
        }
        // Pause when the tab is hidden to avoid wasting CPU/battery.
        document.addEventListener('visibilitychange', function () {
            running = !document.hidden;
            if (running) { last = 0; raf = requestAnimationFrame(frame); }
        });
        raf = requestAnimationFrame(frame);
    })();
</script>
