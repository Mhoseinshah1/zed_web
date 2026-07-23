{{-- Client-side double-submit protection (usability only; the server-side
     purchase intent is the real guarantee). Disables the submit button on the
     first valid submission, shows a loading state, keeps accessibility (aria-busy),
     and restores the button when the page is shown again (validation error / back). --}}
<script>
(function () {
    var LOADING = 'در حال پردازش...';

    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form || !form.matches || !form.matches('[data-idempotent-form]')) return;

        var btn = form.querySelector('[type="submit"]');
        if (form.dataset.submitting === '1') { e.preventDefault(); return; }
        form.dataset.submitting = '1';

        if (btn) {
            if (!btn.dataset.originalText) btn.dataset.originalText = btn.textContent;
            btn.setAttribute('aria-busy', 'true');
            btn.textContent = LOADING;
            // Disable AFTER submit is queued so the button's value is still sent.
            setTimeout(function () { btn.disabled = true; }, 0);
        }
    });

    function restore() {
        document.querySelectorAll('[data-idempotent-form]').forEach(function (form) {
            form.dataset.submitting = '';
            var btn = form.querySelector('[type="submit"]');
            if (!btn) return;
            btn.disabled = false;
            btn.removeAttribute('aria-busy');
            if (btn.dataset.originalText) btn.textContent = btn.dataset.originalText;
        });
    }
    window.addEventListener('pageshow', restore);
})();
</script>
