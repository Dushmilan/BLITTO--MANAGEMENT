<script>
    // No cookie yet: honour the OS preference before first paint. With a cookie
    // the server already rendered the class (spec §7.3).
    (function () {
        if (document.cookie.indexOf('blitto-theme=') !== -1) {
            return;
        }
        if (window.matchMedia('(prefers-color-scheme: dark)').matches) {
            document.documentElement.classList.add('dark');
        }
    })();
</script>
